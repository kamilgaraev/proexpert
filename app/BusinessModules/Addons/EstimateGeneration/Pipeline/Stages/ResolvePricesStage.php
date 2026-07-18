<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Pipeline\Stages;

use App\BusinessModules\Addons\EstimateGeneration\Pipeline\LeaseAwarePipelineStage;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineContext;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineStageResult;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\ProcessingStage;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\RenewsPipelineLease;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimatePricingService;
use Illuminate\Support\Facades\Log;

final readonly class ResolvePricesStage implements LeaseAwarePipelineStage
{
    use RenewsPipelineLease;

    public function __construct(private EstimatePricingService $pricing, private StageResultFactory $results) {}

    public function stage(): ProcessingStage
    {
        return ProcessingStage::ResolvePrices;
    }

    public function execute(PipelineContext $context): PipelineStageResult
    {
        $data = $context->priorOutputs->payload(ProcessingStage::AssembleResources);
        foreach ($data['local_estimates'] as $localIndex => $localEstimate) {
            foreach ($localEstimate['sections'] as $sectionIndex => $section) {
                $data['local_estimates'][$localIndex]['sections'][$sectionIndex]['work_items'] = $this->pricing->price(
                    $section['work_items'],
                    is_array($data['regional_context'] ?? null) ? $data['regional_context'] : [],
                    $context,
                );
            }
        }

        if ($this->canLog()) {
            Log::info('estimate_generation.pricing_resolution_outcomes', [
                'session_id' => $context->sessionId,
                'project_id' => $context->projectId,
                ...$this->pricingSummary($data['local_estimates']),
            ]);
        }

        return $this->results->make($context, $this->stage(), $data);
    }

    /**
     * @param  array<int, array<string, mixed>>  $localEstimates
     * @return array<string, mixed>
     */
    private function pricingSummary(array $localEstimates): array
    {
        $statuses = [];
        $blockers = [];
        $pricedItems = 0;
        $totalCost = 0.0;

        foreach ($localEstimates as $localEstimate) {
            foreach ($localEstimate['sections'] ?? [] as $section) {
                foreach ($section['work_items'] ?? [] as $workItem) {
                    if (! is_array($workItem) || in_array((string) ($workItem['item_type'] ?? 'priced_work'), ['operation', 'resource_note', 'review_note'], true)) {
                        continue;
                    }

                    $status = (string) ($workItem['pricing_status'] ?? 'missing');
                    $blocker = (string) ($workItem['pricing_blocker'] ?? 'none');
                    $statuses[$status] = ($statuses[$status] ?? 0) + 1;
                    $blockers[$blocker] = ($blockers[$blocker] ?? 0) + 1;
                    $pricedItems++;
                    $totalCost += (float) ($workItem['total_cost'] ?? 0);
                }
            }
        }

        ksort($statuses, SORT_STRING);
        ksort($blockers, SORT_STRING);

        return [
            'priced_items_count' => $pricedItems,
            'pricing_status_counts' => $statuses,
            'pricing_blocker_counts' => $blockers,
            'total_cost' => round($totalCost, 2),
        ];
    }

    private function canLog(): bool
    {
        $application = Log::getFacadeApplication();

        return $application !== null && $application->bound('log') && $application->bound('config');
    }
}
