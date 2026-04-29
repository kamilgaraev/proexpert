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
            ->with('estimateItem.contractLinks', 'estimateItem.estimate', 'journalEntry.journal', 'workType')
            ->where(function ($query) use ($contractId): void {
                $query
                    ->where('contract_id', $contractId)
                    ->orWhere(function ($fallbackQuery) use ($contractId): void {
                        $fallbackQuery
                            ->whereNull('contract_id')
                            ->whereHas('estimateItem.contractLinks', function ($contractLinkQuery) use ($contractId): void {
                                $contractLinkQuery->where('contract_id', $contractId);
                            });
                    });
            })
            ->where('status', 'confirmed')
            ->where(function ($query): void {
                $query
                    ->where('work_origin_type', CompletedWork::ORIGIN_JOURNAL)
                    ->orWhereNotNull('journal_entry_id');
            })
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
            'estimate_item_name' => $work->estimateItem?->name,
            'estimate_item_position_number' => $work->estimateItem?->position_number,
            'journal_entry_id' => $work->journal_entry_id,
            'journal_entry_number' => $work->journalEntry?->entry_number,
            'journal_number' => $work->journalEntry?->journal?->journal_number,
            'work_origin_type' => $work->work_origin_type,
            'planning_status' => $work->planning_status,
            'work_title' => $work->workType?->name
                ?? $work->estimateItem?->name
                ?? $work->journalEntry?->work_description
                ?? $work->notes,
            'work_type_name' => $work->workType?->name,
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
