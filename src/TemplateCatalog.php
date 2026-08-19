<?php
declare(strict_types=1);

namespace Permits;

/**
 * Presentation and migration helpers for template pickers.
 *
 * Historical template versions remain in the database for existing records,
 * while new work starts from the preferred/current template. Legacy names are
 * folded into their stronger canonical replacement and inspection checklists
 * are identified separately from permit-to-work templates.
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
        'building inspection permit' => 'Building Inspection Checklist',
        'final inspection permit' => 'Final Inspection Checklist',
        'site safety inspection permit' => 'Site Safety Inspection Checklist',
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
        'blasting/explosives permit' => 'blasting-explosives-v2',
        'restricted area entry permit' => 'restricted-area-entry-v2',
        'vehicle/equipment access permit' => 'vehicle-equipment-access-v2',
        'concrete pouring permit' => 'concrete-pouring-v2',
        'building inspection checklist' => 'building-inspection-v2',
        'final inspection checklist' => 'final-inspection-v2',
        'site safety inspection checklist' => 'site-safety-inspection-v2',
    ];

    /**
     * Explicit replacements for old direct-start URLs. Existing records retain
     * their original template_id; this map is only for starting new work.
     *
     * @var array<string,string>
     */
    private const SUPERSEDED_IDS = [
        'general-work-v1' => 'general-ptw-v1',
        'crane-lifting-v1' => 'lifting-operations-v1',
        'electrical-work-v1' => 'electrical-isolation-v1',
        'roof-work-v1' => 'roof-access-v1',
        'welding-cutting-v1' => 'hot-works-v2',
        'hazardous-materials-v1' => 'hazardous-substances-v1',
        'road-traffic-management-v1' => 'traffic-management-v1',
        'excavation-v1' => 'permit-to-dig-v2',
        'permit-to-dig-v1' => 'permit-to-dig-v2',
        'working-at-heights-v1' => 'working-at-height-v2',
        'asbestos-removal-v1' => 'asbestos-work-v2',
        'hot-works-v1' => 'hot-works-v2',
        'lockout-tagout-v1' => 'lockout-tagout-v2',
        'confined-space-v1' => 'confined-space-entry-v2',
        'confined-space-entry-v1' => 'confined-space-entry-v2',
        'temporary-works-v1' => 'temporary-works-v2',
        'scaffolding-v1' => 'scaffolding-v2',
        'demolition-v1' => 'demolition-v2',
        'blasting-explosives-v1' => 'blasting-explosives-v2',
        'restricted-area-entry-v1' => 'restricted-area-entry-v2',
        'vehicle-equipment-access-v1' => 'vehicle-equipment-access-v2',
        'concrete-pouring-v1' => 'concrete-pouring-v2',
        'building-inspection-v1' => 'building-inspection-v2',
        'final-inspection-v1' => 'final-inspection-v2',
        'site-safety-inspection-v1' => 'site-safety-inspection-v2',
    ];

    /** @var array<int,string> */
    private const INSPECTION_IDS = [
        'building-inspection-v1',
        'building-inspection-v2',
        'final-inspection-v1',
        'final-inspection-v2',
        'site-safety-inspection-v1',
        'site-safety-inspection-v2',
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

    public static function replacementForId(string $templateId): ?string
    {
        $templateId = trim($templateId);
        if ($templateId === '') {
            return null;
        }

        return self::SUPERSEDED_IDS[$templateId] ?? null;
    }

    /** @param array<string,mixed>|string $template */
    public static function isInspection($template): bool
    {
        $id = is_array($template)
            ? trim((string)($template['id'] ?? ''))
            : trim((string)$template);

        if ($id !== '' && in_array($id, self::INSPECTION_IDS, true)) {
            return true;
        }

        if (!is_array($template)) {
            return false;
        }

        $name = mb_strtolower(self::canonicalName(trim((string)($template['name'] ?? ''))), 'UTF-8');
        return in_array($name, [
            'building inspection checklist',
            'final inspection checklist',
            'site safety inspection checklist',
        ], true);
    }

    /** @param array<string,mixed>|string $template */
    public static function publicStartPath($template): string
    {
        $id = is_array($template)
            ? trim((string)($template['id'] ?? ''))
            : trim((string)$template);

        return self::isInspection($template)
            ? '/create-inspection-public.php?template=' . rawurlencode($id)
            : '/create-permit-public.php?template=' . rawurlencode($id);
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
