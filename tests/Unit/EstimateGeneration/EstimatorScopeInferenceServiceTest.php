<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Services\EstimatorScopeInferenceService;
use PHPUnit\Framework\TestCase;

final class EstimatorScopeInferenceServiceTest extends TestCase
{
    public function test_infers_scope_from_specification_quantity_takeoff(): void
    {
        $inferences = (new EstimatorScopeInferenceService())->inferFromDocumentPayload([
            'id' => 12,
            'filename' => 'spec.xlsx',
            'quantity_takeoffs' => [[
                'scope_key' => 'specification_quantity',
                'name' => 'Светильник светодиодный',
                'unit' => 'шт',
                'quantity' => 42,
                'source_refs' => [[
                    'type' => 'document',
                    'document_id' => 12,
                    'filename' => 'spec.xlsx',
                    'page_number' => 1,
                ]],
                'normalized_payload' => [
                    'quantity_key' => 'warehouse.lighting',
                    'source' => 'specification',
                ],
            ]],
        ]);

        self::assertCount(1, $inferences);
        self::assertSame('specification_takeoff', $inferences[0]['inference_type']);
        self::assertSame('electrical', $inferences[0]['scope_type']);
        self::assertSame('warehouse.lighting', $inferences[0]['normalized_payload']['quantity_key']);
        self::assertSame(42, $inferences[0]['normalized_payload']['quantity_value']);
    }
}
