<?php
// /api/push/unsubscribe.php
// Removes a Web Push subscription by endpoint hash.
// Accepts:
//  - POST application/json: { endpoint: "..." }  (keys optional)
//  - POST application/x-www-form-urlencoded: endpoint=...
// Returns: { ok: true, deleted: n }

declare(strict_types=1);
date_default_timezone_set('Europe/London');

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

// -- Optional CORS (uncomment and set your origin if needed) --
// $origin = 'https://permits.defecttracker.uk';
// header('Access-Control-Allow-Origin', $origin);
// header('Vary', 'Origin');
// if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
//     header('Access-Control-Allow-Methods: POST, OPTIONS');
//     header('Access-Control-Allow-Headers: content-type');
//     exit;
// }

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
[$app, $db] = require $root . '/src/bootstrap.php';
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }

function fail(int $code, string $msg): never {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fail(405, 'Method not allowed');
}
if (empty($_SESSION['user_id'])) { fail(401, 'Authentication required'); }

// Read input (JSON or form)
$endpoint = '';
$ct = $_SERVER['CONTENT_TYPE'] ?? '';
$raw = file_get_contents('php://input') ?: '';

if (stripos($ct, 'application/json') !== false) {
    $data = json_decode($raw, true);
    if (is_array($data)) {
        $endpoint = trim((string)($data['endpoint'] ?? ''));
        // Some clients send the full PushSubscription under "subscription"
        if ($endpoint === '' && isset($data['subscription']['endpoint'])) {
            $endpoint = trim((string)$data['subscription']['endpoint']);
        }
    }
} else {
    // Fallback to form body
    parse_str($raw, $form);
    if (isset($form['endpoint'])) {
        $endpoint = trim((string)$form['endpoint']);
    } elseif (isset($_POST['endpoint'])) {
        $endpoint = trim((string)$_POST['endpoint']);
    }
}

if ($endpoint === '') {
    fail(422, 'Missing endpoint');
}

$hash = hash('sha256', $endpoint);

$pdo = $db->pdo;
$driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
$stmt = $pdo->prepare("DELETE FROM push_subscriptions WHERE endpoint_hash = :h AND user_id = :user_id");
$stmt->execute([':h' => $hash, ':user_id' => $_SESSION['user_id']]);
$deleted = $stmt->rowCount();

// Idempotent success
echo json_encode(['ok' => true, 'deleted' => (int)$deleted], JSON_UNESCAPED_SLASHES);
