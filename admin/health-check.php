<?php
declare(strict_types=1);

use Permits\ProductionHealthCheck;
use Permits\SystemSettings;

[$app, $db, $root] = require __DIR__ . '/../src/bootstrap.php';

require_once $root . '/src/Auth.php';
$auth = new Auth($db);
$currentUser = $auth->requireRoles(['admin']);

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$sessionCookieParameters = session_get_cookie_params();
$backupSettings = SystemSettings::load($db, ['backup_path'], [
    'backup_path' => (string)($_ENV['BACKUP_PATH'] ?? ''),
]);
$checker = new ProductionHealthCheck(
    $db->pdo,
    $root,
    [
        'app_env' => $app->config('APP_ENV', 'production'),
        'app_url' => $app->config('APP_URL', ''),
        'app_debug' => $app->config('APP_DEBUG', false),
        'session_cookie_secure' => $sessionCookieParameters['secure'] ?? false,
        'session_cookie_httponly' => $sessionCookieParameters['httponly'] ?? false,
        'db_driver' => $app->config('DB_DRIVER', ''),
        'backup_path' => $backupSettings['backup_path'],
    ]
);
$report = $checker->run();

try {
    $db->pdo->query('SELECT 1 FROM permit_links LIMIT 1');
    $permitLinksReady = true;
} catch (Throwable $exception) {
    $permitLinksReady = false;
}

$phase4CheckKey = 'database.table.permit_links';
$report['checks'][$phase4CheckKey] = [
    'ok' => $permitLinksReady,
    'message' => $permitLinksReady
        ? "Required Phase 4 table 'permit_links' is readable."
        : "Required Phase 4 table 'permit_links' is missing or inaccessible. Run the database migration.",
];
if (!$permitLinksReady && !in_array($phase4CheckKey, $report['failures'], true)) {
    $report['failures'][] = $phase4CheckKey;
}

$totalChecks = count($report['checks']);
$failedChecks = count($report['failures']);
$passedChecks = $totalChecks - $failedChecks;
$ready = $failedChecks === 0;
$branding = SystemSettings::branding($db);
$companyName = $branding['company_name'];
$brandingCss = SystemSettings::brandingCssVariables($branding);

/** @param mixed $value */
function healthCheckEscape($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function healthCheckCategory(string $key): string
{
    return match (strtok($key, '.')) {
        'database' => 'Database',
        'directory' => 'Storage',
        'config' => 'Configuration',
        'extension' => 'PHP extensions',
        default => 'System',
    };
}

$groupedChecks = [];
foreach ($report['checks'] as $key => $check) {
    $groupedChecks[healthCheckCategory((string)$key)][] = $check;
}
?>
<!DOCTYPE html>
<html lang="en" style="<?= healthCheckEscape($brandingCss) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Health Check - <?= healthCheckEscape($companyName) ?></title>
    <meta name="theme-color" content="<?= healthCheckEscape($branding['primary_colour']) ?>">
    <link rel="stylesheet" href="<?= healthCheckEscape(asset('/assets/app.css')) ?>">
    <style>
        .health-summary{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin:18px 0}
        .health-metric{padding:16px;border:1px solid rgba(148,163,184,.22);border-radius:14px;background:rgba(15,23,42,.72)}
        .health-metric strong{display:block;font-size:28px}.health-metric span{color:#cbd5e1}
        .health-banner{padding:18px;border-radius:16px;border:1px solid;margin:18px 0}
        .health-banner--pass{border-color:#22c55e;background:rgba(20,83,45,.35)}
        .health-banner--fail{border-color:#ef4444;background:rgba(127,29,29,.35)}
        .health-group{margin-top:16px;border:1px solid rgba(148,163,184,.2);border-radius:16px;overflow:hidden;background:rgba(15,23,42,.62)}
        .health-group h2{font-size:18px;margin:0;padding:14px 16px;border-bottom:1px solid rgba(148,163,184,.18)}
        .health-list{list-style:none;margin:0;padding:0}.health-item{display:flex;gap:12px;padding:12px 16px;border-bottom:1px solid rgba(148,163,184,.12)}
        .health-item:last-child{border-bottom:0}.health-icon{font-weight:800}.health-icon--pass{color:#4ade80}.health-icon--fail{color:#f87171}
        .health-actions{display:flex;gap:10px;flex-wrap:wrap;align-items:center}.health-time{color:#94a3b8;font-size:13px}
        @media(max-width:650px){.health-summary{grid-template-columns:1fr}.health-item{align-items:flex-start}}
    </style>
</head>
<body class="theme-dark">
<header class="site-header">
    <div class="brand-mark"><div><div class="brand-mark__name"><?= healthCheckEscape($companyName) ?></div><div class="brand-mark__sub">System Health Check</div></div></div>
    <div class="site-header__actions">
        <span class="user-info">👤 <?= healthCheckEscape($currentUser['name'] ?? 'Administrator') ?></span>
        <a class="btn btn-secondary" href="<?= healthCheckEscape($app->url('admin.php')) ?>">← Admin Panel</a>
        <a class="btn btn-secondary" href="<?= healthCheckEscape($app->url('logout.php')) ?>">Logout</a>
    </div>
</header>

<main class="site-container">
    <section class="hero-card">
        <h1>Production health check</h1>
        <p>Read-only checks for the database, storage, configuration and PHP runtime. No settings or records are changed.</p>
        <div class="health-actions">
            <a class="btn" href="<?= healthCheckEscape($app->url('admin/health-check.php')) ?>">Run checks again</a>
            <span class="health-time">Run at <?= healthCheckEscape(date('d/m/Y H:i:s T')) ?></span>
        </div>
    </section>

    <section class="health-banner <?= $ready ? 'health-banner--pass' : 'health-banner--fail' ?>" role="status">
        <h2><?= $ready ? 'Ready for production' : 'Action required before production' ?></h2>
        <p><?= $ready
            ? 'All automated production health checks passed.'
            : healthCheckEscape($failedChecks) . ' check(s) failed. Correct the red items below and run the check again.' ?></p>
    </section>

    <section class="health-summary" aria-label="Health check totals">
        <article class="health-metric"><strong><?= healthCheckEscape($totalChecks) ?></strong><span>Total checks</span></article>
        <article class="health-metric"><strong><?= healthCheckEscape($passedChecks) ?></strong><span>Passed</span></article>
        <article class="health-metric"><strong><?= healthCheckEscape($failedChecks) ?></strong><span>Failed</span></article>
    </section>

    <?php foreach ($groupedChecks as $category => $checks): ?>
        <section class="health-group">
            <h2><?= healthCheckEscape($category) ?></h2>
            <ul class="health-list">
                <?php foreach ($checks as $check): ?>
                    <li class="health-item">
                        <span class="health-icon <?= $check['ok'] ? 'health-icon--pass' : 'health-icon--fail' ?>" aria-hidden="true"><?= $check['ok'] ? '✓' : '✕' ?></span>
                        <span><?= healthCheckEscape($check['message']) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
    <?php endforeach; ?>
</main>
</body>
</html>
