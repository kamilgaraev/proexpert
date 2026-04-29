<?php

declare(strict_types=1);

namespace App\Services\Acting;

use App\Models\CompletedWork;
use App\Models\PerformanceActLine;
use Illuminate\Support\Collection;

use function trans_message;

class ActingAvailabilityService
{
    public function __construct(
        private readonly ActingPriceService $priceService
    ) {
    }

    public function getAvailableWorks(int $contractId, string $periodStart, string $periodEnd): array
    {
        $works = $this->baseWorksQuery($contractId, $periodStart, $periodEnd)
            ->orderBy('completion_date')
            ->orderBy('id')
            ->get();
        $quantityUsage = $this->resolveQuantityUsage($works->pluck('id')->map(fn ($id): int => (int) $id)->all());

        return $works
            ->map(fn (CompletedWork $work): array => $this->mapWork($work, $quantityUsage[$work->id] ?? []))
            ->filter(fn (array $work): bool => $work['available_quantity'] > 0 && $work['blockers'] === [])
            ->values()
            ->all();
    }

    public function getBlockedWorks(int $contractId, string $periodStart, string $periodEnd): array
    {
        $works = $this->baseWorksQuery($contractId, $periodStart, $periodEnd)
            ->orderBy('completion_date')
            ->orderBy('id')
            ->get();
        $quantityUsage = $this->resolveQuantityUsage($works->pluck('id')->map(fn ($id): int => (int) $id)->all());

        return $works
            ->map(fn (CompletedWork $work): array => $this->mapWork($work, $quantityUsage[$work->id] ?? []))
            ->filter(fn (array $work): bool => $work['available_quantity'] <= 0 || $work['blockers'] !== [])
            ->values()
            ->all();
    }

    private function baseWorksQuery(int $contractId, string $periodStart, string $periodEnd)
    {
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
            ->whereBetween('completion_date', [$periodStart, $periodEnd]);
    }

    private function resolveQuantityUsage(array $workIds): array
    {
        if ($workIds === []) {
            return [];
        }

        /** @var Collection<int, PerformanceActLine> $lines */
        $lines = PerformanceActLine::query()
            ->with('performanceAct')
            ->whereIn('completed_work_id', $workIds)
            ->get();

        $usage = [];

        foreach ($lines as $line) {
            $act = $line->performanceAct;

            if (ActingQuantityStatus::isReleased($act)) {
                continue;
            }

            $workId = (int) $line->completed_work_id;
            $usage[$workId] ??= [
                'reserved_quantity' => 0.0,
                'approved_acted_quantity' => 0.0,
            ];

            if (ActingQuantityStatus::isApproved($act)) {
                $usage[$workId]['approved_acted_quantity'] += (float) $line->quantity;
                continue;
            }

            $usage[$workId]['reserved_quantity'] += (float) $line->quantity;
        }

        foreach ($usage as $workId => $values) {
            $usage[$workId] = [
                'reserved_quantity' => round((float) $values['reserved_quantity'], 4),
                'approved_acted_quantity' => round((float) $values['approved_acted_quantity'], 4),
            ];
        }

        return $usage;
    }

    private function mapWork(CompletedWork $work, array $quantityUsage): array
    {
        $effectiveQuantity = (float) ($work->completed_quantity ?? $work->quantity);
        $reservedQuantity = (float) ($quantityUsage['reserved_quantity'] ?? 0);
        $approvedActedQuantity = (float) ($quantityUsage['approved_acted_quantity'] ?? 0);
        $actedQuantity = $reservedQuantity + $approvedActedQuantity;
        $availableQuantity = round(max(0, $effectiveQuantity - $actedQuantity), 4);
        $unitPrice = $this->priceService->resolveCompletedWorkUnitPrice($work, $effectiveQuantity);
        $blockers = $this->buildBlockers($work, $availableQuantity);

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
            'reserved_quantity' => round($reservedQuantity, 4),
            'approved_acted_quantity' => round($approvedActedQuantity, 4),
            'available_quantity' => $availableQuantity,
            'unit_price' => $unitPrice,
            'available_amount' => round($availableQuantity * $unitPrice, 2),
            'completion_date' => optional($work->completion_date)->toDateString(),
            'status' => $work->status,
            'blockers' => $blockers,
        ];
    }

    private function buildBlockers(CompletedWork $work, float $availableQuantity): array
    {
        $blockers = [];

        if ($work->planning_status === CompletedWork::PLANNING_REQUIRES_SCHEDULE) {
            $blockers[] = [
                'code' => 'schedule_missing',
                'message' => trans_message('workflow.blockers.schedule_missing'),
                'target' => 'schedule_missing',
            ];
        }

        if ($availableQuantity <= 0) {
            $blockers[] = [
                'code' => 'already_acted_or_reserved',
                'message' => trans_message('workflow.blockers.already_acted_or_reserved'),
                'target' => 'over_coverage',
            ];
        }

        return $blockers;
    }
}
