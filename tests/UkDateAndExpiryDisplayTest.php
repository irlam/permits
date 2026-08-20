<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class UkDateAndExpiryDisplayTest extends TestCase
{
    public function testSharedHeadLoadsUkDateNormaliser(): void
    {
        $helper = file_get_contents(__DIR__ . '/../src/cache-helper.php');
        self::assertIsString($helper);
        self::assertStringContainsString("asset('/assets/uk-date-display.js')", $helper);
    }

    public function testPermitViewAlsoLoadsUkDateNormaliser(): void
    {
        $view = file_get_contents(__DIR__ . '/../view-permit-public.php');
        self::assertIsString($view);
        self::assertStringContainsString("asset('/assets/uk-date-display.js')", $view);
    }

    public function testPermitViewShowsExplicitExpiredStopWorkBanner(): void
    {
        $view = file_get_contents(__DIR__ . '/../view-permit-public.php');
        self::assertIsString($view);
        self::assertStringContainsString("$status === 'expired'", $view);
        self::assertStringContainsString('EXPIRED — DO NOT WORK', $view);
        self::assertStringContainsString('Work is no longer authorised under this permit', $view);
    }

    public function testDateScriptUsesUkDayMonthYearOrderingAndDoesNotTouchInputs(): void
    {
        $script = file_get_contents(__DIR__ . '/../assets/uk-date-display.js');
        self::assertIsString($script);
        self::assertStringContainsString('`${day}/${month}/${year}`', $script);
        self::assertStringContainsString('`${day}/${month}/${year} ${hour}:${minute}`', $script);
        self::assertStringContainsString("'INPUT'", $script);
        self::assertStringContainsString("'TEXTAREA'", $script);
        self::assertStringContainsString('MutationObserver', $script);
    }
}
