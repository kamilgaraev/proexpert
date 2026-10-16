<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Application\Credits\EstimatorProcessingPlan;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiPriceSnapshot;
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
        self::assertSame(1, $plan['composer_part_limit']);
        self::assertArrayNotHasKey('rerank', $plan['roles']);
        self::assertSame(array_sum(array_column($plan['roles'], 'max_calls')), $plan['maximum_calls']);
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

    public function test_text_reserve_uses_a_frozen_enforced_byte_bound_and_the_effective_output_limit(): void
    {
        config()->set('ai-assistant-credits.profiles.normal', ['input_tokens' => 16384, 'output_tokens' => 2048, 'max_calls' => 4]);
        $plan = (new EstimatorProcessingPlan)->build('generation', 0, 32, false, $this->tariff());
        self::assertSame(65536, $plan['roles']['estimate_composition']['input_ceiling']);
        self::assertSame(2048, $plan['roles']['estimate_composition']['output_ceiling']);
        self::assertSame(['input_tokens' => 16384, 'output_tokens' => 2048, 'max_calls' => 4], $plan['text_payload_policy']['token_limits']);
        config()->set('ai-assistant-credits.profiles.normal', ['input_tokens' => 32768, 'output_tokens' => 4096, 'max_calls' => 6]);
        self::assertSame(2048, $plan['text_payload_policy']['token_limits']['output_tokens']);
    }

    public function test_a_large_operation_reserves_three_audits_two_corrections_and_three_sends_per_part(): void
    {
        $plan = (new EstimatorProcessingPlan)->build('generation', 0, 65, false, $this->tariff());
        self::assertSame(65, $plan['audit_part_limit']);
        self::assertSame(3, $plan['composer_part_limit']);
        self::assertSame(585, $plan['roles']['estimate_audit']['max_calls']);
        self::assertSame(390, $plan['roles']['estimate_composer_correction']['max_calls']);
        self::assertSame(984, $plan['maximum_calls']);
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
        self::assertSame(4, $plan['composer_part_limit']);
        self::assertSame(32, $plan['audit_part_limit']);
        self::assertSame(2, $plan['synthesis_part_limit']);
        self::assertSame(12, $plan['roles']['estimate_composition']['max_calls']);
        self::assertSame(288, $plan['roles']['estimate_audit']['max_calls']);
        self::assertSame(192, $plan['roles']['estimate_composer_correction']['max_calls']);
        self::assertSame(6, $plan['roles']['project_synthesis']['max_calls']);
        self::assertSame(498, $plan['maximum_calls']);
        self::assertGreaterThan($baseline['maximum_provider_cost_micro_rub'], $plan['maximum_provider_cost_micro_rub']);
    }

    public function test_measured_parts_cannot_reduce_the_unknown_input_allowance(): void
    {
        $plan = (new EstimatorProcessingPlan)->build('generation', 0, 65, false, $this->tariff(), false,
            ['composer' => 1, 'audit' => 0, 'synthesis' => 1]);
        self::assertSame(3, $plan['composer_part_limit']);
        self::assertSame(65, $plan['audit_part_limit']);
        self::assertSame(0, $plan['synthesis_part_limit']);
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
