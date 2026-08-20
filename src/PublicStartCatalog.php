<?php
declare(strict_types=1);

namespace Permits;

use PDO;

/**
 * Resolves permanent, human-readable start links to the currently preferred
 * active permit/inspection template. Laminated site QR codes therefore survive
 * future v2/v3 template upgrades as long as the canonical template name stays
 * the same.
 */
final class PublicStartCatalog
{
    /** @var array<string,string> */
    private const SLUG_OVERRIDES = [
        'general permit to work' => 'general-ptw',
        'electrical isolation & energisation permit' => 'electrical-isolation',
        'hazardous substances handling permit' => 'hazardous-substances',
        'traffic management interface permit' => 'traffic-management',
        'permit to dig / excavation permit' => 'permit-to-dig',
        'asbestos work permit' => 'asbestos-work',
        'blasting/explosives permit' => 'blasting-explosives',
        'vehicle/equipment access permit' => 'vehicle-equipment-access',
        'building inspection checklist' => 'building-inspection',
        'final inspection checklist' => 'final-inspection',
        'site safety inspection checklist' => 'site-safety-inspection',
    ];

    /** @return array<int,array<string,mixed>> */
    public static function activeTemplates(PDO $pdo): array
    {
        $stmt = $pdo->query(
            'SELECT id, name, version, created_at, active FROM form_templates WHERE active = 1 ORDER BY name ASC, version DESC, created_at DESC'
        );
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        return TemplateCatalog::latestByName(is_array($rows) ? $rows : []);
    }

    /** @param array<string,mixed> $template */
    public static function slug(array $template): string
    {
        $name = trim((string)($template['name'] ?? ''));
        $key = mb_strtolower($name, 'UTF-8');
        if (isset(self::SLUG_OVERRIDES[$key])) {
            return self::SLUG_OVERRIDES[$key];
        }

        $base = preg_replace('/\s+(permit|checklist)$/iu', '', $name) ?? $name;
        $base = str_replace(['&', '/'], [' and ', ' '], $base);
        if (function_exists('iconv')) {
            $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $base);
            if (is_string($ascii) && $ascii !== '') {
                $base = $ascii;
            }
        }
        $slug = strtolower($base);
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
        $slug = trim($slug, '-');

        if ($slug === '') {
            $id = trim((string)($template['id'] ?? 'template'));
            $slug = preg_replace('/-v\d+$/', '', strtolower($id)) ?? 'template';
        }

        return substr($slug, 0, 80);
    }

    /** @return array<string,mixed>|null */
    public static function findBySlug(PDO $pdo, string $slug): ?array
    {
        $slug = strtolower(trim($slug));
        if (preg_match('/^[a-z0-9][a-z0-9-]{0,79}$/', $slug) !== 1) {
            return null;
        }

        foreach (self::activeTemplates($pdo) as $template) {
            if (hash_equals(self::slug($template), $slug)) {
                return $template;
            }
        }

        return null;
    }

    /** @param array<string,mixed> $template */
    public static function workflowLabel(array $template): string
    {
        return TemplateCatalog::isInspection($template) ? 'Inspection checklist' : 'Permit to work';
    }

    /** @param array<string,mixed> $template */
    public static function icon(array $template): string
    {
        $name = mb_strtolower((string)($template['name'] ?? ''), 'UTF-8');
        $rules = [
            'hot work' => '🔥',
            'electrical' => '⚡',
            'lockout' => '🔒',
            'dig' => '⛏️',
            'excavation' => '⛏️',
            'height' => '🪜',
            'roof' => '🏗️',
            'confined' => '🕳️',
            'lifting' => '🏗️',
            'scaffold' => '🧱',
            'demolition' => '🚧',
            'asbestos' => '⚠️',
            'hazardous' => '☣️',
            'traffic' => '🚦',
            'vehicle' => '🚚',
            'concrete' => '🧱',
            'temporary works' => '⚙️',
            'blast' => '💥',
            'environment' => '🌿',
            'noise' => '🔊',
            'inspection' => '✅',
        ];

        foreach ($rules as $needle => $icon) {
            if (strpos($name, $needle) !== false) {
                return $icon;
            }
        }

        return TemplateCatalog::isInspection($template) ? '✅' : '📋';
    }
}
