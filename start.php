<?php
declare(strict_types=1);

use Permits\PublicStartCatalog;
use Permits\TemplateCatalog;

[$app, $db] = require __DIR__ . '/src/bootstrap.php';

$slug = isset($_GET['slug']) && is_scalar($_GET['slug']) ? strtolower(trim((string)$_GET['slug'])) : '';
if ($slug === '') {
    header('Location: ' . $app->url('/#permit-templates'), true, 302);
    exit;
}

$template = PublicStartCatalog::findBySlug($db->pdo, $slug);
if ($template === null) {
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    ?>
    <!doctype html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width,initial-scale=1">
        <title>Permit type unavailable</title>
        <?php if (function_exists('cache_meta_tags')) { cache_meta_tags(); } ?>
        <link rel="stylesheet" href="<?= htmlspecialchars(asset('/assets/app.css'), ENT_QUOTES, 'UTF-8') ?>">
    </head>
    <body class="theme-dark">
        <main class="site-container" style="max-width:720px;padding-top:64px;">
            <section class="hero-card">
                <h1>Permit type unavailable</h1>
                <p>This notice-board link does not currently match an active permit or inspection template.</p>
                <p><a class="btn btn-accent" href="<?= htmlspecialchars($app->url('/'), ENT_QUOTES, 'UTF-8') ?>">Choose from current forms</a></p>
            </section>
        </main>
    </body>
    </html>
    <?php
    exit;
}

$target = TemplateCatalog::publicStartPath($template);
header('Cache-Control: no-store, max-age=0');
header('Location: ' . $app->url($target), true, 302);
exit;
