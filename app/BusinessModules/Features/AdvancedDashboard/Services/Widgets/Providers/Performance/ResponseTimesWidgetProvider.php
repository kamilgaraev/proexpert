<?php

namespace App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\Performance;

use App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\AbstractWidgetProvider;
use App\BusinessModules\Features\AdvancedDashboard\Enums\WidgetType;
use App\BusinessModules\Features\AdvancedDashboard\DTOs\WidgetDataRequest;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class ResponseTimesWidgetProvider extends AbstractWidgetProvider
{
    public function getType(): WidgetType
    {
        return WidgetType::RESPONSE_TIMES;
    }

    protected function fetchData(WidgetDataRequest $request): array
    {
        // Для SaaS измеряем производительность запросов конкретной организации
        return $this->measureOrganizationQueryPerformance($request->organizationId);
    }

    protected function measureOrganizationQueryPerformance(int $organizationId): array
    {
        $measurements = [];

        // Тест 1: Загрузка списка проектов организации
        $start = microtime(true);
        DB::table('projects')
            ->where('organization_id', $organizationId)
            ->limit(50)
            ->get();
        $measurements['projects_load'] = round((microtime(true) - $start) * 1000, 2);

        // Тест 2: Загрузка выполненных работ с JOIN
        $start = microtime(true);
        DB::table('completed_works')
            ->join('projects', 'completed_works.project_id', '=', 'projects.id')
            ->where('projects.organization_id', $organizationId)
            ->limit(100)
            ->get();
        $measurements['works_with_projects'] = round((microtime(true) - $start) * 1000, 2);

        // Тест 3: Агрегация финансовых данных
        $start = microtime(true);
        DB::table('contracts')
            ->where('organization_id', $organizationId)
            ->select(
                DB::raw('COUNT(*) as total'),
                DB::raw('SUM(total_amount) as sum'),
                DB::raw('AVG(total_amount) as avg')
            )
            ->first();
        $measurements['financial_aggregation'] = round((microtime(true) - $start) * 1000, 2);

        // Тест 4: Сложный запрос с группировкой
        $start = microtime(true);
        DB::table('completed_works')
            ->join('projects', 'completed_works.project_id', '=', 'projects.id')
            ->where('projects.organization_id', $organizationId)
            ->select('completed_works.user_id', DB::raw('COUNT(*) as works_count'))
            ->groupBy('completed_works.user_id')
            ->get();
        $measurements['grouped_analytics'] = round((microtime(true) - $start) * 1000, 2);

        // Анализ производительности
        $avgTime = array_sum($measurements) / count($measurements);
        $maxTime = max($measurements);
        
        $status = 'excellent';
        if ($maxTime > 1000) {
            $status = 'critical';
        } elseif ($maxTime > 500) {
            $status = 'slow';
        } elseif ($maxTime > 200) {
            $status = 'moderate';
        } elseif ($maxTime > 100) {
            $status = 'good';
        }

        return [
            'query_performance' => [
                'organization_id' => $organizationId,
                'measurements_ms' => $measurements,
                'average_ms' => round($avgTime, 2),
                'max_ms' => $maxTime,
                'status' => $status,
                'timestamp' => Carbon::now()->toIso8601String(),
            ],
            'analysis' => $this->analyzePerformance($measurements),
        ];
    }

    protected function analyzePerformance(array $measurements): array
    {
        $analysis = [
            'overall_status' => 'good',
            'slowest_query' => array_keys($measurements, max($measurements))[0] ?? null,
            'recommendations' => [],
        ];

        if ($measurements['projects_load'] > 100) {
            $analysis['recommendations'][] = "Загрузка проектов медленная. Проверьте индекс на organization_id.";
        }

        if ($measurements['works_with_projects'] > 300) {
            $analysis['recommendations'][] = "JOIN запросы медленные. Рассмотрите добавление составных индексов.";
        }

        if ($measurements['financial_aggregation'] > 500) {
            $analysis['recommendations'][] = "Финансовая аналитика медленная. Используйте кеширование.";
        }

        if ($measurements['grouped_analytics'] > 500) {
            $analysis['recommendations'][] = "Групповая аналитика медленная. Рассмотрите материализованные представления.";
        }

        if (empty($analysis['recommendations'])) {
            $analysis['recommendations'][] = "Производительность запросов в норме.";
        }

        return $analysis;
    }
}
