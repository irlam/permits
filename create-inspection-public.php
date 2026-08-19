<?php
declare(strict_types=1);

use Permits\Csrf;
use Permits\Email;
use Permits\FormTemplateSeeder;
use Permits\PermitFormValidator;
use Permits\PublicRateLimiter;
use Permits\SystemSettings;
use Permits\TemplateCatalog;
use Ramsey\Uuid\Uuid;

[$app, $db, $root] = require __DIR__ . '/src/bootstrap.php';
require_once __DIR__ . '/src/Auth.php';

$auth = new Auth($db);
$currentUser = $auth->isLoggedIn() ? $auth->getCurrentUser() : null;

$branding = SystemSettings::branding($db, 'Permit System');
$companyName = $branding['company_name'];
$companyLogoPath = $branding['company_logo_path'];
$companyLogoUrl = $companyLogoPath ? asset('/' . ltrim($companyLogoPath, '/')) : null;
$brandingCss = SystemSettings::brandingCssVariables($branding);

$templateId = isset($_GET['template']) && is_scalar($_GET['template'])
    ? trim((string)$_GET['template'])
    : '';

if ($templateId === '') {
    header('Location: ' . $app->url('/#inspections'));
    exit;
}

try {
    $stmt = $db->pdo->prepare('SELECT * FROM form_templates WHERE id = ? AND active = 1 LIMIT 1');
    $stmt->execute([$templateId]);
    $template = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Throwable $e) {
    error_log('Inspection template lookup failed: ' . $e->getMessage());
    $template = null;
}

if (!$template || !TemplateCatalog::isInspection($template)) {
    header('Location: ' . $app->url('/#inspections'));
    exit;
}

$schema = json_decode((string)($template['json_schema'] ?? ''), true);
if (!is_array($schema) || strtolower((string)($schema['workflow'] ?? '')) !== 'inspection') {
    header('Location: ' . $app->url('/#inspections'));
    exit;
}

$formStructure = json_decode((string)($template['form_structure'] ?? ''), true);
if (!is_array($formStructure) || $formStructure === []) {
    $formStructure = FormTemplateSeeder::buildPublicFormStructure($schema);
}

