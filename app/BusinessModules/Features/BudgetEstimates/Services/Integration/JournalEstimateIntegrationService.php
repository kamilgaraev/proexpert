<?php

namespace App\BusinessModules\Features\BudgetEstimates\Services\Integration;

use App\Enums\ConstructionJournal\JournalEntryStatusEnum;
use App\Models\CompletedWork;
use App\Models\ConstructionJournalEntry;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\JournalWorkVolume;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class JournalEstimateIntegrationService
{
    /**
     * Зафиксировать объемы работ в записи журнала
     */
    public function trackWorkVolumes(ConstructionJournalEntry $entry, array $volumes): Collection
    {
        $createdVolumes = collect();

        foreach ($volumes as $volumeData) {
            $volume = $entry->workVolumes()->create([
                'estimate_item_id' => $volumeData['estimate_item_id'] ?? null,
                'work_type_id' => $volumeData['work_type_id'] ?? null,
                'quantity' => $volumeData['quantity'],
                'measurement_unit_id' => $volumeData['measurement_unit_id'] ?? null,
                'notes' => $volumeData['notes'] ?? null,
            ]);

            $createdVolumes->push($volume);
        }

        // Отправить событие о фиксации объемов
        if ($entry->status === JournalEntryStatusEnum::APPROVED) {
            event(new \App\BusinessModules\Features\BudgetEstimates\Events\JournalWorkVolumesRecorded($entry));
        }

        return $createdVolumes;
    }

    /**
     * Получить сравнение фактических и плановых объемов по смете
     */
    public function getActualVsPlannedVolumes(Estimate $estimate): array
    {
        $items = $estimate->items()
            ->with(['workType', 'measurementUnit'])
            ->get();
        $itemIds = $items->pluck('id');

        if ($itemIds->isEmpty()) {
            return [];
        }

        $completedWorkItemIds = $this->getCompletedWorkItemIds($itemIds);
        $completedWorkVolumes = $this->getCompletedWorkVolumes($itemIds);
        $journalVolumes = $this->getJournalVolumes($itemIds);

        $comparison = [];

        foreach ($items as $item) {
            $plannedVolume = (float) $item->quantity_total;
            $actualVolume = $this->resolveActualVolume(
                (int) $item->id,
                $completedWorkItemIds,
                $completedWorkVolumes,
                $journalVolumes
            );
            $completionPercent = $this->calculateCompletionPercent(
                $actualVolume,
                $item->resolvePlannedQuantity()
            );

            $comparison[] = [
                'item_id' => $item->id,
                'item_name' => $item->name,
                'work_type' => $item->workType?->name,
                'measurement_unit' => $item->measurementUnit?->short_name,
                'planned_volume' => $plannedVolume,
                'actual_volume' => $actualVolume,
                'remaining_volume' => max(0, $plannedVolume - $actualVolume),
                'completion_percent' => round($completionPercent, 2),
                'is_completed' => $completionPercent >= 100,
                'is_over_planned' => $actualVolume > $plannedVolume,
            ];
        }

        return $comparison;
    }

    private function getCompletedWorkItemIds(Collection $itemIds): Collection
    {
        return CompletedWork::query()
            ->whereIn('estimate_item_id', $itemIds)
            ->select('estimate_item_id')
            ->distinct()
            ->pluck('estimate_item_id')
            ->mapWithKeys(static fn ($itemId): array => [(int) $itemId => true]);
    }

    private function getCompletedWorkVolumes(Collection $itemIds): Collection
    {
        return CompletedWork::query()
            ->whereIn('estimate_item_id', $itemIds)
            ->effectiveForSchedule()
            ->select('estimate_item_id')
            ->selectRaw('COALESCE(SUM(completed_quantity), 0) as actual_volume')
            ->groupBy('estimate_item_id')
            ->pluck('actual_volume', 'estimate_item_id')
            ->mapWithKeys(static fn ($actualVolume, $itemId): array => [(int) $itemId => (float) $actualVolume]);
    }

    private function getJournalVolumes(Collection $itemIds): Collection
    {
        return JournalWorkVolume::query()
            ->whereIn('estimate_item_id', $itemIds)
            ->whereHas('journalEntry', static function ($query): void {
                $query->where('status', JournalEntryStatusEnum::APPROVED->value);
            })
            ->select('estimate_item_id')
            ->selectRaw('COALESCE(SUM(quantity), 0) as actual_volume')
            ->groupBy('estimate_item_id')
            ->pluck('actual_volume', 'estimate_item_id')
            ->mapWithKeys(static fn ($actualVolume, $itemId): array => [(int) $itemId => (float) $actualVolume]);
    }

    private function resolveActualVolume(
        int $itemId,
        Collection $completedWorkItemIds,
        Collection $completedWorkVolumes,
        Collection $journalVolumes
    ): float {
        if ($completedWorkItemIds->has($itemId)) {
            return (float) $completedWorkVolumes->get($itemId, 0);
        }

        return (float) $journalVolumes->get($itemId, 0);
    }

    private function calculateCompletionPercent(float $actualVolume, float $plannedQuantity): float
    {
        if ($plannedQuantity <= 0) {
            return 0.0;
        }

        return min(100, ($actualVolume / $plannedQuantity) * 100);
    }

    /**
     * Рассчитать процент выполнения позиции сметы
     */
    public function calculateCompletionPercentage(EstimateItem $item): float
    {
        return $item->getCompletionPercentage();
    }

    /**
     * Получить статистику выполнения сметы
     */
    public function getEstimateCompletionStats(Estimate $estimate): array
    {
        $actualVolumesQuery = DB::table('journal_work_volumes as jwv')
            ->join('construction_journal_entries as cje', 'cje.id', '=', 'jwv.journal_entry_id')
            ->where('cje.estimate_id', $estimate->id)
            ->where('cje.status', JournalEntryStatusEnum::APPROVED->value)
            ->select('jwv.estimate_item_id', DB::raw('SUM(jwv.quantity) as sum_actual'))
            ->groupBy('jwv.estimate_item_id');

        $stats = DB::table('estimate_items as ei')
            ->leftJoinSub($actualVolumesQuery, 'actual_vols', function ($join) {
                $join->on('ei.id', '=', 'actual_vols.estimate_item_id');
            })
            ->where('ei.estimate_id', $estimate->id)
            ->whereNull('ei.deleted_at')
            ->selectRaw('
                COUNT(ei.id) as total_items,
                COALESCE(SUM(ei.quantity_total), 0) as total_planned_volume,
                COALESCE(SUM(actual_vols.sum_actual), 0) as total_actual_volume,
                SUM(CASE WHEN ei.quantity_total > 0 AND COALESCE(actual_vols.sum_actual, 0) >= ei.quantity_total THEN 1 ELSE 0 END) as completed_items,
                SUM(CASE WHEN ei.quantity_total > 0 AND COALESCE(actual_vols.sum_actual, 0) > 0 AND COALESCE(actual_vols.sum_actual, 0) < ei.quantity_total THEN 1 ELSE 0 END) as in_progress_items,
                SUM(CASE WHEN COALESCE(actual_vols.sum_actual, 0) <= 0 THEN 1 ELSE 0 END) as not_started_items
            ')
            ->first();

        $totalItems = (int) ($stats->total_items ?? 0);
        $completedItems = (int) ($stats->completed_items ?? 0);
        $inProgressItems = (int) ($stats->in_progress_items ?? 0);
        $notStartedItems = (int) ($stats->not_started_items ?? 0);
        $totalPlannedVolume = (float) ($stats->total_planned_volume ?? 0);
        $totalActualVolume = (float) ($stats->total_actual_volume ?? 0);

        $overallCompletion = $totalPlannedVolume > 0
            ? ($totalActualVolume / $totalPlannedVolume) * 100
            : 0;

        return [
            'total_items' => $totalItems,
            'completed_items' => $completedItems,
            'in_progress_items' => $inProgressItems,
            'not_started_items' => $notStartedItems,
            'items_completion_percent' => $totalItems > 0
                ? round(($completedItems / $totalItems) * 100, 2)
                : 0,
            'total_planned_volume' => round($totalPlannedVolume, 3),
            'total_actual_volume' => round($totalActualVolume, 3),
            'overall_completion_percent' => round($overallCompletion, 2),
            'estimated_amount' => (float) $estimate->total_amount,
            'completed_amount' => round(((float) $estimate->total_amount * $overallCompletion) / 100, 2),
        ];
    }

    /**
     * Получить позиции сметы с низким прогрессом
     */
    public function getItemsWithLowProgress(Estimate $estimate, float $threshold = 50.0): Collection
    {
        return $estimate->items()
            ->with(['workType', 'measurementUnit'])
            ->get()
            ->filter(function ($item) use ($threshold) {
                return $item->getCompletionPercentage() < $threshold;
            })
            ->map(function ($item) {
                return [
                    'item_id' => $item->id,
                    'item_name' => $item->name,
                    'work_type' => $item->workType?->name,
                    'planned_volume' => (float) $item->quantity_total,
                    'actual_volume' => $item->getActualVolume(),
                    'completion_percent' => round($item->getCompletionPercentage(), 2),
                ];
            });
    }
}
