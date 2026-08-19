<?php
declare(strict_types=1);

namespace Permits;

use PDO;
use RuntimeException;
use Throwable;

final class Phase4DatabaseMaintenance
{
    /** @return array{added:array<int,string>,alreadyPresent:array<int,string>,errors:array<int,string>} */
    public static function ensureFormEventsTable(Db $db): array
    {
        return self::ensureTable(
            $db,
            'form_events',
            "CREATE TABLE IF NOT EXISTS form_events (
                id CHAR(36) NOT NULL PRIMARY KEY,
                form_id CHAR(36) NOT NULL,
                type VARCHAR(64) NOT NULL,
                at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                by_user VARCHAR(191) NULL,
                payload MEDIUMTEXT NULL,
                INDEX idx_form_events_form_at (form_id, at),
                INDEX idx_form_events_type (type)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS form_events (
                id TEXT NOT NULL PRIMARY KEY,
                form_id TEXT NOT NULL,
                type TEXT NOT NULL,
                at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                by_user TEXT NULL,
                payload TEXT NULL
            )",
            [
                'CREATE INDEX IF NOT EXISTS idx_form_events_form_at ON form_events(form_id, at)',
                'CREATE INDEX IF NOT EXISTS idx_form_events_type ON form_events(type)',
            ]
        );
    }

    /** @return array{added:array<int,string>,alreadyPresent:array<int,string>,errors:array<int,string>} */
    public static function ensurePermitLinksTable(Db $db): array
    {
        return self::ensureTable(
            $db,
            'permit_links',
            "CREATE TABLE IF NOT EXISTS permit_links (
                id CHAR(36) NOT NULL PRIMARY KEY,
                form_a_id CHAR(36) NOT NULL,
                form_b_id CHAR(36) NOT NULL,
                relation_type VARCHAR(32) NOT NULL DEFAULT 'related',
                note TEXT NULL,
                created_by VARCHAR(191) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_permit_links_pair_type (form_a_id, form_b_id, relation_type),
                INDEX idx_permit_links_a (form_a_id),
                INDEX idx_permit_links_b (form_b_id),
                INDEX idx_permit_links_type (relation_type)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS permit_links (
                id TEXT NOT NULL PRIMARY KEY,
                form_a_id TEXT NOT NULL,
                form_b_id TEXT NOT NULL,
                relation_type TEXT NOT NULL DEFAULT 'related',
                note TEXT NULL,
                created_by TEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE(form_a_id, form_b_id, relation_type)
            )",
            [
                'CREATE INDEX IF NOT EXISTS idx_permit_links_a ON permit_links(form_a_id)',
                'CREATE INDEX IF NOT EXISTS idx_permit_links_b ON permit_links(form_b_id)',
                'CREATE INDEX IF NOT EXISTS idx_permit_links_type ON permit_links(relation_type)',
            ]
        );
    }

    /**
     * @param array<int,string> $sqliteIndexes
     * @return array{added:array<int,string>,alreadyPresent:array<int,string>,errors:array<int,string>}
     */
    private static function ensureTable(Db $db, string $table, string $mysqlSql, string $sqliteSql, array $sqliteIndexes): array
    {
        $driver = (string)$db->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if (!in_array($driver, ['mysql', 'sqlite'], true)) {
            throw new RuntimeException('Unsupported database driver: ' . $driver);
        }

        try {
            $exists = $driver === 'mysql'
                ? (bool)$db->pdo->query("SHOW TABLES LIKE " . $db->pdo->quote($table))->fetchColumn()
                : (bool)$db->pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name = " . $db->pdo->quote($table))->fetchColumn();

            $db->pdo->exec($driver === 'mysql' ? $mysqlSql : $sqliteSql);
            if ($driver === 'sqlite') {
                foreach ($sqliteIndexes as $indexSql) {
                    $db->pdo->exec($indexSql);
                }
            }

            return [
                'added' => $exists ? [] : [$table],
                'alreadyPresent' => $exists ? [$table] : [],
                'errors' => [],
            ];
        } catch (Throwable $e) {
            return [
                'added' => [],
                'alreadyPresent' => [],
                'errors' => [sprintf('Failed ensuring %s: %s', $table, $e->getMessage())],
            ];
        }
    }
}
