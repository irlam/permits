<?php
// /api/push/subscribe.php
// Idempotent Web Push subscription endpoint.
// Expects: POST application/json with { endpoint, keys: { p256dh, auth } }
// Returns: { ok: true, id: "...", action: "created"|"updated" }

declare(strict_types=1);

use Permits\Csrf;
use Permits\PushSubscriptionValidator;

date_default_timezone_set('Europe/London');

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

// (Optional) relax CORS if you post from a subdomain. Adjust as needed.
// header('Access-Control-Allow-Origin: https://permits.defecttracker.uk');
// header('Vary: Origin');

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
[$app, $db] = require $root . '/src/bootstrap.php';
require_once $root . '/src/Auth.php';

function fail(int $code, string $msg): never {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_SLASHES);
    exit;
}

function read_json(): array {
    $maximumBytes = 16 * 1024;
    $declaredLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($declaredLength > $maximumBytes) {
        fail(413, 'Request too large');
    }

    $raw = file_get_contents('php://input', false, null, 0, $maximumBytes + 1) ?: '';
    if (strlen($raw) > $maximumBytes) {
        fail(413, 'Request too large');
    }
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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { fail(405, 'Method not allowed'); }
$auth = new Auth($db);
$currentUser = $auth->requireJson();
$userId = (string)$currentUser['id'];
if (!Csrf::validateRequest('push-subscription')) {
    fail(419, 'Page expired. Refresh the page and try again.');
}

$payload = read_json();

$endpoint = (string)($payload['endpoint'] ?? '');
$p256dh = (string)($payload['keys']['p256dh'] ?? '');
$authKey = (string)($payload['keys']['auth'] ?? '');

try {
    $validated = PushSubscriptionValidator::validate($endpoint, $p256dh, $authKey);
    $endpoint = $validated['endpoint'];
    $p256dh = $validated['p256dh'];
    $authKey = $validated['auth'];
} catch (InvalidArgumentException $exception) {
    fail(422, $exception->getMessage());
}

$endpointHash = hash('sha256', $endpoint);
$id = uuidv4();

// Upsert into MySQL by unique endpoint_hash
$pdo = $db->pdo;
$driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
$sql = $driver === 'mysql' ? "
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
" : "
INSERT INTO push_subscriptions (id,user_id,endpoint,endpoint_hash,p256dh,auth,created_at)
VALUES (:id,:user_id,:endpoint,:endpoint_hash,:p256dh,:auth,datetime('now'))
ON CONFLICT(endpoint_hash) DO UPDATE SET
user_id=excluded.user_id, endpoint=excluded.endpoint, p256dh=excluded.p256dh, auth=excluded.auth
";

$stmt = $pdo->prepare($sql);
$ok = $stmt->execute([
    ':id'            => $id,
    ':user_id'       => $userId,
    ':endpoint'      => $endpoint,
    ':endpoint_hash' => $endpointHash,
    ':p256dh'        => $p256dh,
    ':auth'          => $authKey,
]);

if (!$ok) {
    error_log('Push subscription database operation failed');
    fail(500, 'Unable to save subscription');
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
