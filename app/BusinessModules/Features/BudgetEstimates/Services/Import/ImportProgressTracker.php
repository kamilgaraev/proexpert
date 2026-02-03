<?php

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class ImportProgressTracker
{
    public function __construct(
        private ?string $jobId
    ) {}

    /**
     * Обновить прогресс на основе количества обработанных элементов
     * 
     * @param int $current Текущий индекс элемента
     * @param int $total Общее количество элементов
     * @param int $startPercent Начальный процент диапазона (например, 50%)
     * @param int $endPercent Конечный процент диапазона (например, 85%)
     */
    public function update(int $current, int $total, int $startPercent = 50, int $endPercent = 85): void
    {
        if ($this->jobId === null || $total === 0) {
            return;
        }

        // Обновляем каждые 50 элементов или в самом конце
        if ($current > 0 && $current % 50 !== 0 && $current !== $total) {
            return;
        }

        $itemsProgress = ($current / $total) * ($endPercent - $startPercent);
        $totalProgress = $startPercent + (int)$itemsProgress;

        $this->saveProgress($totalProgress);
        
        Log::debug("[EstimateImport] Progress updated: {$current}/{$total} ({$totalProgress}%)", [
            'job_id' => $this->jobId
        ]);
    }
    
    /**
     * Установить точное значение прогресса
     */
    public function setProgress(int $progress): void
    {
        if ($this->jobId === null) {
            return;
        }
        
        $this->saveProgress($progress);
    }

    private function saveProgress(int $progress): void
    {
        // Cache progress for real-time access (bypassing DB transaction isolation)
        if ($this->jobId) {
            Cache::put("import_progress_{$this->jobId}", $progress, 3600);
        }

        try {
            // Используем отдельное подключение для прогресса, чтобы обновления были видны 
            // другим процессам сразу, даже если основная транзакция еще не завершена
            DB::connection()->transaction(function () use ($progress) {
                DB::table('estimate_import_history')
                    ->where('job_id', $this->jobId)
                    ->update(['progress' => $progress]);
            }, attempts: 1);
        } catch (\Exception $e) {
            Log::warning('[EstimateImport] Failed to update progress', [
                'job_id' => $this->jobId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
