<?php

declare(strict_types=1);

namespace Tests\Unit\ExecutiveDocumentation;

use App\BusinessModules\Features\ExecutiveDocumentation\Enums\ExecutiveDocumentTypeEnum;
use App\BusinessModules\Features\ExecutiveDocumentation\Support\ExecutiveDocumentProfileRegistry;
use Illuminate\Contracts\Console\Kernel;
use PHPUnit\Framework\TestCase;

final class SpecialJournalProfilesTest extends TestCase
{
    private ExecutiveDocumentProfileRegistry $registry;

    private mixed $app;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = require dirname(__DIR__, 3).'/bootstrap/app.php';
        $this->app->make(Kernel::class)->bootstrap();
        $this->registry = new ExecutiveDocumentProfileRegistry();
    }

    protected function tearDown(): void
    {
        restore_error_handler();
        restore_exception_handler();
        parent::tearDown();
    }

    public function test_special_journal_types_are_distinct_and_legacy_work_journal_remains_available(): void
    {
        $types = [
            'welding_work_journal', 'concrete_work_journal', 'pile_work_journal',
            'installation_work_journal', 'anticorrosion_work_journal', 'designer_supervision_journal',
        ];

        foreach ($types as $type) {
            self::assertContains($type, array_map(static fn (ExecutiveDocumentTypeEnum $case): string => $case->value, ExecutiveDocumentTypeEnum::cases()));
            self::assertNotNull($this->registry->find($type));
        }
        self::assertNotNull($this->registry->find('work_journal'));
        self::assertSame('external_manual_review', $this->registry->require('work_journal')['profile_mode']);
        self::assertNull($this->registry->require('work_journal')['print_template_version']);
        self::assertContains('work_journal', array_column($this->registry->all(), 'type'));
    }

    public function test_special_journals_are_external_manual_review_profiles_with_common_scope_fields(): void
    {
        foreach ([
            'welding_work_journal', 'concrete_work_journal', 'pile_work_journal',
            'installation_work_journal', 'anticorrosion_work_journal', 'designer_supervision_journal',
        ] as $type) {
            $profile = $this->registry->require($type);
            $fields = array_column($profile['fields'], 'key');

            self::assertSame('journals', $profile['category']);
            self::assertSame('external_manual_review', $profile['profile_mode']);
            self::assertNull($profile['print_template_version']);
            self::assertContains($type, array_column($this->registry->all(), 'type'));
            self::assertContains('journal_number', $fields);
            self::assertContains('journal_period', $fields);
            self::assertContains('responsible_person', $fields);
            self::assertContains('normative_basis', $fields);
            self::assertContains('applicability_basis', $fields);
            self::assertContains('project_documentation', $fields);
            self::assertContains('journal_entries', array_column($profile['relations'], 'key'));
            self::assertNotSame([], $profile['regulatory_basis']);
        }
    }

    public function test_construction_journals_require_work_type_but_designer_supervision_does_not(): void
    {
        foreach ([
            'welding_work_journal', 'concrete_work_journal', 'pile_work_journal',
            'installation_work_journal', 'anticorrosion_work_journal',
        ] as $type) {
            self::assertTrue($this->registry->require($type)['requires_work_type']);
        }
        self::assertFalse($this->registry->require('designer_supervision_journal')['requires_work_type']);
    }

    public function test_profile_validator_requires_common_and_subject_fields_and_rejects_unknown_data(): void
    {
        $profile = $this->registry->require('welding_work_journal');
        $missing = $this->registry->missingRequiredFields('welding_work_journal', []);

        foreach (['journal_number', 'journal_period', 'responsible_person', 'normative_basis', 'applicability_basis', 'project_documentation', 'welding_process'] as $key) {
            self::assertArrayHasKey($key, $missing);
        }

        self::assertArrayHasKey('unknown', $this->registry->validateProfileData('welding_work_journal', ['unknown' => 'value']));
        self::assertSame([], $this->registry->validateProfileData('welding_work_journal', $this->validData($profile)));
    }

    private function validData(array $profile): array
    {
        $data = [];
        foreach ($profile['fields'] as $field) {
            if (($field['required'] ?? false) !== true) {
                continue;
            }
            $data[$field['key']] = match ($field['type']) {
                'date' => '2026-09-21',
                'select' => $field['options'][0],
                'table', 'multiselect' => [],
                default => 'значение',
            };
        }

        return $data;
    }
}
