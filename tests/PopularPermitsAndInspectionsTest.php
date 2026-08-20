<?php
declare(strict_types=1);

use Permits\TemplateCatalog;
use PHPUnit\Framework\TestCase;

final class PopularPermitsAndInspectionsTest extends TestCase
{
    private const PERMITS = [
        'fire-system-isolation-v1.json',
        'core-drill-cut-v1.json',
        'breaking-into-services-v1.json',
        'live-electrical-work-v1.json',
        'pressure-testing-v1.json',
        'testing-commissioning-v1.json',
        'mewp-use-v1.json',
        'excavation-entry-v1.json',
        'structural-alteration-v1.json',
        'out-of-hours-working-v1.json',
    ];

    private const INSPECTIONS = [
        'ladder-stepladder-pre-use-v1.json',
        'mewp-daily-inspection-v1.json',
        'harness-inspection-v1.json',
        'excavation-daily-inspection-v1.json',
        'scaffold-weekly-inspection-v1.json',
        'plant-pre-start-inspection-v1.json',
    ];

    /** @return array<string,mixed> */
    private function preset(string $filename): array
    {
        $raw = file_get_contents(dirname(__DIR__) . '/templates/form-presets/' . $filename);
        self::assertIsString($raw, $filename);
        $preset = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($preset, $filename);
        return $preset;
    }

    /** @param array<string,mixed> $preset */
    private function assertSubstantive(array $preset, string $filename): void
    {
        self::assertNotEmpty($preset['id'] ?? null, $filename);
        self::assertNotEmpty($preset['name'] ?? null, $filename);
        self::assertSame(1, $preset['version'] ?? null, $filename);
        self::assertGreaterThanOrEqual(3, count($preset['sections'] ?? []), $filename);

        $keys = [];
        $items = 0;
        foreach (($preset['meta']['fields'] ?? []) as $field) {
            if (is_array($field) && !empty($field['key'])) {
                $keys[] = (string)$field['key'];
            }
        }
        foreach (($preset['sections'] ?? []) as $section) {
            $items += count($section['items'] ?? []);
            foreach (($section['fields'] ?? []) as $field) {
                if (is_array($field) && !empty($field['key'])) {
                    $keys[] = (string)$field['key'];
                }
            }
        }
        self::assertGreaterThanOrEqual(8, $items, $filename);
        self::assertSame($keys, array_values(array_unique($keys)), $filename . ' contains duplicate field keys');
    }

    public function testPopularPermitPresetsUsePermitWorkflowShape(): void
    {
        foreach (self::PERMITS as $filename) {
            $preset = $this->preset($filename);
            $this->assertSubstantive($preset, $filename);
            self::assertNotSame('inspection', $preset['workflow'] ?? null, $filename);
            $metaKeys = array_column($preset['meta']['fields'] ?? [], 'key');
            self::assertContains('validFrom', $metaKeys, $filename);
            self::assertContains('validTo', $metaKeys, $filename);
            self::assertFalse(TemplateCatalog::isInspection($preset), $filename);
        }
    }

    public function testNewInspectionPresetsUseInspectionWorkflow(): void
    {
        foreach (self::INSPECTIONS as $filename) {
            $preset = $this->preset($filename);
            $this->assertSubstantive($preset, $filename);
            self::assertSame('inspection', $preset['workflow'] ?? null, $filename);
            self::assertTrue(TemplateCatalog::isInspection($preset), $filename);
            self::assertStringStartsWith('/create-inspection-public.php?template=', TemplateCatalog::publicStartPath($preset));
        }
    }

    public function testLadderChecklistCoversCorePreUseConditionChecks(): void
    {
        $raw = file_get_contents(dirname(__DIR__) . '/templates/form-presets/ladder-stepladder-pre-use-v1.json');
        self::assertIsString($raw);
        foreach (['Stiles', 'Feet', 'Rungs / steps', 'Locking mechanisms', 'firm, level', 'Overhead electrical'] as $phrase) {
            self::assertStringContainsString($phrase, $raw);
        }
    }

    public function testBlastingIsRetiredFromNewStartPickerWithoutDeletingHistory(): void
    {
        self::assertTrue(TemplateCatalog::isRetiredForNewStart('blasting-explosives-v2'));

        $visible = TemplateCatalog::latestByName([
            ['id' => 'blasting-explosives-v2', 'name' => 'Blasting/Explosives Permit', 'version' => 2],
            ['id' => 'hot-works-v2', 'name' => 'Hot Works Permit', 'version' => 2],
        ]);

        self::assertCount(1, $visible);
        self::assertSame('hot-works-v2', $visible[0]['id']);
    }
}
