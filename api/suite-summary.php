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

$site = trim((string) ($_GET['site'] ?? ''));
$where = ['1=1'];
$params = [];
if ($site !== '') {
    $where[] = 'f.site_block = :site';
    $params[':site'] = $site;
}

try {
    $sql = "SELECT
                COUNT(*) AS total,
                COALESCE(SUM(CASE WHEN f.status IN ('active','issued','approved','open') THEN 1 ELSE 0 END),0) AS active,
                COALESCE(SUM(CASE WHEN f.status = 'pending_approval' THEN 1 ELSE 0 END),0) AS pending_approval,
                COALESCE(SUM(CASE WHEN f.status = 'awaiting_acceptance' THEN 1 ELSE 0 END),0) AS awaiting_acceptance,
                COALESCE(SUM(CASE WHEN f.status = 'suspended' THEN 1 ELSE 0 END),0) AS suspended,
                COALESCE(SUM(CASE WHEN f.status = 'expired' THEN 1 ELSE 0 END),0) AS expired,
                MAX(f.updated_at) AS last_updated
            FROM forms f
            WHERE " . implode(' AND ', $where);

    $stmt = $db->pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    echo json_encode([
        'ok' => true,
        'module' => 'permits',
        'scope' => ['site' => $site !== '' ? $site : null],
        'metrics' => [
            'total' => (int) ($row['total'] ?? 0),
            'active' => (int) ($row['active'] ?? 0),
            'pending_approval' => (int) ($row['pending_approval'] ?? 0),
            'awaiting_acceptance' => (int) ($row['awaiting_acceptance'] ?? 0),
            'suspended' => (int) ($row['suspended'] ?? 0),
            'expired' => (int) ($row['expired'] ?? 0),
        ],
        'last_updated' => $row['last_updated'] ?: null,
    ], JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    error_log('Construction Suite summary failed: ' . $e->getMessage());
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'summary_unavailable']);
}
