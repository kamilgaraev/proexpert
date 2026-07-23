<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\DTO\NormalizedBuildingModelData;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\ResidentialMaterialScenarioCatalog;
use App\BusinessModules\Addons\EstimateGeneration\Planning\ResidentialWorkCompositionCatalog;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\QuantityData;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\QuantitySource;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\ResidentialQuantityScenarioCatalog;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimatorScopeInferenceService;
use App\BusinessModules\Addons\EstimateGeneration\Services\NormativeWorkItemPlannerService;
use App\BusinessModules\Addons\EstimateGeneration\Services\ProjectDocumentNormativeReferenceExtractor;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ResidentialFinishZoningTest extends TestCase
{
    #[Test]
    public function residential_finish_excludes_evidenced_wet_zones_from_dry_floor_and_painted_walls(): void
    {
        $result = (new ResidentialQuantityScenarioCatalog)->build([
            'floor_area' => $this->evidencedQuantity('floor_area', '192.800000', ['drawing:floor-1', 'drawing:floor-2']),
            'net_wall_area' => $this->evidencedQuantity('net_wall_area', '500.000000', ['drawing:walls']),
        ], $this->model(), ['object' => ['object_type' => 'house']]);

        self::assertSame('181.000000', $result->quantities['finish.floor']->amount);
        self::assertSame('11.800000', $result->quantities['sanitary.floor_tile']->amount);
        self::assertSame('39.530000', $result->quantities['sanitary.tile']->amount);
        self::assertSame('460.470000', $result->quantities['finish.paint']->amount);

        self::assertSame(
            ['101', '102', 'drawing:floor-1', 'drawing:floor-2'],
            $result->quantities['finish.floor']->evidenceIds,
        );
        self::assertSame(
            ['101', '102'],
            $result->quantities['sanitary.floor_tile']->evidenceIds,
        );
        self::assertSame(
            ['101', '102', 'drawing:walls'],
            $result->quantities['finish.paint']->evidenceIds,
        );
        self::assertContains(
            'dry_floor_area_excludes_evidenced_wet_rooms',
            $result->quantities['finish.floor']->assumptions,
        );
        self::assertContains(
            'wet_zone_floor_tile_uses_evidenced_room_area',
            $result->quantities['sanitary.floor_tile']->assumptions,
        );
        self::assertContains(
            'paint_area_excludes_evidenced_wet_room_wall_tile',
            $result->quantities['finish.paint']->assumptions,
        );
    }

    #[Test]
    public function painted_wall_area_is_clamped_to_zero_when_wall_tile_area_exceeds_the_wall_basis(): void
    {
        $result = (new ResidentialQuantityScenarioCatalog)->build([
            'floor_area' => $this->evidencedQuantity('floor_area', '192.800000', ['drawing:floors']),
            'net_wall_area' => $this->evidencedQuantity('net_wall_area', '20.000000', ['drawing:walls']),
        ], $this->model(), ['object' => ['object_type' => 'house']]);

        self::assertSame('0.000000', $result->quantities['finish.paint']->amount);
        self::assertGreaterThanOrEqual(0, (float) $result->quantities['finish.paint']->amount);
    }

    #[Test]
    public function wet_zone_floor_tile_is_mandatory_and_planned_with_the_safe_floor_tile_norm(): void
    {
        $requirements = (new ResidentialWorkCompositionCatalog)->requirements([
            'object_profile' => ['object_type' => 'house', 'floors' => 2],
        ]);
        self::assertContains('sanitary.floor_tile', $requirements['plumbing']);

        $scenario = (new ResidentialMaterialScenarioCatalog)->issue('sanitary.floor_tile', 'residential');
        self::assertIsArray($scenario);
        self::assertSame('11-01-027-03', $scenario['normative_rate_code']);
        self::assertSame('wet_zone_ceramic_floor_tile', $scenario['assumption_code']);

        $analysis = [
            'object' => ['object_type' => 'house'],
            'document_context' => ['canonical_building_quantities' => [
                $this->currentScenarioQuantity('sanitary.floor_tile', '11.800000')->toArray(),
            ]],
        ];
        $estimate = $this->estimate('plumbing', 'plumbing');

        $items = (new NormativeWorkItemPlannerService(
            new ProjectDocumentNormativeReferenceExtractor,
            new EstimatorScopeInferenceService,
        ))->build($estimate, $estimate['sections'][0], $analysis);
        $item = array_column($items, null, 'quantity_formula')['sanitary.floor_tile'] ?? null;

        self::assertIsArray($item);
        self::assertSame('11-01-027-03', $item['normative_rate_code']);
        self::assertSame('Устройство плиточного покрытия пола мокрых зон', $item['name']);
        self::assertContains('preliminary_material_assumption', $item['validation_flags']);
        self::assertSame('wet_zone_ceramic_floor_tile', $item['metadata']['specialization_scenario']['assumption_code']);
    }

    /** @param list<string> $evidenceIds */
    private function evidencedQuantity(string $key, string $amount, array $evidenceIds): QuantityData
    {
        return new QuantityData(
            key: $key,
            unit: 'm2',
            amount: $amount,
            formulaKey: 'document.takeoff.'.$key,
            formulaVersion: '1.0.0',
            formulaInputs: ['takeoff' => 'measured'],
            source: QuantitySource::Evidenced,
            evidenceIds: $evidenceIds,
            modelVersion: 'building-model:v1',
        );
    }

    private function currentScenarioQuantity(string $key, string $amount): QuantityData
    {
        return new QuantityData(
            key: $key,
            unit: 'm2',
            amount: $amount,
            formulaKey: 'residential_preliminary.'.$key,
            formulaVersion: ResidentialQuantityScenarioCatalog::VERSION,
            formulaInputs: ['scenario' => [
                'id' => ResidentialQuantityScenarioCatalog::SCENARIO_ID,
                'version' => ResidentialQuantityScenarioCatalog::VERSION,
                'confidence' => 0.62,
                'warnings' => ['preliminary_quantity_scenario'],
            ]],
            source: QuantitySource::Estimated,
            evidenceIds: ['room:bathroom'],
            modelVersion: 'building-model:v1',
            assumptions: [ResidentialQuantityScenarioCatalog::SCENARIO_ID],
        );
    }

    private function estimate(string $key, string $scope): array
    {
        return [
            'key' => $key,
            'title' => $key,
            'scope_type' => $scope,
            'source_refs' => [],
            'sections' => [[
                'key' => $key.'-section',
                'title' => $key,
                'construction_part' => $scope,
                'source_refs' => [],
            ]],
        ];
    }

    private function model(): NormalizedBuildingModelData
    {
        $room = static fn (string $key, string $name, int $evidenceId): array => [
            'key' => $key,
            'name' => $name,
            'polygon' => null,
            'evidence_ids' => [$evidenceId],
            'confidence' => 0.9,
            'geometry_certainty' => 'confirmed',
        ];

        return new NormalizedBuildingModelData(
            unit: 'm',
            scaleStatus: 'confirmed',
            scaleMetersPerUnit: 1.0,
            floors: [new \App\BusinessModules\Addons\EstimateGeneration\BuildingModel\DTO\FloorData(
                key: 'floor-1',
                elevationM: null,
                heightM: null,
                rooms: array_map(
                    static fn (array $data): \App\BusinessModules\Addons\EstimateGeneration\BuildingModel\DTO\RoomData => \App\BusinessModules\Addons\EstimateGeneration\BuildingModel\DTO\RoomData::fromArray($data),
                    [
                        $room('room-bathroom', 'Ванная 6,9 м2', 101),
                        $room('room-wc', 'СУ 4,9 м2', 102),
                        $room('room-living', 'Гостиная 30,0 м2', 103),
                    ],
                ),
                walls: [],
                openings: [],
                engineeringElements: [],
                evidenceIds: [100],
                confidence: 0.9,
                geometryCertainty: 'confirmed',
            )],
            assumptions: [],
            modelVersion: 'building-model:v1',
        );
    }
}
