<?php

namespace App\BusinessModules\Features\AIAssistant\Actions\Analysis;

use App\Models\Project;

class CalculateKPIAction
{
    /**
     * Рассчитать KPI проекта
     *
     * @param int $projectId
     * @param int $organizationId
     * @param array $collectedData Данные из других коллекторов
     * @return array
     */
    public function execute(int $projectId, int $organizationId, array $collectedData = []): array
    {
        $project = Project::where('id', $projectId)
            ->where('organization_id', $organizationId)
            ->firstOrFail();

        // Cost Performance Index (CPI) - индекс выполнения бюджета
        $cpi = $this->calculateCPI($collectedData['budget'] ?? []);

        // Schedule Performance Index (SPI) - индекс выполнения графика
        $spi = $this->calculateSPI($collectedData['schedule'] ?? []);

        // Эффективность использования материалов
        $materialEfficiency = $this->calculateMaterialEfficiency($collectedData['materials'] ?? []);

        // Производительность труда
        $laborProductivity = $this->calculateLaborProductivity($collectedData['workers'] ?? []);

        // Качество управления контрактами
        $contractManagement = $this->calculateContractManagement($collectedData['contracts'] ?? []);

        // Общий индекс здоровья проекта
        $projectHealthIndex = $this->calculateProjectHealthIndex($cpi, $spi, $materialEfficiency, $contractManagement);

        return [
            'project_name' => $project->name,
            'cpi' => $cpi,
            'spi' => $spi,
            'material_efficiency' => $materialEfficiency,
            'labor_productivity' => $laborProductivity,
            'contract_management' => $contractManagement,
            'project_health_index' => $projectHealthIndex,
            'performance_status' => $this->getPerformanceStatus($projectHealthIndex),
        ];
    }

    /**
     * Cost Performance Index (CPI)
     * CPI = Earned Value / Actual Cost
     * > 1.0 = в рамках бюджета, < 1.0 = перерасход
     */
    private function calculateCPI(array $budgetData): array
    {
        if (empty($budgetData)) {
            return ['value' => 1.0, 'status' => 'unknown', 'interpretation' => 'Нет данных'];
        }

        $earnedValue = $budgetData['completed_works_amount'] ?? 0;
        $actualCost = $budgetData['spent_amount'] ?? 0;

        if ($actualCost == 0) {
            return ['value' => 1.0, 'status' => 'unknown', 'interpretation' => 'Работы еще не начались'];
        }

        $cpi = $earnedValue / $actualCost;

        $status = 'good';
        $interpretation = 'Расходы в норме';

        if ($cpi < 0.9) {
            $status = 'critical';
            $interpretation = 'Значительный перерасход бюджета';
        } elseif ($cpi < 0.95) {
            $status = 'warning';
            $interpretation = 'Небольшой перерасход бюджета';
        } elseif ($cpi > 1.1) {
            $interpretation = 'Экономия бюджета';
        }

        return [
            'value' => round($cpi, 3),
            'earned_value' => $earnedValue,
            'actual_cost' => $actualCost,
            'status' => $status,
            'interpretation' => $interpretation,
        ];
    }

    /**
     * Schedule Performance Index (SPI)
     * SPI = Earned Value / Planned Value
     * > 1.0 = опережение графика, < 1.0 = отставание
     */
    private function calculateSPI(array $scheduleData): array
    {
        if (empty($scheduleData)) {
            return ['value' => 1.0, 'status' => 'unknown', 'interpretation' => 'Нет данных'];
        }

        $completionPercentage = $scheduleData['tasks_summary']['completion_percentage'] ?? 0;
        $timePercentage = $scheduleData['project_dates']['total_days'] > 0 
            ? (($scheduleData['project_dates']['total_days'] - ($scheduleData['project_dates']['remaining_days'] ?? 0)) / $scheduleData['project_dates']['total_days']) * 100 
            : 0;

        if ($timePercentage == 0) {
            return ['value' => 1.0, 'status' => 'unknown', 'interpretation' => 'Проект еще не начался'];
        }

        $spi = $completionPercentage / $timePercentage;

        $status = 'good';
        $interpretation = 'График соблюдается';

        if ($spi < 0.8) {
            $status = 'critical';
            $interpretation = 'Серьезное отставание от графика';
        } elseif ($spi < 0.9) {
            $status = 'warning';
            $interpretation = 'Небольшое отставание от графика';
        } elseif ($spi > 1.1) {
            $interpretation = 'Опережение графика';
        }

        return [
            'value' => round($spi, 3),
            'completion_percentage' => $completionPercentage,
            'time_percentage' => round($timePercentage, 2),
            'status' => $status,
            'interpretation' => $interpretation,
        ];
    }

    /**
     * Эффективность использования материалов
     */
    private function calculateMaterialEfficiency(array $materialsData): array
    {
        if (empty($materialsData)) {
            return ['score' => 100, 'status' => 'unknown', 'interpretation' => 'Нет данных'];
        }

        $deficitCount = $materialsData['deficit_analysis']['deficit_count'] ?? 0;
        $daysOfSupply = $materialsData['days_of_supply'] ?? 0;

        // Оценка от 0 до 100
        $score = 100;

        // Штраф за дефицит
        $score -= $deficitCount * 5;

        // Штраф за малый запас
        if ($daysOfSupply < 14) {
            $score -= 30;
        } elseif ($daysOfSupply < 30) {
            $score -= 15;
        }

        $score = max(0, min(100, $score));

        $status = 'good';
        $interpretation = 'Материалы в норме';

        if ($score < 50) {
            $status = 'critical';
            $interpretation = 'Критические проблемы с материалами';
        } elseif ($score < 70) {
            $status = 'warning';
            $interpretation = 'Требуется внимание к материалам';
        }

        return [
            'score' => $score,
            'deficit_count' => $deficitCount,
            'days_of_supply' => $daysOfSupply,
            'status' => $status,
            'interpretation' => $interpretation,
        ];
    }

