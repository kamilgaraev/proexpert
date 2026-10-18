<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\EstimateComposerBatchPlanner;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\EstimateComposerInput;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\RunEstimateComposer;
use App\BusinessModules\Addons\EstimateGeneration\Application\Credits\EstimatorKnownContextParts;
use App\BusinessModules\Addons\EstimateGeneration\Application\Credits\EstimatorProcessingPlan;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiPriceSnapshot;
use App\BusinessModules\Addons\EstimateGeneration\Observability\EstimatorTextWirePolicy;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;

final class EstimatorProcessingPlanTest extends TestCase
{
    private Container $previous;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previous = Container::getInstance();
        $container = new Container;
        $container->instance('config', new Repository([]));
        Container::setInstance($container);
    }

    protected function tearDown(): void
    {
        Container::setInstance($this->previous);
        parent::tearDown();
    }

    public function test_a_simple_raster_quote_allows_finite_conditional_escalation_without_extra_mandatory_roles(): void
    {
        $plan = (new EstimatorProcessingPlan)->build('generation', 1, 32, false, $this->tariff());
        self::assertSame(15, $plan['roles']['vision']['max_calls']);
        self::assertSame(8192, $plan['roles']['vision']['output_ceiling']);
        self::assertArrayNotHasKey('project_synthesis', $plan['roles']);
        self::assertSame(0, $plan['synthesis_part_limit']);
        self::assertSame(32, $plan['composer_part_limit']);
        self::assertArrayNotHasKey('rerank', $plan['roles']);
        self::assertSame(array_sum(array_column($plan['roles'], 'max_calls')), $plan['maximum_calls']);
    }

    public function test_future_composer_candidates_fit_the_approved_plan_with_rich_existing_context(): void
    {
        config()->set('estimate-generation.estimate_composer.max_input_bytes', 16384);
        $facts = $candidates = [];
        for ($index = 0; $index < 12; $index++) {
            $facts[] = ['id' => 'fact:'.$index, 'entity_id' => 'entity:'.($index % 4),
                'value' => str_repeat('context ', 250), 'status' => 'confirmed'];
        }
        for ($index = 0; $index < 32; $index++) {
            $candidates[] = ['candidate_id' => 'candidate:'.$index, 'work_key' => 'work:'.$index,
                'name' => 'Работа '.$index, 'unit' => null, 'quantity' => null, 'quantity_formula' => null,
                'source_fact_ids' => ['fact:'.($index % 12)], 'technology_package_candidate' => null];
        }
        $knownInput = new EstimateComposerInput(10, 20, 30, str_repeat('a', 64), $facts, [], [], [], [], RunEstimateComposer::PROMPT_CONTRACT);
        $known = (new EstimatorKnownContextParts)->measure($knownInput, null, null, [], [], EstimatorTextWirePolicy::capture());
        $runtimeInput = new EstimateComposerInput(10, 20, 30, str_repeat('a', 64), $facts, [], [], $candidates, [], RunEstimateComposer::PROMPT_CONTRACT);
        $actual = (new EstimateComposerBatchPlanner($known['role_limits']['maximum_part_bytes']['composer'], 32,
            $known['role_limits']['policies']['composer']))->plan($runtimeInput);
        self::assertGreaterThan($known['part_limits']['composer'], count($actual));

        $plan = (new EstimatorProcessingPlan)->build('generation', 0, 32, false, $this->tariff(), false, $known['part_limits']);

        self::assertLessThanOrEqual($plan['composer_part_limit'], count($actual));
        self::assertGreaterThanOrEqual(count($actual) * 3, $plan['roles']['estimate_composition']['max_calls']);
        $capped = (new EstimatorProcessingPlan)->capForQaBudget($plan, ['maximum_provider_cost_micro_rub' => 900000]);
        self::assertSame(900000, $capped['maximum_provider_cost_micro_rub']);
        self::assertSame($plan['composer_part_limit'], $capped['composer_part_limit']);
        self::assertSame($plan['roles'], $capped['roles']);
    }

    public function test_document_processing_has_no_paid_composition_or_audit_roles(): void
    {
        $plan = (new EstimatorProcessingPlan)->build('documents', 2, 32, false, $this->tariff());
        self::assertSame(30, $plan['roles']['vision']['max_calls']);
        self::assertArrayNotHasKey('estimate_composition', $plan['roles']);
        self::assertArrayNotHasKey('estimate_audit', $plan['roles']);
        self::assertArrayNotHasKey('estimate_composer_correction', $plan['roles']);
        self::assertSame(0, $plan['composer_part_limit']);
    }

    public function test_composer_allowance_preserves_the_execution_part_ceiling(): void
    {
        $plan = (new EstimatorProcessingPlan)->build('generation', 0, 320, false, $this->tariff(), false,
            ['composer' => EstimateComposerBatchPlanner::MAX_PARTS]);

        self::assertSame(EstimateComposerBatchPlanner::MAX_PARTS, $plan['composer_part_limit']);
        self::assertSame(EstimateComposerBatchPlanner::MAX_PARTS * 3, $plan['roles']['estimate_composition']['max_calls']);
        self::assertLessThanOrEqual(10000, $plan['maximum_calls']);
    }

    public function test_a_generation_that_cannot_reserve_every_future_composer_part_requires_sections_before_approval(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('estimator_processing_plan_requires_section_split');

        (new EstimatorProcessingPlan)->build('generation', 500, 64, true, $this->tariff());
    }

    public function test_a_generation_at_the_call_boundary_preserves_every_future_composer_part_and_other_roles(): void
    {
        $plan = (new EstimatorProcessingPlan)->build('generation', 500, 55, true, $this->tariff());

        self::assertSame(9996, $plan['maximum_calls']);
        self::assertSame(55, $plan['work_item_limit']);
        self::assertSame(55, $plan['composer_part_limit']);
        self::assertSame(165, $plan['roles']['estimate_composition']['max_calls']);
        self::assertSame(7500, $plan['roles']['vision']['max_calls']);
        self::assertSame(1500, $plan['roles']['ocr']['max_calls']);
        self::assertSame(495, $plan['roles']['estimate_audit']['max_calls']);
        self::assertSame(330, $plan['roles']['estimate_composer_correction']['max_calls']);
        self::assertSame(6, $plan['roles']['project_synthesis']['max_calls']);
    }

    public function test_known_composer_parts_are_never_reduced_to_fit_the_operation_call_ceiling(): void
    {
        $builder = new EstimatorProcessingPlan;
        $plan = $builder->build('generation', 500, 55, true, $this->tariff(), false, ['composer' => 1]);
        self::assertSame(56, $plan['composer_part_limit']);
        self::assertSame(9999, $plan['maximum_calls']);
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('estimator_processing_plan_requires_section_split');

        $builder->build('generation', 500, 55, true, $this->tariff(), false, ['composer' => 2]);
    }

    public function test_vision_quotes_freeze_the_configured_completion_ceiling_and_its_cost(): void
    {
        config()->set('estimate-generation.vision.primary_max_output_tokens', 8192);
        $old = (new EstimatorProcessingPlan)->build('documents', 1, 32, false, $this->tariff());
        config()->set('estimate-generation.vision.primary_max_output_tokens', 16384);
        $new = (new EstimatorProcessingPlan)->build('documents', 1, 32, false, $this->tariff());

        self::assertSame(8192, $old['roles']['vision']['output_ceiling']);
        self::assertSame(16384, $new['roles']['vision']['output_ceiling']);
        self::assertSame(1009152, $old['roles']['vision']['maximum_cost_micro_rub']);
        self::assertSame(1562112, $new['roles']['vision']['maximum_cost_micro_rub']);
        self::assertSame(8294400, $new['maximum_provider_cost_micro_rub'] - $old['maximum_provider_cost_micro_rub']);
    }

    public function test_qa_cap_preserves_the_full_role_plan_and_carried_document_cost(): void
    {
        $builder = new EstimatorProcessingPlan;
        $plan = $builder->build('generation', 0, 128, false, $this->tariff());
        $plan['document_costs'] = ['cost_micro_rub' => 180000];
        $budget = ['maximum_provider_cost_micro_rub' => 900000, 'carried_document_cost_micro_rub' => 180000];
        $capped = $builder->capForQaBudget($plan, $budget);

        self::assertSame(1080000, $capped['maximum_provider_cost_micro_rub']);
        self::assertSame($plan['roles'], $capped['roles']);
        self::assertSame($plan['maximum_calls'], $capped['maximum_calls']);
        self::assertSame($plan['work_item_limit'], $capped['work_item_limit']);
        self::assertSame($plan['document_costs'], $capped['document_costs']);
        self::assertSame($budget, $capped['qa_expense_budget']);
        self::assertGreaterThan(900000, $plan['maximum_provider_cost_micro_rub']);
    }

    public function test_document_cost_not_counted_in_this_qa_run_still_uses_its_budget(): void
    {
        $plan = (new EstimatorProcessingPlan)->build('generation', 0, 32, false, $this->tariff());
        $plan['document_costs'] = ['cost_micro_rub' => 180000];
        $this->expectException(\App\BusinessModules\Addons\EstimateGeneration\Observability\SessionAiCostLimitReached::class);
        $this->expectExceptionMessage('estimator_live_qa_expense_limit_reached');
        (new EstimatorProcessingPlan)->capForQaBudget($plan, ['maximum_provider_cost_micro_rub' => 180000]);
    }

    public function test_document_cost_already_counted_in_this_run_does_not_consume_the_new_call_budget_again(): void
    {
        $plan = (new EstimatorProcessingPlan)->build('generation', 0, 32, false, $this->tariff());
        $plan['document_costs'] = ['cost_micro_rub' => 1800000];
        $capped = (new EstimatorProcessingPlan)->capForQaBudget($plan,
            ['maximum_provider_cost_micro_rub' => 180000, 'carried_document_cost_micro_rub' => 1800000]);
        self::assertSame(1980000, $capped['maximum_provider_cost_micro_rub']);
        self::assertSame(180000, $capped['maximum_provider_cost_micro_rub'] - $capped['document_costs']['cost_micro_rub']);
        self::assertSame($plan['roles'], $capped['roles']);
    }

    public function test_qa_cap_does_not_increase_a_smaller_operation_reserve(): void
    {
        $builder = new EstimatorProcessingPlan;
        $plan = $builder->build('documents', 1, 32, false, $this->tariff());
        $capped = $builder->capForQaBudget($plan, ['maximum_provider_cost_micro_rub' => 90000000]);
        self::assertSame($plan['maximum_provider_cost_micro_rub'], $capped['maximum_provider_cost_micro_rub']);
    }

    public function test_text_reserve_uses_a_frozen_enforced_byte_bound_and_the_effective_output_limit(): void
    {
        config()->set('ai-assistant-credits.profiles.normal', ['input_tokens' => 16384, 'output_tokens' => 2048, 'max_calls' => 4]);
        config()->set('estimate-generation.estimate_composer.max_output_tokens', 4096);
        config()->set('estimate-generation.estimate_auditor.max_output_tokens', 4096);
        $plan = (new EstimatorProcessingPlan)->build('generation', 0, 32, false, $this->tariff());
        self::assertSame(65536, $plan['roles']['estimate_composition']['input_ceiling']);
        self::assertSame(8192, $plan['roles']['estimate_composition']['output_ceiling']);
        self::assertSame(8192, $plan['roles']['estimate_audit']['output_ceiling']);
        self::assertSame(8192, $plan['roles']['estimate_composer_correction']['output_ceiling']);
        self::assertSame(['input_tokens' => 16384, 'output_tokens' => 8192, 'max_calls' => 4], $plan['text_payload_policy']['token_limits']);
        config()->set('ai-assistant-credits.profiles.normal', ['input_tokens' => 32768, 'output_tokens' => 4096, 'max_calls' => 6]);
        self::assertSame(8192, $plan['text_payload_policy']['token_limits']['output_tokens']);
    }

    public function test_a_large_operation_reserves_three_audits_two_corrections_and_three_sends_per_part(): void
    {
        $plan = (new EstimatorProcessingPlan)->build('generation', 0, 65, false, $this->tariff());
        self::assertSame(65, $plan['audit_part_limit']);
        self::assertSame(65, $plan['composer_part_limit']);
        self::assertSame(585, $plan['roles']['estimate_audit']['max_calls']);
        self::assertSame(390, $plan['roles']['estimate_composer_correction']['max_calls']);
        self::assertSame(1170, $plan['maximum_calls']);
    }

    public function test_complex_operation_reserves_every_engineer_part_before_execution(): void
    {
        $plan = (new EstimatorProcessingPlan)->build('generation', 0, 65, true, $this->tariff());
        self::assertSame(3, $plan['synthesis_part_limit']);
        self::assertSame(9, $plan['roles']['project_synthesis']['max_calls']);
    }

    public function test_measured_context_parts_raise_both_call_allowances_and_the_frozen_money_reserve(): void
    {
        $baseline = (new EstimatorProcessingPlan)->build('generation', 0, 32, true, $this->tariff());
        $plan = (new EstimatorProcessingPlan)->build('generation', 0, 32, true, $this->tariff(), false,
            ['composer' => 4, 'audit' => 3, 'synthesis' => 2]);
        self::assertSame(36, $plan['composer_part_limit']);
        self::assertSame(32, $plan['audit_part_limit']);
        self::assertSame(2, $plan['synthesis_part_limit']);
        self::assertSame(108, $plan['roles']['estimate_composition']['max_calls']);
        self::assertSame(288, $plan['roles']['estimate_audit']['max_calls']);
        self::assertSame(192, $plan['roles']['estimate_composer_correction']['max_calls']);
        self::assertSame(6, $plan['roles']['project_synthesis']['max_calls']);
        self::assertSame(594, $plan['maximum_calls']);
        self::assertGreaterThan($baseline['maximum_provider_cost_micro_rub'], $plan['maximum_provider_cost_micro_rub']);
    }

    public function test_measured_parts_cannot_reduce_the_unknown_input_allowance(): void
    {
        $plan = (new EstimatorProcessingPlan)->build('generation', 0, 65, false, $this->tariff(), false,
            ['composer' => 1, 'audit' => 0, 'synthesis' => 1]);
        self::assertSame(66, $plan['composer_part_limit']);
        self::assertSame(65, $plan['audit_part_limit']);
        self::assertSame(0, $plan['synthesis_part_limit']);
    }

    public function test_retained_prefix_reserves_only_auditor_and_corrector_and_preserves_context_policy(): void
    {
        $plans = new EstimatorProcessingPlan;
        $full = $plans->build('generation', 2, 32, true, $this->tariff(), true);
        $continued = $plans->forRetainedPrefix($full);

        self::assertSame(['estimate_audit', 'estimate_composer_correction'], array_keys($continued['roles']));
        self::assertTrue($continued['complex']);
        self::assertSame($full['text_payload_policy'], $continued['text_payload_policy']);
        self::assertSame($full['audit_part_limit'], $continued['audit_part_limit']);
        self::assertSame($full['roles']['estimate_audit'], $continued['roles']['estimate_audit']);
        self::assertSame($full['roles']['estimate_composer_correction'], $continued['roles']['estimate_composer_correction']);
        self::assertSame(0, $continued['composer_part_limit']);
        self::assertSame(0, $continued['synthesis_part_limit']);
        self::assertSame(0, $continued['raster_units']);
        self::assertFalse($continued['rerank']);
        self::assertSame(480, $continued['maximum_calls']);
        $expected = array_sum(array_map(static fn (array $role): int => $role['max_calls'] * $role['maximum_cost_micro_rub'], $continued['roles']));
        self::assertSame($expected, $continued['maximum_provider_cost_micro_rub']);
        self::assertLessThan($full['maximum_provider_cost_micro_rub'], $continued['maximum_provider_cost_micro_rub']);
    }

    public function test_future_audit_proofs_are_reserved_at_one_part_per_permitted_work_before_the_draft_exists(): void
    {
        $plan = (new EstimatorProcessingPlan)->build('generation', 0, 32, false, $this->tariff());
        self::assertSame('estimator-processing-plan:v2', $plan['contract_version']);
        self::assertSame('global_work_items:v1', $plan['work_ceiling_policy']);
        self::assertSame(32, $plan['work_item_limit']);
        self::assertSame(32, $plan['audit_part_limit']);
        self::assertSame(288, $plan['roles']['estimate_audit']['max_calls']);
        self::assertSame(192, $plan['roles']['estimate_composer_correction']['max_calls']);
    }

    public function test_a_future_audit_operation_above_the_finite_work_ceiling_requires_sections_before_a_quote(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('estimator_processing_plan_requires_section_split');
        (new EstimatorProcessingPlan)->build('generation', 0, 513, false, $this->tariff());
    }

    private function tariff(): AiPriceSnapshot
    {
        return AiPriceSnapshot::fromArray(['input_per_million' => '13.5', 'cached_input_per_million' => '13.5',
            'output_per_million' => '67.5', 'reasoning_mode' => 'included_in_output', 'currency' => 'RUB',
            'image_unit' => '0', 'page_unit' => '0', 'source' => 'fixture', 'version' => 'luna-test-v1', 'effective_at' => '2026-10-10T00:00:00+00:00']);
    }
}
