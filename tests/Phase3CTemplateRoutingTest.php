<?php
declare(strict_types=1);

use Permits\TemplateCatalog;
use PHPUnit\Framework\TestCase;

final class Phase3CTemplateRoutingTest extends TestCase
{
    public function testSupersededDirectStartIdsResolveToCurrentTemplates(): void
    {
        self::assertSame('hot-works-v2', TemplateCatalog::replacementForId('hot-works-v1'));
        self::assertSame('permit-to-dig-v2', TemplateCatalog::replacementForId('excavation-v1'));
        self::assertSame('lockout-tagout-v2', TemplateCatalog::replacementForId('lockout-tagout-v1'));
        self::assertSame('confined-space-entry-v2', TemplateCatalog::replacementForId('confined-space-v1'));
        self::assertSame('blasting-explosives-v2', TemplateCatalog::replacementForId('blasting-explosives-v1'));
        self::assertSame('building-inspection-v2', TemplateCatalog::replacementForId('building-inspection-v1'));
        self::assertNull(TemplateCatalog::replacementForId('hot-works-v2'));
    }

    public function testInspectionTemplatesUseInspectionEndpoint(): void
    {
        self::assertTrue(TemplateCatalog::isInspection('building-inspection-v2'));
        self::assertTrue(TemplateCatalog::isInspection('final-inspection-v1'));
        self::assertTrue(TemplateCatalog::isInspection([
            'id' => 'custom-id',
            'name' => 'Site Safety Inspection Permit',
        ]));
        self::assertFalse(TemplateCatalog::isInspection('hot-works-v2'));

        self::assertSame(
            '/create-inspection-public.php?template=building-inspection-v2',
            TemplateCatalog::publicStartPath(['id' => 'building-inspection-v2', 'name' => 'Building Inspection Checklist'])
        );
        self::assertSame(
            '/create-permit-public.php?template=hot-works-v2',
            TemplateCatalog::publicStartPath(['id' => 'hot-works-v2', 'name' => 'Hot Works Permit'])
        );
    }

    public function testPickerCanonicalisesOldInspectionNamesAndPrefersV2(): void
    {
        $templates = TemplateCatalog::latestByName([
            ['id' => 'building-inspection-v1', 'name' => 'Building Inspection Permit', 'version' => 9],
            ['id' => 'building-inspection-v2', 'name' => 'Building Inspection Checklist', 'version' => 2],
            ['id' => 'final-inspection-v1', 'name' => 'Final Inspection Permit', 'version' => 8],
            ['id' => 'final-inspection-v2', 'name' => 'Final Inspection Checklist', 'version' => 2],
            ['id' => 'site-safety-inspection-v1', 'name' => 'Site Safety Inspection Permit', 'version' => 7],
            ['id' => 'site-safety-inspection-v2', 'name' => 'Site Safety Inspection Checklist', 'version' => 2],
        ]);

        $byName = [];
        foreach ($templates as $template) {
            $byName[(string)$template['name']] = (string)$template['id'];
        }

        self::assertSame('building-inspection-v2', $byName['Building Inspection Checklist']);
        self::assertSame('final-inspection-v2', $byName['Final Inspection Checklist']);
        self::assertSame('site-safety-inspection-v2', $byName['Site Safety Inspection Checklist']);
    }

    public function testBootstrapProtectsNewWorkButPreservesDraftAndReopenPaths(): void
    {
        $source = file_get_contents(dirname(__DIR__) . '/src/bootstrap.php');
        self::assertIsString($source);

        self::assertStringContainsString('TemplateCatalog::replacementForId', $source);
        self::assertStringContainsString("empty(\$_GET['draft'])", $source);
        self::assertStringContainsString("empty(\$_GET['reopen'])", $source);
        self::assertStringContainsString("'create-inspection-public.php'", $source);
        self::assertStringContainsString("'create-permit-public.php'", $source);
    }

    public function testHomepageEnhancementSeparatesInspectionsFromPermits(): void
    {
        $source = file_get_contents(dirname(__DIR__) . '/assets/phase3c-picker.js');
        self::assertIsString($source);
        self::assertStringContainsString("section.id = 'inspections'", $source);
        self::assertStringContainsString('Inspections &amp; checklists', $source);
        self::assertStringContainsString('create-inspection-public.php?template=', $source);
        self::assertStringContainsString('Start an Inspection', $source);
    }
}