    /**
     * Производительность труда
     */
    private function calculateLaborProductivity(array $workersData): array
    {
        if (empty($workersData)) {
            return ['score' => 50, 'status' => 'unknown', 'interpretation' => 'Нет данных'];
        }

        $trend = $workersData['productivity']['productivity_trend'] ?? 'stable';
        $valuePerDay = $workersData['productivity']['value_per_day'] ?? 0;

        $score = 70; // Базовая оценка

        if ($trend === 'improving') {
            $score += 20;
        } elseif ($trend === 'declining') {
            $score -= 30;
        }

        $status = 'good';
        $interpretation = 'Производительность в норме';

        if ($score < 50) {
            $status = 'critical';
            $interpretation = 'Низкая производительность труда';
        } elseif ($score < 70) {
            $status = 'warning';
            $interpretation = 'Производительность требует улучшения';
        } elseif ($score > 85) {
            $interpretation = 'Высокая производительность труда';
        }

        return [
            'score' => $score,
            'trend' => $trend,
            'value_per_day' => $valuePerDay,
            'status' => $status,
            'interpretation' => $interpretation,
        ];
    }

    /**
     * Качество управления контрактами
     */
    private function calculateContractManagement(array $contractsData): array
    {
        if (empty($contractsData)) {
            return ['score' => 100, 'status' => 'unknown', 'interpretation' => 'Нет данных'];
        }

        $totalContracts = $contractsData['summary']['total_contracts'] ?? 0;
        $problemContracts = $contractsData['problem_contracts_count'] ?? 0;

        if ($totalContracts === 0) {
            return ['score' => 100, 'status' => 'good', 'interpretation' => 'Контракты отсутствуют'];
        }

        $problemPercentage = ($problemContracts / $totalContracts) * 100;
        $score = max(0, 100 - $problemPercentage * 2);

        $status = 'good';
        $interpretation = 'Контракты в норме';

        if ($score < 50) {
            $status = 'critical';
            $interpretation = 'Серьезные проблемы с контрактами';
        } elseif ($score < 70) {
            $status = 'warning';
            $interpretation = 'Есть проблемные контракты';
        }

        return [
            'score' => round($score, 1),
            'total_contracts' => $totalContracts,
            'problem_contracts' => $problemContracts,
            'problem_percentage' => round($problemPercentage, 1),
            'status' => $status,
            'interpretation' => $interpretation,
        ];
    }

    /**
     * Общий индекс здоровья проекта (0-100)
     */
    private function calculateProjectHealthIndex(array $cpi, array $spi, array $materialEfficiency, array $contractManagement): array
    {
        // Нормализуем CPI и SPI к шкале 0-100
        $cpiScore = $this->normalizeIndexToScore($cpi['value'], 0.8, 1.2);
        $spiScore = $this->normalizeIndexToScore($spi['value'], 0.8, 1.2);

        // Взвешенная оценка
        $weights = [
            'cpi' => 0.3,
            'spi' => 0.3,
            'materials' => 0.2,
            'contracts' => 0.2,
        ];

        $totalScore = 
            $cpiScore * $weights['cpi'] +
            $spiScore * $weights['spi'] +
            $materialEfficiency['score'] * $weights['materials'] +
            $contractManagement['score'] * $weights['contracts'];

        $status = 'good';
        $interpretation = 'Проект в хорошем состоянии';

        if ($totalScore < 50) {
            $status = 'critical';
            $interpretation = 'Проект требует срочного вмешательства';
        } elseif ($totalScore < 70) {
            $status = 'warning';
            $interpretation = 'Проект требует внимания';
        } elseif ($totalScore > 85) {
            $interpretation = 'Проект в отличном состоянии';
        }

        return [
            'score' => round($totalScore, 1),
            'status' => $status,
            'interpretation' => $interpretation,
            'components' => [
                'cpi_score' => round($cpiScore, 1),
                'spi_score' => round($spiScore, 1),
                'material_score' => $materialEfficiency['score'],
                'contract_score' => $contractManagement['score'],
            ],
        ];
    }

    /**
     * Нормализовать индекс (типа CPI/SPI) к шкале 0-100
     */
    private function normalizeIndexToScore(float $index, float $minGood, float $maxGood): float
    {
        if ($index >= $minGood && $index <= $maxGood) {
            return 100;
        }

        if ($index < $minGood) {
            // Чем ниже от minGood, тем хуже
            return max(0, 100 - (($minGood - $index) / $minGood) * 100);
        }

        // Чем выше от maxGood, тоже снижаем (слишком высокие показатели могут быть подозрительны)
        return max(70, 100 - (($index - $maxGood) / $maxGood) * 30);
    }

    /**
     * Получить статус производительности
     */
    private function getPerformanceStatus(array $healthIndex): string
    {
        return $healthIndex['status'];
    }
}

