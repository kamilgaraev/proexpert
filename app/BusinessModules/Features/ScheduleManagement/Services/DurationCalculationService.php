<?php

namespace App\BusinessModules\Features\ScheduleManagement\Services;

use Carbon\Carbon;

class DurationCalculationService
{
    /**
     * Рассчитать длительность задачи на основе трудозатрат (чел-час)
     * 
     * @param float $laborHours Трудозатраты в часах
     * @param int $workersCount Количество работников
     * @param int $hoursPerDay Рабочих часов в день
     * @return int Длительность в днях
     */
    public function calculateFromLaborHours(
        float $laborHours,
        int $workersCount = 1,
        int $hoursPerDay = 8
    ): int {
        if ($laborHours <= 0) {
            return 1; // Минимум 1 день
        }

        if ($workersCount < 1) {
            $workersCount = 1;
        }

        if ($hoursPerDay < 1) {
            $hoursPerDay = 8;
        }

        // Формула: ceil(трудозатраты / (часов_в_день * количество_работников))
        $durationDays = ceil($laborHours / ($hoursPerDay * $workersCount));

        // Минимум 1 день
        return max(1, (int) $durationDays);
    }

    /**
     * Рассчитать длительность на основе объема и производительности
     * 
     * @param float $quantity Объем работ
     * @param float $productivityRate Производительность (единиц в день)
     * @return int Длительность в днях
     */
    public function calculateFromQuantity(float $quantity, float $productivityRate): int
    {
        if ($quantity <= 0 || $productivityRate <= 0) {
            return 1;
        }

        $durationDays = ceil($quantity / $productivityRate);

        return max(1, (int) $durationDays);
    }

    /**
     * Скорректировать длительность с учетом рабочих дней (без выходных)
     * 
     * @param int $durationDays Длительность в днях
     * @param Carbon $startDate Дата начала
     * @param bool $includeWeekends Включать ли выходные
     * @return Carbon Дата окончания
     */
    public function adjustForWorkingDays(
        int $durationDays,
        Carbon $startDate,
        bool $includeWeekends = false
    ): Carbon {
        $endDate = $startDate->copy();

        if ($includeWeekends) {
            // Просто добавляем дни
            $endDate->addDays($durationDays - 1);
        } else {
            // Добавляем только рабочие дни (пропуская выходные)
            $addedDays = 0;
            
            while ($addedDays < $durationDays) {
                // Проверяем, является ли день рабочим (не суббота и не воскресенье)
                if (!$endDate->isWeekend()) {
                    $addedDays++;
                }
                
                if ($addedDays < $durationDays) {
                    $endDate->addDay();
                }
            }
        }

        return $endDate;
    }

    /**
     * Рассчитать длительность между двумя датами с учетом только рабочих дней
     * 
     * @param Carbon $startDate Дата начала
     * @param Carbon $endDate Дата окончания
     * @return int Количество рабочих дней
     */
    public function calculateWorkingDays(Carbon $startDate, Carbon $endDate): int
    {
        $workingDays = 0;
        $currentDate = $startDate->copy();

        while ($currentDate->lte($endDate)) {
            if (!$currentDate->isWeekend()) {
                $workingDays++;
            }
            $currentDate->addDay();
        }

        return $workingDays;
    }

    /**
     * Преобразовать календарные дни в рабочие дни
     * 
     * @param int $calendarDays Календарные дни
     * @return int Примерное количество рабочих дней
     */
    public function convertCalendarToWorkingDays(int $calendarDays): int
    {
        // Приблизительно: 5 рабочих дней на 7 календарных
        return (int) round($calendarDays * (5 / 7));
    }

    /**
     * Преобразовать рабочие дни в календарные дни
     * 
     * @param int $workingDays Рабочие дни
     * @return int Примерное количество календарных дней
     */
    public function convertWorkingToCalendarDays(int $workingDays): int
    {
        // Приблизительно: 7 календарных дней на 5 рабочих
        return (int) ceil($workingDays * (7 / 5));
    }

    /**
     * Рассчитать дату окончания задачи с учетом настроек
     * 
     * @param Carbon $startDate Дата начала
     * @param int $durationDays Длительность в днях
     * @param array $settings Настройки расчета
     * @return Carbon Дата окончания
     */
    public function calculateEndDate(
        Carbon $startDate,
        int $durationDays,
        array $settings = []
    ): Carbon {
        $includeWeekends = $settings['include_weekends'] ?? false;

        return $this->adjustForWorkingDays($durationDays, $startDate, $includeWeekends);
    }

    /**
     * Оценить трудозатраты на основе длительности и количества работников
     * 
     * @param int $durationDays Длительность в днях
     * @param int $workersCount Количество работников
     * @param int $hoursPerDay Рабочих часов в день
     * @return float Трудозатраты в часах
     */
    public function estimateLaborHours(
        int $durationDays,
        int $workersCount = 1,
        int $hoursPerDay = 8
    ): float {
        return $durationDays * $workersCount * $hoursPerDay;
    }
}

