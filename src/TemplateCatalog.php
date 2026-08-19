<?php
declare(strict_types=1);

namespace Permits;

/**
 * Presentation helpers for template pickers.
 *
 * Historical template versions remain in the database for existing permits,
 * while public pickers show only the preferred/newest version of each permit.
 * Legacy overlapping names are folded into their stronger canonical template.
 */
final class TemplateCatalog
{
    /** @var array<string,string> */
    private const NAME_ALIASES = [
        'general work permit' => 'General Permit to Work',
        'crane/lifting operations permit' => 'Lifting Operations Permit',
        'electrical work permit' => 'Electrical Isolation & Energisation Permit',
        'roof work permit' => 'Roof Access Permit',
        'welding/cutting permit' => 'Hot Works Permit',
        'hazardous materials handling permit' => 'Hazardous Substances Handling Permit',
        'road/traffic management permit' => 'Traffic Management Interface Permit',
        'excavation permit' => 'Permit to Dig / Excavation Permit',
        'permit to dig' => 'Permit to Dig / Excavation Permit',
        'working at heights permit' => 'Working at Height Permit',
        'asbestos removal permit' => 'Asbestos Work Permit',
    ];

    /** @var array<string,string> */
    private const PREFERRED_IDS = [
        'general permit to work' => 'general-ptw-v1',
        'lifting operations permit' => 'lifting-operations-v1',
        'electrical isolation & energisation permit' => 'electrical-isolation-v1',
        'roof access permit' => 'roof-access-v1',
        'hot works permit' => 'hot-works-v2',
        'hazardous substances handling permit' => 'hazardous-substances-v1',
        'traffic management interface permit' => 'traffic-management-v1',
        'permit to dig / excavation permit' => 'permit-to-dig-v2',
        'working at height permit' => 'working-at-height-v2',
        'asbestos work permit' => 'asbestos-work-v2',
        'lockout/tagout permit' => 'lockout-tagout-v2',
        'confined space entry permit' => 'confined-space-entry-v2',
        'temporary works permit' => 'temporary-works-v2',
        'scaffolding permit' => 'scaffolding-v2',
        'demolition permit' => 'demolition-v2',
    ];

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

            $name = self::canonicalName($name);
            $key = mb_strtolower($name, 'UTF-8');
            $template['name'] = $name;

            if (!isset($latest[$key]) || self::isNewer($template, $latest[$key], $key)) {
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

    private static function canonicalName(string $name): string
    {
        $key = mb_strtolower($name, 'UTF-8');
        return self::NAME_ALIASES[$key] ?? $name;
    }

    /** @param array<string,mixed> $candidate @param array<string,mixed> $current */
    private static function isNewer(array $candidate, array $current, string $canonicalKey): bool
    {
        $preferredId = self::PREFERRED_IDS[$canonicalKey] ?? null;
        if ($preferredId !== null) {
            $candidatePreferred = hash_equals($preferredId, (string)($candidate['id'] ?? ''));
            $currentPreferred = hash_equals($preferredId, (string)($current['id'] ?? ''));
            if ($candidatePreferred !== $currentPreferred) {
                return $candidatePreferred;
            }
        }

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
