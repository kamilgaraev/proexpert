<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    /**
     * Исправить некорректные длительности задач графиков.
     * Пересчитывает planned_duration_days на основе дат для всех существующих задач.
     */
    public function up(): void
    {
        Log::info('[Migration] Начало исправления длительностей задач графиков');
        
        $tasks = DB::table('schedule_tasks')
            ->whereNotNull('planned_start_date')
            ->whereNotNull('planned_end_date')
            ->whereNotNull('planned_duration_days')
            ->whereNull('deleted_at')
            ->get();
        
        $fixedCount = 0;
        $skippedCount = 0;
        $issuesFound = [];
        
        foreach ($tasks as $task) {
            try {
                $startDate = new \DateTime($task->planned_start_date);
                $endDate = new \DateTime($task->planned_end_date);
                
                // Вычисляем правильную длительность
                $calculatedDuration = $startDate->diff($endDate)->days + 1;
                
                // Проверяем, есть ли расхождение
                $difference = abs($calculatedDuration - $task->planned_duration_days);
                
                if ($difference > 0) {
                    // Логируем большие расхождения для ручной проверки
                    if ($difference > 7) {
                        $issuesFound[] = [
                            'task_id' => $task->id,
                            'task_name' => $task->name,
                            'old_duration' => $task->planned_duration_days,
                            'new_duration' => $calculatedDuration,
                            'difference' => $difference,
                        ];
                    }
                    
                    // Обновляем длительность
                    DB::table('schedule_tasks')
                        ->where('id', $task->id)
                        ->update(['planned_duration_days' => $calculatedDuration]);
                    
                    $fixedCount++;
                    
                    Log::info('[Migration] Исправлена длительность задачи', [
                        'task_id' => $task->id,
                        'task_name' => $task->name,
                        'old_duration' => $task->planned_duration_days,
                        'new_duration' => $calculatedDuration,
                        'difference' => $difference,
                    ]);
                } else {
                    $skippedCount++;
                }
            } catch (\Exception $e) {
                Log::error('[Migration] Ошибка при исправлении длительности задачи', [
                    'task_id' => $task->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
        
        Log::info('[Migration] Завершение исправления длительностей задач графиков', [
            'total_tasks' => $tasks->count(),
            'fixed_count' => $fixedCount,
            'skipped_count' => $skippedCount,
            'issues_with_large_difference' => count($issuesFound),
        ]);
        
        // Логируем задачи с большими расхождениями
        if (!empty($issuesFound)) {
            Log::warning('[Migration] Задачи с большими расхождениями длительности (>7 дней)', [
                'count' => count($issuesFound),
                'tasks' => $issuesFound,
            ]);
        }
        
        // Исправляем actual_duration_days для завершенных задач
        $this->fixActualDurations();
    }
    
    /**
     * Исправить фактические длительности для завершенных задач
     */
    protected function fixActualDurations(): void
    {
        Log::info('[Migration] Начало исправления фактических длительностей');
        
        $completedTasks = DB::table('schedule_tasks')
            ->whereNotNull('actual_start_date')
            ->whereNotNull('actual_end_date')
            ->whereNull('deleted_at')
            ->get();
        
        $fixedCount = 0;
        
        foreach ($completedTasks as $task) {
            try {
                $startDate = new \DateTime($task->actual_start_date);
                $endDate = new \DateTime($task->actual_end_date);
                
                // Вычисляем правильную фактическую длительность
                $calculatedDuration = $startDate->diff($endDate)->days + 1;
                
                // Обновляем если отличается от сохраненного значения
                if ($task->actual_duration_days !== $calculatedDuration) {
                    DB::table('schedule_tasks')
                        ->where('id', $task->id)
                        ->update(['actual_duration_days' => $calculatedDuration]);
                    
                    $fixedCount++;
                    
                    Log::info('[Migration] Исправлена фактическая длительность задачи', [
                        'task_id' => $task->id,
                        'old_actual_duration' => $task->actual_duration_days,
                        'new_actual_duration' => $calculatedDuration,
                    ]);
                }
            } catch (\Exception $e) {
                Log::error('[Migration] Ошибка при исправлении фактической длительности', [
                    'task_id' => $task->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
        
        Log::info('[Migration] Завершение исправления фактических длительностей', [
            'total_tasks' => $completedTasks->count(),
            'fixed_count' => $fixedCount,
        ]);
    }

    /**
     * Откатить изменения (не восстанавливаем старые значения)
     */
    public function down(): void
    {
        Log::warning('[Migration] Откат миграции исправления длительностей не поддерживается');
        // Не откатываем исправления, так как они корректируют некорректные данные
    }
};

