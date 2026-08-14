<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Pipeline\Stages;

use App\BusinessModules\Addons\EstimateGeneration\Pipeline\LeaseAwarePipelineStage;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineContext;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineStageResult;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\ProcessingStage;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\RenewsPipelineLease;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\CurrentProjectDerivedQuantityService;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\QuantityCalculationResult;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\QuantityCoverageWarning;
use App\BusinessModules\Addons\EstimateGeneration\Services\Learning\EstimateGenerationQuantityLearningEvidenceService;

final readonly class ExtractQuantitiesStage implements LeaseAwarePipelineStage
{
    use RenewsPipelineLease;

    public function __construct(
        private EstimateGenerationQuantityLearningEvidenceService $learning,
        private StageResultFactory $results,
        private CurrentProjectDerivedQuantityService $canonicalQuantities,
    ) {}

    public function stage(): ProcessingStage
    {
        return ProcessingStage::ExtractQuantities;
    }

    public function execute(PipelineContext $context): PipelineStageResult
    {
        $analysis = $context->priorOutputs->payload(ProcessingStage::UnderstandObject)['analysis'];
        $hints = $this->learning->hintsForAnalysis($context->organizationId, $context->projectId, $analysis);

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
        $quantities = $canonical['quantities'];
        $diagnostics = [];
        foreach ($canonical['warnings'] as $warning) {
            $diagnostics[] = [
                'code' => (string) ($warning['code'] ?? 'canonical_quantity_unresolved'),
                'severity' => 'error',
                'path' => 'quantities.stage6',
                'details' => $warning,
            ];
        }
        $reviewItems = [];
        foreach (array_slice($canonical['warnings'], 0, 1000) as $warning) {
            $question = is_array($warning['questions'][0] ?? null) ? $warning['questions'][0] : [];
            $sourceRefs = [];
            foreach (array_slice(is_array($warning['inputs'] ?? null) ? $warning['inputs'] : [], 0, 16) as $input) {
                if (is_array($input) && is_array($input['source_locator'] ?? null)) {
                    $sourceRefs[] = $input['source_locator'];
                }
            }
            $reviewItems[] = [
                'type' => 'quantity_blocking',
                'code' => (string) ($question['code'] ?? 'canonical_quantity_unresolved'),
                'work_item_key' => null,
                'message_key' => (string) ($question['message_key'] ?? 'estimate_generation.geometry_coverage_review'),
                'entity_id' => $warning['entity_id'] ?? null,
                'source_refs' => $sourceRefs,
            ];
        }
        $data = [
            'quantity_learning_hints' => $hints,
            'quantity_coverage_warnings' => array_values(array_filter(
                $canonical['warnings'],
                [QuantityCoverageWarning::class, 'isValid'],
            )),
            'building_quantities' => (new QuantityCalculationResult(
                $quantities,
                $diagnostics,
                ['canonical_derived_quantity_count' => count($quantities)],
            ))->toArray(),
            'stage6_generation_context' => [
                ...$canonical['context'],
                'warnings' => $canonical['warnings'],
                'review_items' => $reviewItems,
            ],
        ];

        return $this->results->make($context, $this->stage(), $data, ['hints_count' => count($hints)]);
    }
}
