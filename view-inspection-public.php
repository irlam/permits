<?php
declare(strict_types=1);

use Permits\FormTemplateSeeder;
use Permits\SystemSettings;
use Permits\TemplateCatalog;

[$app, $db, $root] = require __DIR__ . '/src/bootstrap.php';

$branding = SystemSettings::branding($db, 'Permit System');
$companyName = $branding['company_name'];
$companyLogoPath = $branding['company_logo_path'];
$companyLogoUrl = $companyLogoPath ? asset('/' . ltrim($companyLogoPath, '/')) : null;
$brandingCss = SystemSettings::brandingCssVariables($branding);

$uniqueLink = isset($_GET['link']) && is_scalar($_GET['link']) ? trim((string)$_GET['link']) : '';
if (strlen($uniqueLink) < 32 || strlen($uniqueLink) > 100) {
    http_response_code(404);
    exit('Inspection record not found.');
}

try {
    $stmt = $db->pdo->prepare("
        SELECT f.*, COALESCE(ft.name, 'Inspection Checklist') AS template_name,
               ft.form_structure, ft.json_schema
        FROM forms f
        LEFT JOIN form_templates ft ON ft.id = f.template_id
        WHERE f.unique_link = ?
        LIMIT 1
    ");
    $stmt->execute([$uniqueLink]);
    $inspection = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Throwable $e) {
    error_log('Inspection record view failed: ' . $e->getMessage());
    $inspection = null;
}

if (!$inspection || !TemplateCatalog::isInspection([
    'id' => (string)($inspection['template_id'] ?? ''),
    'name' => (string)($inspection['template_name'] ?? ''),
])) {
    http_response_code(404);
    exit('Inspection record not found.');
}

$answers = json_decode((string)($inspection['form_data'] ?? ''), true);
if (!is_array($answers)) {
    $answers = [];
}
$formStructure = json_decode((string)($inspection['form_structure'] ?? ''), true);
if (!is_array($formStructure) || $formStructure === []) {
    $schema = json_decode((string)($inspection['json_schema'] ?? ''), true);
    $formStructure = is_array($schema) ? FormTemplateSeeder::buildPublicFormStructure($schema) : [];
}

function inspection_value_label(array $field, string $value): string
{
    foreach (($field['options'] ?? []) as $option) {
        if (is_array($option)) {
            $optionValue = (string)($option['value'] ?? ($option[0] ?? ''));
            $optionLabel = (string)($option['label'] ?? ($option[1] ?? $optionValue));
        } else {
            $optionValue = $optionLabel = (string)$option;
        }
        if ($optionValue !== '' && hash_equals($optionValue, $value)) {
            return $optionLabel;
        }
    }
    return $value;
}

function inspection_date(?string $value): string
{
    if (!$value) {
        return '—';
    }
    $timestamp = strtotime($value);
    return $timestamp === false ? $value : date('d/m/Y H:i', $timestamp);
}

function inspection_media_paths(string $raw): array
{
    return array_values(array_filter(array_map('trim', explode(',', $raw)), static function (string $path): bool {
        return preg_match('#^uploads/[a-f0-9-]{36}/[a-f0-9]{32}\.(?:jpg|jpeg|png|gif|webp|mp4|mov|webm)$#i', $path) === 1;
    }));
}

$reference = (string)($inspection['ref_number'] ?? 'Inspection');
$templateName = (string)($inspection['template_name'] ?? 'Inspection Checklist');
?>
<!doctype html>
<html lang="en" style="<?= htmlspecialchars($brandingCss, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= htmlspecialchars($reference . ' · ' . $templateName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></title>
    <?php if (function_exists('cache_meta_tags')) { cache_meta_tags(); } ?>
    <link rel="stylesheet" href="<?= htmlspecialchars(asset('/assets/app.css'), ENT_QUOTES, 'UTF-8') ?>">
    <style>
        :root{color-scheme:dark}*{box-sizing:border-box}body{margin:0;background:#0f172a;color:#e5e7eb;font-family:system-ui,-apple-system,'Segoe UI',sans-serif}.wrap{max-width:1000px;margin:0 auto;padding:20px 14px 72px}.brand{display:inline-flex;align-items:center;gap:10px;color:#f8fafc;text-decoration:none;margin-bottom:16px;padding:8px;border-radius:12px}.brand-logo,.brand-symbol{width:42px;height:42px;border-radius:10px}.brand-logo{object-fit:contain;background:#fff;padding:4px}.brand-symbol{display:grid;place-items:center;background:var(--brand-primary);color:var(--brand-on-primary);font-weight:800}.card{background:#111827;border:1px solid #263244;border-radius:20px;padding:clamp(18px,4vw,34px)}.top{display:flex;justify-content:space-between;gap:18px;align-items:flex-start;flex-wrap:wrap}.eyebrow{font-size:12px;text-transform:uppercase;letter-spacing:.12em;color:#94a3b8}.title{margin:4px 0 7px;font-size:clamp(24px,4vw,34px)}.ref{color:#cbd5e1}.badge{display:inline-flex;align-items:center;gap:6px;padding:7px 12px;border-radius:999px;background:rgba(34,197,94,.15);border:1px solid rgba(74,222,128,.35);color:#bbf7d0;font-weight:750}.warning{margin:22px 0;padding:14px 16px;border-radius:12px;background:rgba(14,165,233,.1);border:1px solid rgba(56,189,248,.32);color:#bae6fd}.meta{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px;margin:20px 0}.meta-item{background:#0a101a;border:1px solid #263244;border-radius:12px;padding:13px}.meta-item span{display:block;color:#94a3b8;font-size:12px;margin-bottom:4px}.section{border-top:1px solid #263244;margin-top:26px;padding-top:22px}.section h2{margin:0 0 15px;font-size:20px}.answer{display:grid;gap:6px;padding:12px 0;border-bottom:1px solid rgba(51,65,85,.55)}.answer:last-child{border-bottom:0}.label{font-size:13px;color:#94a3b8}.value{white-space:pre-wrap;overflow-wrap:anywhere}.choice-yes{color:#86efac}.choice-no{color:#fca5a5}.choice-na{color:#7dd3fc}.note{margin-top:5px;padding:10px 12px;border-left:3px solid #f59e0b;background:rgba(245,158,11,.08);color:#fde68a;white-space:pre-wrap}.media{display:flex;gap:10px;flex-wrap:wrap;margin-top:8px}.media a{color:#bfdbfe}.media img{width:min(180px,100%);height:120px;object-fit:cover;border-radius:10px;border:1px solid #334155}.actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:26px}.btn{display:inline-flex;align-items:center;justify-content:center;padding:11px 16px;border-radius:10px;text-decoration:none;font-weight:700;border:1px solid #475569;background:#1e293b;color:#e2e8f0}.btn-primary{border-color:transparent;background:var(--brand-primary);color:var(--brand-on-primary)}@media print{body{background:#fff;color:#111}.brand,.actions,.warning{display:none}.card{background:#fff;border:0;padding:0}.meta-item{background:#fff;border-color:#ccc}.section,.answer{border-color:#ddd}.label{color:#555}.badge{color:#111;border-color:#888;background:#eee}}@media(max-width:600px){.actions .btn{width:100%}.card{border-radius:14px}.wrap{padding-left:10px;padding-right:10px}}
    </style>
</head>
<body>
<div class="wrap">
    <a class="brand" href="<?= htmlspecialchars($app->url('/#inspections'), ENT_QUOTES, 'UTF-8') ?>">
        <?php if ($companyLogoUrl): ?><img class="brand-logo" src="<?= htmlspecialchars($companyLogoUrl, ENT_QUOTES, 'UTF-8') ?>" alt=""><?php else: ?><span class="brand-symbol" aria-hidden="true"><?= htmlspecialchars(mb_strtoupper(mb_substr($companyName, 0, 1, 'UTF-8'), 'UTF-8')) ?></span><?php endif; ?>
        <span><strong><?= htmlspecialchars($companyName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong><br><small style="color:#94a3b8">Inspection record</small></span>
    </a>

    <main class="card">
        <div class="top">
            <div><div class="eyebrow">Inspection & checklist record</div><h1 class="title"><?= htmlspecialchars($templateName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h1><div class="ref">Reference <?= htmlspecialchars($reference, ENT_QUOTES, 'UTF-8') ?></div></div>
            <span class="badge">✓ Completed</span>
        </div>
        <div class="warning"><strong>Record only:</strong> this inspection documents findings and actions. It does not itself authorise high-risk work or replace a required permit-to-work.</div>

        <div class="meta">
            <div class="meta-item"><span>Recorded by</span><?= htmlspecialchars((string)($inspection['holder_name'] ?? '—'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
            <div class="meta-item"><span>Completed</span><?= htmlspecialchars(inspection_date((string)($inspection['closed_at'] ?? $inspection['created_at'] ?? '')), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
            <div class="meta-item"><span>Area</span><?= htmlspecialchars((string)($inspection['site_block'] ?? '—'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
        </div>

        <?php foreach ($formStructure as $section): ?>
            <?php if (!is_array($section) || !is_array($section['fields'] ?? null)) { continue; } ?>
            <section class="section">
                <h2><?= htmlspecialchars((string)($section['title'] ?? 'Inspection section'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h2>
                <?php foreach ($section['fields'] as $field): ?>
                    <?php
                        if (!is_array($field) || empty($field['name'])) { continue; }
                        $name = (string)$field['name'];
                        $rawValue = trim((string)($answers[$name] ?? ''));
                        if ($rawValue === '' && empty($answers[$name . '_note']) && empty($answers[$name . '_media'])) { continue; }
                        $displayValue = inspection_value_label($field, $rawValue);
                        $lower = strtolower($rawValue);
                        $choiceClass = in_array($lower, ['yes','no','na'], true) ? 'choice-' . $lower : '';
                    ?>
                    <div class="answer">
                        <div class="label"><?= htmlspecialchars((string)($field['label'] ?? $name), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                        <div class="value <?= htmlspecialchars($choiceClass, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($displayValue !== '' ? $displayValue : '—', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                        <?php if (!empty($answers[$name . '_note'])): ?><div class="note"><strong>Note:</strong> <?= htmlspecialchars((string)$answers[$name . '_note'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div><?php endif; ?>
                        <?php $mediaPaths = inspection_media_paths((string)($answers[$name . '_media'] ?? '')); if ($mediaPaths !== []): ?>
                            <div class="media">
                                <?php foreach ($mediaPaths as $mediaPath): $mediaUrl = $app->url('/' . ltrim($mediaPath, '/')); $extension = strtolower(pathinfo($mediaPath, PATHINFO_EXTENSION)); ?>
                                    <?php if (in_array($extension, ['jpg','jpeg','png','gif','webp'], true)): ?><a href="<?= htmlspecialchars($mediaUrl, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener"><img src="<?= htmlspecialchars($mediaUrl, ENT_QUOTES, 'UTF-8') ?>" alt="Inspection evidence"></a><?php else: ?><a href="<?= htmlspecialchars($mediaUrl, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">View video evidence</a><?php endif; ?>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </section>
        <?php endforeach; ?>

        <div class="actions"><button class="btn btn-primary" type="button" onclick="window.print()">Print inspection</button><a class="btn" href="<?= htmlspecialchars($app->url('/#inspections'), ENT_QUOTES, 'UTF-8') ?>">Back to inspections</a></div>
    </main>
</div>
</body>
</html>
