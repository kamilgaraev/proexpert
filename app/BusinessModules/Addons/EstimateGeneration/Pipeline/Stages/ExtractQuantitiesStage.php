<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Pipeline\Stages;

use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\BuildingModelOperationContext;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\DTO\NormalizedBuildingModelData;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\LeaseAwarePipelineStage;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineContext;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineStageResult;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\ProcessingStage;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\RenewsPipelineLease;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\AnalysisFloorAreaQuantityFactory;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\BuildingModelQuantityInputMapper;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\BuildingQuantityCalculator;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\NormalizedBuildingModelQuantityInputMapper;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\QuantityCalculationResult;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\QuantitySource;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\ResidentialQuantityScenarioCatalog;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\RoomAnnotationFloorAreaQuantityFactory;
use App\BusinessModules\Addons\EstimateGeneration\Services\Learning\EstimateGenerationQuantityLearningEvidenceService;

final readonly class ExtractQuantitiesStage implements LeaseAwarePipelineStage
{
    use RenewsPipelineLease;

    public function __construct(
        private EstimateGenerationQuantityLearningEvidenceService $learning,
        private StageResultFactory $results,
        private RoomAnnotationFloorAreaQuantityFactory $roomAnnotationFloorArea,
        private BuildingModelQuantityInputMapper $inputMapper = new NormalizedBuildingModelQuantityInputMapper,
        private BuildingQuantityCalculator $calculator = new BuildingQuantityCalculator,
        private AnalysisFloorAreaQuantityFactory $analysisFloorArea = new AnalysisFloorAreaQuantityFactory,
        private ResidentialQuantityScenarioCatalog $residentialScenarios = new ResidentialQuantityScenarioCatalog,
    ) {}

    public function stage(): ProcessingStage
    {
        return ProcessingStage::ExtractQuantities;
    }

    public function execute(PipelineContext $context): PipelineStageResult
    {
        $analysis = $context->priorOutputs->payload(ProcessingStage::UnderstandObject)['analysis'];
        $hints = $this->learning->hintsForAnalysis($context->organizationId, $context->projectId, $analysis);

        $data = [
            'quantity_learning_hints' => $hints,
            'quantity_coverage_warnings' => [],
            'building_quantities' => [],
        ];
        $normalized = $analysis['normalized_building_model'] ?? null;
        $quantities = [];
        $diagnostics = [];
        $metrics = [];
        $model = null;
        if (is_array($normalized)) {
            $model = NormalizedBuildingModelData::fromArray($normalized);
            $calculation = $this->calculator->calculate($this->inputMapper->map($model));
            $quantities = $calculation->all();
            $diagnostics = $calculation->diagnostics;
            $metrics = $calculation->metrics;
        }
        if ($model !== null && $context->baseInputVersion !== null) {
            $expectedFloorCount = $this->positiveInteger($analysis['object']['floors'] ?? null);
            $roomAreas = $this->roomAnnotationFloorArea->makeAll(new BuildingModelOperationContext(
                $context->organizationId,
                $context->projectId,
                $context->sessionId,
                $context->baseInputVersion,
            ), $model, $expectedFloorCount);
            foreach ($roomAreas as $roomArea) {
                $quantities[$roomArea->key] = $roomArea;
            }
        }
        $documentArea = $this->analysisFloorArea->make($analysis);
        if ($documentArea !== null
            && ($documentArea->source === QuantitySource::Evidenced
                || ! isset($quantities['floor_area']))) {
            $quantities[$documentArea->key] = $documentArea;
        }
        if ($model !== null) {
            $scenario = $this->residentialScenarios->build($quantities, $model, $analysis);
            foreach ($scenario->quantities as $key => $quantity) {
                if (! isset($quantities[$key])) {
                    $quantities[$key] = $quantity;
                }
            }
            foreach ($scenario->omissions as $omission) {
                $diagnostics[] = [
                    'code' => 'residential_scenario_scope_omitted',
                    'severity' => 'warning',
                    'path' => 'quantities.'.$omission['quantity_key'].'.'.$omission['reason'],
                ];
            }
            $data['quantity_coverage_warnings'] = $scenario->omissions;
            if ($scenario->quantities !== []) {
                $metrics['residential_scenario_quantity_count'] = count($scenario->quantities);
                $metrics['residential_scenario_omission_count'] = count($scenario->omissions);
            }
        }
        if ($quantities !== [] || is_array($normalized)) {
            $data['building_quantities'] = (new QuantityCalculationResult(
                $quantities,
                $diagnostics,
                $metrics,
            ))->toArray();
        }

        return $this->results->make($context, $this->stage(), $data, ['hints_count' => count($hints)]);
    }

    private function positiveInteger(mixed $value): ?int
    {
        if (! is_numeric($value) || (float) $value < 1 || (float) $value > 100 || floor((float) $value) !== (float) $value) {
            return null;
        }

        return (int) $value;
    }
}
