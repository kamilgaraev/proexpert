<?php

namespace App\Services\Monitoring;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

class PrometheusService
{
    private array $metrics = [];
    private string $namespace = 'laravel';

    public function incrementHttpRequests(string $method, string $route, int $status): void
    {
        $key = "http_requests_total{method=\"{$method}\",route=\"{$route}\",status=\"{$status}\"}";
        $this->incrementCounter($key);
    }

    public function observeRequestDuration(string $method, string $route, float $duration): void
    {
        $key = "http_request_duration_seconds{method=\"{$method}\",route=\"{$route}\"}";
        $this->addHistogramObservation($key, $duration);
    }

    public function setMemoryUsage(): void
    {
        $key = 'memory_usage_bytes';
        $this->setGauge($key, memory_get_usage(true));
    }

    public function setDatabaseConnections(): void
    {
        try {
            // Проверяем только основное соединение
            DB::select('SELECT 1');
            $this->setGauge('database_connections_active', 1);
        } catch (\Exception $e) {
            $this->setGauge('database_connections_active', 0);
        }
    }

    public function incrementExceptions(string $exceptionClass, string $type = 'uncaught'): void
    {
        $key = "exceptions_total{class=\"{$exceptionClass}\",type=\"{$type}\"}";
        $this->incrementCounter($key);
    }

    public function setQueueSize(string $queue = 'default'): void
    {
        try {
            // Проверяем есть ли таблица jobs
            if (DB::getSchemaBuilder()->hasTable('jobs')) {
                $size = DB::table('jobs')->where('queue', $queue)->count();
                $this->setGauge("queue_size{queue=\"{$queue}\"}", $size);
            } else {
                $this->setGauge("queue_size{queue=\"{$queue}\"}", 0);
            }
        } catch (\Exception $e) {
            $this->setGauge("queue_size{queue=\"{$queue}\"}", 0);
        }
    }

    public function collectSystemMetrics(): void
    {
        $this->setMemoryUsage();
        $this->setDatabaseConnections();
        $this->setQueueSize();
    }

    public function renderMetrics(): string
    {
        $this->collectSystemMetrics();
        
        $output = [];
        
        // Добавляем HELP и TYPE комментарии
        $output[] = '# HELP laravel_http_requests_total Total number of HTTP requests';
        $output[] = '# TYPE laravel_http_requests_total counter';
        
        $output[] = '# HELP laravel_http_request_duration_seconds Duration of HTTP requests in seconds';
        $output[] = '# TYPE laravel_http_request_duration_seconds histogram';
        
        $output[] = '# HELP laravel_memory_usage_bytes Current memory usage in bytes';
        $output[] = '# TYPE laravel_memory_usage_bytes gauge';
        
        $output[] = '# HELP laravel_database_connections_active Number of active database connections';
        $output[] = '# TYPE laravel_database_connections_active gauge';
        
        $output[] = '# HELP laravel_exceptions_total Total number of exceptions';
        $output[] = '# TYPE laravel_exceptions_total counter';
        
        $output[] = '# HELP laravel_queue_size Number of jobs in queue';
        $output[] = '# TYPE laravel_queue_size gauge';
        
        // Добавляем метрики
        foreach ($this->metrics as $name => $value) {
            if (is_array($value)) {
                // Для гистограмм
                foreach ($value as $label => $val) {
                    $output[] = "{$this->namespace}_{$name}_{$label} {$val}";
                }
            } else {
                $output[] = "{$this->namespace}_{$name} {$value}";
            }
        }
        
        return implode("\n", $output) . "\n";
    }

    private function incrementCounter(string $key): void
    {
        $cacheKey = "prometheus_counter_{$key}";
        $current = Cache::get($cacheKey, 0);
        Cache::put($cacheKey, $current + 1, now()->addHours(24));
        $this->metrics[$key] = $current + 1;
    }

    private function setGauge(string $key, float $value): void
    {
        $this->metrics[$key] = $value;
    }

    private function addHistogramObservation(string $key, float $value): void
    {
        $buckets = [0.1, 0.25, 0.5, 1.0, 2.5, 5.0, 10.0, '+Inf'];
        
        $cacheKey = "prometheus_histogram_{$key}";
        $data = Cache::get($cacheKey, [
            'count' => 0,
            'sum' => 0,
            'buckets' => array_fill_keys($buckets, 0)
        ]);
        
        $data['count']++;
        $data['sum'] += $value;
        
        foreach ($buckets as $bucket) {
            if ($bucket === '+Inf' || $value <= (float)$bucket) {
                $data['buckets'][$bucket]++;
            }
        }
        
        Cache::put($cacheKey, $data, now()->addHours(24));
        
        // Формируем вывод гистограммы
        foreach ($data['buckets'] as $bucket => $count) {
            $this->metrics["{$key}_bucket{le=\"{$bucket}\"}"] = $count;
        }
        $this->metrics["{$key}_count"] = $data['count'];
        $this->metrics["{$key}_sum"] = $data['sum'];
    }
} 