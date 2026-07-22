<?php
declare(strict_types=1);

use Permits\Db;
use Permits\Mailer;
use Permits\SystemSettings;
use PHPUnit\Framework\TestCase;

final class EmailSettingsSecurityTest extends TestCase
{
    public function testValidSettingsAreNormalisedForPersistence(): void
    {
        $settings = SystemSettings::normaliseMailerSettings([
            'email_enabled' => 'true',
            'mail_driver' => 'smtp',
            'smtp_host' => 'smtp.example.com',
            'smtp_port' => '587',
            'smtp_user' => 'permits@example.com',
            'smtp_pass' => '',
            'smtp_secure' => 'tls',
            'smtp_timeout' => '30',
            'mail_from_address' => 'permits@example.com',
            'mail_from_name' => 'Example Permits',
        ], 'kept-secret');

        self::assertSame('true', $settings['email_enabled']);
        self::assertSame('kept-secret', $settings['smtp_pass']);
        self::assertSame('smtp.example.com', $settings['smtp_host']);
    }

    public function testRejectsUnsafeOrOutOfRangeSettings(): void
    {
        $base = [
            'email_enabled' => 'true',
            'mail_driver' => 'smtp',
            'smtp_host' => 'smtp.example.com',
            'smtp_port' => '587',
            'smtp_secure' => 'tls',
            'smtp_timeout' => '30',
            'mail_from_address' => 'permits@example.com',
            'mail_from_name' => 'Permits',
        ];
        $invalid = [
            ['smtp_host' => "smtp.example.com\r\nRCPT TO:attacker@example.com"],
            ['smtp_host' => 'https://smtp.example.com'],
            ['smtp_host' => str_repeat('a', 254)],
            ['smtp_user' => "user\nAUTH PLAIN"],
            ['smtp_user' => str_repeat('u', 256)],
            ['mail_from_address' => "permits@example.com\r\nBcc:attacker@example.com"],
            ['mail_from_address' => 'not-an-email'],
            ['mail_from_name' => "Permits\r\nBcc:attacker@example.com"],
            ['mail_from_name' => str_repeat('n', 121)],
            ['smtp_port' => '0'],
            ['smtp_port' => '65536'],
            ['smtp_port' => '587x'],
            ['smtp_timeout' => '4'],
            ['smtp_timeout' => '121'],
            ['smtp_timeout' => '30 seconds'],
        ];

        foreach ($invalid as $change) {
            try {
                SystemSettings::normaliseMailerSettings(array_replace($base, $change));
                self::fail('Unsafe email settings should have been rejected: ' . json_encode($change));
            } catch (InvalidArgumentException $e) {
                self::assertNotSame('', $e->getMessage());
            }
        }
    }

    public function testDatabaseDisabledSettingDoesNotBecomeLogDelivery(): void
    {
        $db = $this->databaseWithSettings([
            'email_enabled' => 'false',
            'mail_driver' => 'smtp',
            'smtp_host' => '',
            'smtp_port' => '587',
            'smtp_user' => '',
            'smtp_pass' => '',
            'smtp_secure' => 'tls',
            'smtp_timeout' => '30',
            'mail_from_address' => '',
            'mail_from_name' => 'Permits System',
        ]);

        $options = SystemSettings::mailerOptions($db);
        self::assertFalse($options['enabled']);
        self::assertSame('smtp', $options['driver']);
        self::assertFalse(Mailer::fromDatabase($db)->isEnabled());
    }

    /** @param array<string,string> $settings */
    private function databaseWithSettings(array $settings): Db
    {
        $reflection = new ReflectionClass(Db::class);
        /** @var Db $db */
        $db = $reflection->newInstanceWithoutConstructor();
        $db->pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $db->pdo->exec('CREATE TABLE settings (`key` TEXT PRIMARY KEY, value TEXT, updated_at TEXT)');
        $insert = $db->pdo->prepare('INSERT INTO settings (`key`, value) VALUES (?, ?)');
        foreach ($settings as $key => $value) {
            $insert->execute([$key, $value]);
        }

        return $db;
    }
}
