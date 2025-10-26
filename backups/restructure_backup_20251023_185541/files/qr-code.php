<?php
/**
 * QR Code Generator - Working Version
 * 
 * File Path: /qr-code.php
 * Description: Generates QR codes for permit templates using chillerlan/php-qrcode v5
 * Created: 23/10/2025
 * Last Modified: 23/10/2025
 * 
 * Features:
 * - Generate QR codes for each template
 * - High-quality QR codes using chillerlan/php-qrcode
 * - Handles both numeric and string template IDs
 * - Automatic fallback if generation fails
 * - Download option
 * - Proper caching
 */

require __DIR__ . '/vendor/autoload.php';

use chillerlan\QRCode\{QRCode, QROptions};
use chillerlan\QRCode\Common\EccLevel;

[$app, $db, $root] = require __DIR__ . '/src/bootstrap.php';

// Get template ID from query string
$templateId = $_GET['template'] ?? null;
$size = intval($_GET['size'] ?? 300);
$download = isset($_GET['download']);

if (!$templateId) {
    header('Content-Type: image/png');
    $img = imagecreate(300, 300);
    $white = imagecolorallocate($img, 255, 255, 255);
    $black = imagecolorallocate($img, 0, 0, 0);
    imagefill($img, 0, 0, $white);
    imagestring($img, 5, 50, 145, 'Template ID Required', $black);
    imagepng($img);
    imagedestroy($img);
    exit;
}

// Get template details - handles both numeric IDs and slugs
$stmt = $db->pdo->prepare("SELECT * FROM form_templates WHERE id = ?");
$stmt->execute([$templateId]);
$template = $stmt->fetch();

if (!$template) {
    // Try as slug
    $stmt = $db->pdo->prepare("SELECT * FROM form_templates WHERE slug = ?");
    $stmt->execute([$templateId]);
    $template = $stmt->fetch();
}

if (!$template) {
    header('Content-Type: image/png');
    $img = imagecreate(300, 300);
    $white = imagecolorallocate($img, 255, 255, 255);
    $red = imagecolorallocate($img, 220, 53, 69);
    imagefill($img, 0, 0, $white);
    imagestring($img, 5, 40, 140, 'Template Not Found', $red);
    imagestring($img, 2, 60, 160, 'ID: ' . substr($templateId, 0, 20), $red);
    imagepng($img);
    imagedestroy($img);
    exit;
}

// Build the URL for permit creation
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || 
          (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') ||
          (!empty($_SERVER['HTTP_X_FORWARDED_SSL']) && $_SERVER['HTTP_X_FORWARDED_SSL'] === 'on') ? 'https://' : 'http://';
$permitUrl = $scheme . $_SERVER['HTTP_HOST'] . '/create-permit.php?template=' . urlencode($template['id']);

try {
    // QR Code options for v5.0+
    $options = new QROptions;
    
    // Basic settings
    $options->version = 5;
    $options->outputBase64 = false;
    $options->scale = max(3, intval($size / 100));
    
    // Error correction level
    $options->eccLevel = EccLevel::L;
    
    // Image settings
    $options->bgColor = [255, 255, 255];
    $options->imageTransparent = false;
    
    // Quiet zone
    $options->addQuietzone = true;
    $options->quietzoneSize = 2;
    
    // Output type - use the constant that works in your version
    if (defined('chillerlan\QRCode\Output\QROutputInterface::GDIMAGE_PNG')) {
        $options->outputType = \chillerlan\QRCode\Output\QROutputInterface::GDIMAGE_PNG;
    } else {
        // Fallback to string value that works
        $options->outputType = 'png';
    }
    
    // Generate QR code
    $qrcode = new QRCode($options);
    $qrImage = $qrcode->render($permitUrl);
    
    // Prepare filename
    $filename = 'qr-code-' . preg_replace('/[^a-z0-9-]/i', '-', strtolower($template['name'])) . '.png';
    
    // Set headers
    header('Content-Type: image/png');
    header('Cache-Control: public, max-age=86400');
    
    if ($download) {
        header('Content-Disposition: attachment; filename="' . $filename . '"');
    }
    
    header('Content-Length: ' . strlen($qrImage));
    
    // Output
    echo $qrImage;
    exit;
    
} catch (Exception $e) {
    // Log error
    error_log("QR Code generation error: " . $e->getMessage());
    error_log("Template ID: " . $templateId);
    error_log("Template: " . ($template ? $template['name'] : 'not found'));
    
    // Create error image
    header('Content-Type: image/png');
    $img = imagecreate($size, $size);
    $white = imagecolorallocate($img, 255, 255, 255);
    $red = imagecolorallocate($img, 220, 53, 69);
    $black = imagecolorallocate($img, 0, 0, 0);
    imagefill($img, 0, 0, $white);
    imagefilledrectangle($img, 10, $size/2 - 30, $size-10, $size/2 + 30, $red);
    imagestring($img, 5, 20, $size/2 - 15, 'QR Generation Error', $white);
    imagestring($img, 2, 20, $size/2 + 5, 'Check error logs', $black);
    imagepng($img);
    imagedestroy($img);
    exit;
}