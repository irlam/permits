<?php
/**
 * Cache Busting Helper
 * 
 * File Path: /src/cache-helper.php
 * Description: Helper functions to prevent browser caching issues
 * Created: 21/10/2025
 * Last Modified: 21/10/2025
 * 
 * Usage:
 * Include at top of any page:
 * require_once __DIR__ . '/src/cache-helper.php';
 * 
 * Then use in HTML:
 * <link rel="stylesheet" href="<?=asset('/assets/app.css')?>">
 * <script src="<?=asset('/assets/app.js')?>"></script>
 */

// Set cache-busting headers
function set_no_cache_headers() {
    if (!headers_sent()) {
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Cache-Control: post-check=0, pre-check=0', false);
        header('Pragma: no-cache');
        header('Expires: Sat, 01 Jan 2000 00:00:00 GMT');
    }
}

// Get asset URL with version number
function asset($path) {
    // Version number - increment this when you update CSS/JS
    $version = defined('APP_VERSION') ? APP_VERSION : '5.0.0';
    
    // Add version as query string
    $separator = strpos($path, '?') !== false ? '&' : '?';
    return $path . $separator . 'v=' . $version;
}

// Get timestamp-based version (alternative method)
function asset_timestamp($path) {
    $filePath = $_SERVER['DOCUMENT_ROOT'] . $path;
    
    // If file exists, use its modification time
    if (file_exists($filePath)) {
        $version = filemtime($filePath);
    } else {
        // Fallback to current time
        $version = time();
    }
    
    $separator = strpos($path, '?') !== false ? '&' : '?';
    return $path . $separator . 'v=' . $version;
}

// Generate meta tags to prevent caching
function cache_meta_tags() {
    echo '<meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">';
    echo '<meta http-equiv="Pragma" content="no-cache">';
    echo '<meta http-equiv="Expires" content="0">';
}

// Call headers automatically when file is included
set_no_cache_headers();