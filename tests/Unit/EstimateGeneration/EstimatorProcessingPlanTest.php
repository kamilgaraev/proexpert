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
    }

    private function tariff(): AiPriceSnapshot
    {
        return AiPriceSnapshot::fromArray(['input_per_million' => '13.5', 'cached_input_per_million' => '13.5',
            'output_per_million' => '67.5', 'reasoning_mode' => 'included_in_output', 'currency' => 'RUB',
            'image_unit' => '0', 'page_unit' => '0', 'source' => 'fixture', 'version' => 'luna-test-v1', 'effective_at' => '2026-10-10T00:00:00+00:00']);
    }
}
