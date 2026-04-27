<?php

declare(strict_types=1);

namespace App\Services\Acting;

use App\Models\CompletedWork;
use App\Models\PerformanceActLine;

class ActingAvailabilityService
{
    public function __construct(
        private readonly ActingPriceService $priceService
    ) {
    }

    public function getAvailableWorks(int $contractId, string $periodStart, string $periodEnd): array
    {
        $actedQuantities = PerformanceActLine::query()
            ->whereNotNull('completed_work_id')
            ->selectRaw('completed_work_id, SUM(quantity) as acted_quantity')
            ->groupBy('completed_work_id')
            ->pluck('acted_quantity', 'completed_work_id');

        return CompletedWork::query()
            ->with('estimateItem.contractLinks', 'estimateItem.estimate')
            ->where('contract_id', $contractId)
            ->where('status', 'confirmed')
            ->where('work_origin_type', CompletedWork::ORIGIN_JOURNAL)
            ->whereNotNull('journal_entry_id')
            ->whereBetween('completion_date', [$periodStart, $periodEnd])
            ->orderBy('completion_date')
            ->orderBy('id')
            ->get()
            ->map(fn (CompletedWork $work): array => $this->mapWork($work, (float) ($actedQuantities[$work->id] ?? 0)))
            ->filter(fn (array $work): bool => $work['available_quantity'] > 0)
            ->values()
            ->all();
    }

    private function mapWork(CompletedWork $work, float $actedQuantity): array
    {
        $effectiveQuantity = (float) ($work->completed_quantity ?? $work->quantity);
        $availableQuantity = round(max(0, $effectiveQuantity - $actedQuantity), 4);
        $unitPrice = $this->priceService->resolveCompletedWorkUnitPrice($work, $effectiveQuantity);

        return [
            'id' => $work->id,
            'contract_id' => $work->contract_id,
            'project_id' => $work->project_id,
            'estimate_item_id' => $work->estimate_item_id,
            'journal_entry_id' => $work->journal_entry_id,
            'work_origin_type' => $work->work_origin_type,
            'planning_status' => $work->planning_status,
            'quantity' => $effectiveQuantity,
            'acted_quantity' => round($actedQuantity, 4),
            'available_quantity' => $availableQuantity,
            'unit_price' => $unitPrice,
            'available_amount' => round($availableQuantity * $unitPrice, 2),
            'completion_date' => optional($work->completion_date)->toDateString(),
            'status' => $work->status,
        ];
    }

}
