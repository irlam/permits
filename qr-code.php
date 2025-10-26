<?php
// qr-code.php — Generate a QR code PNG for a permit's public URL
// Usage examples:
//   /qr-code.php?id=xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx
//   /qr-code.php?link=PUBLIC_UNIQUE_TOKEN
//
// Requires composer library endroid/qr-code.
//   composer require endroid/qr-code:^5
//
// Notes:
// - Uses APP_URL from .env as base (e.g., https://permits.defecttracker.uk)
// - Falls back to current host if APP_URL is missing.

declare(strict_types=1);

date_default_timezone_set('Europe/London');

// Bootstrap app (PDO + ENV)
$root = __DIR__;
require_once $root . '/vendor/autoload.php';
[$app, $db] = require $root . '/src/bootstrap.php';

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel\ErrorCorrectionLevelHigh;
use Endroid\QrCode\RoundBlockSizeMode\RoundBlockSizeModeMargin;

// --- Helpers ---
function app_base_url(): string {
    $env = rtrim($_ENV['APP_URL'] ?? '', '/');
    if ($env !== '') return $env;
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost');
    return $scheme . '://' . $host;
}

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

// Fetch permit
$pdo = $db->pdo;
if ($id !== '') {
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

// Build the public view URL (this is the text encoded in the QR)
$base = app_base_url();
// If your public page route differs, adjust here:
$permitUrl = $base . '/view-permit-public.php?link=' . rawurlencode($permit['unique_link']);

// --- Generate QR ---
try {
    // Important: do NOT send any output before headers (no BOM / echo / whitespace)
    $builder = Builder::create()
        ->data($permitUrl)
        ->encoding(new Encoding('UTF-8'))
        ->errorCorrectionLevel(new ErrorCorrectionLevelHigh())
        ->roundBlockSizeMode(new RoundBlockSizeModeMargin())
        ->size(512)           // overall image size in px
        ->margin(16)          // white border around the QR
        ->build();

    $png = $builder->getString(); // PNG binary

    // Caching headers (immutable — change URL or bust cache when contents change)
    header('Content-Type: image/png');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: public, max-age=31536000, immutable');
    $lastMod = gmdate('D, d M Y H:i:s') . ' GMT';
    header('Last-Modified: ' . $lastMod);

    echo $png;
    exit;

} catch (Throwable $e) {
    // Library not installed or other render error
    fail(
        "QR generation error: " . $e->getMessage() .
        "\nHint: install the QR library with:\n  composer require endroid/qr-code:^5\n\n" .
        "You can still use this URL directly:\n" . $permitUrl,
        500
    );
}
