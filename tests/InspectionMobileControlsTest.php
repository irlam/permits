<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class InspectionMobileControlsTest extends TestCase
{
    public function testInspectionPageLoadsDedicatedTouchControlsOnlyOnInspectionForm(): void
    {
        $root = dirname(__DIR__);
        $cacheHelper = (string) file_get_contents($root . '/src/cache-helper.php');
        $css = (string) file_get_contents($root . '/assets/inspection-controls.css');

        self::assertStringContainsString("$" . "scriptName === 'create-inspection-public.php'", $cacheHelper);
        self::assertStringContainsString("asset('/assets/inspection-controls.css')", $cacheHelper);

        self::assertStringContainsString('#inspectionForm .choices', $css);
        self::assertStringContainsString('min-height: 58px', $css);
        self::assertStringContainsString('min-height: 62px', $css);
        self::assertStringContainsString('input[value="yes" i]:checked + span', $css);
        self::assertStringContainsString('input[value="no" i]:checked + span', $css);
        self::assertStringContainsString('input[value="n/a" i]:checked + span', $css);
        self::assertStringContainsString('#16a34a', $css);
        self::assertStringContainsString('#dc2626', $css);
        self::assertStringContainsString('#0284c7', $css);
    }

    public function testInspectionRadioMarkupRemainsLabelWrappedAndKeyboardAccessible(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__) . '/create-inspection-public.php');

        self::assertStringContainsString('class="choices" role="radiogroup"', $source);
        self::assertStringContainsString('label class="choice"', $source);
        self::assertStringContainsString('type="radio"', $source);
        self::assertStringContainsString('required', $source);
    }
}
