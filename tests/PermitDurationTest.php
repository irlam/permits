<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/src/permit-durations.php';

final class PermitDurationTest extends TestCase
{
    public function testDefaultsContainOneDayOption(): void
    {
        self::assertContains(
            ['label' => '1 day', 'minutes' => 1440],
            permitDurationDefaults()
        );
    }

    public function testDurationsAreDeduplicatedAndBounded(): void
    {
        $normalised = normalizePermitDurationPresets([
            ['label' => 'One hour', 'minutes' => 60],
            ['label' => 'One hour', 'minutes' => 60],
            ['label' => 'Invalid', 'minutes' => 0],
            ['label' => 'Too long', 'minutes' => 525601],
            ['label' => 'This label exceeds 20 chars', 'minutes' => 120],
        ]);

        self::assertSame([['label' => 'One hour', 'minutes' => 60]], $normalised);
    }

    public function testConfiguredPreferenceAndExplicitSelectionAreResolved(): void
    {
        $presets = [
            ['label' => '4 hours', 'minutes' => 240],
            ['label' => '1 day', 'minutes' => 1440],
        ];

        self::assertSame(
            ['label' => '1 day', 'minutes' => 1440],
            selectPermitDurationPreset($presets, null, '1 DAY')
        );
        self::assertSame(
            ['label' => '4 hours', 'minutes' => 240],
            selectPermitDurationPreset($presets, 240)
        );
        self::assertNull(selectPermitDurationPreset($presets, 60));
    }
}
