<?php
namespace Permits;

use PDO;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Lightweight settings helper that reads/writes key/value pairs stored in the
 * `settings` table. This keeps configuration changes made through the admin UI
 * in sync with application services like the Mailer.
 */
final class SystemSettings
{
    public const DEFAULT_COMPANY_NAME = 'Permits System';
    public const DEFAULT_PRIMARY_COLOUR = '#0ea5e9';

    /**
     * Fetch an associative array of settings. When $keys is empty the entire
     * table is loaded. Missing keys fall back to the provided $defaults map.
     *
     * @param array<int,string> $keys
     * @param array<string,string> $defaults
     * @return array<string,string>
     */
    public static function load(Db $db, array $keys = [], array $defaults = []): array
    {
        $pdo = $db->pdo;

        if (!self::settingsTableExists($pdo)) {
            if (!empty($defaults)) {
                return $defaults;
            }
            return [];
        }

        if (empty($keys)) {
            $stmt = $pdo->query('SELECT `key`, value FROM settings');
        } else {
            $placeholders = implode(',', array_fill(0, count($keys), '?'));
            $stmt = $pdo->prepare('SELECT `key`, value FROM settings WHERE `key` IN (' . $placeholders . ')');
            $stmt->execute($keys);
        }

        $settings = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $settings[$row['key']] = (string)$row['value'];
        }

