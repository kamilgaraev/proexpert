<?php

namespace App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\Performance;

use App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\AbstractWidgetProvider;
use App\BusinessModules\Features\AdvancedDashboard\Enums\WidgetType;
use App\BusinessModules\Features\AdvancedDashboard\DTOs\WidgetDataRequest;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class SystemMetricsWidgetProvider extends AbstractWidgetProvider
{
    public function getType(): WidgetType
    {
        return WidgetType::SYSTEM_METRICS;
    }

    protected function fetchData(WidgetDataRequest $request): array
    {
        $dbConnection = config('database.default');
        $driver = config("database.connections.{$dbConnection}.driver");

        $dbSize = 'N/A';
        
        if ($driver === 'pgsql') {
            try {
                $result = DB::select("SELECT pg_size_pretty(pg_database_size(current_database())) as size");
                $dbSize = $result[0]->size ?? 'N/A';
            } catch (\Exception $e) {
                $dbSize = 'Error';
            }
        } elseif ($driver === 'mysql') {
            try {
                $dbName = config("database.connections.{$dbConnection}.database");
                $result = DB::select("SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) AS size_mb 
                    FROM information_schema.TABLES 
                    WHERE table_schema = ?", [$dbName]);
                $dbSize = ($result[0]->size_mb ?? 0) . ' MB';
            } catch (\Exception $e) {
                $dbSize = 'Error';
            }
        }

        $tablesCount = count(DB::select('SHOW TABLES'));

        return [
            'database_size' => $dbSize,
            'tables_count' => $tablesCount,
            'timestamp' => Carbon::now()->toIso8601String(),
            'php_version' => PHP_VERSION,
            'laravel_version' => app()->version(),
        ];
    }
}

