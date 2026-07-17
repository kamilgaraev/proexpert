<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Quantities;

use App\BusinessModules\Addons\EstimateGeneration\Quantities\AnalysisFloorAreaQuantityFactory;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\QuantitySource;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\WorkItemQuantityMapper;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AnalysisFloorAreaQuantityFactoryTest extends TestCase
{
    #[Test]
    public function confirmed_model_turns_document_area_into_evidenced_floor_quantity(): void
    {
        $quantity = (new AnalysisFloorAreaQuantityFactory)->make([
            'object' => ['area' => 180],
            'document_context' => ['facts_summary' => ['total_area_m2' => 179.8]],
            'normalized_building_model' => [
                'scale_status' => 'confirmed',
                'evidence_ids' => [184, 185],
                'model_version' => 'building-model:v1',
            ],
        ]);

        self::assertNotNull($quantity);
        self::assertSame('floor_area', $quantity->key);
        self::assertSame('179.800000', $quantity->amount);
        self::assertSame(QuantitySource::Evidenced, $quantity->source);
        self::assertSame(['184', '185'], $quantity->evidenceIds);
        self::assertSame([], $quantity->reviewBlockers);

        $workQuantity = (new WorkItemQuantityMapper)->map('finish.floor', [
            'floor_area' => $quantity->toArray(),
        ]);

        self::assertNotNull($workQuantity);
        self::assertSame('179.800000', $workQuantity->amount);
        self::assertSame(QuantitySource::Evidenced, $workQuantity->source);
        self::assertSame([], $workQuantity->reviewBlockers);
    }

    #[Test]
    public function unconfirmed_model_keeps_document_area_blocked(): void
    {
        $quantity = (new AnalysisFloorAreaQuantityFactory)->make([
            'object' => ['area' => 180],
            'normalized_building_model' => [
                'scale_status' => 'estimated',
                'evidence_ids' => [184],
                'model_version' => 'building-model:v1',
            ],
        ]);

        self::assertNotNull($quantity);
        self::assertSame(QuantitySource::Estimated, $quantity->source);
        self::assertSame(['estimated_quantity_requires_review'], $quantity->reviewBlockers);
    }
}
