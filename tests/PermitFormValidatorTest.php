<?php
declare(strict_types=1);

use Permits\PermitFormValidator;
use PHPUnit\Framework\TestCase;

final class PermitFormValidatorTest extends TestCase
{
    /** @var array<int,array<string,mixed>> */
    private array $structure;

    protected function setUp(): void
    {
        $this->structure = [[
            'title' => 'Permit details',
            'fields' => [
                ['name' => 'location', 'label' => 'Location', 'type' => 'text', 'required' => true],
                ['name' => 'start', 'label' => 'Start time', 'type' => 'datetime', 'required' => true],
                [
                    'name' => 'check_one',
                    'label' => 'Area inspected',
                    'type' => 'radio',
                    'required' => false,
                    'scoreItem' => true,
                    'options' => [
                        ['value' => 'yes', 'label' => 'Yes'],
                        ['value' => 'no', 'label' => 'No'],
                        ['value' => 'na', 'label' => 'N/A'],
                    ],
                ],
                ['name' => 'depth', 'label' => 'Depth', 'type' => 'number', 'min' => 0, 'max' => 10],
            ],
        ]];
    }

    public function testFinalSubmissionRequiresTemplateAndSafetyFields(): void
    {
        $errors = PermitFormValidator::validate($this->structure, [], true);

        self::assertSame('Location is required.', $errors['location']);
        self::assertSame('Start time is required.', $errors['start']);
        self::assertSame('Area inspected is required.', $errors['check_one']);
    }

    public function testDraftDoesNotRequireSafetyAnswers(): void
    {
        $errors = PermitFormValidator::validate($this->structure, [], false);

        self::assertArrayHasKey('location', $errors);
        self::assertArrayNotHasKey('check_one', $errors);
    }

    public function testValidSubmissionPasses(): void
    {
        $errors = PermitFormValidator::validate($this->structure, [
            'location' => 'Plant room',
            'start' => '2026-07-22T09:30',
            'check_one' => 'yes',
            'depth' => '2.5',
        ]);

        self::assertSame([], $errors);
    }

    public function testInvalidOptionAndNumberRangeAreRejected(): void
    {
        $errors = PermitFormValidator::validate($this->structure, [
            'location' => 'Plant room',
            'start' => '2026-07-22T09:30',
            'check_one' => 'bypass',
            'depth' => '12',
        ]);

        self::assertStringContainsString('invalid selection', $errors['check_one']);
        self::assertSame('Depth must be no more than 10.', $errors['depth']);
    }

    public function testNoSafetyAnswerRequiresSupportingDetail(): void
    {
        $answers = [
            'location' => 'Plant room',
            'start' => '2026-07-22T09:30',
            'check_one' => 'no',
        ];

        $errors = PermitFormValidator::validate($this->structure, $answers);
        self::assertStringContainsString('needs a note or photo', $errors['check_one']);

        $answers['check_one_note'] = 'Loose guard; replacement arranged before starting.';
        self::assertSame([], PermitFormValidator::validate($this->structure, $answers));
    }

    public function testNaSafetyAnswerRequiresSupportingDetail(): void
    {
        $answers = [
            'location' => 'Plant room',
            'start' => '2026-07-22T09:30',
            'check_one' => 'na',
        ];

        $errors = PermitFormValidator::validate($this->structure, $answers);
        self::assertStringContainsString('explaining the N/A answer', $errors['check_one']);

        $answers['check_one_note'] = 'No stored pressure or secondary energy source is fitted to this equipment.';
        self::assertSame([], PermitFormValidator::validate($this->structure, $answers));
    }
}
