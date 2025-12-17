<?php

namespace App\BusinessModules\Features\BudgetEstimates\Listeners;

use App\BusinessModules\Features\BudgetEstimates\Events\JournalWorkVolumesRecorded;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

class UpdateEstimateActualVolumes implements ShouldQueue
{
    /**
     * Handle the event.
     */
    public function handle(JournalWorkVolumesRecorded $event): void
    {
        $entry = $event->entry;

        // Получить уникальные позиции сметы из объемов работ
        $estimateItemIds = $entry->workVolumes()
            ->whereNotNull('estimate_item_id')
            ->pluck('estimate_item_id')
            ->unique();

        if ($estimateItemIds->isEmpty()) {
            return;
        }

        Log::info('construction_journal.estimate_volumes_updated', [
            'entry_id' => $entry->id,
            'estimate_items' => $estimateItemIds->toArray(),
        ]);

        // Здесь можно добавить логику пересчета и кеширования фактических объемов
        // или отправки уведомлений о достижении определенных процентов выполнения
    }
}

