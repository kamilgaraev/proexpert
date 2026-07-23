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
    public function confirmed_model_uses_only_exact_document_area_evidence(): void
    {
        $quantity = (new AnalysisFloorAreaQuantityFactory)->make([
            'object' => ['area' => 180],
            'document_context' => ['facts_summary' => ['total_area_m2' => 179.8]],
            'normalized_building_model' => [
                'scale_status' => 'confirmed',
                'evidence_ids' => [184, 185],
                'metrics' => ['floor_count' => 2, 'room_count' => 15],
                'model_version' => 'building-model:v1',
            ],
            'document_total_area' => [
                'amount' => '179.800000',
                'evidence_id' => 901,
                'confidence' => 0.95,
                'floor_count' => 2,
            ],
        ]);

        self::assertNotNull($quantity);
        self::assertSame('floor_area', $quantity->key);
        self::assertSame('179.800000', $quantity->amount);
        self::assertSame(QuantitySource::Evidenced, $quantity->source);
        self::assertSame(['901'], $quantity->evidenceIds);
        self::assertSame([], $quantity->reviewBlockers);

        $workQuantity = (new WorkItemQuantityMapper)->map('finish.floor', [
            'floor_area' => $quantity->toArray(),
        ]);

        self::assertNotNull($workQuantity);
        self::assertSame('179.800000', $workQuantity->amount);
        self::assertSame(QuantitySource::Estimated, $workQuantity->source);
        self::assertSame(['901'], $workQuantity->evidenceIds);
        self::assertSame([], $workQuantity->reviewBlockers);
    }

    #[Test]
    public function geometry_evidence_cannot_make_document_area_evidenced(): void
    {
        $quantity = (new AnalysisFloorAreaQuantityFactory)->make([
            'document_context' => ['facts_summary' => ['total_area_m2' => 180]],
            'normalized_building_model' => [
                'scale_status' => 'confirmed',
                'evidence_ids' => [184, 185],
                'metrics' => ['floor_count' => 2, 'room_count' => 15],
                'model_version' => 'building-model:v1',
            ],
        ]);

        self::assertNotNull($quantity);
        self::assertSame(QuantitySource::Estimated, $quantity->source);
        self::assertSame([], $quantity->evidenceIds);
        self::assertSame(['estimated_quantity_requires_review'], $quantity->reviewBlockers);
    }

    #[Test]
    public function exact_document_area_does_not_depend_on_geometric_scale(): void
    {
        $quantity = (new AnalysisFloorAreaQuantityFactory)->make([
            'object' => ['area' => 180],
            'normalized_building_model' => [
                'scale_status' => 'estimated',
                'evidence_ids' => [184],
                'metrics' => ['floor_count' => 1, 'room_count' => 1],
                'model_version' => 'building-model:v1',
            ],
            'document_total_area' => [
                'amount' => '180.000000', 'evidence_id' => 901, 'confidence' => 0.95, 'floor_count' => 1,
            ],
        ]);

        self::assertNotNull($quantity);
        self::assertSame(QuantitySource::Evidenced, $quantity->source);
        self::assertSame(['901'], $quantity->evidenceIds);
        self::assertSame([], $quantity->reviewBlockers);
    }

    #[Test]
    public function exact_document_area_has_canonical_quantity_shape(): void
    {
        $analysis = [
            'object' => ['area' => 179.8],
            'normalized_building_model' => [
                'scale_status' => 'confirmed',
                'evidence_ids' => [184, 185],
                'metrics' => ['floor_count' => 1, 'room_count' => 1],
                'model_version' => 'building-model:v1',
            ],
            'document_total_area' => [
                'amount' => '179.800000', 'evidence_id' => 901, 'confidence' => 0.95, 'floor_count' => 1,
            ],
        ];

        $quantity = (new AnalysisFloorAreaQuantityFactory)->make($analysis);

        self::assertNotNull($quantity);
        self::assertSame('179.800000', $quantity->amount);
        self::assertSame('document.facts.total_floor_area', $quantity->formulaKey);
        self::assertSame(['901'], $quantity->evidenceIds);
        self::assertSame([], $quantity->reviewBlockers);
    }
}
