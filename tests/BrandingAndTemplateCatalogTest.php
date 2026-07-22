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
            ['id' => 'latest', 'name' => '  confined   space entry permit ', 'version' => 3, 'created_at' => '2025-01-02 00:00:00'],
            ['id' => 'hot', 'name' => 'Hot Works Permit', 'version' => 2, 'created_at' => '2025-01-03 00:00:00'],
        ]);

        self::assertCount(2, $templates);
        self::assertSame('latest', $templates[0]['id']);
        self::assertSame('hot', $templates[1]['id']);
    }

    public function testPublicPermitPagesUseTheStoredBrandingPaletteAndIdentity(): void
    {
        $root = dirname(__DIR__);
        $create = file_get_contents($root . '/create-permit-public.php');
        $view = file_get_contents($root . '/view-permit-public.php');

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
    }
}
