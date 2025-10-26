<?php
// /api/push/subscribe.php
// Idempotent Web Push subscription endpoint.
// Expects: POST application/json with { endpoint, keys: { p256dh, auth } }
// Returns: { ok: true, id: "...", action: "created"|"updated" }

declare(strict_types=1);
date_default_timezone_set('Europe/London');

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

// (Optional) relax CORS if you post from a subdomain. Adjust as needed.
// header('Access-Control-Allow-Origin: https://permits.defecttracker.uk');
// header('Vary: Origin');

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
[$app, $db] = require $root . '/src/bootstrap.php';

session_start(); // to read user_id if your auth sets it
$userId = $_SESSION['user_id'] ?? null;

function fail(int $code, string $msg): never {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_SLASHES);
    exit;
}

function read_json(): array {
    $raw = file_get_contents('php://input') ?: '';
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        fail(400, 'Invalid JSON');
    }
    return $data;
}

function uuidv4(): string {
    $d = random_bytes(16);
    $d[6] = chr((ord($d[6]) & 0x0f) | 0x40); // version 4
    $d[8] = chr((ord($d[8]) & 0x3f) | 0x80); // variant
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
}

$payload = read_json();

$endpoint = trim((string)($payload['endpoint'] ?? ''));
$p256dh   = (string)($payload['keys']['p256dh'] ?? '');
$auth     = (string)($payload['keys']['auth'] ?? '');

if ($endpoint === '' || $p256dh === '' || $auth === '') {
    fail(422, 'Missing endpoint or keys');
}

// Basic length sanity to avoid oversized rows
if (strlen($p256dh) > 255 || strlen($auth) > 255) {
    fail(422, 'Key length too long');
}

$endpointHash = hash('sha256', $endpoint);
$id = uuidv4();

// Upsert into MySQL by unique endpoint_hash
$pdo = $db->pdo;
$driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
if ($driver !== 'mysql') {
    // You can add SQLite variant if you need dev runs
    fail(500, 'This endpoint requires MySQL');
}

$sql = "
INSERT INTO push_subscriptions
    (id, user_id, endpoint, endpoint_hash, p256dh, auth, created_at)
VALUES
    (:id, :user_id, :endpoint, :endpoint_hash, :p256dh, :auth, NOW())
ON DUPLICATE KEY UPDATE
    -- refresh keys in case they rotated
    p256dh = VALUES(p256dh),
    auth = VALUES(auth),
    -- keep the most recent known user (nullable)
    user_id = VALUES(user_id)
";

$stmt = $pdo->prepare($sql);
$ok = $stmt->execute([
    ':id'            => $id,
    ':user_id'       => $userId,
    ':endpoint'      => $endpoint,
    ':endpoint_hash' => $endpointHash,
    ':p256dh'        => $p256dh,
    ':auth'          => $auth,
]);

if (!$ok) {
    $err = $stmt->errorInfo();
    fail(500, 'DB error: ' . ($err[2] ?? 'unknown'));
}

// Determine whether we inserted or updated
// If a row existed, LAST_INSERT_ID won't change but MySQL still reports rowCount >= 1.
// We'll simply check if a row already existed by querying for the hash.
$check = $pdo->prepare("SELECT id FROM push_subscriptions WHERE endpoint_hash = :h LIMIT 1");
$check->execute([':h' => $endpointHash]);
$row = $check->fetch(PDO::FETCH_ASSOC);

$finalId = $row['id'] ?? $id;
$action  = ($finalId === $id) ? 'created' : 'updated';

echo json_encode([
    'ok'     => true,
    'id'     => $finalId,
    'action' => $action,
], JSON_UNESCAPED_SLASHES);
