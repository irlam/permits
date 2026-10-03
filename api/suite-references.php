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
        "SELECT site_block, form_data
         FROM forms
         ORDER BY created_at DESC"
    );

    $values = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $location = suite_permit_location($row);
        if ($location === '') {
            continue;
        }
        $values[$location] = true;
    }

    $labels = array_keys($values);
    natcasesort($labels);

    $items = array_map(
        static fn(string $value): array => ['value' => $value, 'label' => $value],
        array_values($labels)
    );

    echo json_encode([
        'ok' => true,
        'module' => 'permits',
        'reference_type' => 'site',
        'items' => $items,
    ], JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    error_log('Construction Suite permits reference lookup failed: ' . $e->getMessage());
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'references_unavailable']);
}
