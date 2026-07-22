<?php

namespace Permits;

use PDO;
use RuntimeException;
use Throwable;

class DatabaseMaintenance
{
    /**
     * Create the mail queue required by notification delivery when upgrading a
     * legacy installation. Fresh installations receive the same schema from
     * database/database.sql.
     *
     * @return array{added:array<int,string>, alreadyPresent:array<int,string>, errors:array<int,string>}
     */
    public static function ensureEmailQueueTable(Db $db): array
    {
        $driver = $db->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if (!in_array($driver, ['mysql', 'sqlite'], true)) {
            throw new RuntimeException('Unsupported database driver: ' . $driver);
        }

        try {
            $exists = $driver === 'mysql'
                ? (bool) $db->pdo->query("SHOW TABLES LIKE 'email_queue'")->fetchColumn()
                : (bool) $db->pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'email_queue'")->fetchColumn();

            $createSql = $driver === 'mysql'
                ? "CREATE TABLE IF NOT EXISTS email_queue (
                    id CHAR(36) NOT NULL PRIMARY KEY,
                    to_email VARCHAR(255) NOT NULL,
                    subject VARCHAR(500) NOT NULL,
                    body MEDIUMTEXT NOT NULL,
                    status VARCHAR(20) NOT NULL DEFAULT 'pending',
                    attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
                    available_at DATETIME NULL,
                    claimed_at DATETIME NULL,
                    claim_token VARCHAR(64) NULL,
                    last_error VARCHAR(1000) NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    sent_at DATETIME NULL,
                    INDEX idx_email_status (status),
                    INDEX idx_email_created (created_at),
                    INDEX idx_email_ready (status, available_at, created_at),
                    INDEX idx_email_claim (claim_token)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
                : "CREATE TABLE IF NOT EXISTS email_queue (
                    id TEXT NOT NULL PRIMARY KEY,
                    to_email TEXT NOT NULL,
                    subject TEXT NOT NULL,
                    body TEXT NOT NULL,
                    status TEXT NOT NULL DEFAULT 'pending',
                    attempt_count INTEGER NOT NULL DEFAULT 0,
                    available_at DATETIME NULL,
                    claimed_at DATETIME NULL,
                    claim_token TEXT NULL,
                    last_error TEXT NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    sent_at DATETIME NULL
                )";

            $db->pdo->exec($createSql);
            if ($driver === 'sqlite') {
                $db->pdo->exec('CREATE INDEX IF NOT EXISTS idx_email_status ON email_queue(status)');
                $db->pdo->exec('CREATE INDEX IF NOT EXISTS idx_email_created ON email_queue(created_at)');
            } else {
                $indexRows = $db->pdo->query('SHOW INDEX FROM email_queue')->fetchAll(PDO::FETCH_ASSOC);
                $indexes = [];
                foreach ($indexRows as $indexRow) {
                    if (isset($indexRow['Key_name'])) {
                        $indexes[strtolower((string)$indexRow['Key_name'])] = true;
                    }
                }
                if (!isset($indexes['idx_email_status'])) {
                    $db->pdo->exec('CREATE INDEX idx_email_status ON email_queue(status)');
                }
                if (!isset($indexes['idx_email_created'])) {
                    $db->pdo->exec('CREATE INDEX idx_email_created ON email_queue(created_at)');
                }
            }

            $columns = self::fetchTableColumns($db, $driver, 'email_queue');
            $retryColumns = [
                'attempt_count' => [
                    'mysql' => 'ALTER TABLE email_queue ADD COLUMN attempt_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER status',
                    'sqlite' => 'ALTER TABLE email_queue ADD COLUMN attempt_count INTEGER NOT NULL DEFAULT 0',
                ],
                'available_at' => [
                    'mysql' => 'ALTER TABLE email_queue ADD COLUMN available_at DATETIME NULL AFTER attempt_count',
                    'sqlite' => 'ALTER TABLE email_queue ADD COLUMN available_at DATETIME NULL',
                ],
                'claimed_at' => [
                    'mysql' => 'ALTER TABLE email_queue ADD COLUMN claimed_at DATETIME NULL AFTER available_at',
                    'sqlite' => 'ALTER TABLE email_queue ADD COLUMN claimed_at DATETIME NULL',
                ],
                'claim_token' => [
                    'mysql' => 'ALTER TABLE email_queue ADD COLUMN claim_token VARCHAR(64) NULL AFTER claimed_at',
                    'sqlite' => 'ALTER TABLE email_queue ADD COLUMN claim_token TEXT NULL',
                ],
                'last_error' => [
                    'mysql' => 'ALTER TABLE email_queue ADD COLUMN last_error VARCHAR(1000) NULL AFTER claim_token',
                    'sqlite' => 'ALTER TABLE email_queue ADD COLUMN last_error TEXT NULL',
                ],
            ];
            foreach ($retryColumns as $column => $sqlMap) {
                if (!isset($columns[$column])) {
                    $db->pdo->exec($sqlMap[$driver]);
                    $columns[$column] = true;
                }
            }

            if ($driver === 'sqlite') {
                $db->pdo->exec('CREATE INDEX IF NOT EXISTS idx_email_ready ON email_queue(status, available_at, created_at)');
                $db->pdo->exec('CREATE INDEX IF NOT EXISTS idx_email_claim ON email_queue(claim_token)');
            } else {
                $indexRows = $db->pdo->query('SHOW INDEX FROM email_queue')->fetchAll(PDO::FETCH_ASSOC);
                $indexes = [];
                foreach ($indexRows as $indexRow) {
                    if (isset($indexRow['Key_name'])) {
                        $indexes[strtolower((string)$indexRow['Key_name'])] = true;
                    }
                }
                if (!isset($indexes['idx_email_ready'])) {
                    $db->pdo->exec('CREATE INDEX idx_email_ready ON email_queue(status, available_at, created_at)');
                }
                if (!isset($indexes['idx_email_claim'])) {
                    $db->pdo->exec('CREATE INDEX idx_email_claim ON email_queue(claim_token)');
                }
            }

            $requiredColumns = [
                'id', 'to_email', 'subject', 'body', 'status', 'attempt_count',
                'available_at', 'claimed_at', 'claim_token', 'last_error', 'created_at', 'sent_at',
            ];
            $columns = self::fetchTableColumns($db, $driver, 'email_queue');
            $missing = array_values(array_filter(
                $requiredColumns,
                static fn(string $column): bool => !isset($columns[$column])
            ));
            if ($missing !== []) {
                return [
                    'added' => $exists ? [] : ['email_queue'],
                    'alreadyPresent' => $exists ? ['email_queue'] : [],
                    'errors' => ['email_queue is missing required columns: ' . implode(', ', $missing)],
                ];
            }

            return [
                'added' => $exists ? [] : ['email_queue'],
                'alreadyPresent' => $exists ? ['email_queue'] : [],
                'errors' => [],
            ];
        } catch (Throwable $e) {
            return [
                'added' => [],
                'alreadyPresent' => [],
                'errors' => ['Failed ensuring email_queue: ' . $e->getMessage()],
            ];
        }
    }

    /**
     * Create the persistent throttle store used by LoginRateLimiter.
     *
     * @return array{added:array<int,string>, alreadyPresent:array<int,string>, errors:array<int,string>}
     */
    public static function ensureLoginAttemptsTable(Db $db): array
    {
        $driver = $db->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if (!in_array($driver, ['mysql', 'sqlite'], true)) {
            throw new RuntimeException('Unsupported database driver: ' . $driver);
        }

        try {
            $exists = $driver === 'mysql'
                ? (bool) $db->pdo->query("SHOW TABLES LIKE 'login_attempts'")->fetchColumn()
                : (bool) $db->pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'login_attempts'")->fetchColumn();

            $sql = $driver === 'mysql'
                ? "CREATE TABLE IF NOT EXISTS login_attempts (
                    key_hash CHAR(64) NOT NULL PRIMARY KEY,
                    attempts INT UNSIGNED NOT NULL DEFAULT 0,
                    window_started_at BIGINT UNSIGNED NOT NULL,
                    last_failed_at BIGINT UNSIGNED NOT NULL,
                    INDEX idx_login_attempts_last_failed (last_failed_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
                : "CREATE TABLE IF NOT EXISTS login_attempts (
                    key_hash TEXT NOT NULL PRIMARY KEY,
                    attempts INTEGER NOT NULL DEFAULT 0,
                    window_started_at INTEGER NOT NULL,
                    last_failed_at INTEGER NOT NULL
                )";
            $db->pdo->exec($sql);
            if ($driver === 'sqlite') {
                $db->pdo->exec('CREATE INDEX IF NOT EXISTS idx_login_attempts_last_failed ON login_attempts(last_failed_at)');
            }

            return [
                'added' => $exists ? [] : ['login_attempts'],
                'alreadyPresent' => $exists ? ['login_attempts'] : [],
                'errors' => [],
            ];
        } catch (Throwable $e) {
            return [
                'added' => [],
                'alreadyPresent' => [],
                'errors' => ['Failed ensuring login_attempts: ' . $e->getMessage()],
            ];
        }
    }

    /**
     * Create the persistent throttle store used by PermitSubmissionRateLimiter.
     *
     * @return array{added:array<int,string>, alreadyPresent:array<int,string>, errors:array<int,string>}
     */
    public static function ensurePublicRateLimitsTable(Db $db): array
    {
        $driver = $db->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if (!in_array($driver, ['mysql', 'sqlite'], true)) {
            throw new RuntimeException('Unsupported database driver: ' . $driver);
        }

        try {
            $exists = $driver === 'mysql'
                ? (bool)$db->pdo->query("SHOW TABLES LIKE 'public_rate_limits'")->fetchColumn()
                : (bool)$db->pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'public_rate_limits'")->fetchColumn();

            $sql = $driver === 'mysql'
                ? "CREATE TABLE IF NOT EXISTS public_rate_limits (
                    key_hash CHAR(64) NOT NULL PRIMARY KEY,
                    attempts INT UNSIGNED NOT NULL DEFAULT 0,
                    window_started_at BIGINT UNSIGNED NOT NULL,
                    last_attempt_at BIGINT UNSIGNED NOT NULL,
                    INDEX idx_public_limits_last_attempt (last_attempt_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
                : "CREATE TABLE IF NOT EXISTS public_rate_limits (
                    key_hash TEXT NOT NULL PRIMARY KEY,
                    attempts INTEGER NOT NULL DEFAULT 0,
                    window_started_at INTEGER NOT NULL,
                    last_attempt_at INTEGER NOT NULL
                )";
            $db->pdo->exec($sql);
            if ($driver === 'sqlite') {
                $db->pdo->exec('CREATE INDEX IF NOT EXISTS idx_public_limits_last_attempt ON public_rate_limits(last_attempt_at)');
            }

            return [
                'added' => $exists ? [] : ['public_rate_limits'],
                'alreadyPresent' => $exists ? ['public_rate_limits'] : [],
                'errors' => [],
            ];
        } catch (Throwable $e) {
            return [
                'added' => [],
                'alreadyPresent' => [],
                'errors' => ['Failed ensuring public_rate_limits: ' . $e->getMessage()],
            ];
        }
    }

    /**
     * Ensure CLI workers can coordinate through short-lived database leases.
     *
     * @return array{added:array<int,string>, alreadyPresent:array<int,string>, errors:array<int,string>}
     */
    public static function ensureWorkerLocksTable(Db $db): array
    {
        $driver = $db->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if (!in_array($driver, ['mysql', 'sqlite'], true)) {
            throw new RuntimeException('Unsupported database driver: ' . $driver);
        }

        try {
            $exists = $driver === 'mysql'
                ? (bool)$db->pdo->query("SHOW TABLES LIKE 'worker_locks'")->fetchColumn()
                : (bool)$db->pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'worker_locks'")->fetchColumn();

            $sql = $driver === 'mysql'
                ? "CREATE TABLE IF NOT EXISTS worker_locks (
                    name VARCHAR(100) NOT NULL PRIMARY KEY,
                    owner_token CHAR(64) NOT NULL,
                    acquired_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    expires_at DATETIME NOT NULL,
                    INDEX idx_worker_locks_expires (expires_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
                : "CREATE TABLE IF NOT EXISTS worker_locks (
                    name TEXT NOT NULL PRIMARY KEY,
                    owner_token TEXT NOT NULL,
                    acquired_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    expires_at DATETIME NOT NULL
                )";
            $db->pdo->exec($sql);
            if ($driver === 'sqlite') {
                $db->pdo->exec('CREATE INDEX IF NOT EXISTS idx_worker_locks_expires ON worker_locks(expires_at)');
            }

            return [
                'added' => $exists ? [] : ['worker_locks'],
                'alreadyPresent' => $exists ? ['worker_locks'] : [],
                'errors' => [],
            ];
        } catch (Throwable $e) {
            return [
                'added' => [],
                'alreadyPresent' => [],
                'errors' => ['Failed ensuring worker_locks: ' . $e->getMessage()],
            ];
        }
    }

    /**
     * Ensure key columns exist on the form_templates table.
     *
     * @return array{added:array<int,string>, alreadyPresent:array<int,string>, errors:array<int,string>}
     */
    public static function ensureFormTemplateColumns(Db $db): array
    {
        $required = [
            'created_by' => [
                'mysql' => 'ALTER TABLE form_templates ADD COLUMN created_by VARCHAR(191) NULL AFTER json_schema',
                'sqlite' => 'ALTER TABLE form_templates ADD COLUMN created_by TEXT NULL',
            ],
            'published_at' => [
                'mysql' => 'ALTER TABLE form_templates ADD COLUMN published_at DATETIME NULL AFTER created_by',
                'sqlite' => 'ALTER TABLE form_templates ADD COLUMN published_at DATETIME NULL',
            ],
            'updated_at' => [
                'mysql' => 'ALTER TABLE form_templates ADD COLUMN updated_at DATETIME NULL AFTER published_at',
                'sqlite' => 'ALTER TABLE form_templates ADD COLUMN updated_at DATETIME NULL',
            ],
        ];

        $driver = $db->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if (!in_array($driver, ['mysql', 'sqlite'], true)) {
            throw new RuntimeException('Unsupported database driver: ' . $driver);
        }

        $existing = self::fetchExistingColumns($db, $driver);

        $added = [];
        $already = [];
        $errors = [];

        foreach ($required as $column => $sqlMap) {
            if (isset($existing[$column])) {
                $already[] = $column;
                continue;
            }

            $sql = $sqlMap[$driver] ?? null;
            if ($sql === null) {
                $errors[] = 'No migration defined for column: ' . $column;
                continue;
            }

            try {
                $db->pdo->exec($sql);
                $added[] = $column;
            } catch (Throwable $e) {
                $errors[] = sprintf('Failed adding %s: %s', $column, $e->getMessage());
            }
        }

        return [
            'added' => $added,
            'alreadyPresent' => $already,
            'errors' => $errors,
        ];
    }

    /**
     * @return array<string,bool>
     */
    private static function fetchExistingColumns(Db $db, string $driver): array
    {
        $columns = [];

        if ($driver === 'mysql') {
            $stmt = $db->pdo->query('SHOW COLUMNS FROM form_templates');
            if ($stmt) {
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    if (!empty($row['Field'])) {
                        $columns[strtolower($row['Field'])] = true;
                    }
                }
            }
        } elseif ($driver === 'sqlite') {
            $stmt = $db->pdo->query("PRAGMA table_info(form_templates)");
            if ($stmt) {
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    if (!empty($row['name'])) {
                        $columns[strtolower($row['name'])] = true;
                    }
                }
            }
        }

        return $columns;
    }

    /**
     * Ensure key columns exist on the forms table (runtime-safe migrations).
     * Currently adds: work_started_at DATETIME NULL
     *
     * @return array{added:array<int,string>, alreadyPresent:array<int,string>, errors:array<int,string>}
     */
    public static function ensureFormsColumns(Db $db): array
    {
        $required = [
            'work_started_at' => [
                'mysql' => 'ALTER TABLE forms ADD COLUMN work_started_at DATETIME NULL AFTER approved_at',
                'sqlite' => 'ALTER TABLE forms ADD COLUMN work_started_at DATETIME NULL',
            ],
        ];

        $driver = $db->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if (!in_array($driver, ['mysql', 'sqlite'], true)) {
            throw new RuntimeException('Unsupported database driver: ' . $driver);
        }

        // Read existing columns for forms table
        $existing = [];
        if ($driver === 'mysql') {
            $stmt = $db->pdo->query('SHOW COLUMNS FROM forms');
            if ($stmt) {
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    if (!empty($row['Field'])) {
                        $existing[strtolower($row['Field'])] = true;
                    }
                }
            }
        } else { // sqlite
            $stmt = $db->pdo->query('PRAGMA table_info(forms)');
            if ($stmt) {
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    if (!empty($row['name'])) {
                        $existing[strtolower($row['name'])] = true;
                    }
                }
            }
        }

        $added = [];
        $already = [];
        $errors = [];

        foreach ($required as $column => $sqlMap) {
            if (isset($existing[$column])) {
                $already[] = $column;
                continue;
            }
            $sql = $sqlMap[$driver] ?? null;
            if (!$sql) {
                $errors[] = 'No migration defined for column: ' . $column;
                continue;
            }
            try {
                $db->pdo->exec($sql);
                $added[] = $column;
            } catch (Throwable $e) {
                $errors[] = sprintf('Failed adding %s: %s', $column, $e->getMessage());
            }
        }

        return [
            'added' => $added,
            'alreadyPresent' => $already,
            'errors' => $errors,
        ];
    }

    /**
     * Ensure bearer links and human-facing permit references cannot be reused.
     * Blank legacy values are normalised to NULL because both supported engines
     * allow multiple NULL values in a unique index.
     *
     * @return array{added:array<int,string>, alreadyPresent:array<int,string>, errors:array<int,string>}
     */
    public static function ensureFormsUniqueIndexes(Db $db): array
    {
        $driver = $db->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if (!in_array($driver, ['mysql', 'sqlite'], true)) {
            throw new RuntimeException('Unsupported database driver: ' . $driver);
        }

        $added = [];
        $already = [];
        $errors = [];
        $definitions = [
            'ref_number' => [
                'target' => 'uq_forms_ref_number',
                'legacy' => 'idx_ref_number',
            ],
            'unique_link' => [
                'target' => 'uq_forms_unique_link',
                'legacy' => 'idx_forms_unique_link',
            ],
        ];

        try {
            foreach (array_keys($definitions) as $column) {
                $db->pdo->exec("UPDATE forms SET {$column} = NULL WHERE {$column} IS NOT NULL AND TRIM({$column}) = ''");
            }
        } catch (Throwable $e) {
            return [
                'added' => [],
                'alreadyPresent' => [],
                'errors' => ['Failed normalising blank permit identifiers: ' . $e->getMessage()],
            ];
        }

        /** @return null|bool Null when absent, otherwise whether the index is unique. */
        $indexIsUnique = static function (string $indexName) use ($db, $driver): ?bool {
            if ($driver === 'mysql') {
                $stmt = $db->pdo->prepare(
                    'SELECT non_unique FROM information_schema.statistics ' .
                    'WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ? LIMIT 1'
                );
                $stmt->execute(['forms', $indexName]);
                $value = $stmt->fetchColumn();
                return $value === false ? null : (int) $value === 0;
            }

            $stmt = $db->pdo->query('PRAGMA index_list(forms)');
            foreach ($stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [] as $row) {
                if (($row['name'] ?? null) === $indexName) {
                    return (int) ($row['unique'] ?? 0) === 1;
                }
            }
            return null;
        };

        foreach ($definitions as $column => $definition) {
            try {
                $duplicateSql = "SELECT COUNT(*) FROM (" .
                    "SELECT {$column} FROM forms WHERE {$column} IS NOT NULL " .
                    "GROUP BY {$column} HAVING COUNT(*) > 1" .
                    ") AS duplicate_values";
                $duplicateGroups = (int) $db->pdo->query($duplicateSql)->fetchColumn();
                if ($duplicateGroups > 0) {
                    $errors[] = sprintf(
                        'forms.%s contains %d duplicate non-empty value group(s); resolve them before production.',
                        $column,
                        $duplicateGroups
                    );
                    continue;
                }

                $target = $definition['target'];
                $targetState = $indexIsUnique($target);
                if ($targetState === true) {
                    $already[] = $target;
                } else {
                    if ($targetState === false) {
                        $db->pdo->exec("DROP INDEX {$target}" . ($driver === 'mysql' ? ' ON forms' : ''));
                    }
                    $db->pdo->exec("CREATE UNIQUE INDEX {$target} ON forms ({$column})");
                    $added[] = $target;
                }

                $legacy = $definition['legacy'];
                if ($indexIsUnique($legacy) === false) {
                    $db->pdo->exec("DROP INDEX {$legacy}" . ($driver === 'mysql' ? ' ON forms' : ''));
                }
            } catch (Throwable $e) {
                $errors[] = sprintf('Failed ensuring unique forms.%s: %s', $column, $e->getMessage());
            }
        }

        return [
            'added' => $added,
            'alreadyPresent' => $already,
            'errors' => $errors,
        ];
    }

    /**
     * Bring legacy activity_log tables up to the schema used by the logger and
     * admin dashboard. Every change is discovered before it is applied, making
     * this safe to run repeatedly on older MySQL/MariaDB and SQLite installs.
     *
     * @return array{added:array<int,string>, alreadyPresent:array<int,string>, errors:array<int,string>}
     */
    public static function ensureActivityLogColumns(Db $db): array
    {
        $driver = $db->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if (!in_array($driver, ['mysql', 'sqlite'], true)) {
            throw new RuntimeException('Unsupported database driver: ' . $driver);
        }

        $createSql = $driver === 'mysql'
            ? "CREATE TABLE IF NOT EXISTS activity_log (
                id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                `timestamp` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                user_id VARCHAR(36) NULL,
                type VARCHAR(64) NULL,
                user_email VARCHAR(255) NULL,
                action VARCHAR(100) NOT NULL DEFAULT 'unknown',
                category VARCHAR(50) NOT NULL DEFAULT 'general',
                resource_type VARCHAR(50) NULL,
                resource_id VARCHAR(100) NULL,
                description TEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                ip_address VARCHAR(45) NULL,
                user_agent TEXT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'success',
                INDEX idx_timestamp (`timestamp`),
                INDEX idx_user_id (user_id),
                INDEX idx_action (action)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            : "CREATE TABLE IF NOT EXISTS activity_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                timestamp DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                user_id TEXT NULL,
                type TEXT NULL,
                user_email TEXT NULL,
                action TEXT NOT NULL DEFAULT 'unknown',
                category TEXT NOT NULL DEFAULT 'general',
                resource_type TEXT NULL,
                resource_id TEXT NULL,
                description TEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                ip_address TEXT NULL,
                user_agent TEXT NULL,
                status TEXT NOT NULL DEFAULT 'success'
            )";

        $added = [];
        $already = [];
        $errors = [];

        try {
            $db->pdo->exec($createSql);
        } catch (Throwable $e) {
            return [
                'added' => [],
                'alreadyPresent' => [],
                'errors' => ['Failed creating activity_log: ' . $e->getMessage()],
            ];
        }

        $required = [
            'timestamp' => [
                'mysql' => 'ALTER TABLE activity_log ADD COLUMN `timestamp` DATETIME NULL',
                'sqlite' => 'ALTER TABLE activity_log ADD COLUMN timestamp DATETIME NULL',
            ],
            'user_id' => [
                'mysql' => 'ALTER TABLE activity_log ADD COLUMN user_id VARCHAR(36) NULL',
                'sqlite' => 'ALTER TABLE activity_log ADD COLUMN user_id TEXT NULL',
            ],
            'type' => [
                'mysql' => 'ALTER TABLE activity_log ADD COLUMN type VARCHAR(64) NULL',
                'sqlite' => 'ALTER TABLE activity_log ADD COLUMN type TEXT NULL',
            ],
            'user_email' => [
                'mysql' => 'ALTER TABLE activity_log ADD COLUMN user_email VARCHAR(255) NULL',
                'sqlite' => 'ALTER TABLE activity_log ADD COLUMN user_email TEXT NULL',
            ],
            'action' => [
                'mysql' => "ALTER TABLE activity_log ADD COLUMN action VARCHAR(100) NULL DEFAULT 'unknown'",
                'sqlite' => "ALTER TABLE activity_log ADD COLUMN action TEXT NULL DEFAULT 'unknown'",
            ],
            'category' => [
                'mysql' => "ALTER TABLE activity_log ADD COLUMN category VARCHAR(50) NULL DEFAULT 'general'",
                'sqlite' => "ALTER TABLE activity_log ADD COLUMN category TEXT NULL DEFAULT 'general'",
            ],
            'resource_type' => [
                'mysql' => 'ALTER TABLE activity_log ADD COLUMN resource_type VARCHAR(50) NULL',
                'sqlite' => 'ALTER TABLE activity_log ADD COLUMN resource_type TEXT NULL',
            ],
            'resource_id' => [
                'mysql' => 'ALTER TABLE activity_log ADD COLUMN resource_id VARCHAR(100) NULL',
                'sqlite' => 'ALTER TABLE activity_log ADD COLUMN resource_id TEXT NULL',
            ],
            'description' => [
                'mysql' => 'ALTER TABLE activity_log ADD COLUMN description TEXT NULL',
                'sqlite' => 'ALTER TABLE activity_log ADD COLUMN description TEXT NULL',
            ],
            'created_at' => [
                'mysql' => 'ALTER TABLE activity_log ADD COLUMN created_at DATETIME NULL',
                'sqlite' => 'ALTER TABLE activity_log ADD COLUMN created_at DATETIME NULL',
            ],
            'ip_address' => [
                'mysql' => 'ALTER TABLE activity_log ADD COLUMN ip_address VARCHAR(45) NULL',
                'sqlite' => 'ALTER TABLE activity_log ADD COLUMN ip_address TEXT NULL',
            ],
            'user_agent' => [
                'mysql' => 'ALTER TABLE activity_log ADD COLUMN user_agent TEXT NULL',
                'sqlite' => 'ALTER TABLE activity_log ADD COLUMN user_agent TEXT NULL',
            ],
            'status' => [
                'mysql' => "ALTER TABLE activity_log ADD COLUMN status VARCHAR(20) NULL DEFAULT 'success'",
                'sqlite' => "ALTER TABLE activity_log ADD COLUMN status TEXT NULL DEFAULT 'success'",
            ],
        ];

        $existing = self::fetchTableColumns($db, $driver, 'activity_log');
        foreach ($required as $column => $sqlMap) {
            if (isset($existing[$column])) {
                $already[] = $column;
                continue;
            }

            try {
                $db->pdo->exec($sqlMap[$driver]);
                $added[] = $column;
                $existing[$column] = true;
            } catch (Throwable $e) {
                $errors[] = sprintf('Failed adding activity_log.%s: %s', $column, $e->getMessage());
            }
        }

        // Preserve useful data from legacy column names after all canonical
        // columns exist. These updates are deliberately repeatable.
        try {
            if (isset($existing['details'])) {
                $db->pdo->exec("UPDATE activity_log SET description = details WHERE (description IS NULL OR description = '') AND details IS NOT NULL");
            }
            $db->pdo->exec("UPDATE activity_log SET type = action WHERE (type IS NULL OR type = '') AND action IS NOT NULL");
            $db->pdo->exec("UPDATE activity_log SET action = type WHERE (action IS NULL OR action = '' OR action = 'unknown') AND type IS NOT NULL");
            $db->pdo->exec("UPDATE activity_log SET category = 'general' WHERE category IS NULL OR category = ''");
            $db->pdo->exec("UPDATE activity_log SET status = 'success' WHERE status IS NULL OR status = ''");
            $db->pdo->exec('UPDATE activity_log SET `timestamp` = created_at WHERE `timestamp` IS NULL AND created_at IS NOT NULL');
            $db->pdo->exec('UPDATE activity_log SET created_at = `timestamp` WHERE created_at IS NULL AND `timestamp` IS NOT NULL');
        } catch (Throwable $e) {
            $errors[] = 'Failed normalising legacy activity data: ' . $e->getMessage();
        }

        if ($driver === 'sqlite') {
            try {
                $db->pdo->exec('CREATE INDEX IF NOT EXISTS idx_timestamp ON activity_log(timestamp)');
                $db->pdo->exec('CREATE INDEX IF NOT EXISTS idx_user_id ON activity_log(user_id)');
                $db->pdo->exec('CREATE INDEX IF NOT EXISTS idx_action ON activity_log(action)');
            } catch (Throwable $e) {
                $errors[] = 'Failed creating activity log indexes: ' . $e->getMessage();
            }
        }

        return [
            'added' => $added,
            'alreadyPresent' => $already,
            'errors' => $errors,
        ];
    }

    /**
     * @return array<string,bool>
     */
    private static function fetchTableColumns(Db $db, string $driver, string $table): array
    {
        if (!in_array($table, ['activity_log', 'email_queue', 'form_templates', 'forms'], true)) {
            throw new RuntimeException('Unsupported table inspection request: ' . $table);
        }

        $columns = [];
        $query = $driver === 'mysql'
            ? 'SHOW COLUMNS FROM `' . $table . '`'
            : 'PRAGMA table_info(' . $table . ')';
        $stmt = $db->pdo->query($query);
        if (!$stmt) {
            return $columns;
        }

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $name = $driver === 'mysql' ? ($row['Field'] ?? null) : ($row['name'] ?? null);
            if (is_string($name) && $name !== '') {
                $columns[strtolower($name)] = true;
            }
        }

        return $columns;
    }
}
