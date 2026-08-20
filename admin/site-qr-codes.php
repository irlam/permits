<?php
declare(strict_types=1);

use Permits\PublicStartCatalog;
use Permits\SystemSettings;
use Permits\TemplateCatalog;

[$app, $db] = require __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/Auth.php';

$auth = new Auth($db);
$currentUser = $auth->requireRoles(['admin']);

$branding = SystemSettings::branding($db);
$companyName = $branding['company_name'];
$companyLogoPath = $branding['company_logo_path'];
$companyLogoUrl = $companyLogoPath ? asset('/' . ltrim($companyLogoPath, '/')) : asset('/favicon.svg');
$brandingCss = SystemSettings::brandingCssVariables($branding);

try {
    $templates = PublicStartCatalog::activeTemplates($db->pdo);
} catch (Throwable $e) {
    error_log('Unable to load site QR templates: ' . $e->getMessage());
    $templates = [];
}

$scope = isset($_GET['scope']) && is_scalar($_GET['scope']) ? strtolower(trim((string)$_GET['scope'])) : 'all';
if (!in_array($scope, ['all', 'permits', 'inspections'], true)) {
    $scope = 'all';
}
$printSlug = isset($_GET['print']) && is_scalar($_GET['print']) ? strtolower(trim((string)$_GET['print'])) : '';
$autoPrint = $printSlug !== '';
$showChooser = $scope !== 'inspections' && ($printSlug === '' || hash_equals('choose', $printSlug));
$chooserStartUrl = $app->url('/start');
$chooserQrUrl = $app->url('/start-qr.php?slug=choose&size=760');
$chooserDownloadUrl = $app->url('/start-qr.php?slug=choose&size=1000&download=1');

$cards = [];
foreach ($templates as $template) {
    $inspection = TemplateCatalog::isInspection($template);
    if ($scope === 'permits' && $inspection) {
        continue;
    }
    if ($scope === 'inspections' && !$inspection) {
        continue;
    }
    $slug = PublicStartCatalog::slug($template);
    if ($printSlug !== '' && !hash_equals($slug, $printSlug)) {
        continue;
    }
    $cards[] = [
        'template' => $template,
        'slug' => $slug,
        'inspection' => $inspection,
        'icon' => PublicStartCatalog::icon($template),
        'start_url' => $app->url('/start/' . rawurlencode($slug)),
        'qr_url' => $app->url('/start-qr.php?slug=' . rawurlencode($slug) . '&size=760'),
        'download_url' => $app->url('/start-qr.php?slug=' . rawurlencode($slug) . '&size=1000&download=1'),
    ];
}

