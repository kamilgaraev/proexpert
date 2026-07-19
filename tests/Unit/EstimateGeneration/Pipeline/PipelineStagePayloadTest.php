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
        ];

        $payload = PipelineStagePayload::from(ProcessingStage::ExtractQuantities, $data);

        self::assertSame($data, $payload->data);
    }

    #[Test]
    public function quantity_coverage_warning_requires_a_complete_structured_identity(): void
    {
        $this->expectException(InvalidArgumentException::class);

        PipelineStagePayload::from(ProcessingStage::ExtractQuantities, [
            'building_quantities' => [],
            'quantity_learning_hints' => [],
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
            'local_estimates' => [],
        ];

        foreach ([ProcessingStage::MatchNormatives, ProcessingStage::AssembleResources, ProcessingStage::ResolvePrices] as $stage) {
            self::assertSame($data, PipelineStagePayload::from($stage, $data)->data);
        }
    }
}
