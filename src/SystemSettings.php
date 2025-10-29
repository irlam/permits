<?php
namespace Permits;

use PDO;
use RuntimeException;
use Throwable;

/**
 * Lightweight settings helper that reads/writes key/value pairs stored in the
 * `settings` table. This keeps configuration changes made through the admin UI
 * in sync with application services like the Mailer.
 */
final class SystemSettings
{
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
            throw new RuntimeException('Settings table is missing. Run php bin/migrate-features.php');
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
        try {
            $settings = self::load($db, [], [
                'email_enabled'    => 'false',
                'mail_driver'      => '',
                'smtp_host'        => '',
                'smtp_port'        => '',
                'smtp_user'        => '',
                'smtp_pass'        => '',
                'smtp_secure'      => '',
                'smtp_timeout'     => '',
                'mail_from_address'=> '',
                'mail_from_name'   => '',
                'smtp_from'        => '', // legacy key from older settings.php
            ]);
        } catch (Throwable $e) {
            return [];
        }

        $options = [];

        $enabled = filter_var($settings['email_enabled'] ?? 'false', FILTER_VALIDATE_BOOLEAN);
        $driver  = strtolower($settings['mail_driver'] ?? '');

        if (!$enabled) {
            $options['driver'] = 'log';
        } elseif ($driver !== '') {
            $options['driver'] = $driver;
        }

        if (!empty($settings['smtp_host'])) {
            $options['smtp_host'] = $settings['smtp_host'];
        }

        if ($settings['smtp_port'] !== '') {
            $port = (int)$settings['smtp_port'];
            if ($port > 0) {
                $options['smtp_port'] = $port;
            }
        }

        if (!empty($settings['smtp_user'])) {
            $options['smtp_user'] = $settings['smtp_user'];
        }

        if (!empty($settings['smtp_pass'])) {
            $options['smtp_pass'] = $settings['smtp_pass'];
        }

        if (!empty($settings['smtp_secure'])) {
            $options['smtp_secure'] = strtolower($settings['smtp_secure']);
        }

        if ($settings['smtp_timeout'] !== '') {
            $timeout = (int)$settings['smtp_timeout'];
            if ($timeout > 0) {
                $options['smtp_timeout'] = $timeout;
            }
        }

        $fromAddress = $settings['mail_from_address'] ?: $settings['smtp_from'];
        if (!empty($fromAddress)) {
            $options['from'] = $fromAddress;
        }

        if (!empty($settings['mail_from_name'])) {
            $options['from_name'] = $settings['mail_from_name'];
        }

        return $options;
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
}
