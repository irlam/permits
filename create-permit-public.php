<?php
/**
 * Public Permit Creation
 * 
 * File Path: /create-permit-public.php
 * Description: Public permit creation form - no login required
 * Created: 23/10/2025
 * Last Modified: 23/10/2025
 * 
 * Features:
 * - No authentication required
 * - Collects holder information (name, email, phone)
 * - Optional push notification subscription
 * - Creates permit as "pending_approval"
 * - Generates unique view link
 * - Sends confirmation email
 */

// Load bootstrap
[$app, $db, $root] = require __DIR__ . '/src/bootstrap.php';
require_once __DIR__ . '/src/approval-notifications.php';
require_once __DIR__ . '/src/Auth.php';

// Authentication remains optional on this public form. When a signed-in,
// active user creates a permit, retain that relationship so the permit appears
// in their dashboard without changing anonymous access to the form.
$auth = new Auth($db);
$currentUser = $auth->isLoggedIn() ? $auth->getCurrentUser() : null;

$branding = \Permits\SystemSettings::branding($db, 'Permit System');
$companyName = $branding['company_name'];
$companyLogoPath = $branding['company_logo_path'];
$companyLogoUrl = $companyLogoPath ? asset('/' . ltrim($companyLogoPath, '/')) : null;
$brandingCss = \Permits\SystemSettings::brandingCssVariables($branding);

// Get template ID from query string or resume an existing draft via unique link
$template_id = isset($_GET['template']) && is_string($_GET['template']) ? trim($_GET['template']) : null;
$draft_link = isset($_GET['draft']) && is_string($_GET['draft']) ? trim($_GET['draft']) : null;
$reopen_link = isset($_GET['reopen']) && is_string($_GET['reopen']) ? trim($_GET['reopen']) : null;

if (!$template_id && !$draft_link && !$reopen_link) {
    header('Location: ' . $app->url('/'));
    exit;
}

// Load existing draft if present
$existingPermit = null;
$isUpdate = false;  // true = edit draft, false = create new
if ($draft_link && strlen($draft_link) >= 32 && strlen($draft_link) <= 100) {
    try {
        $st = $db->pdo->prepare("SELECT * FROM forms WHERE unique_link = ? AND status = 'draft' LIMIT 1");
        $st->execute([$draft_link]);
        $existingPermit = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($existingPermit) {
            $template_id = $existingPermit['template_id'];
            $isUpdate = true;  // This is updating an existing draft
        }
    } catch (Exception $e) {
        // Ignore; we'll fall back to normal flow
    }
}

// Load permit to reopen if present - creates a NEW permit based on existing data
if ($reopen_link && strlen($reopen_link) >= 32 && strlen($reopen_link) <= 100) {
    try {
        // The unguessable public link is required. Accepting a database ID here
        // allowed permit contents to be copied without possession of its link.
        $st = $db->pdo->prepare("SELECT * FROM forms WHERE unique_link = ? AND status IN ('active', 'issued', 'approved', 'closed', 'expired', 'rejected') LIMIT 1");
        $st->execute([(string) $reopen_link]);
        $existingPermit = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($existingPermit) {
            $template_id = $existingPermit['template_id'];
            $isUpdate = false;  // Always create a NEW permit when reopening
        }
    } catch (Exception $e) {
        // Ignore; we'll fall back to normal flow
    }
}

// Get template details
try {
    // Direct links may only start permits from templates currently published by
    // an administrator. A bearer link to an existing draft or previous permit
    // can still be completed even if that template has since been retired.
    $templateSql = 'SELECT * FROM form_templates WHERE id = ?';
    if ($existingPermit === null) {
        $templateSql .= ' AND active = 1';
    }
    $stmt = $db->pdo->prepare($templateSql);
    $stmt->execute([$template_id]);
    $template = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$template) {
        header('Location: ' . $app->url('/'));
        exit;
    }
} catch (Exception $e) {
    error_log('Public template load failed: ' . $e->getMessage());
    http_response_code(500);
    exit('Unable to load the permit template.');
}

$formStructure = [];

try {
    if (!empty($template['form_structure'])) {
        $decodedStructure = json_decode((string)$template['form_structure'], true);
        if (is_array($decodedStructure)) {
            $formStructure = $decodedStructure;
        }
    }

    if ((empty($formStructure) || !is_array($formStructure)) && !empty($template['json_schema'])) {
        $schemaDecoded = json_decode((string)$template['json_schema'], true);
        if (is_array($schemaDecoded)) {
            $formStructure = \Permits\FormTemplateSeeder::buildPublicFormStructure($schemaDecoded);
        }
    }
} catch (\Throwable $e) {
    $formStructure = [];
}

// Handle form submission
$success = false;
$error = null;
$permit_id = null;
$unique_link = null;
$isDraftAction = false;
$holder_name = $isUpdate
    ? (string) ($existingPermit['holder_name'] ?? '')
    : (string) ($currentUser['name'] ?? '');
$holder_email = $isUpdate
    ? (string) ($existingPermit['holder_email'] ?? '')
    : (string) ($currentUser['email'] ?? '');
