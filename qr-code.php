<?php
// qr-code.php — Generate a QR code PNG for a permit's public URL
// Usage examples:
//   /qr-code.php?id=xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx
//   /qr-code.php?link=PUBLIC_UNIQUE_TOKEN
//
// Uses chillerlan/php-qrcode for generation. Install/update via:
//   composer require chillerlan/php-qrcode:^5
//
// Notes:
// - Uses APP_URL from .env as base (e.g., https://permits.defecttracker.uk)
// - Falls back to current host if APP_URL is missing.

declare(strict_types=1);

use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

// Bootstrap app (PDO + ENV)
$root = __DIR__;
require_once $root . '/vendor/autoload.php';
[$app, $db] = require $root . '/src/bootstrap.php';
require_once $root . '/src/Auth.php';

header('Cache-Control: private, no-store, max-age=0');
header('Pragma: no-cache');

function not_found(string $msg = 'Not found'): void {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo $msg;
    exit;
}

function fail(string $msg, int $code = 500): void {
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    echo $msg;
    exit;
}

// --- Input: find permit by id or public link ---
$id   = isset($_GET['id'])   ? trim((string)$_GET['id'])   : '';
$link = isset($_GET['link']) ? trim((string)$_GET['link']) : '';

if ($id === '' && $link === '') {
    not_found('Missing id or link parameter');
}
if ($link !== '' && (strlen($link) < 32 || strlen($link) > 100)) {
    not_found('Permit not found');
}

// Fetch permit
$pdo = $db->pdo;
if ($id !== '') {
    // Database IDs are an administrative lookup mechanism. Public QR requests
    // must use the unguessable permit link.
    $auth = new Auth($db);
    if ($auth->userForRoles(['manager', 'admin']) === null) {
        not_found('Permit not found');
    }
    $stmt = $pdo->prepare("SELECT id, ref_number, unique_link FROM forms WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $id]);
} else {
    $stmt = $pdo->prepare("SELECT id, ref_number, unique_link FROM forms WHERE unique_link = :ul LIMIT 1");
    $stmt->execute([':ul' => $link]);
}
$permit = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$permit) {
    not_found('Permit not found');
}

// Build the canonical public view URL (this is the text encoded in the QR).
$permitUrl = $app->url('/view-permit-public.php?link=' . rawurlencode((string) $permit['unique_link']));

// --- Generate QR ---
try {
    $sizeParam = isset($_GET['size']) ? (int)$_GET['size'] : 512;
    $sizeParam = max(120, min(1600, $sizeParam));

    // Each module gets scaled; this keeps the final bitmap within ~requested size.
    $scale = max(2, min(40, (int)round($sizeParam / 45)));
    $quietzone = max(4, (int)round($scale));

    $options = new QROptions([
        'outputType'   => QRCode::OUTPUT_IMAGE_PNG,
        'eccLevel'     => QRCode::ECC_H,
        'scale'        => $scale,
        'quietzoneSize'=> $quietzone,
        'imageBase64'  => false,
    ]);

    $png = (new QRCode($options))->render($permitUrl);

    $download = isset($_GET['download']) && $_GET['download'] !== '0';
    $filename = 'permit-' . preg_replace('/[^a-z0-9]+/i', '-', $permit['ref_number'] ?? $permit['id']) . '.png';

    // Explicit download and length headers make mobile/browser downloads
    // reliable. QR responses are always private and non-cacheable because they
    // encode a bearer-style permit URL.
    header('Content-Type: image/png');
    header('X-Content-Type-Options: nosniff');
    header("Content-Security-Policy: default-src 'none'");
    header('Content-Length: ' . strlen($png));
    if ($download) {
        header('Content-Disposition: attachment; filename="' . $filename . '"');
    }

    echo $png;
    exit;

} catch (Throwable $e) {
    error_log('QR generation failed: ' . $e->getMessage());
    fail('Unable to generate this QR code. Please try again or contact an administrator.', 500);
}
