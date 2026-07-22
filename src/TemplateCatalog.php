<?php
declare(strict_types=1);

namespace Permits;

/**
 * Presentation helpers for template pickers.
 *
 * Historical template versions remain in the database for existing permits,
 * while public pickers show only the newest version of each named template.
 */
final class TemplateCatalog
{
    /**
     * @param array<int,array<string,mixed>> $templates
     * @return array<int,array<string,mixed>>
     */
    public static function latestByName(array $templates): array
    {
        $latest = [];

        foreach ($templates as $template) {
            $name = preg_replace('/\s+/u', ' ', trim((string)($template['name'] ?? '')));
            if ($name === null || $name === '') {
                continue;
            }

            $key = mb_strtolower($name, 'UTF-8');
            $template['name'] = $name;

            if (!isset($latest[$key]) || self::isNewer($template, $latest[$key])) {
                $latest[$key] = $template;
            }
        }

        uasort(
            $latest,
            static fn (array $left, array $right): int => strnatcasecmp(
                (string)($left['name'] ?? ''),
                (string)($right['name'] ?? '')
            )
        );

        return array_values($latest);
    }

    /** @param array<string,mixed> $candidate @param array<string,mixed> $current */
    private static function isNewer(array $candidate, array $current): bool
    {
        $candidateVersion = (int)($candidate['version'] ?? 0);
        $currentVersion = (int)($current['version'] ?? 0);
        if ($candidateVersion !== $currentVersion) {
            return $candidateVersion > $currentVersion;
        }

        $candidateDate = strtotime((string)($candidate['updated_at'] ?? $candidate['created_at'] ?? '')) ?: 0;
        $currentDate = strtotime((string)($current['updated_at'] ?? $current['created_at'] ?? '')) ?: 0;
        if ($candidateDate !== $currentDate) {
            return $candidateDate > $currentDate;
        }

        return strcmp((string)($candidate['id'] ?? ''), (string)($current['id'] ?? '')) > 0;
    }
}
