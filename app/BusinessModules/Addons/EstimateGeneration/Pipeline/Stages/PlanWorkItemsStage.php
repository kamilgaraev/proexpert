<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Pipeline\Stages;

use App\BusinessModules\Addons\EstimateGeneration\Pipeline\AcceptedQuantityEvidenceMaterializer;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\LeaseAwarePipelineStage;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineContext;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineStageResult;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\ProcessingStage;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\RenewsPipelineLease;
use App\BusinessModules\Addons\EstimateGeneration\Planning\WorkPlanCompiler;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\QuantityData;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\WorkItemQuantityMapper;

final readonly class PlanWorkItemsStage implements LeaseAwarePipelineStage
{
    use RenewsPipelineLease;

    public function __construct(
        private WorkPlanCompiler $compiler,
        private StageResultFactory $results,
        private AcceptedQuantityEvidenceMaterializer $acceptedEvidence,
        private WorkItemQuantityMapper $quantityMapper = new WorkItemQuantityMapper,
    ) {}

    public function stage(): ProcessingStage
    {
        return ProcessingStage::PlanWorkItems;
    }

    public function execute(PipelineContext $context): PipelineStageResult
    {
        $analysis = $context->priorOutputs->payload(ProcessingStage::UnderstandObject)['analysis'];
        $quantityOutput = $context->priorOutputs->payload(ProcessingStage::ExtractQuantities);
        $hints = $quantityOutput['quantity_learning_hints'];
        if ($hints !== []) {
            $analysis['document_context']['quantity_learning_hints'] = $hints;
        }
        $payload = $this->compiler->compile($analysis);
        $quantities = [];
        foreach (($quantityOutput['building_quantities']['quantities'] ?? []) as $quantity) {
            if (! is_array($quantity)) {
                continue;
            }
            $typed = QuantityData::fromArray($quantity)->toArray();
            $quantities[$typed['key']] = $typed;
        }
        foreach ($payload['local_estimates'] as $localIndex => $localEstimate) {
            foreach ($localEstimate['sections'] as $sectionIndex => $section) {
                foreach ($section['work_items'] as $itemIndex => $item) {
                    $mapped = $this->attachCanonicalQuantity($item, $quantities, $this->quantityMapper);
                    $quantity = $mapped['quantity_evidence'] ?? null;
                    if (is_array($quantity) && ($quantity['review_blockers'] ?? []) === []) {
                        $node = $this->acceptedEvidence->materialize($context, QuantityData::fromArray($quantity), $mapped);
                        $mapped['quantity_evidence_id'] = $node->id;
                        $mapped['quantity_evidence_fingerprint'] = $node->fingerprint;
                        $mapped['quantity_evidence_source_version'] = $node->sourceVersion;
                    }
                    $payload['local_estimates'][$localIndex]['sections'][$sectionIndex]['work_items'][$itemIndex] = $mapped;
                }
            }
        }

        return $this->results->make($context, $this->stage(), $payload, [
            'local_estimates_count' => count($payload['local_estimates']),
        ]);
    }

    private function attachCanonicalQuantity(
        array $workItem,
        array $quantities,
        ?WorkItemQuantityMapper $quantityMapper = null
    ): array {
        $quantityKey = $workItem['metadata']['quantity_key'] ?? null;
        $quantity = is_string($quantityKey)
            ? ($quantityMapper ?? new WorkItemQuantityMapper)->map($quantityKey, $quantities)?->toArray()
            : null;
        if (! is_array($quantity)) {
            unset($workItem['quantity'], $workItem['quantity_evidence']);
            $workItem['pricing_status'] = 'not_calculated';
            $workItem['pricing_blocker'] = 'quantity_mapping_missing';
            $workItem['validation_flags'] = array_values(array_unique([
                ...(is_array($workItem['validation_flags'] ?? null) ? $workItem['validation_flags'] : []),
                'quantity_mapping_missing',
                'requires_quantity_review',
            ]));

            return $workItem;
        }
        $workItem['quantity'] = $quantity['amount'];
        $workItem['unit'] = $quantity['unit'];
        $workItem['quantity_evidence'] = $quantity;
        $workItem['validation_flags'] = array_values(array_filter(
            is_array($workItem['validation_flags'] ?? null) ? $workItem['validation_flags'] : [],
            static fn (string $flag): bool => ! in_array($flag, [
                'document_takeoff_required',
                'quantity_mapping_missing',
                'requires_quantity_review',
            ], true)
        ));
        if ($quantity['review_blockers'] !== []) {
            $workItem['pricing_status'] = 'not_calculated';
            $workItem['pricing_blocker'] = 'quantity_review_required';
            $workItem['validation_flags'] = array_values(array_unique([
                ...(is_array($workItem['validation_flags'] ?? null) ? $workItem['validation_flags'] : []),
                ...$quantity['review_blockers'],
                'requires_quantity_review',
            ]));
        } elseif (in_array($workItem['pricing_blocker'] ?? null, [
            'quantity_mapping_missing',
            'quantity_review_required',
        ], true)) {
            $workItem['pricing_blocker'] = 'normative_required';
        }

        return $workItem;
    }
}
