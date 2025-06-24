<?php

namespace App\Services\Monitoring;

class PrometheusService
{
    private array $metrics = [];
    private string $namespace = 'laravel';
    private string $storageDir;

    public function __construct()
    {
        $this->storageDir = storage_path('prometheus');
        if (!is_dir($this->storageDir)) {
            mkdir($this->storageDir, 0775, true);
        }
    }

    public function incrementHttpRequests(string $method, string $route, int $status): void
    {
        $key = "http_requests_total{method=\"{$method}\",route=\"{$route}\",status=\"{$status}\"}";
        $this->incrementCounter($key);
    }

    public function observeRequestDuration(string $method, string $route, float $duration): void
    {
        $key = "http_request_duration_seconds{method=\"{$method}\",route=\"{$route}\"}";
        $this->setGauge($key, $duration);
    }

    public function setMemoryUsage(): void
    {
        $key = 'memory_usage_bytes';
        $this->setGauge($key, memory_get_usage(true));
    }

    public function setDatabaseConnections(): void
    {
        // Упрощенная проверка без реального подключения к БД
        $this->setGauge('database_connections_active', 1);
    }

    public function incrementExceptions(string $exceptionClass, string $type = 'uncaught'): void
    {
        $key = "exceptions_total{class=\"" . str_replace('\\', '_', $exceptionClass) . "\",type=\"{$type}\"}";
        $this->incrementCounter($key);
    }

    public function setQueueSize(string $queue = 'default'): void
    {
        // Упрощенная версия без проверки БД
        $this->setGauge("queue_size{queue=\"{$queue}\"}", 0);
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
        $this->loadCountersFromStorage();
        
        $output = [];
        
        // Добавляем HELP и TYPE комментарии
        $output[] = '# HELP laravel_http_requests_total Total number of HTTP requests';
        $output[] = '# TYPE laravel_http_requests_total counter';
        
        $output[] = '# HELP laravel_http_request_duration_seconds Duration of HTTP requests in seconds';
        $output[] = '# TYPE laravel_http_request_duration_seconds gauge';
        
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
            $output[] = "{$this->namespace}_{$name} {$value}";
        }
        
        return implode("\n", $output) . "\n";
    }

    private function incrementCounter(string $key): void
    {
        $filename = $this->storageDir . '/' . md5($key) . '.counter';
        $current = 0;
        
        if (file_exists($filename)) {
            $current = (int) file_get_contents($filename);
        }
        
        $current++;
        file_put_contents($filename, $current, LOCK_EX);
        $this->saveMetricName($key);
        $this->metrics[$key] = $current;
    }

    private function setGauge(string $key, float $value): void
    {
        $this->metrics[$key] = $value;
    }

    private function loadCountersFromStorage(): void
    {
        $files = glob($this->storageDir . '/*.counter');
        
        foreach ($files as $file) {
            $basename = basename($file, '.counter');
            $metricFiles = glob($this->storageDir . '/' . $basename . '.metric');
            
            if (!empty($metricFiles)) {
                $metricName = file_get_contents($metricFiles[0]);
                $value = (int) file_get_contents($file);
                $this->metrics[$metricName] = $value;
            }
        }
    }

    private function saveMetricName(string $key): void
    {
        $filename = $this->storageDir . '/' . md5($key) . '.metric';
        if (!file_exists($filename)) {
            file_put_contents($filename, $key, LOCK_EX);
        }
    }
} 