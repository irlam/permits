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

/** 1b) Cache helper (no-cache headers + asset() helper) */
require_once __DIR__ . '/cache-helper.php';

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
$configuredTimezone = (string)($_ENV['APP_TIMEZONE'] ?? ($_ENV['TIMEZONE'] ?? 'Europe/London'));
if (!in_array($configuredTimezone, \DateTimeZone::listIdentifiers(), true)) {
    error_log('Invalid APP_TIMEZONE configured; falling back to Europe/London.');
    $configuredTimezone = 'Europe/London';
}
\date_default_timezone_set($configuredTimezone);
$_ENV['APP_TIMEZONE'] = $configuredTimezone;
if (\function_exists('mb_internal_encoding')) {
    \mb_internal_encoding('UTF-8');
}

/** 5) Normalise important paths/URLs */
$APP_URL = rtrim((string)($_ENV['APP_URL'] ?? ''), '/');
if ($APP_URL !== '') {
    $parsedAppUrl = @parse_url($APP_URL);
    $validAppUrl = is_array($parsedAppUrl)
        && in_array(strtolower((string) ($parsedAppUrl['scheme'] ?? '')), ['http', 'https'], true)
        && filter_var((string) ($parsedAppUrl['host'] ?? ''), FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false
        && !isset($parsedAppUrl['user'], $parsedAppUrl['pass'], $parsedAppUrl['query'], $parsedAppUrl['fragment']);
    if (!$validAppUrl) {
        error_log('Invalid APP_URL configured; using a safe local fallback until configuration is corrected.');
        $APP_URL = '';
    }
}
if ($APP_URL === '') {
    // HTTP_HOST is request-controlled and must never influence emailed links or QR codes.
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = strtolower(trim((string) ($_SERVER['SERVER_NAME'] ?? 'localhost')));
    if (filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
        $host = 'localhost';
    }
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
$idleTimeout    = filter_var($_ENV['SESSION_IDLE_TIMEOUT'] ?? 7200, FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 300, 'max_range' => 86400],
]);
if ($idleTimeout === false) {
    $idleTimeout = 7200;
}
$_ENV['SESSION_IDLE_TIMEOUT'] = (string)$idleTimeout;

// Apply the protections before any entry point starts a session. Strict mode
// rejects attacker-supplied IDs; cookies-only prevents IDs leaking into URLs.
@\ini_set('session.use_strict_mode', '1');
@\ini_set('session.use_only_cookies', '1');
@\ini_set('session.use_trans_sid', '0');
@\ini_set('session.cookie_httponly', $cookieHttpOnly ? '1' : '0');
@\ini_set('session.gc_maxlifetime', (string)$idleTimeout);

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
        $baseUrl = rtrim((string)($this->config['APP_URL'] ?? ''), '/');
        $basePathRaw = (string)($this->config['APP_BASE_PATH'] ?? '/');
        $basePath = trim($basePathRaw);
        if ($basePath === '' || $basePath === '/') {
            $basePath = '';
        } else {
            $basePath = '/' . trim($basePath, '/');
        }

        $normalizedPath = ltrim($path, '/');

        if ($normalizedPath === '') {
            return ($baseUrl !== '' ? $baseUrl : '') . ($basePath !== '' ? $basePath . '/' : '/');
        }

        return ($baseUrl !== '' ? $baseUrl : '') . $basePath . '/' . $normalizedPath;
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
    'APP_TIMEZONE'  => $configuredTimezone,

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

// Non-secret regional settings may be managed from the admin panel. Environment
// values remain the fallback when the settings table is unavailable.
try {
    $runtimeSettings = SystemSettings::load($db, ['app_timezone'], [
        'app_timezone' => $configuredTimezone,
    ]);
    $runtimeTimezone = (string)($runtimeSettings['app_timezone'] ?? $configuredTimezone);
    if (in_array($runtimeTimezone, timezone_identifiers_list(), true)) {
        date_default_timezone_set($runtimeTimezone);
        $_ENV['APP_TIMEZONE'] = $runtimeTimezone;
        if ($db->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $runtimeOffset = date('P');
            if (preg_match('/^[+-](?:0\d|1[0-4]):[0-5]\d$/', $runtimeOffset) === 1) {
                $db->pdo->exec('SET SESSION time_zone = ' . $db->pdo->quote($runtimeOffset));
            }
        }
    }
} catch (Throwable $e) {
    error_log('Unable to load runtime regional settings: ' . $e->getMessage());
}

// Make logging helpers available consistently across entry points.
require_once __DIR__ . '/ActivityLogger.php';

/** 10) Return tuple for includes */
return [$app, $db, $root];
