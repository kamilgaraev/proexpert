<?php

namespace App\Services\Logging\Context;

class PerformanceContext
{
    protected float $startTime;
    protected float $startMemory;
    protected array $checkpoints = [];
    protected array $queries = [];
    protected array $customMetrics = [];

    public function __construct()
    {
        $this->startTime = microtime(true);
        $this->startMemory = memory_get_usage(true);
        
        // Начать отслеживание DB запросов, если доступно
        $this->startDatabaseTracking();
    }

    /**
     * Начать отслеживание базы данных
     */
    protected function startDatabaseTracking(): void
    {
        if (config('app.env') !== 'production') {
            try {
                \Illuminate\Support\Facades\DB::listen(function ($query) {
                    $this->queries[] = [
                        'sql' => $query->sql,
                        'time' => $query->time,
                        'bindings_count' => count($query->bindings)
                    ];
                });
            } catch (\Exception $e) {
                // Игнорируем ошибки трекинга в production
            }
        }
    }

    /**
     * Добавить checkpoint для измерения промежуточного времени
     */
    public function checkpoint(string $name): void
    {
        $this->checkpoints[$name] = [
            'time' => microtime(true) - $this->startTime,
            'memory' => memory_get_usage(true) - $this->startMemory,
            'memory_peak' => memory_get_peak_usage(true) - $this->startMemory
        ];
    }

    /**
     * Добавить кастомную метрику
     */
    public function addMetric(string $name, $value, ?string $unit = null): void
    {
        $this->customMetrics[$name] = [
            'value' => $value,
            'unit' => $unit,
            'timestamp' => microtime(true)
        ];
    }

    /**
     * Получить все метрики производительности
     */
    public function getMetrics(): array
    {
        $currentTime = microtime(true);
        $currentMemory = memory_get_usage(true);
        $peakMemory = memory_get_peak_usage(true);

        $metrics = [
            'duration_ms' => round(($currentTime - $this->startTime) * 1000, 2),
            'memory_usage_mb' => round(($currentMemory - $this->startMemory) / 1024 / 1024, 2),
            'memory_peak_mb' => round($peakMemory / 1024 / 1024, 2),
        ];

        // Добавить checkpoints если есть
        if (!empty($this->checkpoints)) {
            $metrics['checkpoints'] = $this->checkpoints;
        }

        // Добавить информацию о БД запросах (только в dev/staging)
        if (!empty($this->queries) && config('app.env') !== 'production') {
            $metrics['database'] = [
                'queries_count' => count($this->queries),
                'total_time_ms' => round(array_sum(array_column($this->queries, 'time')), 2),
                'slow_queries' => $this->getSlowQueries()
            ];
        }

        // Добавить кастомные метрики
        if (!empty($this->customMetrics)) {
            $metrics['custom'] = $this->customMetrics;
        }

        return $metrics;
    }

    /**
     * Получить медленные запросы (>100ms)
     */
    protected function getSlowQueries(): array
    {
        return array_filter($this->queries, function ($query) {
            return $query['time'] > 100; // медленнее 100ms
        });
    }

    /**
     * Получить базовые метрики для логирования
     */
    public function getBasicMetrics(): array
    {
        $currentTime = microtime(true);
        $currentMemory = memory_get_usage(true);

        return [
            'duration_ms' => round(($currentTime - $this->startTime) * 1000, 2),
            'memory_mb' => round(($currentMemory - $this->startMemory) / 1024 / 1024, 2),
        ];
    }

    /**
     * Проверить, медленный ли запрос
     */
    public function isSlowRequest(float $thresholdMs = 1000): bool
    {
        $duration = (microtime(true) - $this->startTime) * 1000;
        return $duration > $thresholdMs;
    }

    /**
     * Проверить, много ли используется памяти
     */
    public function isMemoryIntensive(float $thresholdMb = 50): bool
    {
        $memoryUsage = (memory_get_usage(true) - $this->startMemory) / 1024 / 1024;
        return $memoryUsage > $thresholdMb;
    }

    /**
     * Получить производительность CPU (приблизительно)
     */
    public function getCpuUsage(): ?array
    {
        // Только в dev окружении для избежания overhead
        if (config('app.env') !== 'local') {
            return null;
        }

        try {
            $load = sys_getloadavg();
            return [
                '1min' => $load[0] ?? null,
                '5min' => $load[1] ?? null,
                '15min' => $load[2] ?? null
            ];
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Создать краткий отчет о производительности
     */
    public function getPerformanceSummary(): array
    {
        $metrics = $this->getBasicMetrics();
        
        return [
            'performance_level' => $this->getPerformanceLevel($metrics),
            'duration_ms' => $metrics['duration_ms'],
            'memory_mb' => $metrics['memory_mb'],
            'is_slow' => $this->isSlowRequest(),
            'is_memory_intensive' => $this->isMemoryIntensive()
        ];
    }

    /**
     * Определить уровень производительности
     */
    protected function getPerformanceLevel(array $metrics): string
    {
        if ($metrics['duration_ms'] > 2000 || $metrics['memory_mb'] > 100) {
            return 'poor';
        } elseif ($metrics['duration_ms'] > 1000 || $metrics['memory_mb'] > 50) {
            return 'moderate';
        } elseif ($metrics['duration_ms'] > 500 || $metrics['memory_mb'] > 25) {
            return 'good';
        }

        return 'excellent';
    }

    /**
     * Сброс метрик (для нового запроса)
     */
    public function reset(): void
    {
        $this->startTime = microtime(true);
        $this->startMemory = memory_get_usage(true);
        $this->checkpoints = [];
        $this->queries = [];
        $this->customMetrics = [];
    }
}
