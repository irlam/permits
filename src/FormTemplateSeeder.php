<?php

namespace Permits;

use PDO;
use RuntimeException;
use Throwable;

class FormTemplateSeeder
{
    private static ?bool $supportsFormStructure = null;

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
        $supportsFormStructure = self::supportsFormStructure($db);

        if ($driver === 'mysql') {
            if ($supportsFormStructure) {
                $sql = 'INSERT INTO form_templates (id, name, version, json_schema, form_structure, created_by, published_at, updated_at)
                        VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())
                        ON DUPLICATE KEY UPDATE name = VALUES(name), version = VALUES(version), json_schema = VALUES(json_schema), form_structure = VALUES(form_structure), updated_at = NOW()';
            } else {
                $sql = 'INSERT INTO form_templates (id, name, version, json_schema, created_by, published_at, updated_at)
                        VALUES (?, ?, ?, ?, ?, NOW(), NOW())
                        ON DUPLICATE KEY UPDATE name = VALUES(name), version = VALUES(version), json_schema = VALUES(json_schema), updated_at = NOW()';
            }
        } else {
            if ($supportsFormStructure) {
                $sql = 'INSERT INTO form_templates (id, name, version, json_schema, form_structure, created_by, published_at, updated_at)
                        VALUES (?, ?, ?, ?, ?, ?, datetime("now"), datetime("now"))
                        ON CONFLICT(id) DO UPDATE SET name = excluded.name, version = excluded.version, json_schema = excluded.json_schema, form_structure = excluded.form_structure, updated_at = datetime("now")';
            } else {
                $sql = 'INSERT INTO form_templates (id, name, version, json_schema, created_by, published_at, updated_at)
                        VALUES (?, ?, ?, ?, ?, datetime("now"), datetime("now"))
                        ON CONFLICT(id) DO UPDATE SET name = excluded.name, version = excluded.version, json_schema = excluded.json_schema, updated_at = datetime("now")';
            }
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

            $formStructureJson = null;
            if ($supportsFormStructure) {
                $formStructureJson = self::buildFormStructurePayload($db, $schema, $id);
            }

            try {
                $params = [
                    $id,
                    $name,
                    $version,
                    json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ];

                if ($supportsFormStructure) {
                    $params[] = $formStructureJson;
                }

                $params[] = $createdBy;

                $stmt->execute($params);
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

    /**
     * Convert a rich schema to the simplified public form structure.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function buildPublicFormStructure(array $schema): array
    {
        $structure = [];

        $metaFields = $schema['meta']['fields'] ?? [];
        if (is_array($metaFields) && !empty($metaFields)) {
            $structure[] = [
                'title' => $schema['meta']['title'] ?? 'Permit Details',
                'fields' => self::mapFieldsForPublicForm($metaFields, 'meta'),
            ];
        }

        if (!empty($schema['sections']) && is_array($schema['sections'])) {
            foreach ($schema['sections'] as $index => $section) {
                if (!is_array($section)) {
                    continue;
                }
                $sectionFields = $section['fields'] ?? [];
                if (!is_array($sectionFields) || empty($sectionFields)) {
                    continue;
                }

                $title = (string)($section['title'] ?? ('Section ' . ($index + 1)));
                $structure[] = [
                    'title' => $title,
                    'fields' => self::mapFieldsForPublicForm($sectionFields, 'section' . ($index + 1)),
                ];
            }
        }

        return $structure;
    }

    private static function mapFieldsForPublicForm(array $fields, string $prefix): array
    {
        $mapped = [];

        foreach ($fields as $idx => $field) {
            if (!is_array($field)) {
                continue;
            }

            $label = (string)($field['label'] ?? 'Field');
            $type = strtolower((string)($field['type'] ?? 'text'));
            $required = !empty($field['required']);
            $placeholder = (string)($field['placeholder'] ?? '');
            $options = self::normaliseOptionsForPublicForm($field['options'] ?? []);
            $name = self::resolveFieldName($field, $idx, $prefix);

            $mapped[] = [
                'label' => $label,
                'name' => $name,
                'type' => self::mapFieldType($type, !empty($options)),
                'required' => $required,
                'placeholder' => $placeholder,
                'options' => $options,
            ];
        }

        return $mapped;
    }

    private static function normaliseOptionsForPublicForm($options): array
    {
        $normalised = [];
        if (!is_iterable($options)) {
            return $normalised;
        }

        foreach ($options as $option) {
            if (is_array($option)) {
                $value = (string)($option['value'] ?? ($option['id'] ?? ($option[0] ?? '')));
                if ($value === '') {
                    continue;
                }
                $label = (string)($option['label'] ?? ($option['text'] ?? ($option[1] ?? $value)));
            } else {
                $value = trim((string)$option);
                if ($value === '') {
                    continue;
                }
                $label = $value;
            }

            $normalised[] = [
                'value' => $value,
                'label' => $label,
            ];
        }

        return $normalised;
    }

    private static function mapFieldType(string $type, bool $hasOptions): string
    {
        switch ($type) {
            case 'textarea':
                return 'textarea';
            case 'select':
            case 'dropdown':
                return $hasOptions ? 'select' : 'text';
            case 'multiselect':
            case 'checkbox':
                return $hasOptions ? 'select_multiple' : 'text';
            case 'radio':
                return $hasOptions ? 'radio' : 'text';
            case 'date':
                return 'date';
            case 'time':
                return 'time';
            case 'datetime':
            case 'datetime-local':
                return 'datetime';
            case 'number':
                return 'number';
            case 'email':
                return 'email';
            case 'tel':
            case 'phone':
                return 'tel';
            default:
                return 'text';
        }
    }

    private static function resolveFieldName(array $field, int $index, string $prefix): string
    {
        foreach (['name', 'key', 'id', 'label'] as $candidate) {
            if (!empty($field[$candidate]) && is_string($field[$candidate])) {
                $slug = strtolower(trim(preg_replace('/[^a-z0-9]+/', '_', $field[$candidate]), '_'));
                if ($slug !== '') {
                    return $prefix . '_' . $slug;
                }
            }
        }

        return $prefix . '_field_' . $index;
    }

    private static function buildFormStructurePayload(Db $db, array $schema, string $templateId): string
    {
        $existing = self::fetchExistingFormStructure($db, $templateId);
        if ($existing !== null) {
            $decoded = json_decode($existing, true);
            if (is_array($decoded) && !empty($decoded)) {
                return $existing;
            }
        }

        $structure = self::buildPublicFormStructure($schema);
        $json = json_encode($structure, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $json === false ? '[]' : $json;
    }

    private static function fetchExistingFormStructure(Db $db, string $templateId): ?string
    {
        static $stmt = null;
        if ($stmt === null) {
            $stmt = $db->pdo->prepare('SELECT form_structure FROM form_templates WHERE id = ? LIMIT 1');
        }

        try {
            $stmt->execute([$templateId]);
            $value = $stmt->fetchColumn();
        } catch (Throwable $e) {
            return null;
        }

        return is_string($value) ? $value : null;
    }

    private static function supportsFormStructure(Db $db): bool
    {
        if (self::$supportsFormStructure !== null) {
            return self::$supportsFormStructure;
        }

        $driver = $db->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        try {
            if ($driver === 'mysql') {
                $stmt = $db->pdo->query("SHOW COLUMNS FROM form_templates LIKE 'form_structure'");
                self::$supportsFormStructure = $stmt && $stmt->fetchColumn() !== false;
            } elseif ($driver === 'sqlite') {
                $stmt = $db->pdo->query("PRAGMA table_info(form_templates)");
                self::$supportsFormStructure = false;
                if ($stmt) {
                    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                        if (isset($row['name']) && strtolower((string)$row['name']) === 'form_structure') {
                            self::$supportsFormStructure = true;
                            break;
                        }
                    }
                }
            } else {
                self::$supportsFormStructure = false;
            }
        } catch (Throwable $e) {
            self::$supportsFormStructure = false;
        }

        return self::$supportsFormStructure;
    }
}
