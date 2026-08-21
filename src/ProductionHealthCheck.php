<?php
declare(strict_types=1);

namespace Permits;

use Closure;
use PDO;
use Throwable;

/**
 * Read-only production readiness checks used by bin/health-check.php.
 */
final class ProductionHealthCheck
{
    /** @var list<string> */
    private const REQUIRED_TABLES = [
        'form_templates',
        'forms',
        'form_events',
        'attachments',
        'users',
        'email_queue',
        'login_attempts',
        'public_rate_limits',
        'worker_locks',
        'activity_log',
        'settings',
        'site_settings',
        'permit_approval_links',
        'push_subscriptions',
    ];

    /** @var array<string,list<string>> */
    private const REQUIRED_COLUMNS = [
        'forms' => [
            'id',
            'ref_number',
            'template_id',
            'form_data',
            'site_block',
            'ref',
            'status',
            'holder_id',
            'holder_email',
            'holder_name',
            'holder_phone',
            'notification_token',
            'unique_link',
            'issuer_id',
            'valid_from',
            'valid_to',
            'metadata',
            'created_at',
            'updated_at',
            'approval_status',
            'approved_by',
            'approved_at',
            'approval_notes',
            'requires_approval',
            'notified_at',
            'closed_by',
            'closed_at',
            'closure_reason',
            'expiry_duration',
            'expires_at',
            'expired_at',
            'work_started_at',
        ],
        'email_queue' => [
            'id',
            'to_email',
            'subject',
            'body',
            'status',
            'attempt_count',
            'available_at',
            'claimed_at',
            'claim_token',
            'last_error',
            'created_at',
            'sent_at',
        ],
        'public_rate_limits' => [
            'key_hash',
            'attempts',
            'window_started_at',
            'last_attempt_at',
        ],
        'worker_locks' => [
            'name',
            'owner_token',
            'acquired_at',
            'expires_at',
        ],
    ];

    /** @var array<string,list<string>> */
    private const REQUIRED_UNIQUE_INDEXES = [
        'uq_forms_ref_number' => ['ref_number'],
        'uq_forms_unique_link' => ['unique_link'],
    ];

    /** @var list<string> */
    private const REQUIRED_DIRECTORIES = [
        'uploads',
        'uploads/branding',
        'data',
    ];

    /** @var list<string> */
    private const REQUIRED_EXTENSIONS = [
        'curl',
        'fileinfo',
        'gd',
        'json',
        'mbstring',
        'pdo',
        'zip',
    ];

    private PDO $pdo;
    private string $root;

    /** @var array<string,mixed> */
    private array $config;
    private Closure $extensionLoaded;
    private Closure $directoryProbe;

    /**
     * @param array<string,mixed> $config Effective runtime configuration. Secret values must not be supplied.
     * @param null|callable(string):bool $extensionLoaded
     * @param null|callable(string,string):array{exists:bool,writable:bool} $directoryProbe
     */
    public function __construct(
        PDO $pdo,
        string $root,
        array $config,
        ?callable $extensionLoaded = null,
        ?callable $directoryProbe = null
    ) {
        $this->pdo = $pdo;
        $this->root = rtrim($root, '/\\');
        $this->config = $config;
        $this->extensionLoaded = Closure::fromCallable($extensionLoaded ?? 'extension_loaded');
        $this->directoryProbe = Closure::fromCallable(
            $directoryProbe ?? static function (string $path, string $relativePath): array {
                unset($relativePath);

                return [
                    'exists' => is_dir($path),
                    'writable' => is_dir($path) && is_writable($path),
                ];
            }
        );
    }

