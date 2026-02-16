<?php

namespace App\Services\Report;

use Illuminate\Database\Eloquent\Builder;

class ReportQueryOptimizer
{
    /**
     * Предотвращает full table scan добавляя подсказки индексов
     */
    public function optimize(Builder $query, array $config): Builder
    {
        $primarySource = $config['data_sources']['primary'] ?? null;
        
        if ($primarySource === 'material_movements') {
            // Оптимизация для больших таблиц движений
            $query->from(\DB::raw('warehouse_movements USE INDEX (warehouse_movements_organization_id_index)'));
        }

        if ($primarySource === 'time_entries') {
            $query->from(\DB::raw('time_entries USE INDEX (time_entries_organization_id_index)'));
        }

        return $query;
    }
}
