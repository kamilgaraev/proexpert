<?php

namespace App\BusinessModules\Features\AIAssistant\Actions\Reports\Tools;

use Carbon\Carbon;

trait ReportDateHelper
{
    /**
     * Преобразует текстовое описание периода в даты 'date_from' и 'date_to'.
     *
     * @param string $query Текстовое описание из промпта
     * @return array Возвращает массив с 'date_from' и 'date_to' (формат Y-m-d H:i:s)
     */
    protected function extractPeriod(string $query): array
    {
        $query = mb_strtolower($query);
        $now = Carbon::now();
        
        // Последний месяц
        if (preg_match('/(последн|прошл).* месяц/ui', $query)) {
            return [
                'date_from' => $now->copy()->subMonth()->startOfMonth()->toDateTimeString(),
                'date_to' => $now->copy()->subMonth()->endOfMonth()->toDateTimeString(),
            ];
        }
        
        // Этот месяц
        if (preg_match('/(этот|текущ|за).* месяц/ui', $query)) {
            return [
                'date_from' => $now->copy()->startOfMonth()->toDateTimeString(),
                'date_to' => $now->copy()->endOfMonth()->toDateTimeString(),
            ];
        }
        
        // Квартал
        if (preg_match('/(квартал|кварт)/ui', $query)) {
            return [
                'date_from' => $now->copy()->startOfQuarter()->toDateTimeString(),
                'date_to' => $now->copy()->endOfQuarter()->toDateTimeString(),
            ];
        }
        
        // Год
        if (preg_match('/(год|за год|в год)/ui', $query)) {
            return [
                'date_from' => $now->copy()->startOfYear()->toDateTimeString(),
                'date_to' => $now->copy()->endOfYear()->toDateTimeString(),
            ];
        }
        
        // Конкретный месяц (сентябрь, октябрь и т.д.)
        $months = [
            'январ' => 1, 'феврал' => 2, 'март' => 3, 'апрел' => 4, 'ма[йя]' => 5, 'июн' => 6,
            'июл' => 7, 'август' => 8, 'сентябр' => 9, 'октябр' => 10, 'ноябр' => 11, 'декабр' => 12,
        ];
        
        foreach ($months as $pattern => $monthNum) {
            if (preg_match("/$pattern/ui", $query)) {
                $date = Carbon::create($now->year, $monthNum, 1);
                return [
                    'date_from' => $date->copy()->startOfMonth()->toDateTimeString(),
                    'date_to' => $date->copy()->endOfMonth()->toDateTimeString(),
                ];
            }
        }
        
        // Неопределенный/далекий период - по умолчанию с начала месяца
        // Если указан "весь период"
        if (preg_match('/(весь|вс[её]).* (период|время)/ui', $query)) {
            return [
                'date_from' => null,
                'date_to' => null,
            ];
        }

        // По умолчанию - за текущий месяц (чтобы не перегружать сервис гигантскими выборками)
        return [
            'date_from' => $now->copy()->startOfMonth()->toDateTimeString(),
            'date_to' => $now->copy()->endOfMonth()->toDateTimeString(),
        ];
    }
}
