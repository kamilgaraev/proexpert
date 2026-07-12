<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Quantities;

use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\DTO\FloorData;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\DTO\NormalizedBuildingModelData;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\DTO\RoomData;
use App\BusinessModules\Addons\EstimateGeneration\DTOs\Ocr\OcrPageResult;
use App\BusinessModules\Addons\EstimateGeneration\DTOs\Ocr\OcrRecognitionResult;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\BuildingModelQuantityInputMapper;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\NormalizedBuildingModelQuantityInputMapper;
use App\BusinessModules\Addons\EstimateGeneration\Services\Documents\DrawingGeometryAnalyzer;
use PHPUnit\Framework\TestCase;

final class DrawingGeometryAnalyzerMapperIntegrationTest extends TestCase
{
    public function test_runtime_analyzer_uses_typed_mapper_before_calculation(): void
    {
        $mapper = new class implements BuildingModelQuantityInputMapper
        {
            public int $calls = 0;

            public function map(NormalizedBuildingModelData $model): array
            {
                $this->calls++;

                return (new NormalizedBuildingModelQuantityInputMapper)->map($model);
            }
        };
        $model = new NormalizedBuildingModelData('m', 'confirmed', 1.0, [
            new FloorData('floor-1', 0.0, 2.8, [
                new RoomData('room-1', null, [[0.0, 0.0], [4.0, 0.0], [4.0, 3.0], [0.0, 3.0]], [11], 0.9, 'confirmed'),
            ], [], [], [], [11], 0.9, 'confirmed'),
        ], [], 'building-model:v1');

        $result = (new DrawingGeometryAnalyzer(inputMapper: $mapper))->analyze(1, 'plan.pdf', new OcrRecognitionResult(
            provider: 'test', model: 'test', pages: [new OcrPageResult(1, '', rawPayload: ['normalized_building_model' => $model->toArray()])],
        ));

        self::assertSame(1, $mapper->calls);
        self::assertSame('12.000000', $result['quantities'][0]['amount']);
        self::assertSame([], $result['review_reasons']);
    }
}
