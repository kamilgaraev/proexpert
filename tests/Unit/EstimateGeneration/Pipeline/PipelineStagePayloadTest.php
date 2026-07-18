<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Pipeline;

use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineStagePayload;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\ProcessingStage;
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
        ];

        $payload = PipelineStagePayload::from(ProcessingStage::ExtractQuantities, $data);

        self::assertSame($data, $payload->data);
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