    /**
     * @return array{
     *     checks:array<string,array{ok:bool,message:string}>,
     *     failures:list<string>
     * }
     */
    public function run(): array
    {
        $checks = [];
        $actualDriver = strtolower((string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
        $configuredDriver = strtolower(trim((string) ($this->config['db_driver'] ?? '')));
        $supportedDrivers = ['mysql', 'sqlite'];

        $driverOk = in_array($actualDriver, $supportedDrivers, true)
            && $configuredDriver === $actualDriver;
        $this->addCheck(
            $checks,
            'database.driver',
            $driverOk,
            'Database driver is supported and matches the application configuration.',
            'Database driver is unsupported or does not match DB_DRIVER.'
        );

        $availableTables = [];
        foreach (self::REQUIRED_TABLES as $table) {
            $availableTables[$table] = $this->tableIsReadable($table);
            $this->addCheck(
                $checks,
                'database.table.' . $table,
                $availableTables[$table],
                "Required table '{$table}' is readable.",
                "Required table '{$table}' is missing or inaccessible."
            );
        }

        $activeAdminExists = $availableTables['users']
            && $this->queryHasRows(
                "SELECT 1 FROM users WHERE LOWER(TRIM(role)) = 'admin' AND LOWER(TRIM(status)) = 'active' LIMIT 1"
            );
        $this->addCheck(
            $checks,
            'database.readiness.active_admin',
            $activeAdminExists,
            'At least one active administrator account is available.',
            'Create or reactivate an administrator account before production sign-off.'
        );

        $activeTemplateExists = $availableTables['form_templates']
            && $this->queryHasRows('SELECT 1 FROM form_templates WHERE active = 1 LIMIT 1');
        $this->addCheck(
            $checks,
            'database.readiness.active_template',
            $activeTemplateExists,
            'At least one active permit template is available.',
            'Import or activate at least one permit template before production sign-off.'
        );

        foreach (self::REQUIRED_COLUMNS as $table => $requiredColumns) {
            $columns = $availableTables[$table] ? $this->columnsFor($table, $actualDriver) : [];
            $missingColumns = array_values(array_diff($requiredColumns, $columns));
            $this->addCheck(
                $checks,
                'database.columns.' . $table,
                $missingColumns === [],
                "All required '{$table}' columns are present.",
                "Table '{$table}' is missing required columns: " . implode(', ', $missingColumns) . '.'
            );
        }

        $formsIndexes = $availableTables['forms']
            ? $this->indexesFor('forms', $actualDriver)
            : [];
        foreach (self::REQUIRED_UNIQUE_INDEXES as $name => $requiredColumns) {
            $index = $formsIndexes[strtolower($name)] ?? null;
            $valid = is_array($index)
                && ($index['unique'] ?? false) === true
                && ($index['columns'] ?? []) === $requiredColumns;
            $this->addCheck(
                $checks,
                'database.index.' . $name,
                $valid,
                "Required unique index '{$name}' is present.",
                "Required single-column unique index '{$name}' is missing or invalid."
            );
        }

        foreach (self::REQUIRED_DIRECTORIES as $relativePath) {
            $path = $this->root . DIRECTORY_SEPARATOR
                . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
            try {
                $directoryStatus = ($this->directoryProbe)($path, $relativePath);
            } catch (Throwable $e) {
                $directoryStatus = ['exists' => false, 'writable' => false];
            }

            $exists = ($directoryStatus['exists'] ?? false) === true;
            $writable = ($directoryStatus['writable'] ?? false) === true;
            $message = !$exists
                ? "Required directory '{$relativePath}' does not exist."
                : "Required directory '{$relativePath}' is not writable.";
            $this->addCheck(
                $checks,
                'directory.' . str_replace('/', '.', $relativePath),
                $exists && $writable,
                "Required directory '{$relativePath}' exists and is writable.",
                $message
            );
        }

        try {
            $backupPath = BackupStorage::pathFromValue(
                $this->root,
                (string)($this->config['backup_path'] ?? '')
            );
            $backupDirectoryStatus = ($this->directoryProbe)($backupPath, 'backups.private');
            $backupExists = ($backupDirectoryStatus['exists'] ?? false) === true;
            $backupWritable = ($backupDirectoryStatus['writable'] ?? false) === true;
            $this->addCheck(
                $checks,
                'directory.backups.private',
                $backupExists && $backupWritable,
                'Private backup storage exists outside the public application directory and is writable.',
                !$backupExists
                    ? 'Private backup storage outside the public application directory does not exist.'
                    : 'Private backup storage outside the public application directory is not writable.'
            );
        } catch (Throwable $e) {
            $this->addCheck(
                $checks,
                'directory.backups.private',
                false,
                'Private backup storage is configured safely.',
                'Set BACKUP_PATH to an absolute private directory outside the public application directory and permitted by open_basedir.'
            );
        }

        $this->addProductionConfigurationChecks($checks);

        $extensions = self::REQUIRED_EXTENSIONS;
        $selectedDriver = in_array($configuredDriver, $supportedDrivers, true)
            ? $configuredDriver
            : $actualDriver;
        if (in_array($selectedDriver, $supportedDrivers, true)) {
            $extensions[] = 'pdo_' . $selectedDriver;
        }

        foreach ($extensions as $extension) {
            try {
                $loaded = (bool) ($this->extensionLoaded)($extension);
            } catch (Throwable $e) {
                $loaded = false;
            }
            $this->addCheck(
                $checks,
                'extension.' . $extension,
                $loaded,
                "Required PHP extension '{$extension}' is loaded.",
                "Required PHP extension '{$extension}' is not loaded."
            );
        }

        $failures = [];
        foreach ($checks as $key => $check) {
            if (!$check['ok']) {
                $failures[] = $key;
            }
        }

        return ['checks' => $checks, 'failures' => $failures];
    }

    /**
     * @param array{failures?:list<string>} $report
     */
    public static function exitCode(array $report): int
    {
        return ($report['failures'] ?? []) === [] ? 0 : 1;
    }

    /** @param array<string,array{ok:bool,message:string}> $checks */
    private function addProductionConfigurationChecks(array &$checks): void
    {
        $environment = strtolower(trim((string) ($this->config['app_env'] ?? '')));
        $this->addCheck(
            $checks,
            'config.app_env',
            $environment === 'production',
            'APP_ENV is set to production.',
            'APP_ENV must be set to production for release sign-off.'
        );

        $appUrl = trim((string) ($this->config['app_url'] ?? ''));
        $urlParts = $appUrl !== '' ? parse_url($appUrl) : false;
        $httpsUrl = is_array($urlParts)
            && strtolower((string) ($urlParts['scheme'] ?? '')) === 'https'
            && filter_var((string) ($urlParts['host'] ?? ''), FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false
            && !isset($urlParts['user'], $urlParts['pass'], $urlParts['query'], $urlParts['fragment']);
        $this->addCheck(
            $checks,
            'config.app_url',
            $httpsUrl,
            'APP_URL is a valid HTTPS URL.',
            'APP_URL must be a valid HTTPS URL without credentials, query parameters, or fragments.'
        );

        $debug = $this->booleanValue($this->config['app_debug'] ?? null);
        $this->addCheck(
            $checks,
            'config.app_debug',
            $debug === false,
            'Application debug output is disabled.',
            'APP_DEBUG must be false in production.'
        );

        $secure = $this->booleanValue($this->config['session_cookie_secure'] ?? null);
        $this->addCheck(
            $checks,
            'config.session_cookie_secure',
            $secure === true,
            'Session cookies require HTTPS.',
            'SESSION_COOKIE_SECURE must be true in production.'
        );

        $httpOnly = $this->booleanValue($this->config['session_cookie_httponly'] ?? null);
        $this->addCheck(
            $checks,
            'config.session_cookie_httponly',
            $httpOnly === true,
            'Session cookies are protected from client-side scripts.',
            'SESSION_COOKIE_HTTPONLY must be true in production.'
        );
    }

    /** @param array<string,array{ok:bool,message:string}> $checks */
    private function addCheck(
        array &$checks,
        string $key,
        bool $ok,
        string $successMessage,
        string $failureMessage
    ): void {
        $checks[$key] = [
            'ok' => $ok,
            'message' => $ok ? $successMessage : $failureMessage,
        ];
    }

    private function tableIsReadable(string $table): bool
    {
        try {
            $this->pdo->query("SELECT 1 FROM `{$table}` LIMIT 1");

            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    private function queryHasRows(string $sql): bool
    {
        try {
            return $this->pdo->query($sql)->fetchColumn() !== false;
        } catch (Throwable $e) {
            return false;
        }
    }

    /** @return list<string> */
    private function columnsFor(string $table, string $driver): array
    {
        try {
            if ($driver === 'mysql') {
                $rows = $this->pdo->query("SHOW COLUMNS FROM `{$table}`")->fetchAll(PDO::FETCH_ASSOC);

                return array_values(array_map(
                    static fn (array $row): string => strtolower((string) ($row['Field'] ?? '')),
                    $rows
                ));
            }

            if ($driver === 'sqlite') {
                $rows = $this->pdo->query("PRAGMA table_info('{$table}')")->fetchAll(PDO::FETCH_ASSOC);

                return array_values(array_map(
                    static fn (array $row): string => strtolower((string) ($row['name'] ?? '')),
                    $rows
                ));
            }
        } catch (Throwable $e) {
            return [];
        }

        return [];
    }

    /**
     * @return array<string,array{unique:bool,columns:list<string>}>
     */
    private function indexesFor(string $table, string $driver): array
    {
        try {
            if ($driver === 'mysql') {
                $rows = $this->pdo->query("SHOW INDEX FROM `{$table}`")->fetchAll(PDO::FETCH_ASSOC);
                $indexes = [];
                foreach ($rows as $row) {
                    $name = strtolower((string) ($row['Key_name'] ?? ''));
                    if ($name === '') {
                        continue;
                    }
                    $indexes[$name]['unique'] = (int) ($row['Non_unique'] ?? 1) === 0;
                    $sequence = max(1, (int) ($row['Seq_in_index'] ?? 1));
                    $indexes[$name]['columns'][$sequence] = strtolower((string) ($row['Column_name'] ?? ''));
                }

                foreach ($indexes as &$index) {
                    ksort($index['columns']);
                    $index['columns'] = array_values($index['columns']);
                }
                unset($index);

                return $indexes;
            }

            if ($driver === 'sqlite') {
                $rows = $this->pdo->query("PRAGMA index_list('{$table}')")->fetchAll(PDO::FETCH_ASSOC);
                $indexes = [];
                foreach ($rows as $row) {
                    $originalName = (string) ($row['name'] ?? '');
                    $name = strtolower($originalName);
                    if ($name === '') {
                        continue;
                    }
                    $quotedName = str_replace("'", "''", $originalName);
                    $columnRows = $this->pdo
                        ->query("PRAGMA index_info('{$quotedName}')")
                        ->fetchAll(PDO::FETCH_ASSOC);
                    usort(
                        $columnRows,
                        static fn (array $left, array $right): int =>
                            ((int) ($left['seqno'] ?? 0)) <=> ((int) ($right['seqno'] ?? 0))
                    );
                    $indexes[$name] = [
                        'unique' => (int) ($row['unique'] ?? 0) === 1,
                        'columns' => array_values(array_map(
                            static fn (array $column): string => strtolower((string) ($column['name'] ?? '')),
                            $columnRows
                        )),
                    ];
                }

                return $indexes;
            }
        } catch (Throwable $e) {
            return [];
        }

        return [];
    }

    private function booleanValue(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }
}
