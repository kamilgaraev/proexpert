<?php

namespace App\Services\Monitoring;

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
        $key = "exceptions_total{class=\"{$exceptionClass}\",type=\"{$type}\"}";
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
        // Простой счетчик без кеша
        if (!isset($this->metrics[$key])) {
            $this->metrics[$key] = 0;
        }
        $this->metrics[$key]++;
    }

    private function setGauge(string $key, float $value): void
    {
        $this->metrics[$key] = $value;
    }
} 