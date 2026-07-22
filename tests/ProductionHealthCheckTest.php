<?php
declare(strict_types=1);

use Permits\ProductionHealthCheck;
use PHPUnit\Framework\TestCase;

final class ProductionHealthCheckTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }

    public function testCompleteProductionConfigurationPassesEveryCheck(): void
    {
        $this->createRequiredSchema();
        $checker = $this->checker();

        $report = $checker->run();

        self::assertSame([], $report['failures']);
        self::assertSame(0, ProductionHealthCheck::exitCode($report));
        foreach ([
            'curl', 'fileinfo', 'gd', 'json', 'mbstring', 'pdo', 'zip', 'pdo_sqlite',
        ] as $extension) {
            self::assertTrue($report['checks']['extension.' . $extension]['ok']);
        }
        foreach ([
            'directory.uploads',
            'directory.uploads.branding',
            'directory.backups.private',
            'directory.data',
        ] as $directoryCheck) {
            self::assertTrue($report['checks'][$directoryCheck]['ok']);
        }
    }

    public function testFreshCheckoutIncludesTheRequiredRuntimeDirectories(): void
    {
        $root = dirname(__DIR__);

        foreach (['uploads', 'uploads/branding', 'data'] as $relativePath) {
            $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
            self::assertDirectoryExists($path);
            self::assertTrue(is_writable($path), "Runtime directory '{$relativePath}' must be writable.");
        }
    }

    public function testMissingOrUnwritableDirectoriesAndExtensionsFailTheRun(): void
    {
        $this->createRequiredSchema();
        $directoryStatuses = [
            'uploads' => ['exists' => true, 'writable' => true],
            'uploads/branding' => ['exists' => true, 'writable' => false],
            'backups.private' => ['exists' => false, 'writable' => false],
            'data' => ['exists' => true, 'writable' => true],
        ];
        $checker = $this->checker(
            extensionLoaded: static fn (string $extension): bool => !in_array($extension, ['gd', 'zip'], true),
            directoryProbe: static fn (string $path, string $relativePath): array => $directoryStatuses[$relativePath]
        );

        $report = $checker->run();

        self::assertSame(1, ProductionHealthCheck::exitCode($report));
        self::assertContains('directory.uploads.branding', $report['failures']);
        self::assertContains('directory.backups.private', $report['failures']);
        self::assertContains('extension.gd', $report['failures']);
        self::assertContains('extension.zip', $report['failures']);
        self::assertStringContainsString(
            'not writable',
            $report['checks']['directory.uploads.branding']['message']
        );
        self::assertStringContainsString(
            'does not exist',
            $report['checks']['directory.backups.private']['message']
        );
    }

    public function testUnsafeProductionConfigurationFailsWithoutEchoingItsValues(): void
    {
        $this->createRequiredSchema();
        $checker = $this->checker([
            'app_env' => 'development',
            'app_url' => 'http://admin:secret@example.test/permits?debug=1',
            'app_debug' => true,
            'session_cookie_secure' => false,
            'session_cookie_httponly' => false,
            'db_driver' => 'sqlite',
        ]);

        $report = $checker->run();
        $messages = implode("\n", array_column($report['checks'], 'message'));

        self::assertContains('config.app_env', $report['failures']);
        self::assertContains('config.app_url', $report['failures']);
        self::assertContains('config.app_debug', $report['failures']);
        self::assertContains('config.session_cookie_secure', $report['failures']);
        self::assertContains('config.session_cookie_httponly', $report['failures']);
        self::assertStringNotContainsString('secret', $messages);
        self::assertStringNotContainsString('example.test', $messages);
    }

    public function testMissingCriticalColumnsAndInvalidIdentifierIndexesFailTheRun(): void
    {
        $this->createRequiredSchema(
            omittedFormColumns: ['work_started_at'],
            omittedEmailColumns: ['attempt_count'],
            invalidFormsIndexes: true
        );
        $checker = $this->checker();

        $report = $checker->run();

        self::assertContains('database.columns.forms', $report['failures']);
        self::assertContains('database.columns.email_queue', $report['failures']);
        self::assertContains('database.index.uq_forms_ref_number', $report['failures']);
        self::assertContains('database.index.uq_forms_unique_link', $report['failures']);
        self::assertStringContainsString(
            'work_started_at',
            $report['checks']['database.columns.forms']['message']
        );
        self::assertStringContainsString(
            'attempt_count',
            $report['checks']['database.columns.email_queue']['message']
        );
    }

    public function testReleaseRequiresAnActiveAdminAndAnActiveTemplateWithoutLeakingValues(): void
    {
        $this->createRequiredSchema();
        $this->pdo->exec('DELETE FROM users');
        $this->pdo->exec('DELETE FROM form_templates');
        $this->pdo->exec(
            "INSERT INTO users (id, email, role, status) VALUES ('inactive-admin', 'private-admin@example.test', 'admin', 'inactive')"
        );
        $this->pdo->exec(
            "INSERT INTO form_templates (id, name, active) VALUES ('private-template', 'Private draft name', 0)"
        );

        $report = $this->checker()->run();
        $messages = implode("\n", array_column($report['checks'], 'message'));

        self::assertContains('database.readiness.active_admin', $report['failures']);
        self::assertContains('database.readiness.active_template', $report['failures']);
        self::assertStringNotContainsString('private-admin@example.test', $messages);
        self::assertStringNotContainsString('Private draft name', $messages);
    }

    /**
     * @param array<string,mixed>|null $config
     * @param null|callable(string):bool $extensionLoaded
     * @param null|callable(string,string):array{exists:bool,writable:bool} $directoryProbe
     */
    private function checker(
        ?array $config = null,
        ?callable $extensionLoaded = null,
        ?callable $directoryProbe = null
    ): ProductionHealthCheck {
        return new ProductionHealthCheck(
            $this->pdo,
            dirname(__DIR__),
            $config ?? [
                'app_env' => 'production',
                'app_url' => 'https://permits.example.test',
                'app_debug' => false,
                'session_cookie_secure' => true,
                'session_cookie_httponly' => true,
                'db_driver' => 'sqlite',
                'backup_path' => dirname(__DIR__) . '-private-backups',
            ],
            $extensionLoaded ?? static fn (string $extension): bool => true,
            $directoryProbe ?? static fn (string $path, string $relativePath): array => [
                'exists' => true,
                'writable' => true,
            ]
        );
    }

    /**
     * @param list<string> $omittedFormColumns
     * @param list<string> $omittedEmailColumns
     */
    private function createRequiredSchema(
        array $omittedFormColumns = [],
        array $omittedEmailColumns = [],
        bool $invalidFormsIndexes = false
    ): void {
        $simpleTables = [
            'form_events',
            'attachments',
            'login_attempts',
            'activity_log',
            'settings',
            'site_settings',
            'permit_approval_links',
            'push_subscriptions',
        ];
        foreach ($simpleTables as $table) {
            $this->pdo->exec("CREATE TABLE `{$table}` (id TEXT)");
        }

        $this->pdo->exec('CREATE TABLE users (id TEXT, email TEXT, role TEXT, status TEXT)');
        $this->pdo->exec('CREATE TABLE form_templates (id TEXT, name TEXT, active INTEGER)');
        $this->pdo->exec(
            "INSERT INTO users (id, email, role, status) VALUES ('admin-1', 'admin@example.test', 'admin', 'active')"
        );
        $this->pdo->exec(
            "INSERT INTO form_templates (id, name, active) VALUES ('template-1', 'General permit', 1)"
        );

        $formColumns = [
            'id', 'ref_number', 'template_id', 'form_data', 'site_block', 'ref', 'status',
            'holder_id', 'holder_email', 'holder_name', 'holder_phone', 'notification_token',
            'unique_link', 'issuer_id', 'valid_from', 'valid_to', 'metadata', 'created_at',
            'updated_at', 'approval_status', 'approved_by', 'approved_at', 'approval_notes',
            'requires_approval', 'notified_at', 'closed_by', 'closed_at', 'closure_reason',
            'expiry_duration', 'expires_at', 'expired_at', 'work_started_at',
        ];
        $emailColumns = [
            'id', 'to_email', 'subject', 'body', 'status', 'attempt_count', 'available_at',
            'claimed_at', 'claim_token', 'last_error', 'created_at', 'sent_at',
        ];
        $formColumns = array_values(array_diff($formColumns, $omittedFormColumns));
        $emailColumns = array_values(array_diff($emailColumns, $omittedEmailColumns));

        $this->pdo->exec('CREATE TABLE forms (' . $this->columnDefinitions($formColumns) . ')');
        $this->pdo->exec('CREATE TABLE email_queue (' . $this->columnDefinitions($emailColumns) . ')');
        $this->pdo->exec(
            'CREATE TABLE public_rate_limits (' .
            'key_hash TEXT, attempts TEXT, window_started_at TEXT, last_attempt_at TEXT)'
        );
        $this->pdo->exec(
            'CREATE TABLE worker_locks (' .
            'name TEXT, owner_token TEXT, acquired_at TEXT, expires_at TEXT)'
        );

        if ($invalidFormsIndexes) {
            $this->pdo->exec('CREATE INDEX uq_forms_ref_number ON forms(ref_number)');
        } else {
            $this->pdo->exec('CREATE UNIQUE INDEX uq_forms_ref_number ON forms(ref_number)');
            $this->pdo->exec('CREATE UNIQUE INDEX uq_forms_unique_link ON forms(unique_link)');
        }
    }

    /** @param list<string> $columns */
    private function columnDefinitions(array $columns): string
    {
        return implode(', ', array_map(
            static fn (string $column): string => "`{$column}` TEXT",
            $columns
        ));
    }
}
