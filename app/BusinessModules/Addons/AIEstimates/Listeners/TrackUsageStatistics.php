<?php

namespace App\BusinessModules\Addons\AIEstimates\Listeners;

use App\BusinessModules\Addons\AIEstimates\Events\EstimateGenerationCompleted;
use Illuminate\Support\Facades\Log;

class TrackUsageStatistics
{
    public function handle(EstimateGenerationCompleted $event): void
    {
        $generation = $event->generation;

        Log::info('[TrackUsageStatistics] Tracking generation statistics', [
            'generation_id' => $generation->id,
            'organization_id' => $generation->organization_id,
            'tokens_used' => $generation->tokens_used,
            'cost' => $generation->cost,
            'processing_time_ms' => $generation->processing_time_ms,
            'confidence_score' => $generation->confidence_score,
        ]);

        // TODO: Можно добавить запись в отдельную таблицу статистики
        // или интеграцию с системой аналитики
    }
}
