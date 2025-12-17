<?php

namespace App\BusinessModules\Features\BudgetEstimates\Services\Integration;

use App\Models\ConstructionJournalEntry;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\JournalWorkVolume;
use App\Enums\ConstructionJournal\JournalEntryStatusEnum;
use Illuminate\Support\Collection;

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
            ->with(['workType', 'measurementUnit', 'journalWorkVolumes'])
            ->get();

        $comparison = [];

        foreach ($items as $item) {
            $plannedVolume = (float) $item->quantity_total;
            $actualVolume = $item->getActualVolume();
            $completionPercent = $item->getCompletionPercentage();

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
        $items = $estimate->items()->get();
        
        $totalItems = $items->count();
        $completedItems = 0;
        $totalPlannedVolume = 0;
        $totalActualVolume = 0;

        foreach ($items as $item) {
            $plannedVolume = (float) $item->quantity_total;
            $actualVolume = $item->getActualVolume();
            
            $totalPlannedVolume += $plannedVolume;
            $totalActualVolume += $actualVolume;
            
            if ($item->getCompletionPercentage() >= 100) {
                $completedItems++;
            }
        }

        $overallCompletion = $totalPlannedVolume > 0 
            ? ($totalActualVolume / $totalPlannedVolume) * 100 
            : 0;

        return [
            'total_items' => $totalItems,
            'completed_items' => $completedItems,
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

