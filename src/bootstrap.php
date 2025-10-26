<?php
declare(strict_types=1);

/**
 * src/bootstrap.php
 * ------------------
 * Safe, deterministic app bootstrap:
 *  - Loads composer + .env
 *  - Configures error reporting based on APP_DEBUG
 *  - Sets timezone, multibyte encoding
 *  - Normalises APP_URL / APP_BASE_PATH
 *  - Prepares secure session cookie settings (does NOT start a session)
 *  - Creates PDO via Permits\Db
 *  - Returns [$app, $db, $root]
 */

namespace Permits;

use Dotenv\Dotenv;
use Throwable;

$root = \realpath(__DIR__ . '/..') ?: __DIR__ . '/..';

/** 1) Composer autoload */
require_once $root . '/vendor/autoload.php';

/** 2) Load environment (.env) early */
try {
    if (\is_file($root . '/.env')) {
        $dotenv = Dotenv::createImmutable($root);
        $dotenv->safeLoad();
    }
} catch (Throwable $e) {
    // Don't hard-fail here; we can continue with server env vars.
    error_log('ENV load warning: ' . $e->getMessage());
}

/** 3) App debug / error reporting */
$debug = filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOLEAN);
if ($debug) {
    \ini_set('display_errors', '1');
    \error_reporting(E_ALL);
} else {
    \ini_set('display_errors', '0');
    \error_reporting(E_ALL & ~E_NOTICE & ~E_STRICT & ~E_DEPRECATED);
}

/** 4) Timezone + mbstring */
\date_default_timezone_set((string)($_ENV['APP_TIMEZONE'] ?? 'Europe/London'));
if (\function_exists('mb_internal_encoding')) {
    \mb_internal_encoding('UTF-8');
}

/** 5) Normalise important paths/URLs */
$APP_URL = rtrim((string)($_ENV['APP_URL'] ?? ''), '/');
if ($APP_URL === '') {
    // Fallback to current host if APP_URL is not set (should be set in prod!)
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost');
    $APP_URL = $scheme . '://' . $host;
}
$_ENV['APP_URL'] = $APP_URL;

$APP_BASE_PATH = (string)($_ENV['APP_BASE_PATH'] ?? '/');
if ($APP_BASE_PATH === '' || $APP_BASE_PATH[0] !== '/') {
    $APP_BASE_PATH = '/' . ltrim($APP_BASE_PATH, '/');
}
$_ENV['APP_BASE_PATH'] = rtrim($APP_BASE_PATH, '/') . '/'; // always end with slash

/** 6) Secure session cookie defaults (no session_start() here) */
$cookieSecure   = filter_var($_ENV['SESSION_COOKIE_SECURE']   ?? true, FILTER_VALIDATE_BOOLEAN);
$cookieHttpOnly = filter_var($_ENV['SESSION_COOKIE_HTTPONLY'] ?? true, FILTER_VALIDATE_BOOLEAN);
$sameSite       = (string)($_ENV['SESSION_COOKIE_SAMESITE'] ?? 'Lax');
$sessionName    = (string)($_ENV['SESSION_NAME'] ?? 'permits_session');

if (\PHP_VERSION_ID >= 70300) {
    @\session_name($sessionName);
    @\session_set_cookie_params([
        'lifetime' => 0,
        'path'     => $_ENV['APP_BASE_PATH'] ?? '/',
        'domain'   => '', // default host only
        'secure'   => $cookieSecure,
        'httponly' => $cookieHttpOnly,
        'samesite' => \in_array($sameSite, ['Lax','Strict','None'], true) ? $sameSite : 'Lax',
    ]);
} else {
    // Legacy fallback
    @\ini_set('session.cookie_secure',   $cookieSecure ? '1' : '0');
    @\ini_set('session.cookie_httponly', $cookieHttpOnly ? '1' : '0');
    @\ini_set('session.cookie_samesite', \in_array($sameSite, ['Lax','Strict','None'], true) ? $sameSite : 'Lax');
    @\session_name($sessionName);
}

/** 7) Tiny App helper */
final class App
{
    /** @var array<string,mixed> */
    private array $config;

    /**
     * @param array<string,mixed> $config
     */
    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /** Get a config value with optional default. */
    public function config(string $key, $default = null)
    {
        return $this->config[$key] ?? $default;
    }

    /** Build an absolute URL from a path (respects APP_URL + APP_BASE_PATH). */
    public function url(string $path = '/'): string
    {
        $baseUrl  = rtrim((string)($this->config['APP_URL'] ?? ''), '/');
        $basePath = (string)($this->config['APP_BASE_PATH'] ?? '/');
        $path     = ltrim($path, '/');

        return $baseUrl . rtrim($basePath, '/') . '/' . $path;
    }
}

/** 8) Build config array from ENV (read-only snapshot) */
$appConfig = [
    // app
    'APP_NAME'      => $_ENV['APP_NAME']      ?? 'Defect Tracker Permits',
    'APP_ENV'       => $_ENV['APP_ENV']       ?? 'production',
    'APP_DEBUG'     => $debug,
    'APP_URL'       => $_ENV['APP_URL'],
    'APP_BASE_PATH' => $_ENV['APP_BASE_PATH'],
    'APP_TIMEZONE'  => $_ENV['APP_TIMEZONE']  ?? 'Europe/London',

    // db
    'DB_DRIVER'     => $_ENV['DB_DRIVER']     ?? 'mysql',
    'DB_HOST'       => $_ENV['DB_HOST']       ?? '127.0.0.1',
    'DB_PORT'       => $_ENV['DB_PORT']       ?? '3306',
    'DB_DATABASE'    => $_ENV['DB_DATABASE']   ?? '',
    'DB_USERNAME'    => $_ENV['DB_USERNAME']   ?? '',
    'DB_PASSWORD'    => $_ENV['DB_PASSWORD']   ?? '',
    'DB_CHARSET'     => $_ENV['DB_CHARSET']    ?? 'utf8mb4',
    'DB_COLLATION'   => $_ENV['DB_COLLATION']  ?? 'utf8mb4_unicode_ci',

    // mail (available for whichever mailer you use)
    'MAIL_DRIVER'   => $_ENV['MAIL_DRIVER']   ?? 'smtp',
    'MAIL_HOST'     => $_ENV['MAIL_HOST']     ?? '',
    'MAIL_PORT'     => $_ENV['MAIL_PORT']     ?? '',
    'MAIL_ENCRYPTION'=>$_ENV['MAIL_ENCRYPTION']?? 'ssl',
    'MAIL_USERNAME' => $_ENV['MAIL_USERNAME'] ?? '',
    'MAIL_PASSWORD' => $_ENV['MAIL_PASSWORD'] ?? '',
    'MAIL_FROM_ADDRESS'=> $_ENV['MAIL_FROM_ADDRESS'] ?? '',
    'MAIL_FROM_NAME'   => $_ENV['MAIL_FROM_NAME']    ?? '',

    // push
    'VAPID_PUBLIC_KEY'  => $_ENV['VAPID_PUBLIC_KEY']  ?? '',
    'VAPID_PRIVATE_KEY' => $_ENV['VAPID_PRIVATE_KEY'] ?? '',
    'VAPID_SUBJECT'     => $_ENV['VAPID_SUBJECT']     ?? '',
];

/** 9) Create App + DB */
$app = new App($appConfig);

// Db class should be the hardened version we sent earlier
require_once __DIR__ . '/Db.php';
$db = new Db();

// Make logging helpers available consistently across entry points.
require_once __DIR__ . '/ActivityLogger.php';

/** 10) Return tuple for includes */
return [$app, $db, $root];
