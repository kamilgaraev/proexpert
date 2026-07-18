<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Pipeline\Stages;

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
use App\BusinessModules\Addons\EstimateGeneration\Services\Learning\EstimateGenerationQuantityLearningEvidenceService;

final readonly class ExtractQuantitiesStage implements LeaseAwarePipelineStage
{
    use RenewsPipelineLease;

    public function __construct(
        private EstimateGenerationQuantityLearningEvidenceService $learning,
        private StageResultFactory $results,
        private BuildingModelQuantityInputMapper $inputMapper = new NormalizedBuildingModelQuantityInputMapper,
        private BuildingQuantityCalculator $calculator = new BuildingQuantityCalculator,
        private AnalysisFloorAreaQuantityFactory $analysisFloorArea = new AnalysisFloorAreaQuantityFactory,
    ) {}

    public function stage(): ProcessingStage
    {
        return ProcessingStage::ExtractQuantities;
    }

    public function execute(PipelineContext $context): PipelineStageResult
    {
        $analysis = $context->priorOutputs->payload(ProcessingStage::UnderstandObject)['analysis'];
        $hints = $this->learning->hintsForAnalysis($context->organizationId, $context->projectId, $analysis);

        $data = ['quantity_learning_hints' => $hints, 'building_quantities' => []];
        $normalized = $analysis['normalized_building_model'] ?? null;
        $quantities = [];
        $diagnostics = [];
        $metrics = [];
        if (is_array($normalized)) {
            $model = NormalizedBuildingModelData::fromArray($normalized);
            $calculation = $this->calculator->calculate($this->inputMapper->map($model));
            $quantities = $calculation->all();
            $diagnostics = $calculation->diagnostics;
            $metrics = $calculation->metrics;
        }
        $documentArea = $this->analysisFloorArea->make($analysis);
        if ($documentArea !== null
            && ($documentArea->source === QuantitySource::Evidenced
                || ! isset($quantities['floor_area']))) {
            $quantities[$documentArea->key] = $documentArea;
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
}