$success = false;
$error = null;
$inspectionId = null;
$reference = null;
$uniqueLink = null;
$answers = [];
$holderName = (string)($currentUser['name'] ?? '');
$holderEmail = (string)($currentUser['email'] ?? '');
$holderPhone = '';
$uploadedTargets = [];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        if (!Csrf::validateRequest('public-inspection-submit', true)) {
            http_response_code(419);
            throw new InvalidArgumentException('Your form session expired. Refresh the page and try again.');
        }

        if (!empty($_POST['website'])) {
            throw new InvalidArgumentException('Unable to submit this inspection. Refresh the page and try again.');
        }

        foreach (['holder_name', 'holder_email', 'holder_phone'] as $contactField) {
            if (isset($_POST[$contactField]) && !is_scalar($_POST[$contactField])) {
                throw new InvalidArgumentException('Contact details contain an invalid value.');
            }
        }

        $holderName = trim((string)($_POST['holder_name'] ?? ''));
        $holderEmail = strtolower(trim((string)($_POST['holder_email'] ?? '')));
        $holderPhone = trim((string)($_POST['holder_phone'] ?? ''));

        if ($holderName === '' || filter_var($holderEmail, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('Enter your name and a valid email address.');
        }
        if (mb_strlen($holderName, 'UTF-8') > 255 || mb_strlen($holderEmail, 'UTF-8') > 255 || mb_strlen($holderPhone, 'UTF-8') > 50) {
            throw new InvalidArgumentException('Contact details are too long.');
        }

        $submittedValues = [];
        foreach ($formStructure as $section) {
            foreach (($section['fields'] ?? []) as $field) {
                if (!is_array($field) || empty($field['name'])) {
                    continue;
                }

                $fieldName = (string)$field['name'];
                $raw = $_POST[$fieldName] ?? '';
                if (is_array($raw)) {
                    $clean = array_values(array_filter(array_map(
                        static fn($item): string => is_scalar($item) ? trim((string)$item) : '',
                        $raw
                    ), static fn(string $value): bool => $value !== ''));
                    $value = implode(', ', $clean);
                } else {
                    $value = trim((string)$raw);
                }
                if (mb_strlen($value, 'UTF-8') > 10000) {
                    throw new InvalidArgumentException('One of the inspection answers is too long.');
                }

                $answers[$fieldName] = $value;
                $submittedValues[$fieldName] = $value;

                $noteKey = $fieldName . '_note';
                if (isset($_POST[$noteKey])) {
                    if (!is_scalar($_POST[$noteKey])) {
                        throw new InvalidArgumentException('An inspection note has an invalid value.');
                    }
                    $note = trim((string)$_POST[$noteKey]);
                    if (mb_strlen($note, 'UTF-8') > 5000) {
                        throw new InvalidArgumentException('Inspection notes must be 5000 characters or fewer.');
                    }
                    $answers[$noteKey] = $note;
                    $submittedValues[$noteKey] = $note;
                }

                $mediaKey = $fieldName . '_media';
                if (!empty($_FILES[$mediaKey]['name'])) {
                    $submittedValues[$mediaKey] = $_FILES[$mediaKey]['name'];
                }
            }
        }

        if ($formStructure === []) {
            throw new InvalidArgumentException('This inspection checklist has no usable fields. Contact an administrator.');
        }
        $fieldErrors = PermitFormValidator::validate($formStructure, $submittedValues, true);
        if ($fieldErrors !== []) {
            $messages = array_slice(array_values($fieldErrors), 0, 5);
            $remaining = count($fieldErrors) - count($messages);
            $message = implode(' ', $messages);
            if ($remaining > 0) {
                $message .= sprintf(' Please complete %d more required field%s.', $remaining, $remaining === 1 ? '' : 's');
            }
            throw new InvalidArgumentException($message);
        }

        $rateLimit = (new PublicRateLimiter($db->pdo))->consumePermitSubmission(
            (string)($_SERVER['REMOTE_ADDR'] ?? ''),
            $holderEmail,
            $currentUser !== null
        );
        if ($rateLimit['limited']) {
            http_response_code(429);
            header('Retry-After: ' . $rateLimit['retry_after']);
            throw new InvalidArgumentException('Too many submissions. Please wait before trying again.');
        }

        $inspectionId = Uuid::uuid4()->toString();
        $uniqueLink = bin2hex(random_bytes(32));
        $referenceFactory = static fn(): string => 'INS-' . date('Y') . '-' .
            str_pad((string)random_int(0, 9_999_999_999), 10, '0', STR_PAD_LEFT);
        $reference = $referenceFactory();

        // Hardened evidence uploads: MIME allow-list, random stored names and
        // conservative per-file / per-submission limits.
        $uploadRoot = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'uploads';
        $baseUploadDir = $uploadRoot . DIRECTORY_SEPARATOR . $inspectionId;
        $allowedTypes = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            'video/mp4' => 'mp4',
            'video/quicktime' => 'mov',
            'video/webm' => 'webm',
        ];
        $maxFiles = 10;
        $maxPerFile = ($currentUser !== null ? 25 : 10) * 1024 * 1024;
        $maxTotal = ($currentUser !== null ? 50 : 20) * 1024 * 1024;
        $fileCount = 0;
        $totalBytes = 0;
        $mime = new finfo(FILEINFO_MIME_TYPE);

        foreach ($formStructure as $section) {
            foreach (($section['fields'] ?? []) as $field) {
                if (!is_array($field) || empty($field['name'])) {
                    continue;
                }
                $fieldName = (string)$field['name'];
                $mediaKey = $fieldName . '_media';
                if (empty($_FILES[$mediaKey]) || empty($_FILES[$mediaKey]['name'])) {
                    continue;
                }

                $files = $_FILES[$mediaKey];
                $names = is_array($files['name'] ?? null) ? $files['name'] : [$files['name'] ?? ''];
                $tmpNames = is_array($files['tmp_name'] ?? null) ? $files['tmp_name'] : [$files['tmp_name'] ?? ''];
                $errors = is_array($files['error'] ?? null) ? $files['error'] : [$files['error'] ?? UPLOAD_ERR_NO_FILE];
                $paths = [];

                foreach ($names as $index => $originalName) {
                    $originalName = is_scalar($originalName) ? trim((string)$originalName) : '';
                    if ($originalName === '') {
                        continue;
                    }
                    $fileCount++;
                    if ($fileCount > $maxFiles) {
                        throw new InvalidArgumentException('Choose no more than 10 evidence files for one inspection.');
                    }

                    $tmp = (string)($tmpNames[$index] ?? '');
                    $uploadError = (int)($errors[$index] ?? UPLOAD_ERR_NO_FILE);
                    if ($uploadError !== UPLOAD_ERR_OK || !is_uploaded_file($tmp)) {
                        throw new InvalidArgumentException('One of the evidence files could not be uploaded.');
                    }
                    $size = filesize($tmp);
                    if ($size === false || $size > $maxPerFile) {
                        throw new InvalidArgumentException('One of the evidence files is too large.');
                    }
                    $totalBytes += $size;
                    if ($totalBytes > $maxTotal) {
                        throw new InvalidArgumentException('The total evidence upload is too large.');
                    }

                    $detectedType = $mime->file($tmp);
                    if (!is_string($detectedType) || !isset($allowedTypes[$detectedType])) {
                        throw new InvalidArgumentException('Only approved image and video evidence can be uploaded.');
                    }
                    if (!is_dir($baseUploadDir) && !@mkdir($baseUploadDir, 0775, true) && !is_dir($baseUploadDir)) {
                        throw new RuntimeException('Unable to create the inspection evidence folder.');
                    }

                    $target = $baseUploadDir . DIRECTORY_SEPARATOR . bin2hex(random_bytes(16)) . '.' . $allowedTypes[$detectedType];
                    if (!@move_uploaded_file($tmp, $target)) {
                        throw new RuntimeException('Unable to save inspection evidence.');
                    }
                    $uploadedTargets[] = $target;
                    $paths[] = 'uploads/' . $inspectionId . '/' . basename($target);
                }

                if ($paths !== []) {
                    $answers[$mediaKey] = implode(', ', $paths);
                }
            }
        }

        $siteBlock = '';
        foreach (['inspectionArea', 'buildingArea', 'propertyArea', 'siteProject'] as $locationKey) {
            if (!empty($answers[$locationKey])) {
                $siteBlock = mb_substr(trim((string)$answers[$locationKey]), 0, 191, 'UTF-8');
                break;
            }
        }

        $holderId = null;
        if (
            isset($currentUser['id'], $currentUser['email'])
            && hash_equals(strtolower(trim((string)$currentUser['email'])), $holderEmail)
        ) {
            $holderId = (string)$currentUser['id'];
        }

        $driver = (string)$db->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $now = $driver === 'sqlite' ? "datetime('now')" : 'NOW()';
        $isDuplicateKey = static function (PDOException $exception): bool {
            $driverCode = (int)($exception->errorInfo[1] ?? 0);
            return in_array((string)$exception->getCode(), ['23000', '23505'], true)
                || in_array($driverCode, [19, 1062, 2067], true);
        };

        $insert = $db->pdo->prepare("
            INSERT INTO forms (
                id, ref_number, template_id, form_data, site_block, status,
                holder_id, holder_email, holder_name, holder_phone, unique_link,
                requires_approval, approval_status, closed_at, created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, 'closed', ?, ?, ?, ?, ?, 0, NULL, {$now}, {$now}, {$now})
        ");

        $db->pdo->beginTransaction();
        $inserted = false;
        for ($attempt = 0; $attempt < 10; $attempt++) {
            try {
                $insert->execute([
                    $inspectionId,
                    $reference,
                    $templateId,
                    json_encode($answers, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    $siteBlock !== '' ? $siteBlock : null,
                    $holderId,
                    $holderEmail,
                    $holderName,
                    $holderPhone,
                    $uniqueLink,
                ]);
                $inserted = true;
                break;
            } catch (PDOException $insertError) {
                if (!$isDuplicateKey($insertError) || $attempt === 9) {
                    throw $insertError;
                }
                $reference = $referenceFactory();
                $uniqueLink = bin2hex(random_bytes(32));
            }
        }
        if (!$inserted) {
            throw new RuntimeException('Unable to allocate inspection identifiers.');
        }
        $db->pdo->commit();

        try {
            if (function_exists('logActivity')) {
                logActivity(
                    'public_inspection_completed',
                    'inspection',
                    'form',
                    $inspectionId,
                    'Inspection completed: ' . $reference . ' by ' . $holderEmail
                );
            }
        } catch (Throwable $logError) {
            error_log('Unable to log inspection completion: ' . $logError->getMessage());
        }

        try {
            $viewUrl = $app->url('/view-inspection-public.php?link=' . rawurlencode($uniqueLink));
            $subject = 'Inspection recorded: ' . $reference;
            $safeTemplate = htmlspecialchars((string)($template['name'] ?? 'Inspection Checklist'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $safeReference = htmlspecialchars($reference, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $safeUrl = htmlspecialchars($viewUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $body = '<!doctype html><html><body style="font-family:Arial,sans-serif;color:#111827">'
                . '<h2>Inspection recorded</h2>'
                . '<p><strong>Checklist:</strong> ' . $safeTemplate . '</p>'
                . '<p><strong>Reference:</strong> ' . $safeReference . '</p>'
                . '<p><a href="' . $safeUrl . '">View the inspection record</a></p>'
                . '<p>This inspection record does not by itself authorise high-risk work. Use the appropriate permit-to-work controls where required.</p>'
                . '</body></html>';
            (new Email($db, $root))->queue($holderEmail, $subject, $body);
        } catch (Throwable $emailError) {
            error_log('Unable to queue inspection confirmation email: ' . $emailError->getMessage());
        }

        $success = true;
    } catch (Throwable $e) {
        if ($db->pdo->inTransaction()) {
            $db->pdo->rollBack();
        }
        foreach ($uploadedTargets as $uploadedTarget) {
            @unlink($uploadedTarget);
        }
        $uploadedTargets = [];
        error_log('Public inspection submission failed: ' . $e->getMessage());
        $error = $e instanceof InvalidArgumentException
            ? $e->getMessage()
            : 'Unable to record the inspection. Please try again.';
    }
}

function inspection_field_options(array $field): array
{
    $options = $field['options'] ?? [];
    return is_array($options) ? $options : [];
}
?>
<!doctype html>
<html lang="en" style="<?= htmlspecialchars($brandingCss, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= htmlspecialchars((string)$template['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> · <?= htmlspecialchars($companyName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></title>
    <?php if (function_exists('cache_meta_tags')) { cache_meta_tags(); } ?>
    <link rel="stylesheet" href="<?= htmlspecialchars(asset('/assets/app.css'), ENT_QUOTES, 'UTF-8') ?>">
    <style>
        :root{color-scheme:dark}*{box-sizing:border-box}body{margin:0;background:#0f172a;color:#e5e7eb;font-family:system-ui,-apple-system,'Segoe UI',sans-serif}.wrap{max-width:980px;margin:0 auto;padding:20px 14px 72px}.brand{display:inline-flex;align-items:center;gap:10px;color:#f8fafc;text-decoration:none;margin:0 0 16px;padding:8px;border-radius:12px}.brand:hover{background:rgba(var(--brand-primary-rgb),.12)}.brand-logo,.brand-symbol{width:42px;height:42px;border-radius:10px}.brand-logo{object-fit:contain;background:#fff;padding:4px}.brand-symbol{display:grid;place-items:center;background:var(--brand-primary);color:var(--brand-on-primary);font-weight:800}.card{background:#111827;border:1px solid #263244;border-radius:20px;padding:clamp(18px,4vw,34px);box-shadow:0 22px 55px rgba(0,0,0,.25)}h1{margin:0 0 8px;font-size:clamp(24px,4vw,34px)}.lead{color:#94a3b8;margin:0 0 22px}.notice{padding:14px 16px;border-radius:12px;background:rgba(14,165,233,.11);border:1px solid rgba(56,189,248,.35);color:#bae6fd;margin:18px 0}.error{padding:14px 16px;border-radius:12px;background:rgba(239,68,68,.12);border:1px solid rgba(248,113,113,.4);color:#fecaca;margin:18px 0}.success{padding:24px;border-radius:16px;background:rgba(34,197,94,.12);border:1px solid rgba(74,222,128,.38);text-align:center}.section{border-top:1px solid #263244;padding-top:20px;margin-top:28px}.section h2{font-size:20px;margin:0 0 16px}.field{display:grid;gap:7px;margin:0 0 18px}.field label{font-weight:650;color:#e2e8f0}.required{color:#f87171}input,textarea,select{width:100%;border:1px solid #334155;background:#0a101a;color:#f8fafc;border-radius:10px;padding:12px 13px;font:inherit}textarea{min-height:100px;resize:vertical}input:focus,textarea:focus,select:focus{outline:2px solid rgba(var(--brand-primary-rgb),.7);outline-offset:1px}.choices{display:grid;gap:9px}.choice{display:flex;gap:10px;align-items:center;padding:10px 12px;border:1px solid #334155;border-radius:10px;background:#0a101a}.choice input{width:auto}.tools{display:flex;gap:8px;flex-wrap:wrap;margin-top:8px}.tool{border:1px solid #475569;background:#1e293b;color:#e2e8f0;border-radius:9px;padding:8px 10px;cursor:pointer}.extra{margin-top:10px}.extra[hidden]{display:none}.actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:28px}.btn{display:inline-flex;justify-content:center;align-items:center;min-height:46px;padding:11px 18px;border-radius:10px;border:1px solid transparent;font-weight:700;text-decoration:none;cursor:pointer}.btn-primary{background:var(--brand-primary);color:var(--brand-on-primary)}.btn-secondary{border-color:#475569;background:#1e293b;color:#e2e8f0}.honeypot{position:fixed;left:-10000px;opacity:0;pointer-events:none}.help{color:#94a3b8;font-size:13px}.media-existing{font-size:13px;color:#cbd5e1}.success-actions{display:flex;justify-content:center;gap:10px;flex-wrap:wrap;margin-top:20px}@media(max-width:640px){.actions .btn,.success-actions .btn{width:100%}.card{border-radius:14px}.wrap{padding-left:10px;padding-right:10px}}
    </style>
</head>
<body>
<div class="wrap">
    <a class="brand" href="<?= htmlspecialchars($app->url('/#inspections'), ENT_QUOTES, 'UTF-8') ?>">
        <?php if ($companyLogoUrl): ?><img class="brand-logo" src="<?= htmlspecialchars($companyLogoUrl, ENT_QUOTES, 'UTF-8') ?>" alt=""><?php else: ?><span class="brand-symbol" aria-hidden="true"><?= htmlspecialchars(mb_strtoupper(mb_substr($companyName, 0, 1, 'UTF-8'), 'UTF-8')) ?></span><?php endif; ?>
        <span><strong><?= htmlspecialchars($companyName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong><br><small style="color:#94a3b8">Inspections & checklists</small></span>
    </a>
    <main class="card">
        <?php if ($success): ?>
            <div class="success">
                <h1>✅ Inspection recorded</h1>
                <p><strong>Reference:</strong> <?= htmlspecialchars((string)$reference, ENT_QUOTES, 'UTF-8') ?></p>
                <p>This checklist is complete and does not require manager permit approval.</p>
                <div class="success-actions">
                    <a class="btn btn-primary" href="<?= htmlspecialchars($app->url('/view-inspection-public.php?link=' . rawurlencode((string)$uniqueLink)), ENT_QUOTES, 'UTF-8') ?>">View inspection</a>
                    <a class="btn btn-secondary" href="<?= htmlspecialchars($app->url('/#inspections'), ENT_QUOTES, 'UTF-8') ?>">Back to inspections</a>
                </div>
            </div>
        <?php else: ?>
            <h1>🔎 <?= htmlspecialchars((string)$template['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h1>
            <p class="lead"><?= htmlspecialchars((string)($template['description'] ?? 'Record the inspection findings and actions.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
            <div class="notice"><strong>Inspection record:</strong> completing this checklist records findings and actions. It does not itself authorise high-risk work; raise the relevant permit where one is required.</div>
            <?php if ($error): ?><div class="error" role="alert">⚠️ <?= htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div><?php endif; ?>

            <form method="post" enctype="multipart/form-data" id="inspectionForm">
                <?= Csrf::getFormField('public-inspection-submit') ?>
                <div class="honeypot" aria-hidden="true"><label for="website">Website</label><input type="text" id="website" name="website" tabindex="-1" autocomplete="off"></div>

                <section class="section" style="border-top:0;padding-top:0;margin-top:0">
                    <h2>Your details</h2>
                    <div class="field"><label for="holder_name">Your name <span class="required">*</span></label><input id="holder_name" name="holder_name" maxlength="255" required autocomplete="name" value="<?= htmlspecialchars($holderName, ENT_QUOTES, 'UTF-8') ?>"></div>
                    <div class="field"><label for="holder_email">Your email <span class="required">*</span></label><input id="holder_email" type="email" name="holder_email" maxlength="255" required autocomplete="email" value="<?= htmlspecialchars($holderEmail, ENT_QUOTES, 'UTF-8') ?>"></div>
                    <div class="field"><label for="holder_phone">Phone number</label><input id="holder_phone" type="tel" name="holder_phone" maxlength="50" autocomplete="tel" value="<?= htmlspecialchars($holderPhone, ENT_QUOTES, 'UTF-8') ?>"></div>
                </section>

                <?php foreach ($formStructure as $sectionIndex => $section): ?>
                    <?php if (!is_array($section) || !is_array($section['fields'] ?? null)) { continue; } ?>
                    <section class="section">
                        <h2><?= htmlspecialchars((string)($section['title'] ?? ('Section ' . ($sectionIndex + 1))), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h2>
                        <?php foreach ($section['fields'] as $field): ?>
                            <?php
                                if (!is_array($field) || empty($field['name'])) { continue; }
                                $name = (string)$field['name'];
                                $label = (string)($field['label'] ?? $name);
                                $type = (string)($field['type'] ?? 'text');
                                $required = !empty($field['required']) || !empty($field['scoreItem']);
                                $value = (string)($answers[$name] ?? '');
                                $options = inspection_field_options($field);
                            ?>
                            <div class="field">
                                <label><?= htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?><?php if ($required): ?> <span class="required">*</span><?php endif; ?></label>
                                <?php if ($type === 'textarea'): ?>
                                    <textarea name="<?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?>" maxlength="10000" <?= $required ? 'required' : '' ?>><?= htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></textarea>
                                <?php elseif ($type === 'select'): ?>
                                    <select name="<?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?>" <?= $required ? 'required' : '' ?>><option value="">Select...</option><?php foreach ($options as $option): $ov = is_array($option) ? (string)($option['value'] ?? '') : (string)$option; $ol = is_array($option) ? (string)($option['label'] ?? $ov) : $ov; ?><option value="<?= htmlspecialchars($ov, ENT_QUOTES, 'UTF-8') ?>" <?= $value === $ov ? 'selected' : '' ?>><?= htmlspecialchars($ol, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option><?php endforeach; ?></select>
                                <?php elseif ($type === 'select_multiple'): ?>
                                    <?php $selected = array_map('trim', explode(',', $value)); ?>
                                    <select name="<?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?>[]" multiple <?= $required ? 'required' : '' ?>><?php foreach ($options as $option): $ov = is_array($option) ? (string)($option['value'] ?? '') : (string)$option; $ol = is_array($option) ? (string)($option['label'] ?? $ov) : $ov; ?><option value="<?= htmlspecialchars($ov, ENT_QUOTES, 'UTF-8') ?>" <?= in_array($ov, $selected, true) ? 'selected' : '' ?>><?= htmlspecialchars($ol, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option><?php endforeach; ?></select>
                                <?php elseif ($type === 'radio'): ?>
                                    <div class="choices" role="radiogroup" aria-label="<?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>">
                                    <?php foreach ($options as $optionIndex => $option): $ov = is_array($option) ? (string)($option['value'] ?? '') : (string)$option; $ol = is_array($option) ? (string)($option['label'] ?? $ov) : $ov; $id = $name . '_' . $optionIndex; ?>
                                        <label class="choice" for="<?= htmlspecialchars($id, ENT_QUOTES, 'UTF-8') ?>"><input id="<?= htmlspecialchars($id, ENT_QUOTES, 'UTF-8') ?>" type="radio" name="<?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?>" value="<?= htmlspecialchars($ov, ENT_QUOTES, 'UTF-8') ?>" <?= $required && $optionIndex === 0 ? 'required' : '' ?> <?= $value === $ov ? 'checked' : '' ?>> <span><?= htmlspecialchars($ol, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span></label>
                                    <?php endforeach; ?>
                                    </div>
                                    <?php if (!empty($field['scoreItem'])): ?>
                                        <div class="tools"><button class="tool" type="button" data-toggle="note_<?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?>">📝 Add note</button><button class="tool" type="button" data-toggle="media_<?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?>">📸 Add evidence</button></div>
                                        <div class="extra" id="note_<?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?>" <?= empty($answers[$name . '_note']) ? 'hidden' : '' ?>><textarea name="<?= htmlspecialchars($name . '_note', ENT_QUOTES, 'UTF-8') ?>" maxlength="5000" placeholder="Explain No or N/A answers, or add useful context."><?= htmlspecialchars((string)($answers[$name . '_note'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></textarea></div>
                                        <div class="extra" id="media_<?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?>" hidden><input type="file" name="<?= htmlspecialchars($name . '_media', ENT_QUOTES, 'UTF-8') ?>[]" accept="image/*,video/*" capture="environment" multiple><div class="help">Up to 10 evidence files per inspection.</div></div>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <?php $htmlType = in_array($type, ['date','time','datetime','number','email','tel'], true) ? ($type === 'datetime' ? 'datetime-local' : $type) : 'text'; ?>
                                    <input type="<?= htmlspecialchars($htmlType, ENT_QUOTES, 'UTF-8') ?>" name="<?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?>" value="<?= htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" <?= $required ? 'required' : '' ?><?php if (isset($field['min'])): ?> min="<?= htmlspecialchars((string)$field['min'], ENT_QUOTES, 'UTF-8') ?>"<?php endif; ?><?php if (isset($field['max'])): ?> max="<?= htmlspecialchars((string)$field['max'], ENT_QUOTES, 'UTF-8') ?>"<?php endif; ?><?php if (isset($field['step'])): ?> step="<?= htmlspecialchars((string)$field['step'], ENT_QUOTES, 'UTF-8') ?>"<?php endif; ?>>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </section>
                <?php endforeach; ?>

                <div class="actions">
                    <button class="btn btn-primary" type="submit">Complete inspection</button>
                    <a class="btn btn-secondary" href="<?= htmlspecialchars($app->url('/#inspections'), ENT_QUOTES, 'UTF-8') ?>">Cancel</a>
                </div>
            </form>
        <?php endif; ?>
    </main>
</div>
<script>
document.querySelectorAll('[data-toggle]').forEach(function(button){button.addEventListener('click',function(){var el=document.getElementById(button.dataset.toggle);if(el){el.hidden=!el.hidden;if(!el.hidden){var input=el.querySelector('textarea,input');if(input){input.focus();}}}});});
document.querySelectorAll('input[type="radio"]').forEach(function(input){input.addEventListener('change',function(){if(!['no','na'].includes(String(input.value).toLowerCase()))return;var note=document.getElementById('note_'+input.name);if(note){note.hidden=false;}});});
</script>
</body>
</html>
