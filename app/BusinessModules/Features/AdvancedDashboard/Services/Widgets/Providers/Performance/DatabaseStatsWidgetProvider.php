<?php

namespace App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\Performance;

use App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\AbstractWidgetProvider;
use App\BusinessModules\Features\AdvancedDashboard\Enums\WidgetType;
use App\BusinessModules\Features\AdvancedDashboard\DTOs\WidgetDataRequest;
use Illuminate\Support\Facades\DB;

class DatabaseStatsWidgetProvider extends AbstractWidgetProvider
{
    public function getType(): WidgetType
    {
        return WidgetType::DATABASE_STATS;
    }

    protected function fetchData(WidgetDataRequest $request): array
    {
        $tables = [];
        $driver = config('database.default');

        try {
            if ($driver === 'mysql') {
                $dbName = config("database.connections.mysql.database");
                $results = DB::select("
                    SELECT 
                        table_name,
                        table_rows,
                        ROUND(((data_length + index_length) / 1024 / 1024), 2) AS size_mb
                    FROM information_schema.TABLES
                    WHERE table_schema = ?
                    ORDER BY (data_length + index_length) DESC
                    LIMIT 10
                ", [$dbName]);

                $tables = collect($results)->map(fn($r) => [
                    'table' => $r->table_name,
                    'rows' => $r->table_rows,
                    'size_mb' => $r->size_mb,
                ])->toArray();
            }
        } catch (\Exception $e) {
            $tables = [];
        }

        return ['database_stats' => $tables];
    }
}

