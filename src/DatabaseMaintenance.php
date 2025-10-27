<?php

namespace Permits;

use PDO;
use RuntimeException;
use Throwable;

class DatabaseMaintenance
{
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
}
