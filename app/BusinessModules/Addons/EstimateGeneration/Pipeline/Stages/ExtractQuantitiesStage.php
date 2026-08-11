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
use App\BusinessModules\Addons\EstimateGeneration\Quantities\CurrentProjectDerivedQuantityService;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\EffectiveProjectModelQuantityInputProjector;
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
        private EffectiveProjectModelQuantityInputProjector $effectiveProjection = new EffectiveProjectModelQuantityInputProjector,
        private ?CurrentProjectDerivedQuantityService $canonicalQuantities = null,
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
            'stage6_generation_context' => [],
        ];
        $normalized = $analysis['normalized_building_model'] ?? null;
        $quantities = [];
        $diagnostics = [];
        $metrics = [];
        $model = null;
        $hasEffectiveAreaCorrections = false;
        if (is_array($normalized)) {
            $model = NormalizedBuildingModelData::fromArray($normalized);
            $effectiveValues = is_array($analysis['effective_project_model_values'] ?? null)
                ? $analysis['effective_project_model_values'] : [];
            $hasEffectiveAreaCorrections = $this->effectiveProjection->hasAreaCorrections($effectiveValues);
            $calculation = $this->calculator->calculate($this->effectiveProjection->project($this->inputMapper->map($model), $effectiveValues));
            $quantities = $calculation->all();
            $diagnostics = $calculation->diagnostics;
            $metrics = $calculation->metrics;
        }
        $documentArea = $this->analysisFloorArea->make($analysis);
        if (! $hasEffectiveAreaCorrections && $documentArea !== null
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
        if ($this->canonicalQuantities !== null) {
            $decisionIds = [];
            foreach ($analysis['effective_project_model_values'] ?? [] as $effectiveValue) {
                if (is_array($effectiveValue) && is_string($effectiveValue['decision_id'] ?? null)) {
                    $decisionIds[] = $effectiveValue['decision_id'];
                }
            }
            $canonical = $this->canonicalQuantities->derive(
                $context->organizationId,
                $context->projectId,
                $context->sessionId,
                $decisionIds,
            );
            foreach ($canonical['quantities'] as $key => $quantity) {
                $quantities[$key] = $quantity;
            }
            foreach ($canonical['warnings'] as $warning) {
                $diagnostics[] = [
                    'code' => (string) ($warning['code'] ?? 'canonical_quantity_unresolved'),
                    'severity' => 'warning',
                    'path' => 'quantities.stage6',
                    'details' => $warning,
                ];
            }
            $data['stage6_generation_context'] = $canonical['context'];
            $metrics['canonical_derived_quantity_count'] = count($canonical['quantities']);
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