$holder_phone = $isUpdate ? (string) ($existingPermit['holder_phone'] ?? '') : '';
$uploadedTargets = [];
// Prefill data when editing a draft
$existingData = [];
if ($existingPermit && $isUpdate) {
    // Only set IDs when editing an existing draft
    $permit_id = $existingPermit['id'];
    $unique_link = $existingPermit['unique_link'];
    $existingData = json_decode((string)($existingPermit['form_data'] ?? ''), true) ?: [];
} elseif ($existingPermit && !$isUpdate) {
    // A similar permit may reuse descriptive details, but its dates, safety
    // checks and evidence must be completed afresh for the new job.
    $existingData = json_decode((string)($existingPermit['form_data'] ?? ''), true) ?: [];
    foreach ($formStructure as $section) {
        foreach (($section['fields'] ?? []) as $field) {
            if (!is_array($field) || empty($field['name'])) {
                continue;
            }
            $fieldName = (string) $field['name'];
            $fieldType = strtolower((string) ($field['type'] ?? 'text'));
            if (
                !empty($field['scoreItem'])
                || in_array($fieldType, ['date', 'time', 'datetime'], true)
                || preg_match('/(?:^|_)(?:permit_?no|permit_?number)$/i', $fieldName) === 1
            ) {
                $existingData[$fieldName] = '';
            }
            unset($existingData[$fieldName . '_note'], $existingData[$fieldName . '_media']);
        }
    }
    unset($existingData['_applicant_declaration'], $existingData['_applicant_declared_at']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!\Permits\Csrf::validateRequest('public-permit-submit', true)) {
            http_response_code(419);
            throw new InvalidArgumentException('Your form session expired. Please refresh the page and try again.');
        }
        $isDraftAction = (string) ($_POST['action'] ?? 'submit') === 'save_draft';
        $targetStatus = $isDraftAction ? 'draft' : 'pending_approval';

        // Collect form data
        foreach (['holder_name', 'holder_email', 'holder_phone'] as $contactField) {
            if (isset($_POST[$contactField]) && !is_scalar($_POST[$contactField])) {
                throw new InvalidArgumentException('Contact details contain an invalid value.');
            }
        }
        if (isset($_POST['applicant_declaration']) && !is_scalar($_POST['applicant_declaration'])) {
            throw new InvalidArgumentException('The applicant declaration contains an invalid value.');
        }
        $holder_name = trim((string) ($_POST['holder_name'] ?? ''));
        $holder_email = strtolower(trim((string) ($_POST['holder_email'] ?? '')));
        $holder_phone = trim((string) ($_POST['holder_phone'] ?? ''));
        $applicantDeclaration = isset($_POST['applicant_declaration'])
            && hash_equals('1', (string) $_POST['applicant_declaration']);
        
        // Validate required fields
        if (empty($holder_name) || empty($holder_email)) {
            throw new InvalidArgumentException("Name and email are required");
        }
        if (!filter_var($holder_email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException("Enter a valid email address");
        }
        if (mb_strlen($holder_name) > 255 || mb_strlen($holder_email) > 255 || mb_strlen($holder_phone) > 50) {
            throw new InvalidArgumentException("Contact details are too long");
        }

        if (!empty($_POST['website'])) {
            throw new InvalidArgumentException('Unable to submit this permit. Please refresh the page and try again.');
        }

        $issuer_id = isset($currentUser['id'])
            ? (string) $currentUser['id']
            : ($isUpdate ? ($existingPermit['issuer_id'] ?? null) : null);
        $holder_id = null;
        if (
            isset($currentUser['id'], $currentUser['email'])
            && hash_equals(strtolower(trim((string) $currentUser['email'])), $holder_email)
        ) {
            $holder_id = (string) $currentUser['id'];
        } elseif (
            $isUpdate
            && !empty($existingPermit['holder_id'])
            && hash_equals(strtolower(trim((string) ($existingPermit['holder_email'] ?? ''))), $holder_email)
        ) {
            // Preserve the established owner when their draft is resumed using
            // its private link without requiring them to sign in again.
            $holder_id = (string) $existingPermit['holder_id'];
        }
        if (!$isDraftAction && !$applicantDeclaration) {
            throw new InvalidArgumentException('Confirm the applicant declaration before submitting the permit.');
        }
        // Generate IDs early so we can store media predictably
        if (!$isUpdate) {
            $permit_id = \Ramsey\Uuid\Uuid::uuid4()->toString();
            $unique_link = bin2hex(random_bytes(32));
        }

        // A renewed permit is a new record and must never reuse its source reference.
        // Legacy drafts without a reference receive one when next saved.
        $ref_number = $isUpdate ? trim((string) ($existingPermit['ref_number'] ?? '')) : '';
        $referenceFactory = null;
        if ($ref_number === '') {
            try {
                $referenceSettings = \Permits\SystemSettings::load(
                    $db,
                    ['permit_prefix'],
                    ['permit_prefix' => 'PTW']
                );
                $permitPrefix = strtoupper(trim((string) ($referenceSettings['permit_prefix'] ?? 'PTW')));
            } catch (Throwable $settingsError) {
                error_log('Unable to load permit prefix: ' . $settingsError->getMessage());
                $permitPrefix = 'PTW';
            }
            if (preg_match('/^[A-Z0-9-]{2,10}$/', $permitPrefix) !== 1) {
                $permitPrefix = 'PTW';
            }

            $referenceFactory = static fn(): string => $permitPrefix . '-' . date('Y') . '-' .
                str_pad((string)random_int(0, 9_999_999_999), 10, '0', STR_PAD_LEFT);
            $ref_number = $referenceFactory();
        }

        // Keep the stored values separately while rebuilding the submitted data.
        // This preserves existing evidence when a draft is saved without selecting
        // the same files again.
        $previousData = $existingData;

        // Collect permit data using the parsed structure (values + optional notes)
        $permit_data = [];
        $submittedValues = [];
        foreach ($formStructure as $section) {
            if (!isset($section['fields']) || !is_array($section['fields'])) {
                continue;
            }

            foreach ($section['fields'] as $field) {
                if (!is_array($field) || empty($field['name'])) {
                    continue;
                }

                $fieldName = (string)$field['name'];
                $rawValue = array_key_exists($fieldName, $_POST)
                    ? $_POST[$fieldName]
                    : ($previousData[$fieldName] ?? '');
                $submittedValues[$fieldName] = $rawValue;

                if (is_array($rawValue)) {
                    $rawValue = array_values(array_filter(array_map(static function ($item): string {
                        return is_scalar($item) ? trim((string) $item) : '';
                    }, $rawValue), static function (string $value): bool {
                        return $value !== '';
                    }));
                    $value = implode(', ', $rawValue);
                } else {
                    $value = trim((string)$rawValue);
                }

                $permit_data[$fieldName] = $value;

                // Optional note paired with tri-state or any field
                $noteKey = $fieldName . '_note';
                if (isset($_POST[$noteKey])) {
                    if (is_array($_POST[$noteKey])) {
                        throw new InvalidArgumentException('A field note has an invalid value.');
                    }
                    $note = trim((string) $_POST[$noteKey]);
                    if (mb_strlen($note, 'UTF-8') > 5000) {
                        throw new InvalidArgumentException('Field notes must be 5000 characters or fewer.');
                    }
                    $permit_data[$noteKey] = $note;
                    $submittedValues[$noteKey] = $note;
                } elseif (isset($previousData[$noteKey])) {
                    $permit_data[$noteKey] = trim((string)$previousData[$noteKey]);
                    $submittedValues[$noteKey] = $permit_data[$noteKey];
                }

                $mediaKey = $fieldName . '_media';
                if (!empty($_FILES[$mediaKey]['name'])) {
                    $submittedValues[$mediaKey] = $_FILES[$mediaKey]['name'];
                } elseif (isset($previousData[$mediaKey])) {
                    $submittedValues[$mediaKey] = $previousData[$mediaKey];
                }
            }
        }

        $permit_data['_applicant_declaration'] = $applicantDeclaration ? 'confirmed' : '';
        if (!$isDraftAction && $applicantDeclaration) {
            $permit_data['_applicant_declared_at'] = date('Y-m-d H:i:s');
        } elseif (!empty($previousData['_applicant_declared_at'])) {
            $permit_data['_applicant_declared_at'] = (string) $previousData['_applicant_declared_at'];
        }

        // Re-render the values the user just submitted if validation or storage
        // fails, which is especially important on long mobile permit forms.
        $existingData = $permit_data;

        // Drafts deliberately allow incomplete answers. Final submissions do not:
        // required template fields and every safety checklist item are enforced here.
        if (!$isDraftAction) {
            if ($formStructure === []) {
                throw new InvalidArgumentException('This permit template has no usable fields. Please contact an administrator.');
            }
            $fieldErrors = \Permits\PermitFormValidator::validate($formStructure, $submittedValues, true);
            if ($fieldErrors !== []) {
                $messages = array_slice(array_values($fieldErrors), 0, 5);
                $remaining = count($fieldErrors) - count($messages);
                $message = implode(' ', $messages);
                if ($remaining > 0) {
                    $message .= sprintf(' Please complete %d more required field%s.', $remaining, $remaining === 1 ? '' : 's');
                }
                throw new InvalidArgumentException($message);
            }
        }

        $publicLimiter = new \Permits\PublicRateLimiter($db->pdo);
        $rateLimit = $isDraftAction
            ? $publicLimiter->consumePermitDraft(
                (string)($_SERVER['REMOTE_ADDR'] ?? ''),
                $holder_email,
                $currentUser !== null
            )
            : $publicLimiter->consumePermitSubmission(
                (string)($_SERVER['REMOTE_ADDR'] ?? ''),
                $holder_email,
                $currentUser !== null
            );
        if ($rateLimit['limited']) {
            http_response_code(429);
            header('Retry-After: ' . $rateLimit['retry_after']);
            throw new InvalidArgumentException(
                $isDraftAction
                    ? 'Too many draft saves. Please wait before trying again.'
                    : 'Too many permit submissions. Please wait before trying again.'
            );
        }

        // Handle media uploads (images/videos) for fields ending with _media
        $uploadErrors = [];
        $uploadedAny = false;
        $maxUploadFilesPerField = 5;
        $maxTotalUploadFiles = 10;
        $maxUploadFileBytes = ($currentUser !== null ? 25 : 10) * 1024 * 1024;
        $maxTotalUploadBytes = ($currentUser !== null ? 50 : 20) * 1024 * 1024;
        $totalUploadCount = 0;
        $totalUploadBytes = 0;
        $baseUploadDir = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . $permit_id;
        $uploadRoot = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'uploads';
        $hasPendingUploads = false;
        foreach ($_FILES as $fileGroup) {
            $names = $fileGroup['name'] ?? [];
            $names = is_array($names) ? $names : [$names];
            if (array_filter($names, static fn($name): bool => is_scalar($name) && trim((string)$name) !== '') !== []) {
                $hasPendingUploads = true;
                break;
            }
        }
        if ($hasPendingUploads) {
            $freeBytes = @disk_free_space($uploadRoot);
            if ($freeBytes !== false && $freeBytes < $maxTotalUploadBytes + (256 * 1024 * 1024)) {
                throw new RuntimeException('Permit attachments are temporarily unavailable because storage is running low.');
            }
        }
        $allowedTypes = [
            'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp',
            'video/mp4' => 'mp4', 'video/quicktime' => 'mov', 'video/webm' => 'webm',
        ];
        $mimeDetector = new finfo(FILEINFO_MIME_TYPE);
        foreach ($formStructure as $section) {
            if (empty($section['fields']) || !is_array($section['fields'])) { continue; }
            foreach ($section['fields'] as $field) {
                if (!is_array($field) || empty($field['name'])) { continue; }
                $name = (string)$field['name'];
                $mediaKey = $name . '_media';
                if (empty($_FILES[$mediaKey]) || empty($_FILES[$mediaKey]['name'])) { continue; }
                $files = $_FILES[$mediaKey];
                $paths = [];
                if (
                    !is_array($files['name'] ?? null)
                    || !is_array($files['tmp_name'] ?? null)
                    || !is_array($files['error'] ?? null)
                ) {
                    $uploadErrors[] = 'An attachment was submitted in an invalid format.';
                    continue;
                }
                $count = count($files['name']);
                $fieldUploadCount = 0;
                for ($i=0; $i<$count; $i++) {
                    $origName = basename(str_replace('\\', '/', (string)$files['name'][$i]));
                    $origName = preg_replace('/[\x00-\x1F\x7F]+/u', '', $origName) ?? '';
                    $origName = mb_substr($origName, 0, 180, 'UTF-8');
                    if ($origName === '') { continue; }
                    $fieldUploadCount++;
                    $totalUploadCount++;
                    if ($fieldUploadCount > $maxUploadFilesPerField) {
                        $uploadErrors[] = 'Choose no more than 5 files for each permit question.';
                        continue;
                    }
                    if ($totalUploadCount > $maxTotalUploadFiles) {
                        $uploadErrors[] = 'Choose no more than 10 files for one permit.';
                        continue;
                    }

                    $tmp = (string)($files['tmp_name'][$i] ?? '');
                    $err = (int)($files['error'][$i] ?? UPLOAD_ERR_NO_FILE);
                    if ($err !== UPLOAD_ERR_OK || !is_uploaded_file($tmp)) {
                        $uploadErrors[] = 'Unable to upload file: ' . $origName;
                        continue;
                    }
                    $actualSize = filesize($tmp);
                    if ($actualSize === false) {
                        $uploadErrors[] = 'Unable to check file: ' . $origName;
                        continue;
                    }
                    if ($actualSize > $maxUploadFileBytes) {
                        $uploadErrors[] = 'File is too large: ' . $origName;
                        continue;
                    }
                    $totalUploadBytes += $actualSize;
                    if ($totalUploadBytes > $maxTotalUploadBytes) {
                        $uploadErrors[] = 'Attachments must be 50 MB or less in total.';
                        continue;
                    }
                    $type = $mimeDetector->file($tmp);
                    if (!isset($allowedTypes[$type])) { $uploadErrors[] = 'Rejected file type for ' . $origName; continue; }
                    if (!is_dir($baseUploadDir) && !@mkdir($baseUploadDir, 0775, true) && !is_dir($baseUploadDir)) {
                        throw new RuntimeException('Unable to create the permit attachment folder.');
                    }
                    $target = $baseUploadDir . DIRECTORY_SEPARATOR . bin2hex(random_bytes(16)) . '.' . $allowedTypes[$type];
                    if (@move_uploaded_file($tmp, $target)) {
                        $uploadedAny = true;
                        $uploadedTargets[] = $target;
                        $rel = 'uploads/' . $permit_id . '/' . basename($target);
                        $paths[] = $rel;
                    } else {
                        $uploadErrors[] = 'Unable to save file: ' . $origName;
                    }
                }
                if (!empty($paths)) {
                    $permit_data[$mediaKey] = implode(', ', $paths);
                } elseif (!empty($previousData[$mediaKey])) {
                    // Preserve previously uploaded media when editing and no new files were added
                    $permit_data[$mediaKey] = (string)$previousData[$mediaKey];
                }
            }
        }

        if ($uploadErrors !== []) {
            foreach ($uploadedTargets as $uploadedTarget) {
                @unlink($uploadedTarget);
            }
            $uploadedTargets = [];
            throw new InvalidArgumentException(implode(' ', $uploadErrors));
        }
        
        $now = $db->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? "datetime('now')" : 'NOW()';
        $recordChanged = true;
        $isDuplicateKey = static function (PDOException $exception): bool {
            $driverCode = (int) ($exception->errorInfo[1] ?? 0);
            return in_array((string) $exception->getCode(), ['23000', '23505'], true)
                || in_array($driverCode, [19, 1062, 2067], true);
        };
        $db->pdo->beginTransaction();
        if ($isUpdate) {
            $stmt = $db->pdo->prepare("UPDATE forms SET ref_number=?, form_data=?, holder_name=?, holder_email=?, holder_phone=?, holder_id=?, issuer_id=?, status=?, updated_at=$now WHERE id=? AND unique_link=? AND status='draft'");
            for ($updateAttempt = 0; ; $updateAttempt++) {
                try {
                    $stmt->execute([$ref_number, json_encode($permit_data), $holder_name, $holder_email, $holder_phone, $holder_id, $issuer_id, $targetStatus, $permit_id, $unique_link]);
                    break;
                } catch (PDOException $updateError) {
                    if (!$isDuplicateKey($updateError) || !$referenceFactory || $updateAttempt >= 9) {
                        throw $updateError;
                    }
                    $ref_number = $referenceFactory();
                }
            }
            if ($stmt->rowCount() !== 1) {
                $stateCheck = $db->pdo->prepare('SELECT status FROM forms WHERE id = ? AND unique_link = ? LIMIT 1');
                $stateCheck->execute([$permit_id, $unique_link]);
                $currentStatus = strtolower((string) $stateCheck->fetchColumn());
                if ($currentStatus !== $targetStatus) {
                    throw new RuntimeException('Draft could not be updated');
                }
                $recordChanged = false;
            }
        } else {
            $stmt = $db->pdo->prepare("
            INSERT INTO forms (
                id, 
                ref_number, 
                template_id, 
                form_data, 
                status,
                holder_name,
                holder_email,
                holder_phone,
                holder_id,
                issuer_id,
                unique_link,
                created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, $now)
        ");

            // The database unique indexes are the final authority. If two
            // concurrent requests happen to choose the same short reference,
            // retry the statement with a fresh reference and bearer token.
            $inserted = false;
            for ($insertAttempt = 0; $insertAttempt < 10; $insertAttempt++) {
                try {
                    $stmt->execute([
                        $permit_id,
                        $ref_number,
                        $template_id,
                        json_encode($permit_data),
                        $targetStatus,
                        $holder_name,
                        $holder_email,
                        $holder_phone,
                        $holder_id,
                        $issuer_id,
                        $unique_link
                    ]);
                    $inserted = true;
                    break;
                } catch (PDOException $insertError) {
                    if (!$isDuplicateKey($insertError) || !$referenceFactory || $insertAttempt === 9) {
                        throw $insertError;
                    }
                    $ref_number = $referenceFactory();
                    $unique_link = bin2hex(random_bytes(32));
                }
            }
            if (!$inserted) {
                throw new RuntimeException('Unable to allocate unique permit identifiers.');
            }
        }
        $db->pdo->commit();

        if (!$recordChanged && $uploadedTargets !== []) {
            foreach ($uploadedTargets as $uploadedTarget) {
                @unlink($uploadedTarget);
            }
            $uploadedTargets = [];
        }

        if (!$isDraftAction && $recordChanged) {
            try {
                notifyPendingApprovalRecipients($db, $root, $permit_id);
            } catch (\Throwable $notificationError) {
                error_log('Failed to queue approval notification: ' . $notificationError->getMessage());
            }
        }
        
        // Log activity
        if (function_exists('logActivity')) {
            try {
                logActivity(
                    $isDraftAction ? 'public_permit_draft_saved' : 'public_permit_created',
                    'permit',
                    'form',
                    $permit_id,
                    ($isDraftAction ? 'Public permit draft saved' : 'Public permit submitted') . ": {$ref_number} by {$holder_email}"
                );
            } catch (Throwable $logError) {
                error_log('Unable to log public permit submission: ' . $logError->getMessage());
            }
        }
        
        $success = true;
        
    } catch (Throwable $e) {
        if ($db->pdo->inTransaction()) { $db->pdo->rollBack(); }
        foreach ($uploadedTargets as $uploadedTarget) {
            @unlink($uploadedTarget);
        }
        error_log('Public permit submission failed: ' . $e->getMessage());
        $error = $e instanceof InvalidArgumentException ? $e->getMessage() : 'Unable to save the permit. Please try again.';
    }
}
?>
<!DOCTYPE html>
<html lang="en" style="<?= htmlspecialchars($brandingCss, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $isUpdate ? 'Edit Draft' : ($existingPermit ? 'Create Similar Permit' : 'Create Permit'); ?> - <?php echo htmlspecialchars($template['name']); ?></title>
    <link rel="stylesheet" href="<?= asset('/assets/app.css') ?>">
    <style>
        :root {
            color-scheme: dark;
        }

        * {
            box-sizing: border-box;
        }

        body.theme-dark {
            background: #0f172a;
            color: #e5e7eb;
            font-family: system-ui, -apple-system, sans-serif;
            margin: 0;
        }

        .public-wrap {
            max-width: 960px;
            margin: 0 auto;
            padding: 20px 16px 80px;
        }

        .public-brand-header {
            margin-bottom: 16px;
        }

        .public-brand-link {
            display: inline-flex;
            align-items: center;
            gap: 12px;
            max-width: 100%;
            color: #f8fafc;
            text-decoration: none;
            border-radius: 12px;
            padding: 8px 10px;
        }

        .public-brand-link:hover {
            background: rgba(var(--brand-primary-rgb), 0.12);
        }

        .public-brand-link:focus-visible {
            outline: 3px solid rgba(var(--brand-primary-light-rgb), 0.55);
            outline-offset: 2px;
        }

        .public-brand-logo,
        .public-brand-symbol {
            width: 44px;
            height: 44px;
            flex: 0 0 44px;
            border-radius: 10px;
        }

        .public-brand-logo {
            object-fit: contain;
            background: #ffffff;
            padding: 4px;
        }

        .public-brand-symbol {
            display: grid;
            place-items: center;
            background: var(--brand-primary);
            color: var(--brand-on-primary);
            font-weight: 800;
            font-size: 20px;
        }

        .public-brand-copy {
            min-width: 0;
        }

        .public-brand-name {
            display: block;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            font-size: 16px;
            font-weight: 750;
        }

        .public-brand-subtitle {
            display: block;
            color: #94a3b8;
            font-size: 13px;
        }

        .public-card {
            background: #111827;
            border: 1px solid #1f2937;
            border-radius: 18px;
            padding: 32px;
            box-shadow: 0 24px 48px rgba(15, 23, 42, 0.45);
        }

        @media (max-width: 768px) {
            .public-card {
                padding: 20px;
            }
        }

        .card-heading {
            text-align: center;
            margin-bottom: 28px;
        }

        .card-heading h1 {
            font-size: 26px;
            margin: 0 0 8px;
        }

        .card-heading p {
            margin: 0;
            color: #94a3b8;
        }

        .form-group {
            margin-bottom: 20px;
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        label {
            font-size: 14px;
            font-weight: 600;
            color: #cbd5f5;
        }

        .required {
            color: #f87171;
        }

        input[type="text"],
        input[type="email"],
        input[type="tel"],
        input[type="date"],
        input[type="time"],
        input[type="datetime-local"],
        input[type="number"],
        textarea,
        select {
            width: 100%;
            padding: 12px 14px;
            border-radius: 10px;
            border: 1px solid #1f2937;
            background: #0a101a;
            color: #e5e7eb;
            font-size: 15px;
        }

        input:focus,
        textarea:focus,
        select:focus {
            outline: none;
            border-color: var(--brand-primary-light);
            box-shadow: 0 0 0 3px rgba(var(--brand-primary-rgb), 0.25);
        }

        textarea {
            min-height: 100px;
            resize: vertical;
        }

        .notification-box {
            background: rgba(var(--brand-primary-rgb), 0.12);
            border: 1px solid rgba(var(--brand-primary-light-rgb), 0.35);
            border-radius: 12px;
            padding: 16px;
            margin: 24px 0;
        }

        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .checkbox-group input[type="checkbox"] {
            width: 20px;
            height: 20px;
            cursor: pointer;
        }

        .checkbox-group label {
            margin: 0;
            color: #e5e7eb;
            cursor: pointer;
        }

        .checkbox-group span {
            color: #94a3b8;
        }

        .choice-group {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }

        .choice-group.vertical {
            flex-direction: column;
            gap: 10px;
        }

        .choice-input {
            position: absolute;
            opacity: 0;
            width: 0;
            height: 0;
        }

        .choice-pill {
            display: inline-block;
            padding: 12px 18px;
            border-radius: 999px;
            border: 1px solid #1f2937;
            background: #0a101a;
            color: #e5e7eb;
            font-weight: 600;
            cursor: pointer;
            user-select: none;
            transition: all 0.15s ease;
            text-align: center;
            min-width: 90px;
        }

        .choice-pill:empty::before {
            content: attr(data-label);
        }

        .choice-pill:hover {
            border-color: var(--brand-primary-light);
            box-shadow: 0 4px 12px rgba(var(--brand-primary-rgb), 0.2);
        }

        .form-honeypot {
            position: fixed !important;
            left: -10000px !important;
            top: auto !important;
            width: 1px !important;
            height: 1px !important;
            overflow: hidden !important;
            opacity: 0 !important;
            pointer-events: none !important;
        }

        .choice-input:focus + .choice-pill {
            outline: 2px solid var(--brand-primary-light);
            outline-offset: 2px;
        }

        .choice-group.vertical .choice-pill {
            width: 100%;
            border-radius: 24px;
        }

        .choice-input:checked + .choice-pill {
            color: #ffffff;
            border-color: transparent;
            box-shadow: 0 6px 18px rgba(0, 0, 0, 0.25);
        }

        .choice-input:checked + .choice-pill.choice-yes {
            background: #22c55e;
        }

        .choice-input:checked + .choice-pill.choice-no {
            background: #ef4444;
        }

        .choice-input:checked + .choice-pill.choice-na {
            background: #0ea5e9;
        }

        @media (max-width: 640px) {
            .choice-group {
                gap: 10px;
            }

            .choice-input {
                position: static;
                opacity: 1;
                width: 18px;
                height: 18px;
                margin-right: 8px;
            }

            .choice-pill {
                display: inline-flex;
                align-items: center;
                gap: 10px;
                min-height: 44px;
                padding: 10px 14px;
            }
        }

        .field-toolbar {
            display: flex;
            gap: 12px;
            align-items: center;
            margin: 8px 0 6px;
            flex-wrap: wrap;
        }

        .tool-link {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            color: var(--brand-primary-light);
            background: rgba(var(--brand-primary-rgb), 0.18);
            border: 1px solid rgba(var(--brand-primary-light-rgb), 0.35);
            padding: 8px 12px;
            border-radius: 10px;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .tool-link:hover {
            background: rgba(var(--brand-primary-rgb), 0.3);
        }

        .note-box,
        .media-box {
            display: none;
            margin-top: 8px;
        }

        .note-box textarea {
            width: 100%;
            min-height: 80px;
            border: 1px solid #1f2937;
            border-radius: 10px;
            padding: 10px;
            background: #0a101a;
            color: #e5e7eb;
        }

        .media-box input[type="file"] {
            display: block;
            width: 100%;
            padding: 10px;
            border: 1px dashed rgba(var(--brand-primary-light-rgb), 0.45);
            border-radius: 10px;
            background: rgba(15, 23, 42, 0.8);
            color: #cbd5f5;
        }

        .media-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-bottom: 8px;
        }

        .media-note {
            font-size: 12px;
            color: #94a3b8;
            margin-top: 6px;
        }

        .hidden-input {
            display: none;
        }

        .success-message {
            background: rgba(16, 185, 129, 0.12);
            border: 1px solid rgba(16, 185, 129, 0.45);
            border-radius: 12px;
            padding: 24px;
            text-align: center;
        }

        .success-message h2 {
            color: #bbf7d0;
            margin-bottom: 12px;
        }

        .success-message p {
            color: #a7f3d0;
            margin-bottom: 8px;
        }

        .success-meta {
            margin-top: 12px;
            font-size: 14px;
            color: #cbd5f5;
        }

        .success-meta a {
            color: #bfdbfe;
        }

        .success-reminder {
            margin-top: 16px;
            font-size: 14px;
            color: #cbd5f5;
        }

        .success-actions {
            margin-top: 24px;
        }

        .error-message {
            background: rgba(239, 68, 68, 0.12);
            border: 1px solid rgba(239, 68, 68, 0.45);
            border-radius: 12px;
            padding: 16px;
            margin-bottom: 20px;
            color: #fecaca;
        }

        .section-title {
            font-size: 20px;
            font-weight: 700;
            color: #e5e7eb;
            margin: 32px 0 16px;
            padding-bottom: 8px;
            border-bottom: 1px solid #1f2937;
            position: relative;
            padding-left: 40px;
        }

        .section-title::before {
            content: attr(data-number);
            position: absolute;
            left: 0;
            top: -2px;
            width: 32px;
            height: 32px;
            background: linear-gradient(135deg, var(--brand-primary-light), var(--brand-primary-dark));
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            font-weight: 700;
            color: #fff;
        }

        .section-complete {
            border-left: 4px solid #22c55e;
            padding-left: 36px;
        }

        .section-incomplete {
            border-left: 4px solid #ef4444;
            padding-left: 36px;
        }

        .progress-bar-container {
            position: sticky;
            top: 0;
            z-index: 100;
            background: #111827;
            padding: 16px;
            margin: -32px -32px 24px;
            border-bottom: 2px solid #1f2937;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }

        .progress-bar {
            width: 100%;
            height: 12px;
            background: #1f2937;
            border-radius: 999px;
            overflow: hidden;
            position: relative;
        }

        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #22c55e, #16a34a);
            border-radius: 999px;
            transition: width 0.3s ease;
            position: relative;
        }

        .progress-fill::after {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.3), transparent);
            animation: shimmer 2s infinite;
        }

        @keyframes shimmer {
            0% { transform: translateX(-100%); }
            100% { transform: translateX(100%); }
        }

        .progress-text {
            text-align: center;
            margin-top: 8px;
            font-size: 14px;
            color: #94a3b8;
            font-weight: 600;
        }

        .risk-indicator {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 16px;
            border-radius: 999px;
            font-size: 14px;
            font-weight: 600;
            margin-top: 8px;
        }

        .risk-low {
            background: rgba(34, 197, 94, 0.2);
            border: 1px solid rgba(34, 197, 94, 0.4);
            color: #86efac;
        }

        .risk-medium {
            background: rgba(251, 191, 36, 0.2);
            border: 1px solid rgba(251, 191, 36, 0.4);
            color: #fcd34d;
        }

        .risk-high {
            background: rgba(239, 68, 68, 0.2);
            border: 1px solid rgba(239, 68, 68, 0.4);
            color: #fca5a5;
        }

        .field-counter {
            font-size: 12px;
            color: #64748b;
            margin-left: 8px;
        }

        .help-tooltip {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 18px;
            height: 18px;
            background: rgba(var(--brand-primary-rgb), 0.2);
            border: 1px solid rgba(var(--brand-primary-light-rgb), 0.4);
            border-radius: 50%;
            color: #93c5fd;
            font-size: 12px;
            font-weight: 700;
            cursor: help;
            margin-left: 6px;
            position: relative;
        }

        .help-tooltip:hover::after {
            content: attr(data-tip);
            position: absolute;
            bottom: 100%;
            left: 50%;
            transform: translateX(-50%);
            background: #1e293b;
            color: #e2e8f0;
            padding: 8px 12px;
            border-radius: 8px;
            white-space: nowrap;
            font-size: 12px;
            font-weight: 400;
            margin-bottom: 8px;
            border: 1px solid #334155;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
            z-index: 1000;
        }

        .form-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-top: 32px;
        }

        .form-actions .btn {
            flex: 1 1 200px;
            justify-content: center;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--brand-primary-light), var(--brand-primary));
            border-color: var(--brand-primary);
            color: var(--brand-on-primary);
            box-shadow: 0 4px 14px rgba(var(--brand-primary-rgb), 0.35);
        }

        .btn-primary:hover,
        .btn-primary:active {
            background: linear-gradient(135deg, var(--brand-primary), var(--brand-primary-dark));
            border-color: var(--brand-primary-dark);
        }

        .btn-block {
            width: 100%;
        }

        .cancel-link {
            margin-top: 16px;
            text-align: center;
        }

        .incomplete-field-indicator {
            display: inline-block;
            width: 8px;
            height: 8px;
            background: #ef4444;
            border-radius: 50%;
            margin-left: 8px;
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }

        .quick-nav {
            position: fixed;
            bottom: 80px;
            right: 20px;
            background: #111827;
            border: 1px solid #1f2937;
            border-radius: 12px;
            padding: 12px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
            max-width: 200px;
            z-index: 50;
        }

        .quick-nav-title {
            font-size: 12px;
            font-weight: 600;
            color: #94a3b8;
            margin-bottom: 8px;
            text-transform: uppercase;
        }

        .quick-nav-link {
            display: block;
            padding: 6px 8px;
            font-size: 13px;
            color: #e5e7eb;
            text-decoration: none;
            border-radius: 6px;
            margin-bottom: 4px;
            transition: background 0.2s;
        }

        .quick-nav-link:hover {
            background: #1f2937;
        }

        .quick-nav-link.incomplete {
            color: #fca5a5;
        }

        .quick-nav-link.complete {
            color: #86efac;
        }

        @media (max-width: 768px) {
            .quick-nav {
                display: none;
            }

            .public-wrap {
                padding: 12px 10px 56px;
            }

            .public-card {
                padding: 18px 14px;
                border-radius: 14px;
            }

            .progress-bar-container {
                margin: -18px -14px 22px;
                padding: 14px;
            }

            .card-heading h1 {
                font-size: 22px;
                line-height: 1.25;
            }
        }

        /* Print styles for permit hardcopies */
        @media print {
            .progress-bar-container,
            .field-toolbar,
            .form-actions,
            .cancel-link,
            .quick-nav,
            .notification-box {
                display: none !important;
            }

            body {
                background: white;
                color: black;
            }

            .public-card {
                background: white;
                border: none;
                box-shadow: none;
            }

            .choice-pill {
                border: 1px solid #000;
                background: white;
                color: black;
            }

            .choice-input:checked + .choice-pill {
                background: #f0f0f0;
                border: 2px solid #000;
            }

            .section-title {
                page-break-after: avoid;
                border-bottom: 2px solid #000;
                color: #000;
            }

            .note-box,
            .media-box {
                display: block !important;
                border: 1px solid #ddd;
                padding: 8px;
                margin-top: 8px;
            }
        }
    </style>
