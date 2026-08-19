<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Phase3CInspectionWorkflowTest extends TestCase
{
    /** @return array<string,array{0:string,1:array<int,string>}> */
    public static function checklistProvider(): array
    {
        return [
            'building' => [
                'building-inspection-v2.json',
                ['Building / Area Inspected', 'Inspection Outcome', 'Corrective Actions Required', 'Inspector Sign-off Name'],
            ],
            'final' => [
                'final-inspection-v2.json',
                ['Building / Area / Package', 'Completion Status', 'Final Acceptance Status', 'Inspector Sign-off Name'],
            ],
            'site safety' => [
                'site-safety-inspection-v2.json',
                ['Inspection Area / Zone', 'Overall Inspection Rating', 'Hazards / Non-conformances Identified', 'Inspector Sign-off Name'],
            ],
        ];
    }

    /** @dataProvider checklistProvider */
    public function testInspectionPresetsAreDedicatedV2Checklists(string $fileName, array $criticalLabels): void
    {
        $path = dirname(__DIR__) . '/templates/form-presets/' . $fileName;
        $raw = file_get_contents($path);
        self::assertIsString($raw);
        $schema = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        self::assertIsArray($schema);
        self::assertStringEndsWith('-v2', (string)$schema['id']);
        self::assertSame(2, $schema['version']);
        self::assertSame('inspection', $schema['workflow']);
        self::assertStringContainsString('Checklist', (string)$schema['name']);
        self::assertStringNotContainsString(' Permit', (string)$schema['name']);
        self::assertGreaterThanOrEqual(4, count($schema['sections'] ?? []));

        $sectionTitles = array_map(
            static fn(array $section): string => strtoupper(trim((string)($section['title'] ?? ''))),
            $schema['sections'] ?? []
        );
        self::assertNotContains('AUTHORIZATION TO START', $sectionTitles);
        self::assertNotContains('HAND-BACK AND CLOSE-OUT', $sectionTitles);

        $labels = [];
        $itemCount = 0;
        foreach (($schema['meta']['fields'] ?? []) as $field) {
            if (is_array($field)) {
                $labels[] = (string)($field['label'] ?? '');
            }
        }
        foreach (($schema['sections'] ?? []) as $section) {
            if (!is_array($section)) {
                continue;
            }
            $itemCount += count($section['items'] ?? []);
            foreach (($section['fields'] ?? []) as $field) {
                if (is_array($field)) {
                    $labels[] = (string)($field['label'] ?? '');
                }
            }
        }
        self::assertGreaterThanOrEqual(15, $itemCount);
        foreach ($criticalLabels as $label) {
            self::assertContains($label, $labels);
        }
    }

    public function testInspectionEndpointCompletesWithoutPermitApprovalWorkflow(): void
    {
        $source = file_get_contents(dirname(__DIR__) . '/create-inspection-public.php');
        self::assertIsString($source);

        self::assertStringContainsString("'closed'", $source);
        self::assertStringContainsString('requires_approval, approval_status', $source);
        self::assertStringContainsString('0, NULL', $source);
        self::assertStringContainsString("'INS-'", $source);
        self::assertStringContainsString('public_inspection_completed', $source);
        self::assertStringContainsString('view-inspection-public.php?link=', $source);
        self::assertStringNotContainsString('notifyPendingApprovalRecipients(', $source);
        self::assertStringNotContainsString("'pending_approval'", $source);
    }

    public function testInspectionViewClearlyStatesItDoesNotAuthoriseWork(): void
    {
        $source = file_get_contents(dirname(__DIR__) . '/view-inspection-public.php');
        self::assertIsString($source);
        self::assertStringContainsString('does not itself authorise high-risk work', $source);
        self::assertStringContainsString('✓ Completed', $source);
        self::assertStringContainsString('TemplateCatalog::isInspection', $source);
    }
}
