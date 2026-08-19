<?php
declare(strict_types=1);

use Permits\SystemSettings;
use Permits\TemplateCatalog;
use PHPUnit\Framework\TestCase;

final class BrandingAndTemplateCatalogTest extends TestCase
{
    public function testPrimaryColourAcceptsOnlySixDigitHex(): void
    {
        self::assertSame('#123abc', SystemSettings::normalisePrimaryColour(' #123ABC '));
        self::assertSame(SystemSettings::DEFAULT_PRIMARY_COLOUR, SystemSettings::normalisePrimaryColour('red; color: white'));
        self::assertSame(SystemSettings::DEFAULT_PRIMARY_COLOUR, SystemSettings::normalisePrimaryColour('#fff'));

        $css = SystemSettings::brandingCssVariables(['primary_colour' => '#ffffff']);
        self::assertStringContainsString('--brand-primary:#ffffff', $css);
        self::assertStringContainsString('--brand-on-primary:#0f172a', $css);
    }

    public function testCompanyNameIsTrimmedAndControlCharactersAreRemoved(): void
    {
        self::assertSame('North Site Permits', SystemSettings::normaliseCompanyName("  North\nSite\tPermits  "));
    }

    public function testLogoPathIsRestrictedToRasterBrandingFiles(): void
    {
        self::assertSame(
            'uploads/branding/company-logo-a1b2c3.webp',
            SystemSettings::normaliseLogoPath('/uploads/branding/company-logo-a1b2c3.webp')
        );
        self::assertNull(SystemSettings::normaliseLogoPath('uploads/branding/logo.svg'));
        self::assertNull(SystemSettings::normaliseLogoPath('uploads/branding/../../config.php'));
    }

    public function testTemplatePickerKeepsNewestVersionPerNormalisedName(): void
    {
        $templates = TemplateCatalog::latestByName([
            ['id' => 'old', 'name' => 'Confined Space Entry Permit', 'version' => 1, 'created_at' => '2025-01-01 00:00:00'],
            ['id' => 'confined-space-entry-v2', 'name' => '  confined   space entry permit ', 'version' => 2, 'created_at' => '2026-01-02 00:00:00'],
            ['id' => 'hot-works-v2', 'name' => 'Hot Works Permit', 'version' => 2, 'created_at' => '2026-01-03 00:00:00'],
        ]);

        self::assertCount(2, $templates);
        self::assertSame('confined-space-entry-v2', $templates[0]['id']);
        self::assertSame('hot-works-v2', $templates[1]['id']);
    }

    public function testTemplatePickerConsolidatesLegacyOverlappingPermits(): void
    {
        $templates = TemplateCatalog::latestByName([
            ['id' => 'general-work-v1', 'name' => 'General Work Permit', 'version' => 5],
            ['id' => 'general-ptw-v1', 'name' => 'General Permit to Work', 'version' => 1],
            ['id' => 'crane-lifting-v1', 'name' => 'Crane/Lifting Operations Permit', 'version' => 4],
            ['id' => 'lifting-operations-v1', 'name' => 'Lifting Operations Permit', 'version' => 1],
            ['id' => 'electrical-work-v1', 'name' => 'Electrical Work Permit', 'version' => 3],
            ['id' => 'electrical-isolation-v1', 'name' => 'Electrical Isolation & Energisation Permit', 'version' => 1],
            ['id' => 'roof-work-v1', 'name' => 'Roof Work Permit', 'version' => 3],
            ['id' => 'roof-access-v1', 'name' => 'Roof Access Permit', 'version' => 1],
            ['id' => 'welding-cutting-v1', 'name' => 'Welding/Cutting Permit', 'version' => 3],
            ['id' => 'hot-works-v2', 'name' => 'Hot Works Permit', 'version' => 2],
            ['id' => 'hazardous-materials-v1', 'name' => 'Hazardous Materials Handling Permit', 'version' => 3],
            ['id' => 'hazardous-substances-v1', 'name' => 'Hazardous Substances Handling Permit', 'version' => 1],
            ['id' => 'road-traffic-management-v1', 'name' => 'Road/Traffic Management Permit', 'version' => 3],
            ['id' => 'traffic-management-v1', 'name' => 'Traffic Management Interface Permit', 'version' => 1],
            ['id' => 'excavation-v1', 'name' => 'Excavation Permit', 'version' => 5],
            ['id' => 'permit-to-dig-v1', 'name' => 'Permit to Dig', 'version' => 1],
            ['id' => 'permit-to-dig-v2', 'name' => 'Permit to Dig / Excavation Permit', 'version' => 2],
            ['id' => 'working-at-heights-v1', 'name' => 'Working at Heights Permit', 'version' => 4],
            ['id' => 'working-at-height-v2', 'name' => 'Working at Height Permit', 'version' => 2],
            ['id' => 'asbestos-removal-v1', 'name' => 'Asbestos Removal Permit', 'version' => 4],
            ['id' => 'asbestos-work-v2', 'name' => 'Asbestos Work Permit', 'version' => 2],
        ]);

        $byName = [];
        foreach ($templates as $template) {
            $byName[$template['name']] = $template['id'];
        }

        self::assertSame('general-ptw-v1', $byName['General Permit to Work']);
        self::assertSame('lifting-operations-v1', $byName['Lifting Operations Permit']);
        self::assertSame('electrical-isolation-v1', $byName['Electrical Isolation & Energisation Permit']);
        self::assertSame('roof-access-v1', $byName['Roof Access Permit']);
        self::assertSame('hot-works-v2', $byName['Hot Works Permit']);
        self::assertSame('hazardous-substances-v1', $byName['Hazardous Substances Handling Permit']);
        self::assertSame('traffic-management-v1', $byName['Traffic Management Interface Permit']);
        self::assertSame('permit-to-dig-v2', $byName['Permit to Dig / Excavation Permit']);
        self::assertSame('working-at-height-v2', $byName['Working at Height Permit']);
        self::assertSame('asbestos-work-v2', $byName['Asbestos Work Permit']);
    }

    public function testPublicPermitPagesUseTheStoredBrandingPaletteAndIdentity(): void
    {
        $root = dirname(__DIR__);
        $create = file_get_contents($root . '/create-permit-public.php');
        $view = file_get_contents($root . '/view-permit-public-legacy.php');
        $wrapper = file_get_contents($root . '/view-permit-public.php');

        foreach ([$create, $view] as $source) {
            self::assertIsString($source);
            self::assertStringContainsString('SystemSettings::branding($db', $source);
            self::assertStringContainsString('SystemSettings::brandingCssVariables($branding)', $source);
            self::assertStringContainsString('companyLogoUrl', $source);
            self::assertStringContainsString('var(--brand-primary)', $source);
            self::assertStringContainsString("$" . "app->url('/')", $source);
        }

        self::assertStringContainsString('class="public-brand-header"', $create);
        self::assertStringContainsString('class="customer-brand"', $view);
        self::assertStringContainsString("view-permit-public-legacy.php", (string)$wrapper);
    }
}
