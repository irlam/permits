<?php
declare(strict_types=1);

use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Permits\PublicStartCatalog;

[$app, $db] = require __DIR__ . '/src/bootstrap.php';

$slug = isset($_GET['slug']) && is_scalar($_GET['slug']) ? strtolower(trim((string)$_GET['slug'])) : '';
$template = $slug !== '' ? PublicStartCatalog::findBySlug($db->pdo, $slug) : null;
if ($template === null) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'QR code unavailable';
    exit;
}

$size = isset($_GET['size']) ? (int)$_GET['size'] : 640;
$size = max(180, min(1600, $size));
$scale = max(4, min(40, (int)round($size / 45)));
$url = $app->url('/start/' . rawurlencode($slug));

try {
    $options = new QROptions([
        'outputType' => QRCode::OUTPUT_IMAGE_PNG,
        'eccLevel' => QRCode::ECC_H,
        'scale' => $scale,
        'quietzoneSize' => max(4, (int)round($scale * 0.8)),
        'imageBase64' => false,
    ]);
    $png = (new QRCode($options))->render($url);

    header('Content-Type: image/png');
    header('X-Content-Type-Options: nosniff');
    header("Content-Security-Policy: default-src 'none'");
    header('Cache-Control: public, max-age=300, must-revalidate');
    header('Content-Length: ' . strlen($png));

    if (!empty($_GET['download'])) {
        header('Content-Disposition: attachment; filename="start-' . $slug . '-qr.png"');
    }

    echo $png;
    exit;
} catch (Throwable $e) {
    error_log('Start QR generation failed: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Unable to generate QR code';
}
