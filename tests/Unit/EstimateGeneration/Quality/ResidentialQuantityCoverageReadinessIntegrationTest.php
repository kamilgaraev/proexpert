<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Quality;

use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\DTO\NormalizedBuildingModelData;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\QuantityData;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\QuantitySource;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\ResidentialQuantityScenarioCatalog;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateDecompositionService;
use App\BusinessModules\Addons\EstimateGeneration\Services\PackagePlannerService;
use App\BusinessModules\Addons\EstimateGeneration\Services\Quality\DraftReadinessInspector;
use App\BusinessModules\Addons\EstimateGeneration\Services\Quality\EstimatorReadinessEvaluator;
use App\BusinessModules\Addons\EstimateGeneration\Services\Quality\EstimatorReadinessInput;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ResidentialQuantityCoverageReadinessIntegrationTest extends TestCase
{
    #[Test]
    public function unresolved_heating_source_and_sewer_outlet_from_catalog_block_apply(): void
    {
        $model = $this->model();
        $result = (new ResidentialQuantityScenarioCatalog)->build([
            'floor_area' => new QuantityData(
                key: 'floor_area',
                unit: 'm2',
                amount: '180.000000',
                formulaKey: 'document.rooms.floor_area',
                formulaVersion: '1.0.0',
                formulaInputs: ['items' => []],
                source: QuantitySource::Evidenced,
                evidenceIds: ['1', '2'],
                modelVersion: 'building-model:v1',
            ),
            'first_floor_internal_area' => new QuantityData(
                key: 'first_floor_internal_area',
                unit: 'm2',
                amount: '90.000000',
                formulaKey: 'document.rooms.first_floor_internal_area',
                formulaVersion: '1.0.0',
                formulaInputs: ['items' => []],
                source: QuantitySource::Evidenced,
                evidenceIds: ['1'],
                modelVersion: 'building-model:v1',
            ),
        ], $model, [
            'object' => ['object_type' => 'house', 'floors' => 2, 'roof_type' => 'pitched'],
        ]);

        $analysis = [
            'object' => [
                'object_type' => 'house',
                'area' => 180.0,
                'floors' => 2,
                'rooms' => 2,
                'roof_type' => 'pitched',
            ],
            'document_context' => ['quantity_coverage_warnings' => $result->omissions],
        ];
        $packagePlanner = new PackagePlannerService;
        $profile = $packagePlanner->profileFromAnalysis($analysis);
        $localEstimates = (new EstimateDecompositionService)->decomposePackagePlan(
            $analysis,
            $packagePlanner->plan($profile),
        );

        $buildingModel = $model->toArray();
        $buildingModel['cad_status'] = 'completed';
        $inspection = (new DraftReadinessInspector)->inspect([
            'building_model' => $buildingModel,
            'quality_summary' => [
                'duplicate_work_items' => 0,
                'review_items' => ['blocking' => 0],
                'warning_codes' => [],
            ],
            'local_estimates' => $localEstimates,
        ]);
        $readiness = (new EstimatorReadinessEvaluator)->evaluate(new EstimatorReadinessInput(
            'ready_to_apply',
            true,
            'passed',
            'passed',
            array_merge([
                'documents_total' => 1,
                'documents_ready' => 1,
                'priced_work_items_total' => 1,
            ], $inspection->metrics),
        ));
        $issue = array_values(array_filter(
            $inspection->blockingIssues,
            static fn (array $issue): bool => ($issue['code'] ?? null) === 'required_scope_unresolved',
        ))[0] ?? null;
        $coverage = $issue['details']['quantity_coverage'] ?? [];

        self::assertSame(
            ['heating.unit', 'sewerage.outlet_route'],
            array_values(array_intersect(
                ['heating.unit', 'sewerage.outlet_route'],
                array_column($coverage, 'quantity_key'),
            )),
        );
        self::assertSame(
            ['heating_source_type_missing', 'sewer_outlet_route_missing'],
            array_values(array_intersect(
                ['heating_source_type_missing', 'sewer_outlet_route_missing'],
                array_column($coverage, 'reason'),
            )),
        );
        self::assertFalse($readiness->canApply);
    }

    private function model(): NormalizedBuildingModelData
    {
        $floor = static fn (string $key, string $roomKey, string $roomName, int $evidenceId): array => [
            'key' => $key,
            'elevation_m' => null,
            'height_m' => null,
            'rooms' => [[
                'key' => $roomKey,
                'name' => $roomName,
                'polygon' => null,
                'evidence_ids' => [$evidenceId],
                'confidence' => 0.9,
                'geometry_certainty' => 'confirmed',
            ]],
            'walls' => [],
            'openings' => [],
            'engineering_elements' => [],
            'evidence_ids' => [$evidenceId],
            'confidence' => 0.9,
            'geometry_certainty' => 'confirmed',
        ];

        return NormalizedBuildingModelData::fromArray([
            'model_version' => 'building-model:v1',
            'coordinate_system' => 'metric-right-handed-2d:v1',
            'unit' => 'm',
            'scale_status' => 'confirmed',
            'scale_meters_per_unit' => 1.0,
            'floors' => [
                $floor('floor-1', 'room-1', 'Bathroom 5.0 m2', 1),
                $floor('floor-2', 'room-2', 'Bedroom 20.0 m2', 2),
            ],
            'assumptions' => [],
            'evidence_ids' => [1, 2],
            'metrics' => [
                'floor_count' => 2,
                'room_count' => 2,
                'wall_count' => 0,
                'opening_count' => 0,
                'engineering_element_count' => 0,
                'evidence_count' => 2,
                'minimum_confidence' => 0.9,
                'complete' => true,
            ],
        ]);
    }
}
