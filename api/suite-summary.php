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

function suite_permit_location(array $row): string
{
    $siteBlock = trim((string) ($row['site_block'] ?? ''));
    if ($siteBlock !== '') {
        return $siteBlock;
    }

    $data = json_decode((string) ($row['form_data'] ?? ''), true);
    if (!is_array($data)) {
        return '';
    }

    foreach ([
        'siteProject',
        'inspectionArea',
        'buildingArea',
        'propertyArea',
        'location',
        'exactWorkLocation',
        'workLocation',
        'exactLocation',
        'siteLocation',
        'siteBlock',
        'area',
    ] as $key) {
        if (!isset($data[$key]) || !is_scalar($data[$key])) {
            continue;
        }

        $value = trim((string) $data[$key]);
        if ($value !== '') {
            return mb_substr($value, 0, 190, 'UTF-8');
        }
    }

    return '';
}

try {
    $stmt = $db->pdo->query(
        "SELECT status, site_block, form_data, updated_at
         FROM forms"
    );

    $metrics = [
        'total' => 0,
        'active' => 0,
        'pending_approval' => 0,
        'awaiting_acceptance' => 0,
        'suspended' => 0,
        'expired' => 0,
    ];
    $lastUpdated = null;

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if ($site !== '' && strcasecmp(suite_permit_location($row), $site) !== 0) {
            continue;
        }

        $status = strtolower(trim((string) ($row['status'] ?? '')));
        $metrics['total']++;

        if (in_array($status, ['active', 'issued', 'approved', 'open'], true)) {
            $metrics['active']++;
        }
        if ($status === 'pending_approval') {
            $metrics['pending_approval']++;
        }
        if ($status === 'awaiting_acceptance') {
            $metrics['awaiting_acceptance']++;
        }
        if ($status === 'suspended') {
            $metrics['suspended']++;
        }
        if ($status === 'expired') {
            $metrics['expired']++;
        }

        $updated = trim((string) ($row['updated_at'] ?? ''));
        if ($updated !== '' && ($lastUpdated === null || strcmp($updated, $lastUpdated) > 0)) {
            $lastUpdated = $updated;
        }
    }

    echo json_encode([
        'ok' => true,
        'module' => 'permits',
        'scope' => ['site' => $site !== '' ? $site : null],
        'metrics' => $metrics,
        'last_updated' => $lastUpdated,
    ], JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    error_log('Construction Suite summary failed: ' . $e->getMessage());
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'summary_unavailable']);
}
