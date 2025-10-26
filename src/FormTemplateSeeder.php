<?php

namespace Permits;

use PDO;
use RuntimeException;
use Throwable;

class FormTemplateSeeder
{
    /**
     * @return array{processed:int, imported:array<int,string>, errors:array<int,string>}
     */
    public static function importFromDirectory(Db $db, string $directory): array
    {
        $realPath = realpath($directory);
        if ($realPath === false || !is_dir($realPath)) {
            throw new RuntimeException('Preset directory not found: ' . $directory);
        }

        $files = glob($realPath . DIRECTORY_SEPARATOR . '*.json');
        if (!$files) {
            return [
                'processed' => 0,
                'imported' => [],
                'errors' => [],
            ];
        }

        sort($files);

        $driver = $db->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'mysql') {
            $sql = 'INSERT INTO form_templates (id, name, version, json_schema, created_by, published_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, NOW(), NOW())
                    ON DUPLICATE KEY UPDATE name = VALUES(name), version = VALUES(version), json_schema = VALUES(json_schema), updated_at = NOW()';
        } else {
            $sql = 'INSERT INTO form_templates (id, name, version, json_schema, created_by, published_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, datetime("now"), datetime("now"))
                    ON CONFLICT(id) DO UPDATE SET name = excluded.name, version = excluded.version, json_schema = excluded.json_schema, updated_at = datetime("now")';
        }

        $stmt = $db->pdo->prepare($sql);

        $processed = 0;
        $imported = [];
        $errors = [];

        foreach ($files as $file) {
            $raw = file_get_contents($file);
            if ($raw === false) {
                $errors[] = 'Failed to read ' . $file;
                continue;
            }

            $schema = json_decode($raw, true);
            if (!is_array($schema)) {
                $errors[] = 'Invalid JSON in ' . $file;
                continue;
            }

            $id = $schema['id'] ?? null;
            $name = $schema['title'] ?? ($schema['name'] ?? null);
            $version = (int)($schema['version'] ?? 1);

            if (!$id || !$name) {
                $errors[] = 'Schema missing id/title: ' . $file;
                continue;
            }

            $createdBy = 'system';

            try {
                $stmt->execute([
                    $id,
                    $name,
                    $version,
                    json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    $createdBy,
                ]);
                $processed++;
                $imported[] = $id;
            } catch (Throwable $e) {
                $errors[] = sprintf('Failed to import %s: %s', $id, $e->getMessage());
            }
        }

        return [
            'processed' => $processed,
            'imported' => $imported,
            'errors' => $errors,
        ];
    }
}