        return array_replace($defaults, $settings);
    }

    /**
     * Load the small set of values used to brand the user interface.
     *
     * Values are normalised here so every page receives the same safe name,
     * logo path and CSS colour palette even if legacy database values exist.
     *
     * @return array{
     *   company_name:string,
     *   company_logo_path:?string,
     *   primary_colour:string,
     *   primary_colour_light:string,
     *   primary_colour_dark:string,
     *   primary_text_colour:string,
     *   primary_colour_rgb:string,
     *   primary_colour_light_rgb:string
     * }
     */
    public static function branding(Db $db, string $defaultName = self::DEFAULT_COMPANY_NAME): array
    {
        try {
            $settings = self::load(
                $db,
                ['company_name', 'company_logo_path', 'brand_primary_colour'],
                []
            );
        } catch (Throwable $e) {
            $settings = [];
        }

        $primary = self::normalisePrimaryColour($settings['brand_primary_colour'] ?? null);
        $light = self::shiftColour($primary, 0.18);
        $dark = self::shiftColour($primary, -0.22);

        return [
            'company_name' => self::normaliseCompanyName($settings['company_name'] ?? null, $defaultName),
            'company_logo_path' => self::normaliseLogoPath($settings['company_logo_path'] ?? null),
            'primary_colour' => $primary,
            'primary_colour_light' => $light,
            'primary_colour_dark' => $dark,
            'primary_text_colour' => self::contrastTextColour($primary),
            'primary_colour_rgb' => self::hexToRgbString($primary),
            'primary_colour_light_rgb' => self::hexToRgbString($light),
        ];
    }

    /** Convenience accessor retained for existing pages. */
    public static function companyName(Db $db): ?string
    {
        try {
            $settings = self::load($db, ['company_name'], []);
        } catch (Throwable $e) {
            return null;
        }

        $name = trim((string)($settings['company_name'] ?? ''));
        return $name !== '' ? self::normaliseCompanyName($name) : null;
    }

    /**
     * Return a validated relative path to a configured raster logo.
     */
    public static function companyLogoPath(Db $db): ?string
    {
        try {
            $settings = self::load($db, ['company_logo_path'], []);
        } catch (Throwable $e) {
            return null;
        }

        return self::normaliseLogoPath($settings['company_logo_path'] ?? null);
    }

    public static function primaryColour(Db $db): string
    {
        try {
            $settings = self::load($db, ['brand_primary_colour'], []);
        } catch (Throwable $e) {
            return self::DEFAULT_PRIMARY_COLOUR;
        }

        return self::normalisePrimaryColour($settings['brand_primary_colour'] ?? null);
    }

    public static function normaliseCompanyName(?string $value, string $fallback = self::DEFAULT_COMPANY_NAME): string
    {
        $name = trim((string)$value);
        $name = preg_replace('/[\p{C}\s]+/u', ' ', $name) ?? '';
        if ($name === '') {
            $name = trim($fallback) !== '' ? trim($fallback) : self::DEFAULT_COMPANY_NAME;
        }

        return mb_substr($name, 0, 120, 'UTF-8');
    }

    public static function normaliseLogoPath(?string $value): ?string
    {
        $path = ltrim(trim((string)$value), '/');
        if ($path === '') {
            return null;
        }

        // Logos are served as images from one dedicated directory. Disallow
        // nested paths, traversal and executable/vector formats.
        if (preg_match('#^uploads/branding/[A-Za-z0-9][A-Za-z0-9._-]*\.(?:png|jpe?g|webp)$#i', $path) !== 1) {
            return null;
        }

        return $path;
    }

    public static function normalisePrimaryColour(?string $value): string
    {
        $colour = strtolower(trim((string)$value));
        if (preg_match('/^#[0-9a-f]{6}$/', $colour) !== 1) {
            return self::DEFAULT_PRIMARY_COLOUR;
        }

        return $colour;
    }

    /**
     * Build a safe inline CSS custom-property declaration for branded pages.
     *
     * @param array<string,mixed> $branding
     */
    public static function brandingCssVariables(array $branding): string
    {
        $primary = self::normalisePrimaryColour((string)($branding['primary_colour'] ?? ''));
        $light = self::normalisePrimaryColour((string)($branding['primary_colour_light'] ?? ''));
        $dark = self::normalisePrimaryColour((string)($branding['primary_colour_dark'] ?? ''));

        if ($light === self::DEFAULT_PRIMARY_COLOUR && $primary !== self::DEFAULT_PRIMARY_COLOUR) {
            $light = self::shiftColour($primary, 0.18);
        }
        if ($dark === self::DEFAULT_PRIMARY_COLOUR && $primary !== self::DEFAULT_PRIMARY_COLOUR) {
            $dark = self::shiftColour($primary, -0.22);
        }

        return sprintf(
            '--brand-primary:%s;--brand-primary-light:%s;--brand-primary-dark:%s;--brand-on-primary:%s;--brand-primary-rgb:%s;--brand-primary-light-rgb:%s',
            $primary,
            $light,
            $dark,
            self::contrastTextColour($primary),
            self::hexToRgbString($primary),
            self::hexToRgbString($light)
        );
    }

    /**
     * Persist a batch of settings. Values are stored as plain text.
     *
     * @param array<string,string> $values
     */
    public static function save(Db $db, array $values): void
    {
        if ($values === []) {
            return;
        }

        $pdo = $db->pdo;

        if (!self::settingsTableExists($pdo)) {
            throw new RuntimeException('Settings table is missing. Import the current database schema, then run php bin/migrate.php');
        }

        $stmt = $pdo->prepare(
            'REPLACE INTO settings (`key`, value, updated_at) VALUES (?, ?, CURRENT_TIMESTAMP)'
        );

        foreach ($values as $key => $value) {
            $stmt->execute([$key, $value]);
        }
    }

    /**
     * Convenience helper that returns Mailer constructor overrides sourced from
     * persisted settings. Environment values are still honoured as fallbacks.
     *
     * @return array<string,mixed>
     */
    public static function mailerOptions(Db $db): array
    {
        $keys = [
            'email_enabled', 'mail_driver', 'smtp_host', 'smtp_port',
            'smtp_user', 'smtp_pass', 'smtp_secure', 'smtp_timeout',
            'mail_from_address', 'mail_from_name', 'smtp_from',
        ];
        try {
            $settings = self::load($db, $keys, []);
        } catch (Throwable $e) {
            error_log('[Permits email settings] Unable to read configuration: ' . $e->getMessage());
            return ['enabled' => false];
        }

        // If no database-backed mail value exists, allow the .env settings to
        // provide the complete configuration. Mailer itself still defaults to
        // disabled when EMAIL_ENABLED is absent.
        if ($settings === []) {
            return [];
        }

        $storedOrEnvironment = static function (string $key, string $environmentKey, string $default = '') use ($settings): string {
            return array_key_exists($key, $settings)
                ? (string)$settings[$key]
                : (string)($_ENV[$environmentKey] ?? $default);
        };

        $legacyFrom = array_key_exists('smtp_from', $settings) ? (string)$settings['smtp_from'] : '';
        $effective = [
            'email_enabled' => $storedOrEnvironment('email_enabled', 'EMAIL_ENABLED', 'false'),
            'mail_driver' => $storedOrEnvironment('mail_driver', 'MAIL_DRIVER', 'smtp'),
            'smtp_host' => $storedOrEnvironment('smtp_host', 'MAIL_HOST', (string)($_ENV['SMTP_HOST'] ?? '')),
            'smtp_port' => $storedOrEnvironment('smtp_port', 'MAIL_PORT', (string)($_ENV['SMTP_PORT'] ?? '587')),
            'smtp_user' => $storedOrEnvironment('smtp_user', 'MAIL_USERNAME', (string)($_ENV['SMTP_USER'] ?? '')),
            'smtp_pass' => $storedOrEnvironment('smtp_pass', 'MAIL_PASSWORD', (string)($_ENV['SMTP_PASS'] ?? '')),
            'smtp_secure' => $storedOrEnvironment('smtp_secure', 'MAIL_ENCRYPTION', (string)($_ENV['SMTP_SECURE'] ?? 'tls')),
            'smtp_timeout' => $storedOrEnvironment('smtp_timeout', 'MAIL_TIMEOUT', (string)($_ENV['SMTP_TIMEOUT'] ?? '30')),
            'mail_from_address' => array_key_exists('mail_from_address', $settings)
                ? (string)$settings['mail_from_address']
                : ($legacyFrom !== '' ? $legacyFrom : (string)($_ENV['MAIL_FROM_ADDRESS'] ?? $_ENV['MAIL_FROM'] ?? '')),
            'mail_from_name' => $storedOrEnvironment('mail_from_name', 'MAIL_FROM_NAME', 'Permits System'),
        ];

        try {
            $normalised = self::normaliseMailerSettings($effective, (string)$effective['smtp_pass']);
        } catch (InvalidArgumentException $e) {
            // A malformed legacy value must fail closed. In particular, never
            // fall back to an environment transport that could send mail when
            // the administrator has disabled database-backed delivery.
            error_log('[Permits email settings] Invalid stored configuration: ' . $e->getMessage());
            return ['enabled' => false];
        }

        return [
            'enabled' => $normalised['email_enabled'] === 'true',
            'driver' => $normalised['mail_driver'],
            'smtp_host' => $normalised['smtp_host'],
            'smtp_port' => (int)$normalised['smtp_port'],
            'smtp_user' => $normalised['smtp_user'],
            'smtp_pass' => $normalised['smtp_pass'],
            'smtp_secure' => $normalised['smtp_secure'],
            'smtp_timeout' => (int)$normalised['smtp_timeout'],
            'from' => $normalised['mail_from_address'],
            'from_name' => $normalised['mail_from_name'],
        ];
    }

    /**
     * Validate and normalise values accepted by the email settings page.
     *
     * The returned shape is suitable for persistence. Disabled delivery may
     * retain incomplete SMTP details so an administrator can save work in
     * progress, but enabled delivery always requires a valid sender and (for
     * SMTP) a valid host.
     *
     * @param array<string,mixed> $values
     * @return array<string,string>
     */
    public static function normaliseMailerSettings(array $values, string $existingPassword = ''): array
    {
        $enabled = filter_var(
            $values['email_enabled'] ?? false,
            FILTER_VALIDATE_BOOLEAN,
            FILTER_NULL_ON_FAILURE
        );
        if ($enabled === null) {
            throw new InvalidArgumentException('Email enabled setting is invalid.');
        }

        $driver = strtolower(self::scalarString($values['mail_driver'] ?? 'smtp'));
        if (!in_array($driver, ['smtp', 'mail', 'log'], true)) {
            throw new InvalidArgumentException('Unsupported email delivery method.');
        }

        $host = trim(self::scalarString($values['smtp_host'] ?? ''));
        if ($host !== '' && !self::isValidSmtpHost($host)) {
            throw new InvalidArgumentException('SMTP host is invalid.');
        }
        if ($enabled && $driver === 'smtp' && $host === '') {
            throw new InvalidArgumentException('SMTP host is required.');
        }

        $port = self::boundedInteger($values['smtp_port'] ?? 587, 1, 65535, 'SMTP port');
        $timeout = self::boundedInteger($values['smtp_timeout'] ?? 30, 5, 120, 'SMTP timeout');

        $smtpUser = trim(self::scalarString($values['smtp_user'] ?? ''));
        self::assertSingleLine($smtpUser, 255, 'SMTP username');

        $submittedPassword = self::scalarString($values['smtp_pass'] ?? '');
        $smtpPass = $submittedPassword !== '' ? $submittedPassword : $existingPassword;
        if (strlen($smtpPass) > 4096) {
            throw new InvalidArgumentException('SMTP password is too long.');
        }

        $secure = strtolower(trim(self::scalarString($values['smtp_secure'] ?? 'tls')));
        if ($secure === 'none') {
            $secure = '';
        }
        if (!in_array($secure, ['', 'tls', 'ssl'], true)) {
            throw new InvalidArgumentException('SMTP encryption setting is invalid.');
        }

        $fromAddress = trim(self::scalarString($values['mail_from_address'] ?? ''));
        self::assertSingleLine($fromAddress, 254, 'From email address');
        if ($fromAddress !== '' && filter_var($fromAddress, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('From email address is invalid.');
        }
        if ($enabled && $fromAddress === '') {
            throw new InvalidArgumentException('From email address is required.');
        }

        $fromName = trim(self::scalarString($values['mail_from_name'] ?? 'Permits System'));
        self::assertSingleLine($fromName, 120, 'From name');
        if ($fromName === '') {
            $fromName = 'Permits System';
        }

        return [
            'email_enabled' => $enabled ? 'true' : 'false',
            'mail_driver' => $driver,
            'smtp_host' => $host,
            'smtp_port' => (string)$port,
            'smtp_user' => $smtpUser,
            'smtp_pass' => $smtpPass,
            'smtp_secure' => $secure,
            'smtp_timeout' => (string)$timeout,
            'mail_from_address' => $fromAddress,
            'mail_from_name' => $fromName,
        ];
    }

    private static function scalarString(mixed $value): string
    {
        if (!is_scalar($value) && $value !== null) {
            throw new InvalidArgumentException('Email setting has an invalid value.');
        }

        return (string)$value;
    }

    private static function boundedInteger(mixed $value, int $minimum, int $maximum, string $label): int
    {
        if (is_int($value)) {
            $integer = $value;
        } elseif (is_string($value) && preg_match('/^\d+$/', trim($value)) === 1) {
            $integer = (int)trim($value);
        } else {
            throw new InvalidArgumentException($label . ' must be a whole number.');
        }

        if ($integer < $minimum || $integer > $maximum) {
            throw new InvalidArgumentException(sprintf('%s must be between %d and %d.', $label, $minimum, $maximum));
        }

        return $integer;
    }

    private static function assertSingleLine(string $value, int $maximumLength, string $label): void
    {
        if (preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new InvalidArgumentException($label . ' must be a single line.');
        }
        if (mb_strlen($value, 'UTF-8') > $maximumLength) {
            throw new InvalidArgumentException($label . ' is too long.');
        }
    }

    private static function isValidSmtpHost(string $host): bool
    {
        if (strlen($host) > 253 || preg_match('#[\x00-\x20\x7F/\\\\]#', $host) === 1) {
            return false;
        }

        $ip = $host;
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $ip = substr($host, 1, -1);
        }
        if (filter_var($ip, FILTER_VALIDATE_IP) !== false) {
            return true;
        }

        return preg_match(
            '/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)(?:\.(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?))*$/i',
            $host
        ) === 1;
    }

    private static function settingsTableExists(PDO $pdo): bool
    {
        try {
            $pdo->query('SELECT 1 FROM settings LIMIT 1');
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    private static function shiftColour(string $hex, float $amount): string
    {
        $hex = ltrim(self::normalisePrimaryColour($hex), '#');
        $channels = [
            hexdec(substr($hex, 0, 2)),
            hexdec(substr($hex, 2, 2)),
            hexdec(substr($hex, 4, 2)),
        ];

        foreach ($channels as &$channel) {
            if ($amount >= 0) {
                $channel = (int)round($channel + ((255 - $channel) * min(1, $amount)));
            } else {
                $channel = (int)round($channel * max(0, 1 + $amount));
            }
            $channel = max(0, min(255, $channel));
        }
        unset($channel);

        return sprintf('#%02x%02x%02x', $channels[0], $channels[1], $channels[2]);
    }

    private static function hexToRgbString(string $hex): string
    {
        $hex = ltrim(self::normalisePrimaryColour($hex), '#');
        return implode(',', [
            (string)hexdec(substr($hex, 0, 2)),
            (string)hexdec(substr($hex, 2, 2)),
            (string)hexdec(substr($hex, 4, 2)),
        ]);
    }

    private static function contrastTextColour(string $hex): string
    {
        $rgb = array_map('intval', explode(',', self::hexToRgbString($hex)));
        $luminance = (($rgb[0] ?? 0) * 0.299) + (($rgb[1] ?? 0) * 0.587) + (($rgb[2] ?? 0) * 0.114);
        return $luminance > 155 ? '#0f172a' : '#ffffff';
    }
}
