<?php
declare(strict_types=1);

use Permits\AdminProvisioner;
use Permits\DatabaseMaintenance;
use Permits\Db;
use PHPUnit\Framework\TestCase;

final class FreshInstallTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec(<<<SQL
            CREATE TABLE users (
                id TEXT PRIMARY KEY,
                email TEXT NOT NULL UNIQUE,
                password_hash TEXT NOT NULL,
                name TEXT NOT NULL,
                role TEXT NOT NULL DEFAULT 'user',
                status TEXT NOT NULL DEFAULT 'active'
            )
            SQL);
    }

    public function testGeneratedPasswordMeetsTheAccountPolicy(): void
    {
        $password = AdminProvisioner::generateOneTimePassword();

        self::assertSame(24, strlen($password));
        self::assertMatchesRegularExpression('/[A-Z]/', $password);
        self::assertMatchesRegularExpression('/[a-z]/', $password);
        self::assertMatchesRegularExpression('/[0-9]/', $password);
        self::assertMatchesRegularExpression('/[!@#$%&*+\-=\?]/', $password);
    }

    public function testCreatesAnActiveAdminWithAHashedOneTimePassword(): void
    {
        $created = AdminProvisioner::createFirstAdmin(
            $this->pdo,
            '  OWNER@Example.COM ',
            'Site Administrator'
        );

        $row = $this->pdo->query('SELECT * FROM users')->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        self::assertSame($created['id'], $row['id']);
        self::assertSame('owner@example.com', $row['email']);
        self::assertSame('Site Administrator', $row['name']);
        self::assertSame('admin', $row['role']);
        self::assertSame('active', $row['status']);
        self::assertNotSame($created['password'], $row['password_hash']);
        self::assertTrue(password_verify($created['password'], $row['password_hash']));
    }

    public function testRefusesToCreateAnotherAdministrator(): void
    {
        AdminProvisioner::createFirstAdmin($this->pdo, 'first@example.com');

        try {
            AdminProvisioner::createFirstAdmin($this->pdo, 'second@example.com');
            self::fail('A second administrator should not be provisioned by the first-install command.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('administrator already exists', $e->getMessage());
        }

        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM users')->fetchColumn());
    }

    public function testRefusesToOverwriteAnExistingAccount(): void
    {
        $this->pdo->exec(
            "INSERT INTO users (id, email, password_hash, name, role, status) VALUES " .
            "('existing', 'owner@example.com', 'unchanged-hash', 'Existing User', 'viewer', 'active')"
        );

        try {
            AdminProvisioner::createFirstAdmin($this->pdo, 'OWNER@example.com');
            self::fail('An existing account should never be overwritten.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('already belongs to an account', $e->getMessage());
        }

        $row = $this->pdo->query("SELECT role, password_hash FROM users WHERE id = 'existing'")->fetch(PDO::FETCH_ASSOC);
        self::assertSame(['role' => 'viewer', 'password_hash' => 'unchanged-hash'], $row);
    }

    public function testFreshSchemaHasPortableCollationsAndNoSeededAdministrator(): void
    {
        $sql = file_get_contents(dirname(__DIR__) . '/database/database.sql');

        self::assertIsString($sql);
        self::assertStringNotContainsString('utf8mb4_0900_ai_ci', $sql);
        self::assertStringNotContainsString('utf8mb3', $sql);
        self::assertStringNotContainsString('INSERT INTO `users`', $sql);
        self::assertStringNotContainsString('admin@example.com', $sql);
        self::assertStringContainsString('CREATE TABLE `email_queue`', $sql);
        self::assertStringContainsString('idx_email_status', $sql);
        self::assertStringContainsString('idx_email_created', $sql);
        self::assertStringContainsString('UNIQUE KEY `uq_forms_ref_number` (`ref_number`)', $sql);
        self::assertStringContainsString('UNIQUE KEY `uq_forms_unique_link` (`unique_link`)', $sql);
        self::assertStringContainsString('CREATE TABLE `login_attempts`', $sql);
        self::assertStringContainsString('CREATE TABLE `public_rate_limits`', $sql);
        self::assertStringContainsString('CREATE TABLE `worker_locks`', $sql);

        $loginUpgradeSql = file_get_contents(dirname(__DIR__) . '/database/imports/2026-07-security-login-rate-limits.sql');
        self::assertIsString($loginUpgradeSql);
        self::assertStringContainsString('CREATE TABLE IF NOT EXISTS `login_attempts`', $loginUpgradeSql);
        self::assertStringContainsString('idx_email_ready', $sql);
        self::assertStringContainsString('attempt_count', $sql);

        $publicRateUpgradeSql = file_get_contents(dirname(__DIR__) . '/database/imports/2026-07-public-rate-limits.sql');
        self::assertIsString($publicRateUpgradeSql);
        self::assertStringContainsString('CREATE TABLE IF NOT EXISTS `public_rate_limits`', $publicRateUpgradeSql);
        self::assertStringContainsString('idx_public_limits_last_attempt', $publicRateUpgradeSql);

        $workerLockUpgradeSql = file_get_contents(dirname(__DIR__) . '/database/imports/2026-07-worker-locks.sql');
        self::assertIsString($workerLockUpgradeSql);
        self::assertStringContainsString('CREATE TABLE IF NOT EXISTS `worker_locks`', $workerLockUpgradeSql);
        self::assertStringContainsString('idx_worker_locks_expires', $workerLockUpgradeSql);

        $upgradeSql = file_get_contents(dirname(__DIR__) . '/database/imports/2026-07-email-queue.sql');
        self::assertIsString($upgradeSql);
        self::assertStringContainsString('CREATE TABLE IF NOT EXISTS `email_queue`', $upgradeSql);
        self::assertStringContainsString('utf8mb4_unicode_ci', $upgradeSql);
        self::assertStringContainsString("column_name = 'attempt_count'", $upgradeSql);
        self::assertStringNotContainsString('ADD COLUMN IF NOT EXISTS', $upgradeSql);
        self::assertStringNotContainsString('utf8mb4_0900_ai_ci', $upgradeSql);
    }

    public function testProvisioningEntryPointIsCliOnlyAndDoesNotAcceptAPassword(): void
    {
        $source = file_get_contents(dirname(__DIR__) . '/bin/create-admin.php');

        self::assertIsString($source);
        self::assertStringContainsString("PHP_SAPI !== 'cli'", $source);
        self::assertStringContainsString("'email:'", $source);
        self::assertStringContainsString("'name:'", $source);
        self::assertStringNotContainsString("'password:'", $source);
        self::assertStringNotContainsString('--force', $source);
    }

    public function testCanonicalMigrationCreatesMailQueueAndIsIdempotent(): void
    {
        $reflection = new ReflectionClass(Db::class);
        /** @var Db $db */
        $db = $reflection->newInstanceWithoutConstructor();
        $db->pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        $first = DatabaseMaintenance::ensureEmailQueueTable($db);
        self::assertSame([], $first['errors']);
        self::assertSame(['email_queue'], $first['added']);

        $columns = $db->pdo->query('PRAGMA table_info(email_queue)')->fetchAll(PDO::FETCH_COLUMN, 1);
        self::assertSame([
            'id', 'to_email', 'subject', 'body', 'status', 'attempt_count',
            'available_at', 'claimed_at', 'claim_token', 'last_error', 'created_at', 'sent_at',
        ], $columns);

        $indexes = $db->pdo->query('PRAGMA index_list(email_queue)')->fetchAll(PDO::FETCH_COLUMN, 1);
        self::assertContains('idx_email_status', $indexes);
        self::assertContains('idx_email_created', $indexes);
        self::assertContains('idx_email_ready', $indexes);
        self::assertContains('idx_email_claim', $indexes);

        $second = DatabaseMaintenance::ensureEmailQueueTable($db);
        self::assertSame([], $second['errors']);
        self::assertSame([], $second['added']);
        self::assertSame(['email_queue'], $second['alreadyPresent']);
    }

    public function testCanonicalMigrationUpgradesLegacyMailQueueWithoutLosingMessages(): void
    {
        $reflection = new ReflectionClass(Db::class);
        /** @var Db $db */
        $db = $reflection->newInstanceWithoutConstructor();
        $db->pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $db->pdo->exec(
            'CREATE TABLE email_queue (' .
            'id TEXT PRIMARY KEY, to_email TEXT NOT NULL, subject TEXT NOT NULL, body TEXT NOT NULL, ' .
            "status TEXT NOT NULL DEFAULT 'pending', created_at TEXT NOT NULL, sent_at TEXT NULL)"
        );
        $db->pdo->exec(
            "INSERT INTO email_queue (id, to_email, subject, body, status, created_at) " .
            "VALUES ('legacy', 'holder@example.com', 'Queued', '<p>Keep me</p>', 'pending', datetime('now'))"
        );

        $result = DatabaseMaintenance::ensureEmailQueueTable($db);

        self::assertSame([], $result['errors']);
        self::assertSame(['email_queue'], $result['alreadyPresent']);
        $row = $db->pdo->query("SELECT id, status, attempt_count, body FROM email_queue WHERE id = 'legacy'")->fetch(PDO::FETCH_ASSOC);
        self::assertSame([
            'id' => 'legacy',
            'status' => 'pending',
            'attempt_count' => 0,
            'body' => '<p>Keep me</p>',
        ], $row);
        $indexes = $db->pdo->query('PRAGMA index_list(email_queue)')->fetchAll(PDO::FETCH_COLUMN, 1);
        self::assertContains('idx_email_ready', $indexes);
        self::assertContains('idx_email_claim', $indexes);
    }

    public function testLegacyConflictingMigrationEntrypointsAreNotDeployed(): void
    {
        self::assertFileDoesNotExist(dirname(__DIR__) . '/bin/migrate-features.php');
        self::assertFileDoesNotExist(dirname(__DIR__) . '/bin/ensure-form-template-columns.php');
    }

    public function testWorkerLockMigrationIsIdempotent(): void
    {
        $reflection = new ReflectionClass(Db::class);
        /** @var Db $db */
        $db = $reflection->newInstanceWithoutConstructor();
        $db->pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        $first = DatabaseMaintenance::ensureWorkerLocksTable($db);
        self::assertSame([], $first['errors']);
        self::assertSame(['worker_locks'], $first['added']);
        $columns = $db->pdo->query('PRAGMA table_info(worker_locks)')->fetchAll(PDO::FETCH_COLUMN, 1);
        self::assertSame(['name', 'owner_token', 'acquired_at', 'expires_at'], $columns);
        $indexes = $db->pdo->query('PRAGMA index_list(worker_locks)')->fetchAll(PDO::FETCH_COLUMN, 1);
        self::assertContains('idx_worker_locks_expires', $indexes);

        $second = DatabaseMaintenance::ensureWorkerLocksTable($db);
        self::assertSame([], $second['errors']);
        self::assertSame(['worker_locks'], $second['alreadyPresent']);
    }

    public function testPermitIdentifierMigrationCreatesUniqueIndexesAndRejectsDuplicates(): void
    {
        $reflection = new ReflectionClass(Db::class);
        /** @var Db $db */
        $db = $reflection->newInstanceWithoutConstructor();
        $db->pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $db->pdo->exec('CREATE TABLE forms (id TEXT PRIMARY KEY, ref_number TEXT NULL, unique_link TEXT NULL)');
        $db->pdo->exec("INSERT INTO forms VALUES ('one', '', ''), ('two', NULL, NULL)");

        $first = DatabaseMaintenance::ensureFormsUniqueIndexes($db);
        self::assertSame([], $first['errors']);
        self::assertSame(['uq_forms_ref_number', 'uq_forms_unique_link'], $first['added']);
        $indexes = $db->pdo->query('PRAGMA index_list(forms)')->fetchAll(PDO::FETCH_ASSOC);
        $uniqueByName = array_column($indexes, 'unique', 'name');
        self::assertSame(1, (int) ($uniqueByName['uq_forms_ref_number'] ?? 0));
        self::assertSame(1, (int) ($uniqueByName['uq_forms_unique_link'] ?? 0));
        self::assertSame(2, (int) $db->pdo->query('SELECT COUNT(*) FROM forms WHERE ref_number IS NULL')->fetchColumn());

        $second = DatabaseMaintenance::ensureFormsUniqueIndexes($db);
        self::assertSame([], $second['errors']);
        self::assertSame(['uq_forms_ref_number', 'uq_forms_unique_link'], $second['alreadyPresent']);

        $db->pdo->exec('DROP INDEX uq_forms_ref_number');
        $db->pdo->exec("UPDATE forms SET ref_number = 'PTW-2026-000001'");
        $duplicate = DatabaseMaintenance::ensureFormsUniqueIndexes($db);
        self::assertCount(1, $duplicate['errors']);
        self::assertStringContainsString('duplicate non-empty value group', $duplicate['errors'][0]);
    }
}
