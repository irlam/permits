<?php
declare(strict_types=1);

use Permits\PublicPermitStatus;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('Pragma: no-cache');

[$app, $db] = require __DIR__ . '/../src/bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

try {
    $permits = PublicPermitStatus::current($db->pdo, 50);
    echo json_encode([
        'success' => true,
        'generated_at' => date(DATE_ATOM),
        'counts' => PublicPermitStatus::counts($permits),
        'permits' => $permits,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('Unable to load public current permits: ' . $e->getMessage());
    http_response_code(503);
    echo json_encode([
        'success' => false,
        'message' => 'Current permit status is temporarily unavailable.',
    ]);
}
