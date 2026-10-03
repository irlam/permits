<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
[, $db] = require $root . '/src/bootstrap.php';

$expected = trim((string) ($_ENV['CONSTRUCTION_SUITE_API_KEY'] ?? getenv('CONSTRUCTION_SUITE_API_KEY') ?: ''));
$provided = trim((string) ($_SERVER['HTTP_X_CONSTRUCTION_SUITE_KEY'] ?? ''));

if ($expected === '') {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'suite_integration_not_configured']);
    exit;
}
if ($provided === '' || !hash_equals($expected, $provided)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'unauthorized']);
    exit;
}

try {
    $stmt = $db->pdo->query(
        "SELECT DISTINCT TRIM(site_block) AS site_block
         FROM forms
         WHERE site_block IS NOT NULL AND TRIM(site_block) <> ''
         ORDER BY site_block ASC"
    );

    $items = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $site = (string) ($row['site_block'] ?? '');
        if ($site === '') continue;
        $items[] = ['value' => $site, 'label' => $site];
    }

    echo json_encode([
        'ok' => true,
        'module' => 'permits',
        'reference_type' => 'site_block',
        'items' => $items,
    ], JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    error_log('Construction Suite permits reference lookup failed: ' . $e->getMessage());
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'references_unavailable']);
}
