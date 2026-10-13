<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Analysis;

use App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\WorkIntentSourceClassifier;
use PHPUnit\Framework\TestCase;

final class WorkIntentSourceClassifierTest extends TestCase
{
    public function test_measured_equipment_count_does_not_alone_request_purchase_or_installation(): void
    {
        $this->expectExceptionMessage('estimate_composer_work_source_unrelated');
        (new WorkIntentSourceClassifier)->binding(['work_key' => 'equipment.purchase', 'source_fact_ids' => ['fact:count']],
            [['id' => 'fact:count', 'type' => 'equipment_count', 'entity_type' => 'equipment', 'entity_id' => 'equipment:p1',
                'status' => 'confirmed', 'origin' => 'document', 'evidence_ids' => ['proof:count']]]);
    }

    public function test_equipment_specification_supports_the_selected_asset_and_not_a_pipeline(): void
    {
        $source = ['id' => 'fact:spec', 'type' => 'equipment_specification', 'entity_type' => 'equipment', 'entity_id' => 'equipment:p1',
            'status' => 'confirmed', 'origin' => 'document', 'evidence_ids' => ['proof:spec']];
        $classifier = new WorkIntentSourceClassifier;
        self::assertSame('equipment', $classifier->binding(['work_key' => 'equipment.install', 'entity_id' => 'equipment:p1',
            'source_fact_ids' => ['fact:spec']], [$source])['work_section']);
        $this->expectExceptionMessage('estimate_composer_work_source_unrelated');
        $classifier->binding(['work_key' => 'pipelines.install', 'source_fact_ids' => ['fact:spec']], [$source]);
    }

    private function roof(): array
    {
        return ['id' => 'fact:roof', 'type' => 'roof_type', 'entity_id' => 'roof:1', 'entity_type' => 'roof',
            'status' => 'confirmed', 'origin' => 'document', 'evidence_ids' => ['evidence:roof']];
    }

    public function test_roof_type_never_justifies_heating_even_when_the_fact_is_confirmed(): void
    {
        $this->expectExceptionMessage('estimate_composer_work_source_unrelated');
        (new WorkIntentSourceClassifier)->binding(['work_key' => 'heating.pipe', 'source_fact_ids' => ['fact:roof']], [$this->roof()]);
    }

    public function test_verified_heating_specification_and_same_entity_allow_a_bounded_work_context(): void
    {
        $fact = ['id' => 'fact:heating', 'type' => 'heating_specification', 'entity_type' => 'heating_system',
            'entity_id' => 'heating:1', 'origin' => 'document', 'status' => 'confirmed', 'evidence_ids' => ['evidence:heating']];
        $result = (new WorkIntentSourceClassifier)->binding(['work_key' => 'heating.pipe', 'entity_id' => 'heating:1',
            'source_fact_ids' => ['fact:heating']], [$fact]);
        self::assertSame(['entity_id' => 'heating:1', 'work_section' => 'heating', 'basis_fact_ids' => ['fact:heating']], $result);
        self::assertArrayNotHasKey('quantity', $result);
    }

    public function test_unconfirmed_relevant_context_requires_information_instead_of_a_work(): void
    {
        foreach (['candidate', 'unresolved'] as $status) {
            try {
                (new WorkIntentSourceClassifier)->binding(['work_key' => 'roof_underlay', 'source_fact_ids' => ['fact:roof']],
                    [[...$this->roof(), 'status' => $status]]);
                self::fail('An unresolved context became a work basis.');
            } catch (\InvalidArgumentException $exception) {
                self::assertSame('estimate_composer_work_context_unconfirmed', $exception->getMessage());
            }
        }
    }

    public function test_existing_fact_ids_do_not_bypass_entity_or_section_checks(): void
    {
        foreach ([['work_key' => 'roof_underlay', 'entity_id' => 'roof:2'],
            ['work_key' => 'roof_underlay', 'work_section' => 'heating'], ['work_key' => 'unfamiliar.process']] as $intent) {
            try {
                (new WorkIntentSourceClassifier)->binding([...$intent, 'source_fact_ids' => ['fact:roof']], [$this->roof()]);
                self::fail('An incompatible work context was accepted.');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function test_server_bound_description_only_allows_the_declared_scope_without_a_quantity(): void
    {
        $fact = ['id' => 'fact:description', 'type' => 'evaluation_answer', 'entity_type' => 'quantity',
            'entity_id' => 'context:1', 'origin' => 'user_input', 'status' => 'confirmed', 'evidence_ids' => ['evidence:description'],
            'context_kind' => 'initial_description', 'declared_work_sections' => ['finishing']];
        $classifier = new WorkIntentSourceClassifier;
        $binding = $classifier->binding(['work_key' => 'painting-from-description', 'work_section' => 'finishing',
            'source_fact_ids' => ['fact:description']], [$fact]);
        self::assertSame('context:1', $binding['entity_id']);
        self::assertArrayNotHasKey('quantity', $binding);
        $this->expectExceptionMessage('estimate_composer_work_source_unrelated');
        $classifier->binding(['work_key' => 'heating.pipe', 'source_fact_ids' => ['fact:description']], [$fact]);
    }
}
