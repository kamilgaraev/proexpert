<?php

namespace App\BusinessModules\Features\AIAssistant\Actions\Analysis;

use App\Models\Project;
use App\Models\CompletedWork;
use Illuminate\Support\Facades\DB;

class CollectWorkersDataAction
{
    /**
     * Собрать данные по рабочим и бригадам проекта
     *
     * @param int $projectId
     * @param int $organizationId
     * @return array
     */
    public function execute(int $projectId, int $organizationId): array
    {
        $project = Project::where('id', $projectId)
            ->where('organization_id', $organizationId)
            ->firstOrFail();

        // Выполненные работы с группировкой по бригадам/исполнителям
        $completedWorks = CompletedWork::where('project_id', $projectId)
            ->with(['workType'])
            ->get();

        // Анализ по бригадам (используем contractor_id как идентификатор бригады)
        $brigadeAnalysis = $this->analyzeBrigades($completedWorks);
        
        // Производительность
        $productivity = $this->calculateProductivity($completedWorks, $project);
        
        // Загруженность (на основе выполненных работ во времени)
        $workload = $this->analyzeWorkload($completedWorks);

        return [
            'project_name' => $project->name,
            'total_completed_works' => $completedWorks->count(),
            'total_works_value' => $completedWorks->sum('total_amount'),
            'brigade_analysis' => $brigadeAnalysis,
            'productivity' => $productivity,
            'workload' => $workload,
            'workers_health' => $this->assessWorkersHealth($brigadeAnalysis, $productivity),
        ];
    }

    /**
     * Анализ работы бригад
     */
    private function analyzeBrigades($completedWorks): array
    {
        // Группируем по подрядчикам (условно - бригады)
        $brigades = $completedWorks->groupBy('contractor_id');
        
        $brigadesArray = [];

        foreach ($brigades as $contractorId => $works) {
            $totalAmount = $works->sum('total_amount');
            $worksCount = $works->count();
            $avgWorkValue = $worksCount > 0 ? $totalAmount / $worksCount : 0;

            // Определяем типы работ
            $workTypes = $works->pluck('workType.name')->unique()->filter()->values();

            $brigadesArray[] = [
                'contractor_id' => $contractorId,
                'contractor_name' => $works->first()->contractor_name ?? 'Бригада #' . ($contractorId ?? 'N/A'),
                'works_count' => $worksCount,
                'total_amount' => $totalAmount,
                'avg_work_value' => $avgWorkValue,
                'work_types' => $workTypes->toArray(),
                'specialization' => $workTypes->first() ?? 'Общестроительные работы',
            ];
        }

        // Сортируем по объему работ
        usort($brigadesArray, fn($a, $b) => $b['total_amount'] <=> $a['total_amount']);

        return [
            'brigades' => $brigadesArray,
            'total_brigades' => count($brigadesArray),
        ];
    }

    /**
     * Рассчитать производительность
     */
    private function calculateProductivity($completedWorks, Project $project): array
    {
        if ($completedWorks->isEmpty()) {
            return [
                'works_per_day' => 0,
                'value_per_day' => 0,
                'productivity_trend' => 'no_data',
            ];
        }

        $now = now();
        $projectStartDate = $project->start_date ?? $completedWorks->min('date');
        
        if (!$projectStartDate) {
            $daysElapsed = 1;
        } else {
            $daysElapsed = max(1, $projectStartDate->diffInDays($now));
        }

        $totalWorks = $completedWorks->count();
        $totalValue = $completedWorks->sum('total_amount');

        $worksPerDay = $totalWorks / $daysElapsed;
        $valuePerDay = $totalValue / $daysElapsed;

        // Анализ тренда (сравниваем первую и вторую половину периода)
        $midpoint = $projectStartDate?->addDays($daysElapsed / 2) ?? $now->subDays($daysElapsed / 2);
        
        $firstHalf = $completedWorks->filter(fn($w) => $w->date && $w->date->lt($midpoint));
        $secondHalf = $completedWorks->filter(fn($w) => $w->date && $w->date->gte($midpoint));

        $firstHalfAvg = $firstHalf->count() > 0 ? $firstHalf->sum('total_amount') / max(1, $daysElapsed / 2) : 0;
        $secondHalfAvg = $secondHalf->count() > 0 ? $secondHalf->sum('total_amount') / max(1, $daysElapsed / 2) : 0;

        $trend = 'stable';
        if ($secondHalfAvg > $firstHalfAvg * 1.2) {
            $trend = 'improving';
        } elseif ($secondHalfAvg < $firstHalfAvg * 0.8) {
            $trend = 'declining';
        }

        return [
            'works_per_day' => round($worksPerDay, 2),
            'value_per_day' => round($valuePerDay, 2),
            'productivity_trend' => $trend,
            'first_half_avg' => round($firstHalfAvg, 2),
            'second_half_avg' => round($secondHalfAvg, 2),
        ];
    }

    /**
     * Анализ загруженности
     */
    private function analyzeWorkload($completedWorks): array
    {
        // Группируем по месяцам
        $worksByMonth = $completedWorks->groupBy(function ($work) {
            return $work->date ? $work->date->format('Y-m') : 'unknown';
        });

        $monthlyData = [];
        foreach ($worksByMonth as $month => $works) {
            if ($month === 'unknown') continue;
            
            $monthlyData[] = [
                'month' => $month,
                'works_count' => $works->count(),
                'total_value' => $works->sum('total_amount'),
            ];
        }

        // Сортируем по месяцам
        usort($monthlyData, fn($a, $b) => strcmp($a['month'], $b['month']));

        // Определяем пиковую и минимальную загрузку
        $values = array_column($monthlyData, 'total_value');
        $maxLoad = !empty($values) ? max($values) : 0;
        $minLoad = !empty($values) ? min($values) : 0;
        $avgLoad = !empty($values) ? array_sum($values) / count($values) : 0;

        return [
            'monthly_data' => $monthlyData,
            'max_monthly_load' => $maxLoad,
            'min_monthly_load' => $minLoad,
            'avg_monthly_load' => round($avgLoad, 2),
            'workload_variability' => $avgLoad > 0 ? round(($maxLoad - $minLoad) / $avgLoad * 100, 2) : 0,
        ];
    }

    /**
     * Оценить состояние рабочей силы
     */
    private function assessWorkersHealth(array $brigadeAnalysis, array $productivity): string
    {
        $brigadesCount = $brigadeAnalysis['total_brigades'];
        $trend = $productivity['productivity_trend'];

        // Мало бригад или падающая производительность - проблема
        if ($brigadesCount < 2 || $trend === 'declining') {
            return 'warning';
        }

        if ($trend === 'improving' && $brigadesCount >= 3) {
            return 'good';
        }

        return 'good';
    }
}

