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
        self::assertSame(4096, $plan['roles']['vision']['output_ceiling']);
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

    public function test_a_large_operation_reserves_three_audits_two_corrections_and_three_sends_per_part(): void
    {
        $plan = (new EstimatorProcessingPlan)->build('generation', 0, 65, false, $this->tariff());
        self::assertSame(3, $plan['audit_part_limit']);
        self::assertSame(3, $plan['composer_part_limit']);
        self::assertSame(27, $plan['roles']['estimate_audit']['max_calls']);
        self::assertSame(18, $plan['roles']['estimate_composer_correction']['max_calls']);
        self::assertSame(54, $plan['maximum_calls']);
    }

    public function test_complex_operation_reserves_every_engineer_part_before_execution(): void
    {
        $plan = (new EstimatorProcessingPlan)->build('generation', 0, 65, true, $this->tariff());
        self::assertSame(3, $plan['synthesis_part_limit']);
        self::assertSame(9, $plan['roles']['project_synthesis']['max_calls']);
    }

    private function tariff(): AiPriceSnapshot
    {
        return AiPriceSnapshot::fromArray(['input_per_million' => '13.5', 'cached_input_per_million' => '13.5',
            'output_per_million' => '67.5', 'reasoning_mode' => 'included_in_output', 'currency' => 'RUB',
            'image_unit' => '0', 'page_unit' => '0', 'source' => 'fixture', 'version' => 'luna-test-v1', 'effective_at' => '2026-10-10T00:00:00+00:00']);
    }
}
