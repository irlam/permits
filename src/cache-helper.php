<?php
/**
 * Cache Busting Helper
 *
 * Provides helpers for setting no-cache headers and generating
 * versioned asset URLs so browsers fetch updated CSS/JS when files change.
 */

// Set cache-busting headers (skip for CLI to keep cron output clean)
function set_no_cache_headers(): void
{
    if (PHP_SAPI === 'cli') {
        return;
    }

    if (!headers_sent()) {
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Cache-Control: post-check=0, pre-check=0', false);
        header('Pragma: no-cache');
        header('Expires: Sat, 01 Jan 2000 00:00:00 GMT');
    }
}

/**
 * Locate an asset on disk relative to the project or document root.
 */
function resolve_asset_path(string $path): ?string
{
    $normalized = '/' . ltrim($path, '/');

    static $root = null;
    if ($root === null) {
        $root = realpath(__DIR__ . '/..') ?: __DIR__ . '/..';
    }

    $candidates = [$root . $normalized];

    $envPublic = $_ENV['APP_PUBLIC_PATH'] ?? $_ENV['ASSET_PUBLIC_PATH'] ?? '';
    if ($envPublic !== '') {
        $candidates[] = rtrim($envPublic, '/') . $normalized;
    }

    $documentRoot = $_SERVER['DOCUMENT_ROOT'] ?? ($_ENV['DOCUMENT_ROOT'] ?? '');
    if ($documentRoot !== '') {
        $candidates[] = rtrim($documentRoot, '/') . $normalized;
    }

    foreach ($candidates as $candidate) {
        if (is_file($candidate)) {
            return $candidate;
        }
    }

    return null;
}

/**
 * Determine a cache-busting version string for an asset.
 */
function asset_version(string $path): string
{
    static $versionCache = [];

    if (isset($versionCache[$path])) {
        return $versionCache[$path];
    }

    if (preg_match('#^https?://#i', $path)) {
        $versionCache[$path] = defined('APP_ASSET_VERSION') ? APP_ASSET_VERSION : (defined('APP_VERSION') ? APP_VERSION : (string) time());
        return $versionCache[$path];
    }

    $resolved = resolve_asset_path($path);
    if ($resolved) {
        $versionCache[$path] = (string) filemtime($resolved);
        return $versionCache[$path];
    }

    if (defined('APP_ASSET_VERSION')) {
        $versionCache[$path] = APP_ASSET_VERSION;
        return $versionCache[$path];
    }

    if (defined('APP_VERSION')) {
        $versionCache[$path] = APP_VERSION;
        return $versionCache[$path];
    }

    $versionCache[$path] = (string) time();
    return $versionCache[$path];
}

/**
 * Build a versioned asset URL suitable for use in views.
 */
function asset(string $path): string
{
    $version = asset_version($path);

    if (preg_match('#^https?://#i', $path)) {
        $separator = strpos($path, '?') !== false ? '&' : '?';
        return $path . $separator . 'v=' . $version;
    }

    $baseUrl = rtrim((string) ($_ENV['APP_URL'] ?? ''), '/');
    $basePathRaw = (string) ($_ENV['APP_BASE_PATH'] ?? '/');
    $basePathTrim = trim($basePathRaw);
    if ($basePathTrim === '' || $basePathTrim === '/') {
        $basePath = '';
    } else {
        $basePath = '/' . trim($basePathTrim, '/');
    }

    $normalized = '/' . ltrim($path, '/');

    $href = ($baseUrl !== '' ? $baseUrl : '') . $basePath . $normalized;
    if ($href === '') {
        $href = $normalized;
    }

    $separator = strpos($href, '?') !== false ? '&' : '?';
    return $href . $separator . 'v=' . $version;
}

/**
 * Backwards-compatible alias for older templates that called asset_timestamp().
 */
function asset_timestamp(string $path): string
{
    return asset($path);
}

// Generate shared head tags for cache control and site identity.
function cache_meta_tags(): void
{
    echo '<meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">';
    echo '<meta http-equiv="Pragma" content="no-cache">';
    echo '<meta http-equiv="Expires" content="0">';

    $faviconUrl = htmlspecialchars(asset('/favicon.svg'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    echo '<link rel="icon" type="image/svg+xml" href="' . $faviconUrl . '">';
    echo '<link rel="shortcut icon" href="' . $faviconUrl . '">';

    // User-facing dates are stored in database/HTML-safe ISO formats but are
    // displayed consistently as UK dates (DD/MM/YYYY HH:MM). The script only
    // rewrites rendered text; form values, API values and database values stay
    // untouched for reliable validation and expiry calculations.
    $ukDateScript = htmlspecialchars(asset('/assets/uk-date-display.js'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    echo '<script src="' . $ukDateScript . '" defer></script>';

    // Page-specific progressive enhancements. Keep specialist assets off pages
    // that do not use them so admin and permit screens remain lean.
    $scriptName = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
    if ($scriptName === '' || $scriptName === 'index.php') {
        $homeCardCss = htmlspecialchars(asset('/assets/home-feature-card-polish.css'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $statusScript = htmlspecialchars(asset('/assets/phase3-status.js'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $pickerScript = htmlspecialchars(asset('/assets/phase3c-picker.js'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        echo '<link rel="stylesheet" href="' . $homeCardCss . '">';
        echo '<script src="' . $statusScript . '" defer></script>';
        echo '<script src="' . $pickerScript . '" defer></script>';
    }

    if ($scriptName === 'create-inspection-public.php') {
        $inspectionControls = htmlspecialchars(asset('/assets/inspection-controls.css'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        echo '<link rel="stylesheet" href="' . $inspectionControls . '">';
    }
}

// Call headers automatically when file is included
set_no_cache_headers();