usort($cards, static function (array $a, array $b): int {
    if ($a['inspection'] !== $b['inspection']) {
        return $a['inspection'] ? 1 : -1;
    }
    return strnatcasecmp((string)$a['template']['name'], (string)$b['template']['name']);
});
?>
<!doctype html>
<html lang="en" style="<?= htmlspecialchars($brandingCss, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="theme-color" content="<?= htmlspecialchars($branding['primary_colour'], ENT_QUOTES, 'UTF-8') ?>">
    <title>Site QR Codes · <?= htmlspecialchars($companyName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></title>
    <?php if (function_exists('cache_meta_tags')) { cache_meta_tags(); } ?>
    <link rel="stylesheet" href="<?= htmlspecialchars(asset('/assets/app.css'), ENT_QUOTES, 'UTF-8') ?>">
    <style>
        body { background:#0f172a; }
        .qr-wrap { max-width:1450px; margin:0 auto; padding:24px; }
        .qr-toolbar { display:flex; align-items:flex-start; justify-content:space-between; gap:18px; flex-wrap:wrap; margin-bottom:24px; }
        .qr-toolbar h1 { margin:0 0 8px; color:#f8fafc; font-size:clamp(28px,5vw,42px); }
        .qr-toolbar p { margin:0; max-width:760px; color:#94a3b8; line-height:1.6; }
        .qr-actions { display:flex; gap:10px; flex-wrap:wrap; }
        .notice { margin:0 0 24px; padding:17px 18px; border-radius:16px; border:1px solid rgba(248,113,113,.45); background:rgba(127,29,29,.28); color:#fee2e2; font-weight:700; line-height:1.55; }
        .how-link { margin-bottom:22px; padding:16px 18px; border:1px solid rgba(var(--brand-primary-light-rgb),.3); background:rgba(var(--brand-primary-rgb),.08); border-radius:16px; color:#cbd5e1; }
        .how-link a { color:#fff; font-weight:800; }
        .qr-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(300px,1fr)); gap:18px; }
        .qr-card { background:linear-gradient(145deg,#0b1220,#111827); border:1px solid rgba(148,163,184,.2); border-radius:22px; padding:22px; display:grid; gap:14px; text-align:center; box-shadow:0 18px 40px rgba(2,6,23,.22); }
        .qr-card--chooser { border-color:rgba(var(--brand-primary-light-rgb),.55); background:linear-gradient(145deg,rgba(var(--brand-primary-rgb),.16),#111827); }
        .qr-card__kind { display:inline-flex; justify-self:center; padding:6px 10px; border-radius:999px; font-size:12px; font-weight:800; text-transform:uppercase; letter-spacing:.04em; background:rgba(var(--brand-primary-rgb),.14); color:#dbeafe; }
        .qr-card__icon { font-size:34px; }
        .qr-card h2 { margin:0; font-size:21px; color:#f8fafc; line-height:1.2; }
        .qr-card__qr { width:min(100%,250px); aspect-ratio:1; background:#fff; border-radius:16px; padding:10px; margin:0 auto; display:grid; place-items:center; }
        .qr-card__qr img { width:100%; height:100%; object-fit:contain; }
        .qr-card__scan { margin:0; font-weight:900; font-size:18px; color:#fff; }
        .qr-card__url { margin:0; font-size:11px; color:#94a3b8; overflow-wrap:anywhere; }
        .qr-card__actions { display:flex; justify-content:center; gap:8px; flex-wrap:wrap; }
        .qr-card__actions .btn { min-height:42px; }
        .empty { padding:36px; border:1px dashed #475569; border-radius:18px; color:#94a3b8; text-align:center; grid-column:1/-1; }
        .print-footer { display:none; }
        @media(max-width:640px){ .qr-wrap{padding:16px}.qr-actions,.qr-actions .btn,.qr-card__actions,.qr-card__actions .btn{width:100%}.qr-card__actions .btn{justify-content:center} }
        @page { size:A4 portrait; margin:10mm; }
        @media print {
            body { background:#fff !important; color:#000; }
            .site-header,.qr-toolbar,.notice,.how-link,.qr-card__actions { display:none !important; }
            .qr-wrap { max-width:none; padding:0; }
            .qr-grid { display:grid; grid-template-columns:1fr 1fr; gap:8mm; }
            .qr-card { min-height:128mm; border:2px solid #111827; border-radius:6mm; box-shadow:none; background:#fff; color:#000; padding:8mm; break-inside:avoid; page-break-inside:avoid; align-content:center; }
            .qr-card__kind { color:#111827; background:#e5e7eb; border:1px solid #cbd5e1; }
            .qr-card h2,.qr-card__scan { color:#000; }
            .qr-card__url { color:#334155; }
            .qr-card__qr { width:58mm; border:1px solid #cbd5e1; }
            .print-footer { display:block; font-size:10px; line-height:1.4; color:#111827; margin-top:4mm; }
            body.single-print .qr-grid { display:block; }
            body.single-print .qr-card { width:100%; min-height:260mm; padding:16mm; border-width:3px; display:grid; align-content:center; }
            body.single-print .qr-card__qr { width:110mm; }
            body.single-print .qr-card h2 { font-size:30px; }
            body.single-print .qr-card__scan { font-size:24px; }
            body.single-print .qr-card__icon { font-size:52px; }
        }
    </style>
</head>
<body class="theme-dark <?= $autoPrint ? 'single-print' : '' ?>">
<header class="site-header">
    <div class="brand-mark">
        <?php if ($companyLogoPath): ?><img src="<?= htmlspecialchars($companyLogoUrl, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($companyName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> logo" class="brand-mark__logo"><?php endif; ?>
        <div><div class="brand-mark__name"><?= htmlspecialchars($companyName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div><div class="brand-mark__sub">Site QR Board Manager</div></div>
    </div>
    <div class="site-header__actions">
        <a class="btn btn-secondary" href="<?= htmlspecialchars($app->url('/admin.php'), ENT_QUOTES, 'UTF-8') ?>">Admin Panel</a>
        <a class="btn btn-secondary" href="<?= htmlspecialchars($app->url('/'), ENT_QUOTES, 'UTF-8') ?>">Home</a>
    </div>
</header>

<main class="qr-wrap">
    <div class="qr-toolbar">
        <div>
            <h1>📱 Site Notice-Board QR Codes</h1>
            <p>Print permanent QR signs for every current permit type and inspection checklist. Each QR resolves to the current preferred template, so a laminated sign is not tied to an old v1/v2 form.</p>
        </div>
        <div class="qr-actions">
            <a class="btn <?= $scope === 'all' ? 'btn-accent' : 'btn-secondary' ?>" href="?scope=all">All</a>
            <a class="btn <?= $scope === 'permits' ? 'btn-accent' : 'btn-secondary' ?>" href="?scope=permits">Permits</a>
            <a class="btn <?= $scope === 'inspections' ? 'btn-accent' : 'btn-secondary' ?>" href="?scope=inspections">Inspections</a>
            <button class="btn btn-secondary" type="button" onclick="window.print()">🖨️ Print Current Sheet</button>
        </div>
    </div>

    <div class="notice">⚠️ IMPORTANT: Scanning or submitting a form does not authorise work. Work may only start when the permit has been approved, accepted by the permit holder and is shown as ACTIVE.</div>
    <div class="how-link">Need something to show new users? Open the public <a href="<?= htmlspecialchars($app->url('/how-it-works.php'), ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">How the Permit System Works</a> showcase.</div>

    <section class="qr-grid" aria-label="Permanent permit start QR codes">
        <?php if ($showChooser): ?>
            <article class="qr-card qr-card--chooser">
                <img src="<?= htmlspecialchars($companyLogoUrl, ENT_QUOTES, 'UTF-8') ?>" alt="" aria-hidden="true" style="width:44px;height:44px;justify-self:center;border-radius:10px;">
                <div class="qr-card__kind">Any permit or inspection</div>
                <div class="qr-card__icon" aria-hidden="true">📱</div>
                <h2>Choose a Permit or Inspection</h2>
                <div class="qr-card__qr"><img src="<?= htmlspecialchars($chooserQrUrl, ENT_QUOTES, 'UTF-8') ?>" alt="QR code to choose a permit or inspection"></div>
                <p class="qr-card__scan">Scan to choose a form</p>
                <p class="qr-card__url"><?= htmlspecialchars($chooserStartUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
                <div class="qr-card__actions">
                    <a class="btn btn-accent" href="?print=choose">Print A4</a>
                    <a class="btn btn-secondary" href="<?= htmlspecialchars($chooserDownloadUrl, ENT_QUOTES, 'UTF-8') ?>">Download QR</a>
                    <a class="btn btn-secondary" href="<?= htmlspecialchars($chooserStartUrl, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">Test Link</a>
                </div>
                <div class="print-footer">Choose the correct form for the task. Submitting a permit does not authorise work; it must be approved, accepted and shown as ACTIVE before work starts.</div>
            </article>
        <?php endif; ?>

        <?php if (!$cards && !$showChooser): ?>
            <div class="empty">No active current templates are available for this filter.</div>
        <?php endif; ?>
        <?php foreach ($cards as $card): ?>
            <?php $template = $card['template']; ?>
            <article class="qr-card">
                <img src="<?= htmlspecialchars($companyLogoUrl, ENT_QUOTES, 'UTF-8') ?>" alt="" aria-hidden="true" style="width:44px;height:44px;justify-self:center;border-radius:10px;">
                <div class="qr-card__kind"><?= htmlspecialchars($card['inspection'] ? 'Inspection checklist' : 'Permit to work', ENT_QUOTES, 'UTF-8') ?></div>
                <div class="qr-card__icon" aria-hidden="true"><?= $card['icon'] ?></div>
                <h2><?= htmlspecialchars((string)$template['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h2>
                <div class="qr-card__qr"><img src="<?= htmlspecialchars($card['qr_url'], ENT_QUOTES, 'UTF-8') ?>" alt="QR code to start <?= htmlspecialchars((string)$template['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"></div>
                <p class="qr-card__scan">Scan to <?= $card['inspection'] ? 'start inspection' : 'apply for permit' ?></p>
                <p class="qr-card__url"><?= htmlspecialchars($card['start_url'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
                <div class="qr-card__actions">
                    <a class="btn btn-accent" href="?print=<?= rawurlencode($card['slug']) ?>">Print A4</a>
                    <a class="btn btn-secondary" href="<?= htmlspecialchars($card['download_url'], ENT_QUOTES, 'UTF-8') ?>">Download QR</a>
                    <a class="btn btn-secondary" href="<?= htmlspecialchars($card['start_url'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">Test Link</a>
                </div>
                <div class="print-footer">Submitting this form does not authorise work. A permit must be approved, accepted by the holder and shown as ACTIVE before work starts.</div>
            </article>
        <?php endforeach; ?>
    </section>
</main>
<?php if ($autoPrint): ?>
<script>window.addEventListener('load', function(){ window.setTimeout(function(){ window.print(); }, 250); });</script>
<?php endif; ?>
</body>
</html>
