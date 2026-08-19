<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ConstructionPermitPresetTest extends TestCase
{
    /** @return array<string,array<string,mixed>> */
    private function presets(): array
    {
        $root = dirname(__DIR__) . '/templates/form-presets';
        $files = [
            'lockout' => 'lockout-tagout.json',
            'confined' => 'confined-space-entry.json',
            'dig' => 'permit-to-dig.json',
            'height' => 'working-at-heights.json',
            'temporary' => 'temporary-works.json',
            'asbestos' => 'asbestos-removal.json',
            'scaffold' => 'scaffolding.json',
            'demolition' => 'demolition.json',
            'hotworks' => 'hot-works.json',
        ];

        $presets = [];
        foreach ($files as $key => $file) {
            $raw = file_get_contents($root . '/' . $file);
            self::assertIsString($raw, $file . ' must be readable');
            $decoded = json_decode($raw, true);
            self::assertIsArray($decoded, $file . ' must contain valid JSON');
            $presets[$key] = $decoded;
        }

        return $presets;
    }

    /** @param array<string,mixed> $preset */
    private function labels(array $preset): array
    {
        $labels = [];
        array_walk_recursive($preset, static function ($value, $key) use (&$labels): void {
            if ($key === 'label' && is_string($value)) {
                $labels[] = $value;
            }
        });
        return $labels;
    }

    public function testCriticalConstructionPresetsAreVersionTwoAndStructured(): void
    {
        foreach ($this->presets() as $name => $preset) {
            self::assertGreaterThanOrEqual(2, (int)($preset['version'] ?? 0), $name . ' must be a versioned safety upgrade');
            self::assertNotEmpty($preset['meta']['fields'] ?? [], $name . ' must include permit details');
            self::assertGreaterThanOrEqual(5, count($preset['sections'] ?? []), $name . ' must include substantive control sections');

            $checkItems = 0;
            foreach (($preset['sections'] ?? []) as $section) {
                $checkItems += count($section['items'] ?? []);
            }
            self::assertGreaterThanOrEqual(15, $checkItems, $name . ' must include a meaningful safety checklist');
        }
    }

    public function testLockoutTagoutCapturesIsolationVerificationAndReinstatement(): void
    {
        $labels = $this->labels($this->presets()['lockout']);
        self::assertContains('Plant / Equipment ID or Tag Number', $labels);
        self::assertContains('Lock / Tag Numbers and Owners', $labels);
        self::assertContains('Isolation Verified By', $labels);
        self::assertContains('Reinstatement / Re-energisation Authorised By', $labels);
    }

    public function testConfinedSpaceCapturesAtmosphereAndRescueControls(): void
    {
        $labels = $this->labels($this->presets()['confined']);
        self::assertContains('Gas Detector ID / Serial Number', $labels);
        self::assertContains('Oxygen Reading (%)', $labels);
        self::assertContains('Flammable Reading (% LEL)', $labels);
        self::assertContains('Rescue Plan Reference', $labels);
        self::assertContains('Casualty Retrieval Method', $labels);
    }

    public function testPermitToDigCapturesServiceLocationAndExcavationInspection(): void
    {
        $labels = $this->labels($this->presets()['dig']);
        self::assertContains('Utility / Service Drawing References and Dates', $labels);
        self::assertContains('Locator Equipment ID / Serial Number', $labels);
        self::assertContains('Scan Results / Service Positions and Depths', $labels);
        self::assertContains('Competent Excavation Inspector', $labels);
    }

    public function testWorkAtHeightCapturesHierarchyAndRescue(): void
    {
        $labels = $this->labels($this->presets()['height']);
        self::assertContains('Why Work at Height Cannot Be Avoided / Hierarchy Selection', $labels);
        self::assertContains('Fall Protection System', $labels);
        self::assertContains('Rescue Plan Reference', $labels);
        self::assertContains('Rescue Method / Equipment / Named Rescuers', $labels);
    }

    public function testTemporaryWorksHasLoadAndStrikeHoldPoints(): void
    {
        $labels = $this->labels($this->presets()['temporary']);
        self::assertContains('Temporary Works Register Reference', $labels);
        self::assertContains('Design Check Certificate / Approval Reference', $labels);
        self::assertContains('Permit to Load / Use Authorised By (TWC or delegated competent person)', $labels);
        self::assertContains('Permit to Strike / Remove Authorised By', $labels);
    }

    public function testAsbestosPresetCapturesClassificationNotificationAndClearance(): void
    {
        $labels = $this->labels($this->presets()['asbestos']);
        self::assertContains('Work Classification', $labels);
        self::assertContains('HSE Asbestos Licence Number / Expiry (if licensable)', $labels);
        self::assertContains('ASB5 / NNLW Notification Reference (if applicable)', $labels);
        self::assertContains('Clearance Requirement', $labels);
    }

    public function testScaffoldPresetCapturesDesignHandoverAndInspection(): void
    {
        $labels = $this->labels($this->presets()['scaffold']);
        self::assertContains('Scaffold Design / Standard Configuration Reference', $labels);
        self::assertContains('Handover Certificate / Inspection Record Reference', $labels);
        self::assertContains('Competent Inspector', $labels);
        self::assertContains('Next Inspection Due', $labels);
    }

    public function testDemolitionPresetCapturesStructuralAndServiceControls(): void
    {
        $labels = $this->labels($this->presets()['demolition']);
        self::assertContains('Structural Survey / Engineer Report Reference', $labels);
        self::assertContains('Asbestos Survey / Register Reference', $labels);
        self::assertContains('Service Isolation / Disconnection References', $labels);
        self::assertContains('Demolition Sequence / Drawing Reference', $labels);
    }

    public function testHotWorksCapturesFireWatchAndAlarmReinstatement(): void
    {
        $labels = $this->labels($this->presets()['hotworks']);
        self::assertContains('Fire Extinguisher Type, Quantity and Location', $labels);
        self::assertContains('Named Fire Watcher', $labels);
        self::assertContains('Required Post-work Fire Watch Duration / Basis', $labels);
        self::assertContains('Fire Alarm / Detector Reinstated Date / Time', $labels);
    }
}
