<?php
declare(strict_types=1);

use Permits\DatabaseMaintenance;
use Permits\Db;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/src/ActivityLogger.php';

final class ActivityCompatibilityTest extends TestCase
{
    private function database(): Db
    {
        $reflection = new ReflectionClass(Db::class);
        /** @var Db $db */
        $db = $reflection->newInstanceWithoutConstructor();
        $db->pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        return $db;
    }

    public function testLoggerPopulatesCanonicalRequiredColumnsAndContext(): void
    {
        $db = $this->database();
        $db->pdo->exec(<<<'SQL'
            CREATE TABLE activity_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
                user_id TEXT,
                type TEXT,
                user_email TEXT,
                action TEXT NOT NULL,
                category TEXT NOT NULL,
                resource_type TEXT,
                resource_id TEXT,
                description TEXT,
                ip_address TEXT,
                user_agent TEXT,
                status TEXT NOT NULL
            )
            SQL);

        $_SESSION['user_email'] = 'admin@example.test';
        $_SERVER['REMOTE_ADDR'] = '192.0.2.10';
        $_SERVER['HTTP_USER_AGENT'] = 'Permits test client';

        self::assertTrue(log_activity(
            $db,
            'admin-id',
            'settings_updated',
            'Settings changed',
            'settings',
            'setting',
            'branding'
        ));

        $row = $db->pdo->query('SELECT * FROM activity_log')->fetch();
        self::assertSame('settings_updated', $row['type']);
        self::assertSame('settings_updated', $row['action']);
        self::assertSame('settings', $row['category']);
        self::assertSame('setting', $row['resource_type']);
        self::assertSame('branding', $row['resource_id']);
        self::assertSame('Settings changed', $row['description']);
        self::assertSame('admin@example.test', $row['user_email']);
        self::assertSame('success', $row['status']);
    }

    public function testLoggerSupportsLegacyDetailsOnlySchema(): void
    {
        $db = $this->database();
        $db->pdo->exec(<<<'SQL'
            CREATE TABLE activity_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id TEXT,
                action TEXT NOT NULL,
                details TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
            SQL);

        self::assertTrue(log_activity($db, 'system', 'permit_expired', 'Permit expired'));

        $row = $db->pdo->query('SELECT user_id, action, details FROM activity_log')->fetch();
        self::assertSame('system', $row['user_id']);
        self::assertSame('permit_expired', $row['action']);
        self::assertSame('Permit expired', $row['details']);
    }

    public function testActivityMigrationPreservesLegacyRowsAndIsIdempotent(): void
    {
        $db = $this->database();
        $db->pdo->exec(<<<'SQL'
            CREATE TABLE activity_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                details TEXT,
                created_at DATETIME
            )
            SQL);
        $db->pdo->exec("INSERT INTO activity_log (details, created_at) VALUES ('Legacy message', '2026-07-22 09:00:00')");

        $first = DatabaseMaintenance::ensureActivityLogColumns($db);
        self::assertSame([], $first['errors']);
        self::assertContains('description', $first['added']);
        self::assertContains('timestamp', $first['added']);

        $row = $db->pdo->query('SELECT description, timestamp, created_at, action, category, status FROM activity_log')->fetch();
        self::assertSame('Legacy message', $row['description']);
        self::assertSame('2026-07-22 09:00:00', $row['timestamp']);
        self::assertSame('general', $row['category']);
        self::assertSame('success', $row['status']);

        $second = DatabaseMaintenance::ensureActivityLogColumns($db);
        self::assertSame([], $second['errors']);
        self::assertSame([], $second['added']);
    }
}
