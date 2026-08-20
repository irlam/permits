<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class HomeFeatureCardPolishTest extends TestCase
{
    public function testHomepageLoadsDedicatedCardPolishStylesheet(): void
    {
        $helper = (string)file_get_contents(__DIR__ . '/../src/cache-helper.php');

        self::assertStringContainsString("asset('/assets/home-feature-card-polish.css')", $helper);
        self::assertStringContainsString("$scriptName === '' || $scriptName === 'index.php'", $helper);
    }

    public function testControlledCardHasDesktopSpecificOpticalSizing(): void
    {
        $css = (string)file_get_contents(__DIR__ . '/../assets/home-feature-card-polish.css');

        self::assertStringContainsString('.stat-card:nth-child(2) .stat-card__value', $css);
        self::assertStringContainsString('font-size: clamp(28px, 2.2vw, 34px)', $css);
        self::assertStringContainsString('grid-template-rows: auto 84px 1fr', $css);
        self::assertStringContainsString('justify-items: center', $css);
        self::assertStringContainsString('text-align: center', $css);
    }

    public function testMobileCardSizingRemainsResponsive(): void
    {
        $css = (string)file_get_contents(__DIR__ . '/../assets/home-feature-card-polish.css');

        self::assertStringContainsString('@media (max-width: 700px)', $css);
        self::assertStringContainsString('font-size: clamp(29px, 7.5vw, 36px)', $css);
    }
}
