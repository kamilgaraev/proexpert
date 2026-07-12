<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Pipeline\Stages;

use App\BusinessModules\Addons\EstimateGeneration\Enums\EstimateGenerationMode;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceData;
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
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;

final readonly class PlanWorkItemsStage implements LeaseAwarePipelineStage
{
    use RenewsPipelineLease;

    public function __construct(
        private PackagePlannerService $packagePlanner,
        private EstimateDecompositionService $decomposition,
        private NormativeWorkItemPlannerService $workItemPlanner,
        private NormativeContextPinResolver $normativePins,
        private StageResultFactory $results,
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
        $quantity = $this->canonicalQuantity($workItem['quantity'] ?? null);
        $unit = $this->evidenceUnit($workItem['unit'] ?? null);
        if ($quantity === null || $unit === null) {
            return $workItem;
        }
        $workItem['quantity'] = $quantity;
        $workItem['unit'] = $unit;
        $identity = hash('sha256', (string) ($workItem['key'] ?? json_encode($workItem, JSON_THROW_ON_ERROR)));
        $data = new EvidenceData(
            organizationId: $context->organizationId,
            projectId: $context->projectId,
            sessionId: $context->sessionId,
            type: EvidenceType::WorkItem,
            sourceType: EvidenceSourceType::Pipeline,
            sourceRef: 'pipeline:decompose',
            sourceVersion: 'pipeline:v1',
            locator: ['item_key' => 'item:'.$identity],
            value: ['work_code' => 'work_type:'.$identity, 'quantity' => $quantity, 'unit' => $unit],
            confidence: (float) ($workItem['confidence'] ?? 1),
            producerName: 'work_planner',
            producerVersion: 'pipeline:v1',
        );
        $workItem['quantity_evidence_descriptor'] = [
            'fingerprint' => $data->fingerprint(),
            'quantity' => $quantity,
            'unit' => $unit,
            'locator' => $data->locator,
            'source_type' => $data->sourceType->value,
            'source_ref' => $data->sourceRef,
            'source_version' => $data->sourceVersion,
            'producer_name' => $data->producerName,
            'producer_version' => $data->producerVersion,
            'confidence' => number_format($data->confidence, 6, '.', ''),
            'work_code' => $data->value['work_code'],
        ];

        return $workItem;
    }

    private function canonicalQuantity(mixed $quantity): ?string
    {
        if (is_int($quantity)) {
            $quantity = (string) $quantity;
        }
        if (! is_string($quantity) || strlen($quantity) > 64
            || preg_match('/^(0|[1-9][0-9]*)(\.[0-9]+)?$/D', $quantity) !== 1) {
            return null;
        }

        try {
            $decimal = BigDecimal::of($quantity);
        } catch (MathException) {
            return null;
        }

        if ($decimal->isLessThanOrEqualTo(0)) {
            return null;
        }

        return (string) $decimal->stripTrailingZeros();
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