</head>
<body class="theme-dark">
    <div class="public-wrap">
        <header class="public-brand-header">
            <a class="public-brand-link" href="<?= htmlspecialchars($app->url('/'), ENT_QUOTES, 'UTF-8') ?>" aria-label="<?= htmlspecialchars('Return to ' . $companyName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                <?php if ($companyLogoUrl): ?>
                    <img class="public-brand-logo" src="<?= htmlspecialchars($companyLogoUrl, ENT_QUOTES, 'UTF-8') ?>" alt="">
                <?php else: ?>
                    <span class="public-brand-symbol" aria-hidden="true"><?= htmlspecialchars(mb_strtoupper(mb_substr($companyName, 0, 1, 'UTF-8'), 'UTF-8'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                <?php endif; ?>
                <span class="public-brand-copy">
                    <span class="public-brand-name"><?= htmlspecialchars($companyName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                    <span class="public-brand-subtitle">Permit centre · Home</span>
                </span>
            </a>
        </header>
        <div class="public-card">
            <?php if ($success): ?>
                <!-- Success Message -->
                <div class="success-message">
                    <?php if (!empty($isDraftAction)): ?>
                        <h2>📝 Draft Saved</h2>
                        <p><strong>Reference:</strong> #<?php echo htmlspecialchars($ref_number ?? 'N/A'); ?></p>
                        <p>You can resume editing this permit using the link below.</p>
                    <?php else: ?>
                        <h2>✅ Permit Submitted Successfully!</h2>
                        <p><strong>Reference:</strong> #<?php echo htmlspecialchars($ref_number ?? 'N/A'); ?></p>
                        <p>Your permit is now awaiting manager approval.</p>
                    <?php endif; ?>
                    <p class="success-reminder">
                        You can check the status anytime on the homepage<br>
                        using your email address and permit reference.
                    </p>
                    <?php if (!empty($unique_link) && $isDraftAction): ?>
                        <div class="success-meta">
                            <div><strong>Edit Link:</strong> <a href="<?php echo htmlspecialchars($app->url('create-permit-public.php?draft=' . urlencode($unique_link))); ?>">Resume editing</a></div>
                        </div>
                    <?php elseif (!empty($unique_link)): ?>
                        <div class="success-meta">
                            <div><strong>Permit Link:</strong> <a href="<?php echo htmlspecialchars($app->url('view-permit-public.php?link=' . urlencode($unique_link))); ?>">View your submitted permit</a></div>
                        </div>
                    <?php endif; ?>
                    <div class="success-actions">
                        <a href="<?php echo htmlspecialchars($app->url('/')); ?>" class="btn btn-primary btn-block">← Back to Homepage</a>
                    </div>
                </div>
                
            <?php else: ?>
                <!-- Permit Creation Form -->
                <div class="card-heading">
                    <h1>📋 <?php echo htmlspecialchars($template['name']); ?></h1>
                    <p>Please fill in all required information</p>
                    <?php if (!empty($template['description'])): ?>
                        <p style="font-size: 14px; color: #94a3b8; margin-top: 8px;"><?php echo htmlspecialchars($template['description']); ?></p>
                    <?php endif; ?>
                </div>
                
                <!-- Progress Bar -->
                <div class="progress-bar-container" id="progressBarContainer">
                    <div class="progress-bar">
                        <div class="progress-fill" id="progressFill" style="width: 0%"></div>
                    </div>
                    <div class="progress-text">
                        <span id="progressText">0% Complete</span>
                        <span class="risk-indicator risk-low" id="riskIndicator" style="display: none;">
                            <span id="riskIcon">✓</span>
                            <span id="riskText">Low Risk</span>
                        </span>
                    </div>
                </div>
                
                <?php if ($error): ?>
                    <div class="error-message">
                        ⚠️ <?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>
                
                <form method="POST" id="permitForm" enctype="multipart/form-data">
                    <?php echo \Permits\Csrf::getFormField('public-permit-submit'); ?>
                    <div class="form-honeypot" aria-hidden="true">
                        <label for="website">Website</label>
                        <input type="text" id="website" name="website" value="" tabindex="-1" autocomplete="off">
                    </div>
                    <?php if ($existingPermit && $isUpdate): ?>
                        <input type="hidden" name="permit_id" value="<?php echo htmlspecialchars($existingPermit['id']); ?>">
                    <?php endif; ?>
                    
                    <!-- Your Information Section -->
                    <div class="section-title">Your Information</div>
                    
                    <div class="form-group">
                        <label>Your Name <span class="required">*</span></label>
                        <input type="text" name="holder_name" required maxlength="255" autocomplete="name" placeholder="John Smith" value="<?php echo htmlspecialchars($holder_name); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label>Your Email <span class="required">*</span></label>
                        <input type="email" name="holder_email" required maxlength="255" inputmode="email" autocomplete="email" placeholder="john@example.com" value="<?php echo htmlspecialchars($holder_email); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label>Your Phone Number</label>
                        <input type="tel" name="holder_phone" maxlength="50" inputmode="tel" autocomplete="tel" placeholder="+44 7700 900000" value="<?php echo htmlspecialchars($holder_phone); ?>">
                    </div>
                    
                    <div class="notification-box">
                        <strong>Decision updates</strong><br>
                        <span>Approval or rejection updates are sent to the email address above.</span>
                    </div>
                    
                    <!-- Dynamic Form Fields -->
                    <?php 
                    $sectionNumber = 0;
                    foreach ($formStructure as $section):
                        if (!isset($section['fields']) || !is_array($section['fields'])) {
                            continue;
                        }
                        $sectionNumber++;
                    ?>
                        <div class="section-title" data-number="<?php echo $sectionNumber; ?>" data-section-id="section_<?php echo $sectionNumber; ?>">
                            <?php echo htmlspecialchars($section['title']); ?>
                            <span class="field-counter" id="counter_section_<?php echo $sectionNumber; ?>"></span>
                        </div>
                        
                        <?php foreach ($section['fields'] as $field):
                            if (!is_array($field) || empty($field['name'])) {
                                continue;
                            }

                            $fieldType = $field['type'] ?? 'text';
                            $fieldName = (string)$field['name'];
                            $fieldLabel = (string)($field['label'] ?? $fieldName);
                            // Safety checklist items are always mandatory when submitting.
                            // The Save Draft action removes required attributes before submit.
                            $fieldRequired = !empty($field['required']) || !empty($field['scoreItem']);
                            $fieldPlaceholder = (string)($field['placeholder'] ?? '');
                            $fieldOptions = $field['options'] ?? [];
                        ?>
                            <div class="form-group">
                                <label>
                                    <?php echo htmlspecialchars($fieldLabel); ?>
                                    <?php if ($fieldRequired): ?>
                                        <span class="required">*</span>
                                    <?php endif; ?>
                                </label>
                                
                                <?php if ($fieldType === 'textarea'): ?>
                                    <textarea 
                                        name="<?php echo htmlspecialchars($fieldName); ?>"
                                        <?php echo $fieldRequired ? 'required' : ''; ?>
                                        maxlength="5000"
                                        placeholder="<?php echo htmlspecialchars($fieldPlaceholder); ?>"
                                    ><?php echo htmlspecialchars((string)($existingData[$fieldName] ?? '')); ?></textarea>
                                <?php elseif ($fieldType === 'select'): ?>
                                    <select 
                                        name="<?php echo htmlspecialchars($fieldName); ?>"
                                        <?php echo $fieldRequired ? 'required' : ''; ?>
                                    >
                                        <option value="">Select...</option>
                                        <?php foreach ($fieldOptions as $option):
                                            if (!is_array($option)) {
                                                $optionValue = $optionLabel = (string)$option;
                                            } else {
                                                $optionValue = (string)($option['value'] ?? ($option[0] ?? ''));
                                                $optionLabel = (string)($option['label'] ?? ($option[1] ?? $optionValue));
                                            }
                                            if ($optionValue === '') { continue; }
                                        ?>
                                            <option value="<?php echo htmlspecialchars($optionValue); ?>" <?php echo ((string)($existingData[$fieldName] ?? '') === (string)$optionValue) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($optionLabel); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php elseif ($fieldType === 'select_multiple'): ?>
                                    <select 
                                        name="<?php echo htmlspecialchars($fieldName); ?>[]"
                                        multiple
                                        <?php echo $fieldRequired ? 'required' : ''; ?>
                                    >
                                        <?php $existingVals = array_values(array_filter(array_map('trim', explode(',', (string)($existingData[$fieldName] ?? ''))))); ?>
                                        <?php foreach ($fieldOptions as $option):
                                            if (!is_array($option)) {
                                                $optionValue = $optionLabel = (string)$option;
                                            } else {
                                                $optionValue = (string)($option['value'] ?? ($option[0] ?? ''));
                                                $optionLabel = (string)($option['label'] ?? ($option[1] ?? $optionValue));
                                            }
                                            if ($optionValue === '') { continue; }
                                        ?>
                                            <option value="<?php echo htmlspecialchars($optionValue); ?>" <?php echo in_array((string)$optionValue, $existingVals, true) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($optionLabel); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php elseif ($fieldType === 'radio'): ?>
                                    <?php $isScoreItem = !empty($field['scoreItem']); ?>
                                    <div class="choice-group <?php echo $isScoreItem ? 'vertical' : ''; ?>" role="radiogroup" aria-label="<?php echo htmlspecialchars($fieldLabel); ?>">
                                        <?php 
                                            $firstOption = true; 
                                            $existingVal = (string)($existingData[$fieldName] ?? '');
                                            foreach ($fieldOptions as $option):
                                                if (!is_array($option)) {
                                                    $optionValue = $optionLabel = (string)$option;
                                                } else {
                                                    $optionValue = (string)($option['value'] ?? ($option[0] ?? ''));
                                                    $optionLabel = (string)($option['label'] ?? ($option[1] ?? $optionValue));
                                                }
                                                if ($optionValue === '') { continue; }
                                                $optionId = $fieldName . '_' . preg_replace('/[^a-z0-9]+/i', '_', strtolower($optionValue));
                                                $variant = in_array(strtolower($optionValue), ['yes','no','na'], true) ? 'choice-' . strtolower($optionValue) : '';
                                        ?>
                                            <input class="choice-input" type="radio" name="<?php echo htmlspecialchars($fieldName); ?>" value="<?php echo htmlspecialchars($optionValue); ?>" id="<?php echo htmlspecialchars($optionId); ?>" data-score-item="<?php echo $isScoreItem ? 'true' : 'false'; ?>" <?php echo ($fieldRequired && $firstOption) ? 'required' : ''; ?> <?php echo ($existingVal !== '' && (string)$existingVal === (string)$optionValue) ? 'checked' : ''; ?>>
                                            <label class="choice-pill <?php echo htmlspecialchars($variant); ?>" for="<?php echo htmlspecialchars($optionId); ?>"><?php echo htmlspecialchars($optionLabel); ?></label>
                                        <?php $firstOption = false; endforeach; ?>
                                    </div>
                                    <?php if ($isScoreItem): ?>
                                        <div class="field-toolbar">
                                            <button type="button" class="tool-link toggle-note" data-for="<?php echo htmlspecialchars($fieldName); ?>">📝 Add Note</button>
                                            <button type="button" class="tool-link toggle-media" data-for="<?php echo htmlspecialchars($fieldName); ?>">📸 Add Photo/Video</button>
                                            <span class="help-tooltip" data-tip="For 'No' answers, please provide details in notes or supporting media">?</span>
                                        </div>
                                        <div class="note-box" id="note_<?php echo htmlspecialchars($fieldName); ?>">
                                            <textarea name="<?php echo htmlspecialchars($fieldName); ?>_note" placeholder="Please explain any 'No' answers or provide additional context..."><?php echo htmlspecialchars((string)($existingData[$fieldName . '_note'] ?? '')); ?></textarea>
                                        </div>
                                        <div class="media-box" id="media_<?php echo htmlspecialchars($fieldName); ?>">
                                            <div class="media-actions">
                                                <button type="button" class="btn media-btn" data-target="camera" data-name="<?php echo htmlspecialchars($fieldName); ?>_media">📷 Take Photo/Video</button>
                                                <button type="button" class="btn btn-secondary media-btn" data-target="gallery" data-name="<?php echo htmlspecialchars($fieldName); ?>_media">🖼️ Choose from Gallery</button>
                                            </div>
                                            <input class="hidden-input" type="file" name="<?php echo htmlspecialchars($fieldName); ?>_media[]" accept="image/*,video/*" capture="environment" multiple>
                                            <input class="hidden-input" type="file" name="<?php echo htmlspecialchars($fieldName); ?>_media[]" accept="image/*,video/*" multiple>
                                            <div class="media-note">Attach up to 5 photos or videos here (<?php echo $currentUser !== null ? '25 MB each; 50 MB total' : '10 MB each; 20 MB total'; ?> per permit).</div>
                                        </div>
                                    <?php endif; ?>
                                <?php elseif ($fieldType === 'date'): ?>
                                    <input 
                                        type="date" 
                                        name="<?php echo htmlspecialchars($fieldName); ?>"
                                        <?php echo $fieldRequired ? 'required' : ''; ?>
                                        placeholder="<?php echo htmlspecialchars($fieldPlaceholder); ?>"
                                        value="<?php echo htmlspecialchars((string)($existingData[$fieldName] ?? '')); ?>"
                                    >
                                <?php elseif ($fieldType === 'time'): ?>
                                    <input 
                                        type="time" 
                                        name="<?php echo htmlspecialchars($fieldName); ?>"
                                        <?php echo $fieldRequired ? 'required' : ''; ?>
                                        placeholder="<?php echo htmlspecialchars($fieldPlaceholder); ?>"
                                        value="<?php echo htmlspecialchars((string)($existingData[$fieldName] ?? '')); ?>"
                                    >
                                <?php elseif ($fieldType === 'datetime'): ?>
                                    <input 
                                        type="datetime-local" 
                                        name="<?php echo htmlspecialchars($fieldName); ?>"
                                        <?php echo $fieldRequired ? 'required' : ''; ?>
                                        placeholder="<?php echo htmlspecialchars($fieldPlaceholder); ?>"
                                        value="<?php echo htmlspecialchars((string)($existingData[$fieldName] ?? '')); ?>"
                                    >
                                <?php elseif ($fieldType === 'number'): ?>
                                    <input 
                                        type="number" 
                                        name="<?php echo htmlspecialchars($fieldName); ?>"
                                        <?php echo $fieldRequired ? 'required' : ''; ?>
                                        <?php echo isset($field['min']) ? 'min="' . htmlspecialchars((string) $field['min']) . '"' : ''; ?>
                                        <?php echo isset($field['max']) ? 'max="' . htmlspecialchars((string) $field['max']) . '"' : ''; ?>
                                        <?php echo isset($field['step']) ? 'step="' . htmlspecialchars((string) $field['step']) . '"' : 'step="any"'; ?>
                                        placeholder="<?php echo htmlspecialchars($fieldPlaceholder); ?>"
                                        value="<?php echo htmlspecialchars((string)($existingData[$fieldName] ?? '')); ?>"
                                    >
                                <?php elseif ($fieldType === 'email'): ?>
                                    <input 
                                        type="email" 
                                        name="<?php echo htmlspecialchars($fieldName); ?>"
                                        <?php echo $fieldRequired ? 'required' : ''; ?>
                                        maxlength="255"
                                        inputmode="email"
                                        placeholder="<?php echo htmlspecialchars($fieldPlaceholder); ?>"
                                        value="<?php echo htmlspecialchars((string)($existingData[$fieldName] ?? '')); ?>"
                                    >
                                <?php elseif ($fieldType === 'tel'): ?>
                                    <input 
                                        type="tel" 
                                        name="<?php echo htmlspecialchars($fieldName); ?>"
                                        <?php echo $fieldRequired ? 'required' : ''; ?>
                                        maxlength="50"
                                        inputmode="tel"
                                        placeholder="<?php echo htmlspecialchars($fieldPlaceholder); ?>"
                                        value="<?php echo htmlspecialchars((string)($existingData[$fieldName] ?? '')); ?>"
                                    >
                                <?php else: ?>
                                    <input 
                                        type="text" 
                                        name="<?php echo htmlspecialchars($fieldName); ?>"
                                        <?php echo $fieldRequired ? 'required' : ''; ?>
                                        maxlength="1000"
                                        placeholder="<?php echo htmlspecialchars($fieldPlaceholder); ?>"
                                        value="<?php echo htmlspecialchars((string)($existingData[$fieldName] ?? '')); ?>"
                                    >
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endforeach; ?>

                    <div class="notification-box">
                        <div class="checkbox-group">
                            <input type="checkbox" name="applicant_declaration" value="1" id="applicant_declaration" required <?php echo !empty($existingData['_applicant_declaration']) ? 'checked' : ''; ?>>
                            <label for="applicant_declaration">
                                <strong>I confirm this information is accurate</strong><br>
                                <span>I understand the controls listed and will not start work until the permit is approved.</span>
                            </label>
                        </div>
                    </div>
                    
                    <!-- Submit -->
                    <div class="form-actions">
                        <button type="submit" name="action" value="save_draft" class="btn btn-secondary" formnovalidate>
                            📝 Save Draft
                        </button>
                        <button type="submit" name="action" value="submit" class="btn btn-primary" id="submitBtn">
                            ✅ Submit Permit for Approval
                        </button>
                    </div>

                    <!-- Validation Warning -->
                    <div id="validationWarning" style="display: none; margin-top: 16px; padding: 16px; background: rgba(251, 191, 36, 0.15); border: 1px solid rgba(251, 191, 36, 0.4); border-radius: 12px; color: #fcd34d;">
                        <strong>⚠️ Incomplete Permit</strong>
                        <p style="margin: 8px 0 0; font-size: 14px;">Please complete all safety checks before submitting. Missing fields are highlighted above.</p>
                    </div>

                    <div class="cancel-link">
                        <a href="<?php echo htmlspecialchars($app->url('/')); ?>" class="btn btn-secondary">← Cancel</a>
                    </div>
                </form>

                <!-- Quick Navigation -->
                <div class="quick-nav" id="quickNav" style="display: none;">
                    <div class="quick-nav-title">Sections</div>
                    <div id="quickNavLinks"></div>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <script>
        // Progress tracking and risk assessment
        (function() {
            let totalFields = 0;
            let completedFields = 0;
            let scoreItems = [];
            let autoSaveTimer = null;
            const autoSaveEnabled = <?php echo $currentUser !== null ? 'true' : 'false'; ?>;
            const autoSaveKey = 'permit_session_draft_<?php echo hash('sha256', (string)($currentUser['id'] ?? 'guest') . '|' . (string)$template_id); ?>';
            const requiredValueSelector = [
                'input[type="text"][required]',
                'input[type="email"][required]',
                'input[type="tel"][required]',
                'input[type="date"][required]',
                'input[type="time"][required]',
                'input[type="datetime-local"][required]',
                'input[type="number"][required]',
                'textarea[required]',
                'select[required]'
            ].join(',');

            // Initialize
            function init() {
                countFields();
                attachFieldListeners();
                updateProgress();
                initAutoSave();
                restoreAutoSavedData();
            }

            function requiredRadioGroups(scope) {
                const groups = new Set();
                scope.querySelectorAll('input[type="radio"][required], input[type="radio"][data-score-item="true"]').forEach(radio => {
                    if (radio.name) groups.add(radio.name);
                });
                return groups;
            }

            // Progress reflects only fields required for a final submission.
            function countFields() {
                const form = document.getElementById('permitForm');
                if (!form) return;

                totalFields = form.querySelectorAll(requiredValueSelector).length;
                totalFields += form.querySelectorAll('input[type="checkbox"][required]').length;
                const radioGroups = requiredRadioGroups(form);
                totalFields += radioGroups.size;
                scoreItems = [];
                form.querySelectorAll('input[type="radio"]').forEach(radio => {
                    if (radio.name && radio.dataset.scoreItem === 'true' && !scoreItems.includes(radio.name)) {
                        scoreItems.push(radio.name);
                    }
                });
            }

            // Attach listeners to all form fields
            function attachFieldListeners() {
                const form = document.getElementById('permitForm');
                if (!form) return;

                form.addEventListener('change', function() {
                    updateProgress();
                    scheduleAutoSave();
                });

                form.addEventListener('input', function(e) {
                    if (e.target.tagName === 'TEXTAREA' || e.target.type === 'text') {
                        scheduleAutoSave();
                    }
                });
            }

            // Update progress bar and risk indicator
            function updateProgress() {
                const form = document.getElementById('permitForm');
                if (!form) return;

                completedFields = 0;
                let noCount = 0;
                let yesCount = 0;
                let naCount = 0;

                form.querySelectorAll(requiredValueSelector).forEach(field => {
                    if (field.value.trim() !== '' && field.value !== '') {
                        completedFields++;
                    }
                });

                form.querySelectorAll('input[type="checkbox"][required]').forEach(field => {
                    if (field.checked) completedFields++;
                });

                const checkedGroups = new Set();
                form.querySelectorAll('input[type="radio"]:checked').forEach(radio => {
                    if (radio.name && requiredRadioGroups(form).has(radio.name)) {
                        checkedGroups.add(radio.name);
                        
                        // Track for risk assessment
                        const value = radio.value.toLowerCase();
                        if (scoreItems.includes(radio.name)) {
                            if (value === 'yes') yesCount++;
                            else if (value === 'no') noCount++;
                            else if (value === 'na') naCount++;
                        }
                    }
                });
                completedFields += checkedGroups.size;

                // Calculate percentage
                const percentage = totalFields > 0 ? Math.min(100, Math.round((completedFields / totalFields) * 100)) : 0;
                
                // Update progress bar
                const progressFill = document.getElementById('progressFill');
                const progressText = document.getElementById('progressText');
                if (progressFill) progressFill.style.width = percentage + '%';
                if (progressText) progressText.textContent = percentage + '% Complete (' + completedFields + '/' + totalFields + ' fields)';

                // Update risk indicator
                updateRiskIndicator(noCount, yesCount, naCount);
                
                // Update section counters
                updateSectionCounters();
            }

            // Update risk indicator based on responses
            function updateRiskIndicator(noCount, yesCount, naCount) {
                const riskIndicator = document.getElementById('riskIndicator');
                const riskIcon = document.getElementById('riskIcon');
                const riskText = document.getElementById('riskText');
                
                if (!riskIndicator || !riskIcon || !riskText) return;

                const totalScoreItems = noCount + yesCount + naCount;
                if (totalScoreItems === 0) {
                    riskIndicator.style.display = 'none';
                    return;
                }

                riskIndicator.style.display = 'inline-flex';
                
                // Match server/start-work scoring: N/A responses are excluded.
                const applicableChecks = yesCount + noCount;
                const passPercentage = applicableChecks > 0 ? (yesCount / applicableChecks) * 100 : 100;
                
                // Remove all risk classes
                riskIndicator.classList.remove('risk-low', 'risk-medium', 'risk-high');
                
                if (passPercentage < 60) {
                    riskIndicator.classList.add('risk-high');
                    riskIcon.textContent = '⚠️';
                    riskText.textContent = 'High Risk - ' + noCount + ' issues identified';
                } else if (passPercentage < 80) {
                    riskIndicator.classList.add('risk-medium');
                    riskIcon.textContent = '⚡';
                    riskText.textContent = 'Medium Risk - ' + noCount + ' issues to address';
                } else {
                    riskIndicator.classList.add('risk-low');
                    riskIcon.textContent = '✓';
                    riskText.textContent = applicableChecks > 0
                        ? 'Controls passed - ' + Math.round(passPercentage) + '%'
                        : 'Checks marked not applicable';
                }
            }

            // Update section completion counters
            function updateSectionCounters() {
                const form = document.getElementById('permitForm');
                const quickNav = document.getElementById('quickNav');
                const quickNavLinks = document.getElementById('quickNavLinks');
                
                if (!form) return;

                let navHtml = '';
                let hasSections = false;

                // Get all sections
                document.querySelectorAll('.section-title').forEach((sectionTitle, index) => {
                    const sectionId = sectionTitle.getAttribute('data-section-id');
                    const counter = document.getElementById('counter_' + sectionId);
                    const sectionName = sectionTitle.textContent.trim().split('(')[0].trim();
                    
                    if (!counter) return;

                    hasSections = true;

                    // Find all fields in this section (until next section-title)
                    let currentElement = sectionTitle.nextElementSibling;
                    let sectionTotal = 0;
                    let sectionComplete = 0;

                    while (currentElement && !currentElement.classList.contains('section-title')) {
                        // Count radio groups
                        const radioGroups = requiredRadioGroups(currentElement);
                        radioGroups.forEach(name => {
                            sectionTotal++;
                            if (currentElement.querySelector('input[type="radio"][name="' + CSS.escape(name) + '"]:checked')) {
                                sectionComplete++;
                            }
                        });

                        currentElement.querySelectorAll(requiredValueSelector).forEach(field => {
                            sectionTotal++;
                            if (field.value.trim() !== '') sectionComplete++;
                        });

                        currentElement = currentElement.nextElementSibling;
                    }

                    if (sectionTotal > 0) {
                        counter.textContent = '(' + sectionComplete + '/' + sectionTotal + ')';
                        
                        // Add visual indicator
                        if (sectionComplete === sectionTotal) {
                            sectionTitle.classList.remove('section-incomplete');
                            sectionTitle.classList.add('section-complete');
                        } else if (sectionComplete > 0) {
                            sectionTitle.classList.remove('section-complete');
                            sectionTitle.classList.add('section-incomplete');
                        } else {
                            sectionTitle.classList.remove('section-complete', 'section-incomplete');
                        }

                        // Add to quick nav
                        const statusClass = sectionComplete === sectionTotal ? 'complete' : 'incomplete';
                        const icon = sectionComplete === sectionTotal ? '✓' : '○';
                        navHtml += '<a href="#' + sectionId + '" class="quick-nav-link ' + statusClass + '" data-section="' + sectionId + '">' + 
                                   icon + ' ' + sectionName + ' ' + sectionComplete + '/' + sectionTotal + '</a>';
                    }
                });

                // Update quick nav
                if (quickNavLinks && hasSections) {
                    quickNavLinks.innerHTML = navHtml;
                    if (quickNav) quickNav.style.display = 'block';

                    // Add click handlers for smooth scrolling
                    quickNavLinks.querySelectorAll('a').forEach(link => {
                        link.addEventListener('click', function(e) {
                            e.preventDefault();
                            const sectionId = this.getAttribute('data-section');
                            const section = document.querySelector('[data-section-id="' + sectionId + '"]');
                            if (section) {
                                section.scrollIntoView({ behavior: 'smooth', block: 'start' });
                            }
                        });
                    });
                }
            }

            // Auto-save functionality
            function initAutoSave() {
                // Browser recovery is session-scoped and only enabled for a
                // signed-in user; anonymous/kiosk users never share saved PII.
            }

            function scheduleAutoSave() {
                if (!autoSaveEnabled) return;
                if (autoSaveTimer) clearTimeout(autoSaveTimer);
                autoSaveTimer = setTimeout(saveToSessionStorage, 2000);
            }

            function saveToSessionStorage() {
                if (!autoSaveEnabled) return;
                try {
                    const form = document.getElementById('permitForm');
                    if (!form) return;

                    const formData = new FormData(form);
                    const data = {};
                    
                    for (let [key, value] of formData.entries()) {
                        if (
                            key === 'csrf_token'
                            || key === 'website'
                            || key === 'holder_name'
                            || key === 'holder_email'
                            || key === 'holder_phone'
                            || key === 'applicant_declaration'
                            || value instanceof File
                        ) {
                            continue;
                        }
                        if (data[key]) {
                            // Handle multiple values (like checkboxes)
                            if (Array.isArray(data[key])) {
                                data[key].push(value);
                            } else {
                                data[key] = [data[key], value];
                            }
                        } else {
                            data[key] = value;
                        }
                    }

                    sessionStorage.setItem(autoSaveKey, JSON.stringify({
                        data: data,
                        timestamp: Date.now()
                    }));
                } catch (e) {
                    console.error('Auto-save failed:', e);
                }
            }

            function restoreAutoSavedData() {
                if (!autoSaveEnabled) return;
                // Don't restore if we're already editing a permit
                <?php if ($existingPermit): ?>
                return;
                <?php endif; ?>

                try {
                    const saved = sessionStorage.getItem(autoSaveKey);
                    
                    if (saved) {
                        const parsed = JSON.parse(saved);
                        const ageMinutes = (Date.now() - parsed.timestamp) / 1000 / 60;
                        
                        // Session recovery expires after two hours.
                        if (ageMinutes < 120) {
                            if (confirm('We found an auto-saved draft from ' + Math.round(ageMinutes) + ' minutes ago. Would you like to restore it?')) {
                                const form = document.getElementById('permitForm');
                                if (form) {
                                    Object.keys(parsed.data).forEach(key => {
                                        if (key === 'csrf_token') return;
                                        const field = form.elements[key];
                                        if (field) {
                                            if (field.type === 'radio') {
                                                form.querySelectorAll('input[name="' + key + '"]').forEach(radio => {
                                                    if (radio.value === parsed.data[key]) {
                                                        radio.checked = true;
                                                    }
                                                });
                                            } else {
                                                field.value = parsed.data[key];
                                            }
                                        }
                                    });
                                    updateProgress();
                                }
                            } else {
                                sessionStorage.removeItem(autoSaveKey);
                            }
                        } else {
                            sessionStorage.removeItem(autoSaveKey);
                        }
                    }
                } catch (e) {
                    console.error('Failed to restore auto-saved data:', e);
                }
            }

            <?php if ($success && $currentUser !== null): ?>
            sessionStorage.removeItem(autoSaveKey);
            <?php endif; ?>

            // Initialize on DOM ready
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', init);
            } else {
                init();
            }
        })();

        // Pre-submission validation
        document.addEventListener('DOMContentLoaded', function() {
            const submitBtn = document.getElementById('submitBtn');
            const form = document.getElementById('permitForm');
            const validationWarning = document.getElementById('validationWarning');

            if (submitBtn && form) {
                form.addEventListener('submit', function(e) {
                    // Only validate for final submission, not drafts
                    const clickedButton = document.activeElement;
                    if (clickedButton && clickedButton.value === 'save_draft') {
                        return true;
                    }

                    // Count incomplete required fields
                    let incompleteCount = 0;
                    let incompleteScoreItems = 0;

                    // Check radio groups
                    const radioGroups = new Set();
                    form.querySelectorAll('input[type="radio"]').forEach(radio => {
                        if (!radioGroups.has(radio.name)) {
                            radioGroups.add(radio.name);
                            const checked = form.querySelector('input[name="' + radio.name + '"]:checked');
                            if (!checked) {
                                incompleteCount++;
                                if (radio.getAttribute('data-score-item') === 'true') {
                                    incompleteScoreItems++;
                                }
                            }
                        }
                    });

                    // Show warning if there are incomplete items
                    if (incompleteScoreItems > 0) {
                        if (validationWarning) {
                            validationWarning.style.display = 'block';
                            validationWarning.innerHTML = '<strong>⚠️ Incomplete Safety Checks</strong>' +
                                '<p style="margin: 8px 0 0; font-size: 14px;">You have ' + incompleteScoreItems + ' safety check(s) not completed. ' +
                                'Please review all sections marked as incomplete (highlighted in red).</p>';
                            validationWarning.scrollIntoView({ behavior: 'smooth', block: 'center' });
                        }
                        
                        // Ask for confirmation
                        if (!confirm('You have ' + incompleteScoreItems + ' safety check(s) not completed.\n\nAre you sure you want to submit this permit with incomplete safety checks?')) {
                            e.preventDefault();
                            return false;
                        }
                    }

                    // Final confirmation
                    if (!confirm('Submit this permit for approval?\n\nPlease confirm all information is accurate and complete.')) {
                        e.preventDefault();
                        return false;
                    }
                });
            }
        });

        // Save Draft should bypass HTML5 required on dynamic fields
        (function(){
            var form = document.getElementById('permitForm');
            if (!form) return;
            var saveBtn = form.querySelector('button[name="action"][value="save_draft"]');
            if (saveBtn) {
                saveBtn.addEventListener('click', function(){
                    form.dataset.draft = '1';
                });
            }
            form.addEventListener('submit', function(){
                if (form.dataset.draft === '1') {
                    var nodes = form.querySelectorAll('[required]');
                    nodes.forEach(function(el){
                        if (el.name !== 'holder_name' && el.name !== 'holder_email') {
                            el.removeAttribute('required');
                        }
                    });
                }
            });
        })();
        // Toggle Note/Media per score item
        document.addEventListener('click', function(e){
            var t = e.target;
            // Proxy clicks for media buttons to the appropriate hidden file inputs
            if (t && t.closest && t.closest('.media-btn')) {
                var btn = t.closest('.media-btn');
                var box = btn.closest('.media-box');
                if (box) {
                    var inputs = box.querySelectorAll('input[type=file]');
                    var target = btn.getAttribute('data-target');
                    var input = Array.prototype.find.call(inputs, function(el){
                        return target === 'camera' ? el.hasAttribute('capture') : !el.hasAttribute('capture');
                    });
                    if (input) { input.click(); }
                }
            }
            if (t && t.classList && t.classList.contains('toggle-note')) {
                var name = t.getAttribute('data-for');
                var box = document.getElementById('note_' + name);
                if (box) { box.style.display = (box.style.display === 'block') ? 'none' : 'block';
                    if (box.style.display === 'block') { var ta = box.querySelector('textarea'); if (ta) ta.focus(); }
                }
            }
            if (t && t.classList && t.classList.contains('toggle-media')) {
                var name2 = t.getAttribute('data-for');
                var box2 = document.getElementById('media_' + name2);
                if (box2) { box2.style.display = (box2.style.display === 'block') ? 'none' : 'block';
                    if (box2.style.display === 'block') { var fi = box2.querySelector('input[type=file]'); if (fi) fi.focus(); }
                }
            }
        });
        
        // Auto-open note box when "No" is selected
        document.addEventListener('change', function(e) {
            if (e.target.type === 'radio' && e.target.value.toLowerCase() === 'no') {
                var fieldName = e.target.name;
                var noteBox = document.getElementById('note_' + fieldName);
                if (noteBox && noteBox.style.display !== 'block') {
                    noteBox.style.display = 'block';
                    var textarea = noteBox.querySelector('textarea');
                    if (textarea) {
                        setTimeout(function() {
                            textarea.focus();
                            if (!textarea.value) {
                                textarea.placeholder = "⚠️ Please explain why this safety check cannot be met and what alternative measures are in place...";
                            }
                        }, 100);
                    }
                }
            }
        });
        
        // Auto-open note box when there's existing content
        document.addEventListener('DOMContentLoaded', function(){
            document.querySelectorAll('.note-box').forEach(function(box){
                var ta = box.querySelector('textarea');
                if (ta && ta.value.trim() !== '') { box.style.display = 'block'; }
            });
        });
    </script>
</body>
</html>
