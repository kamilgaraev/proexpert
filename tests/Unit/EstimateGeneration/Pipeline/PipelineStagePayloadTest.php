<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Pipeline;

use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineStagePayload;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\ProcessingStage;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PipelineStagePayloadTest extends TestCase
{
    #[Test]
    public function persisted_jsonb_key_order_does_not_invalidate_stage_schema(): void
    {
        $data = [
            'building_quantities' => [],
            'quantity_learning_hints' => [],
            'quantity_coverage_warnings' => [],
            'stage6_generation_context' => [],
        ];

        $payload = PipelineStagePayload::from(ProcessingStage::ExtractQuantities, $data);

        self::assertSame($data, $payload->data);
    }

    public function test_universal_policy_is_additive_and_preserved_through_all_price_stages(): void
    {
        $policy = ['mode' => 'universal', 'price_policy' => 'catalog', 'profile_id' => 'renovation',
            'selected_sections' => ['finishing'], 'construction_type' => 'current_repair',
            'scope_selection' => null, 'scenario_selections' => null];
        foreach ([ProcessingStage::MatchNormatives, ProcessingStage::AssembleResources, ProcessingStage::ResolvePrices] as $stage) {
            $data = ['regional_context' => [], 'supplementary_materials' => [], 'local_estimates' => [], 'evaluation_policy' => $policy];
            self::assertSame($data, PipelineStagePayload::from($stage, $data)->data);
        }
        $this->expectException(InvalidArgumentException::class);
        PipelineStagePayload::from(ProcessingStage::ResolvePrices, [...$data, 'evaluation_policy' => [...$policy, 'model' => 'openai/gpt-6-sol']]);
    }

    public function test_scenario_policy_is_structural_only_and_cannot_accept_scalar_or_unbounded_selections(): void
    {
        $policy = ['mode' => 'universal', 'price_policy' => 'catalog', 'profile_id' => 'renovation', 'selected_sections' => ['finishing']];
        $data = ['regional_context' => [], 'supplementary_materials' => [], 'local_estimates' => [],
            'evaluation_policy' => [...$policy, 'scenario_selections' => ['version' => 'evaluation-scenarios:v1', 'fact_id' => 'fact:scenario', 'definitions' => []]]];
        self::assertSame($data, PipelineStagePayload::from(ProcessingStage::ResolvePrices, $data)->data);
        foreach (['invalid', ['basis' => str_repeat('x', 2097153)]] as $selection) {
            try {
                PipelineStagePayload::from(ProcessingStage::ResolvePrices, [...$data, 'evaluation_policy' => [...$policy, 'scenario_selections' => $selection]]);
                self::fail('An invalid scenario selection entered the pipeline.');
            } catch (InvalidArgumentException $exception) {
                self::assertSame('evaluation_policy_contract_invalid', $exception->getMessage());
            }
        }
    }

    #[Test]
    public function persisted_jsonb_key_order_does_not_invalidate_composition_schema(): void
    {
        $data = [
            'document_requirements' => [],
            'estimate_composition' => [
                'derived_quantities' => [],
                'input_fingerprint' => str_repeat('b', 64),
                'intents_count' => 1,
                'schema_version' => 1,
                'snapshot_token' => str_repeat('a', 64),
            ],
            'generation_mode' => 'ai_assisted',
            'local_estimates' => [],
            'normative_context_pin' => [],
            'object_profile' => [],
            'package_plan' => [],
            'regional_context' => [],
        ];

        self::assertSame($data, PipelineStagePayload::from(ProcessingStage::PlanWorkItems, $data)->data);
        $notice = ['index' => 1, 'reason' => 'estimate_composer_physical_duplicate_covered', 'work_name' => 'Отделка пола',
            'next_action' => 'keep_existing_work', 'source_fact_ids' => ['fact:floor']];
        foreach (['covered_by_position_key', 'covered_by_candidate_id'] as $field) {
            $data['estimate_composition']['request_info'] = [[...$notice, $field => 'floor:1']];
            self::assertSame($data, PipelineStagePayload::from(ProcessingStage::PlanWorkItems, $data)->data);
        }
        foreach ([$notice, [...$notice, 'covered_by_position_key' => ''],
            [...$notice, 'covered_by_position_key' => 'floor:1', 'reason' => 'estimate_composer_work_scope_unresolved'],
            [...$notice, 'covered_by_position_key' => 'floor:1', 'covered_by_candidate_id' => 'candidate:1']] as $invalidNotice) {
            $data['estimate_composition']['request_info'] = [$invalidNotice];
            try {
                PipelineStagePayload::from(ProcessingStage::PlanWorkItems, $data);
                self::fail('Invalid duplicate notice was accepted.');
            } catch (InvalidArgumentException $exception) {
                self::assertSame('Pipeline work source requests are invalid.', $exception->getMessage());
            }
        }
        $data['estimate_composition']['intents_count'] = 0;
        $data['estimate_composition']['request_info'] = [['index' => -1, 'reason' => 'estimate_composer_work_scope_unresolved',
            'work_name' => null, 'next_action' => 'provide_work_scope', 'source_fact_ids' => []]];
        self::assertSame($data, PipelineStagePayload::from(ProcessingStage::PlanWorkItems, $data)->data);
        $data['estimate_composition']['request_info'][0]['next_action'] = 'send_without_quote';
        $this->expectExceptionMessage('Pipeline work source requests are invalid.');
        PipelineStagePayload::from(ProcessingStage::PlanWorkItems, $data);
    }

    #[Test]
    public function quantity_coverage_warning_requires_a_complete_structured_identity(): void
    {
        $this->expectException(InvalidArgumentException::class);

        PipelineStagePayload::from(ProcessingStage::ExtractQuantities, [
            'building_quantities' => [],
            'quantity_learning_hints' => [],
            'stage6_generation_context' => [],
            'quantity_coverage_warnings' => [[
                'quantity_key' => 'stairs.railings',
                'reason' => 'arbitrary_missing_reason',
                'package_key' => 'stairs',
            ]],
        ]);
    }

    #[Test]
    public function pricing_pipeline_preserves_the_pinned_regional_context(): void
    {
        $data = [
            'regional_context' => ['region_id' => 16, 'price_zone_id' => 3, 'period_id' => 8],
            'supplementary_materials' => [],
            'local_estimates' => [],
        ];

        foreach ([ProcessingStage::MatchNormatives, ProcessingStage::AssembleResources, ProcessingStage::ResolvePrices] as $stage) {
            self::assertSame($data, PipelineStagePayload::from($stage, $data)->data);
        }
    }
}
