<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Phase3SpecialistPermitPresetTest extends TestCase
{
    /** @var array<string,array<int,string>> */
    private const PRESETS = [
        'blasting-explosives-v2.json' => [
            'Shotfirer Competence / Appointment Reference',
            'Blasting Specification / Blast Plan Reference',
            'Blast Exclusion / Danger Zone and Boundaries',
            'Misfire Procedure Reference',
            'Re-entry / Fume Clearance Criteria and Time',
            'Explosives and Detonators Accounted For / Returned',
        ],
        'restricted-area-entry-v2.json' => [
            'Linked Permits / Isolations / SIMOPS References',
            'Entry / Exit Log Reference or Accountability Method',
            'Stop-work / Immediate Withdrawal Conditions',
            'Area Emergency / Evacuation Procedure Reference',
            'All Persons Out / Accounted For Confirmed By',
        ],
        'vehicle-equipment-access-v2.json' => [
            'Traffic Management Plan / Logistics Plan Reference',
            'Pedestrian Segregation / Crossing Controls',
            'Ground / Slab / Working Platform Suitability Reference',
            'Signaller / Vehicle Marshaller (if required)',
            'Breakdown / Recovery / Emergency Arrangement',
        ],
        'concrete-pouring-v2.json' => [
            'Pre-pour Inspection / Hold-point Reference',
            'Formwork / Falsework Design or Standard Arrangement Reference',
            'Temporary Works Permit-to-Load / Pour Release Reference (if applicable)',
            'Concrete / Cement COSHH Assessment Reference',
            'Required Slump / Flow / Cube / Other Test Regime',
            'Concrete Washout / Waste Location and Controls',
        ],
    ];

    public function testSpecialistV2PresetsAreSubstantiveAndHaveCriticalControls(): void
    {
        $root = dirname(__DIR__) . '/templates/form-presets/';

        foreach (self::PRESETS as $filename => $criticalLabels) {
            $raw = file_get_contents($root . $filename);
            self::assertIsString($raw, $filename);

            $preset = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($preset, $filename);
            self::assertStringEndsWith('-v2', (string)($preset['id'] ?? ''), $filename);
            self::assertSame(2, $preset['version'] ?? null, $filename);
            self::assertNotEmpty($preset['name'] ?? null, $filename);
            self::assertGreaterThanOrEqual(5, count($preset['sections'] ?? []), $filename);

            $itemCount = 0;
            $labels = [];
            $keys = [];

            foreach (($preset['meta']['fields'] ?? []) as $field) {
                if (!is_array($field)) {
                    continue;
                }
                $labels[] = (string)($field['label'] ?? '');
                if (!empty($field['key'])) {
                    $keys[] = (string)$field['key'];
                }
            }

            foreach (($preset['sections'] ?? []) as $section) {
                $itemCount += count($section['items'] ?? []);
                foreach (($section['fields'] ?? []) as $field) {
                    if (!is_array($field)) {
                        continue;
                    }
                    $labels[] = (string)($field['label'] ?? '');
                    if (!empty($field['key'])) {
                        $keys[] = (string)$field['key'];
                    }
                }
            }

            self::assertGreaterThanOrEqual(15, $itemCount, $filename);
            self::assertSame($keys, array_values(array_unique($keys)), $filename . ' contains duplicate field keys');

            foreach ($criticalLabels as $label) {
                self::assertContains($label, $labels, $filename . ' missing ' . $label);
            }
        }
    }

    public function testV2PresetsRemoveLegacyHardCodedAssumptions(): void
    {
        $root = dirname(__DIR__) . '/templates/form-presets/';
        $blasting = file_get_contents($root . 'blasting-explosives-v2.json');
        $vehicle = file_get_contents($root . 'vehicle-equipment-access-v2.json');
        $concrete = file_get_contents($root . 'concrete-pouring-v2.json');

        self::assertIsString($blasting);
        self::assertIsString($vehicle);
        self::assertIsString($concrete);

        self::assertStringNotContainsString('Minimum 30 minutes', $blasting);
        self::assertStringNotContainsString('Required for all reversing movements', $vehicle);
        self::assertStringNotContainsString('stop if <5°C or >35°C', $concrete);
    }
}
