<?php

declare(strict_types=1);

use Permits\Db;

if (!function_exists('permitDurationDefaults')) {
    /**
     * Default permit duration presets (label + minutes).
     *
     * @return array<int,array{label:string,minutes:int}>
     */
    function permitDurationDefaults(): array
    {
        return [
            ['label' => '1 hour', 'minutes' => 60],
            ['label' => '4 hours', 'minutes' => 240],
            ['label' => '1 day', 'minutes' => 1440],
        ];
    }
}

if (!function_exists('normalizePermitDurationPresets')) {
    /**
     * Sanitize and deduplicate duration entries.
     *
     * @param array<int,array<string,mixed>> $presets
     * @return array<int,array{label:string,minutes:int}>
     */
    function normalizePermitDurationPresets(array $presets): array
    {
        $normalized = [];

        foreach ($presets as $preset) {
            if (!is_array($preset)) {
                continue;
            }

            $label = trim((string)($preset['label'] ?? ''));
            $minutes = (int)($preset['minutes'] ?? 0);

            if ($label === '' || $minutes <= 0) {
                continue;
            }

            $key = strtolower($label) . '|' . $minutes;
            $normalized[$key] = [
                'label' => $label,
                'minutes' => $minutes,
            ];
        }

        return array_values($normalized);
    }
}

if (!function_exists('getPermitDurationPresets')) {
    /**
     * Retrieve duration presets from the settings table (with default fallback).
     *
     * @return array<int,array{label:string,minutes:int}>
     */
    function getPermitDurationPresets(Db $db): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }

        $defaults = permitDurationDefaults();

        try {
            $stmt = $db->pdo->prepare('SELECT value FROM settings WHERE `key` = ? LIMIT 1');
            $stmt->execute(['permit_duration_presets']);
            $raw = $stmt->fetchColumn();
        } catch (\Throwable $e) {
            return $cache = $defaults;
        }

        if (!is_string($raw) || $raw === '') {
            return $cache = $defaults;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return $cache = $defaults;
        }

        $normalized = normalizePermitDurationPresets($decoded);
        if (empty($normalized)) {
            return $cache = $defaults;
        }

        return $cache = $normalized;
    }
}

if (!function_exists('buildPermitDurationPresetsFromInput')) {
    /**
     * Build preset array from parallel label/minute inputs.
     *
     * @param array<int,string> $labels
     * @param array<int,string|int> $minutes
     * @return array<int,array{label:string,minutes:int}>
     */
    function buildPermitDurationPresetsFromInput(array $labels, array $minutes): array
    {
        $presets = [];
        $count = max(count($labels), count($minutes));

        for ($i = 0; $i < $count; $i++) {
            $label = trim((string)($labels[$i] ?? ''));
            $mins = (int)($minutes[$i] ?? 0);
            $presets[] = [
                'label' => $label,
                'minutes' => $mins,
            ];
        }

        return $presets;
    }
}

if (!function_exists('savePermitDurationPresets')) {
    /**
     * Persist validated duration presets into the settings table.
     *
     * @param array<int,array{label:string,minutes:int}> $presets
     */
    function savePermitDurationPresets(Db $db, array $presets): void
    {
        $normalized = normalizePermitDurationPresets($presets);
        if (empty($normalized)) {
            throw new \InvalidArgumentException('At least one duration preset is required.');
        }

        $encoded = json_encode(array_values($normalized), JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            throw new \RuntimeException('Failed to encode duration presets.');
        }

        $driver = $db->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) ?: 'mysql';
        $nowExpr = $driver === 'sqlite' ? "datetime('now')" : 'NOW()';

        $existsStmt = $db->pdo->prepare('SELECT COUNT(*) FROM settings WHERE `key` = ?');
        $existsStmt->execute(['permit_duration_presets']);
        $exists = (int)$existsStmt->fetchColumn() > 0;

        if ($exists) {
            $sql = "UPDATE settings SET value = ?, updated_at = $nowExpr WHERE `key` = ?";
            $stmt = $db->pdo->prepare($sql);
            $stmt->execute([$encoded, 'permit_duration_presets']);
            return;
        }

        $sql = "INSERT INTO settings (`key`, value, updated_at) VALUES (?, ?, $nowExpr)";
        $stmt = $db->pdo->prepare($sql);
        $stmt->execute(['permit_duration_presets', $encoded]);
    }
}
