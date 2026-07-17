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
}
