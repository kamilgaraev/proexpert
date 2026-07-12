<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Pipeline\Stages;

use App\BusinessModules\Addons\EstimateGeneration\Enums\EstimateGenerationMode;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceData;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceRepository;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceSourceType;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceType;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\NormativeContextPinResolver;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\LeaseAwarePipelineStage;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineContext;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineStageResult;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\ProcessingStage;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\RenewsPipelineLease;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateDecompositionService;
use App\BusinessModules\Addons\EstimateGeneration\Services\NormativeWorkItemPlannerService;
use App\BusinessModules\Addons\EstimateGeneration\Services\PackagePlannerService;

final readonly class PlanWorkItemsStage implements LeaseAwarePipelineStage
{
    use RenewsPipelineLease;

    public function __construct(
        private PackagePlannerService $packagePlanner,
        private EstimateDecompositionService $decomposition,
        private NormativeWorkItemPlannerService $workItemPlanner,
        private NormativeContextPinResolver $normativePins,
        private StageResultFactory $results,
        private EvidenceRepository $evidence,
    ) {}

    public function stage(): ProcessingStage
    {
        return ProcessingStage::PlanWorkItems;
    }

    public function execute(PipelineContext $context): PipelineStageResult
    {
        $analysis = $context->priorOutputs->payload(ProcessingStage::UnderstandObject)['analysis'];
        $hints = $context->priorOutputs->payload(ProcessingStage::ExtractQuantities)['quantity_learning_hints'];
        if ($hints !== []) {
            $analysis['document_context']['quantity_learning_hints'] = $hints;
        }
        $profile = $this->packagePlanner->profileFromAnalysis($analysis);
        $plan = $this->packagePlanner->plan($profile);
        $localEstimates = $this->decomposition->decomposePackagePlan($analysis, $plan);
        foreach ($localEstimates as $localIndex => $localEstimate) {
            foreach ($localEstimate['sections'] as $sectionIndex => $section) {
                $items = $this->workItemPlanner->build($localEstimate, $section, $analysis);
                foreach ($items as $itemIndex => $item) {
                    $items[$itemIndex] = $this->attachQuantityEvidence($context, $item);
                }
                $localEstimates[$localIndex]['sections'][$sectionIndex]['work_items'] = $items;
            }
        }

        return $this->results->make($context, $this->stage(), [
            'object_profile' => $profile->toArray(),
            'package_plan' => $plan->toArray(),
            'document_requirements' => $this->packagePlanner->documentRequirements($profile),
            'generation_mode' => EstimateGenerationMode::fromInput($profile->planningSignals['generation_mode'] ?? null)->value,
            'regional_context' => $analysis['regional_context'] ?? [],
            'normative_context_pin' => $this->normativePins->resolve(is_array($analysis['regional_context'] ?? null) ? $analysis['regional_context'] : []),
            'local_estimates' => $localEstimates,
        ], ['local_estimates_count' => count($localEstimates)]);
    }

    private function attachQuantityEvidence(PipelineContext $context, array $workItem): array
    {
        $quantity = filter_var($workItem['quantity'] ?? null, FILTER_VALIDATE_FLOAT);
        $unit = $this->evidenceUnit($workItem['unit'] ?? null);
        if ($quantity === false || $quantity <= 0 || $unit === null) {
            return $workItem;
        }
        $workItem['unit'] = $unit;
        $identity = hash('sha256', (string) ($workItem['key'] ?? json_encode($workItem, JSON_THROW_ON_ERROR)));
        $node = $this->evidence->insertOrGet(new EvidenceData(
            organizationId: $context->organizationId,
            projectId: $context->projectId,
            sessionId: $context->sessionId,
            type: EvidenceType::WorkItem,
            sourceType: EvidenceSourceType::Pipeline,
            sourceRef: 'pipeline:decompose',
            sourceVersion: 'pipeline:v1',
            locator: ['item_key' => 'item:'.$identity],
            value: ['work_code' => 'work_type:'.$identity, 'quantity' => (float) $quantity, 'unit' => $unit],
            confidence: (float) ($workItem['confidence'] ?? 1),
            producerName: 'work_planner',
            producerVersion: 'pipeline:v1',
        ));
        $workItem['quantity_evidence_id'] = $node->id;
        $workItem['quantity_evidence_fingerprint'] = $node->fingerprint;

        return $workItem;
    }

    private function evidenceUnit(mixed $unit): ?string
    {
        if (! is_string($unit)) {
            return null;
        }

        return match (mb_strtolower(trim($unit))) {
            'm', 'м', 'п.м', 'пм' => 'm',
            'm2', 'м2', 'м²' => 'm2',
            'm3', 'м3', 'м³' => 'm3',
            'pcs', 'шт', 'шт.' => 'pcs',
            'kg', 'кг' => 'kg',
            't', 'т' => 't',
            'h', 'ч', 'час' => 'h',
            default => null,
        };
    }
}
