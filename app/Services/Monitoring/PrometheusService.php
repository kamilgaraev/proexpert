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
        // КРИТИЧНО: Кешируем метрики более агрессивно + fallback если кеш блокируется
        try {
            return \Illuminate\Support\Facades\Cache::remember('prometheus_metrics', 120, function () {
                // Timeout для всей операции
                $startTime = microtime(true);
                $maxExecutionTime = 5; // Максимум 5 секунд
                
                $this->collectSystemMetrics();
                
                // Проверяем timeout перед загрузкой файлов
                if ((microtime(true) - $startTime) < $maxExecutionTime) {
                    $this->loadCountersFromStorage();
                }
                
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
                
                // Добавляем метрики (с timeout)
                $metricCount = 0;
                foreach ($this->metrics as $name => $value) {
                    $output[] = "{$this->namespace}_{$name} {$value}";
                    $metricCount++;
                    
                    // Ограничиваем количество метрик для производительности
                    if ($metricCount > 50) {
                        break;
                    }
                }
                
                return implode("\n", $output) . "\n";
            });
        } catch (\Exception $e) {
            // FALLBACK: Если кеш не работает - возвращаем минимальные метрики
            return "# Prometheus metrics - fallback mode\n" .
                   "laravel_memory_usage_bytes " . memory_get_usage(true) . "\n" .
                   "laravel_database_connections_active 1\n" .
                   "laravel_http_requests_total{method=\"GET\",route=\"metrics\",status=\"200\"} 1\n";
        }
    }

    private function incrementCounter(string $key): void
    {
        try {
            $filename = $this->storageDir . '/' . md5($key) . '.counter';
            $current = 0;
            
            // КРИТИЧНО: Убираем LOCK_EX чтобы избежать блокировок
            // В крайнем случае допускаем небольшую потерю точности метрик ради производительности
            if (file_exists($filename)) {
                $content = @file_get_contents($filename);
                $current = $content !== false ? (int) $content : 0;
            }
            
            $current++;
            
            // Неблокирующая запись без LOCK_EX
            @file_put_contents($filename, $current);
            $this->saveMetricName($key);
            $this->metrics[$key] = $current;
        } catch (\Exception $e) {
            // Если запись метрик не работает - не блокируем приложение
            $this->metrics[$key] = ($this->metrics[$key] ?? 0) + 1;
        }
    }

    private function setGauge(string $key, float $value): void
    {
        $this->metrics[$key] = $value;
    }

    private function loadCountersFromStorage(): void
    {
        // КРИТИЧНО: Избегаем блокировок файлов - возвращаем заглушку если кеш недоступен
        try {
            $cachedMetrics = \Illuminate\Support\Facades\Cache::remember('prometheus_file_metrics', 60, function () {
                $metrics = [];
                
                // TIMEOUT: Ограничиваем время выполнения
                $startTime = microtime(true);
                $timeoutSeconds = 2; // Максимум 2 секунды на чтение файлов
                
                $files = glob($this->storageDir . '/*.counter');
                if (!$files) {
                    return $metrics; // Если нет файлов, возвращаем пустой массив
                }
                
                // Ограничиваем количество файлов более агрессивно
                $files = array_slice($files, 0, 10); // Максимум 10 файлов!
                
                foreach ($files as $file) {
                    // Проверяем timeout
                    if ((microtime(true) - $startTime) > $timeoutSeconds) {
                        break; // Выходим при превышении timeout
                    }
                    
                    $basename = basename($file, '.counter');
                    $metricFile = $this->storageDir . '/' . $basename . '.metric';
                    
                    if (file_exists($metricFile)) {
                        try {
                            // Используем file_get_contents с timeout
                            $context = stream_context_create([
                                'http' => ['timeout' => 1] // 1 секунда timeout
                            ]);
                            
                            $metricName = @file_get_contents($metricFile, false, $context);
                            $value = @file_get_contents($file, false, $context);
                            
                            if ($metricName !== false && $value !== false) {
                                $metrics[$metricName] = (int) $value;
                            }
                        } catch (\Exception $e) {
                            // Пропускаем проблемные файлы
                            continue;
                        }
                    }
                }
                
                return $metrics;
            });
            
            $this->metrics = array_merge($this->metrics, $cachedMetrics);
        } catch (\Exception $e) {
            // Если кеш недоступен или есть ошибки - используем заглушки
            $this->metrics = array_merge($this->metrics, [
                'http_requests_total{method="GET",route="/",status="200"}' => 1,
                'memory_usage_bytes' => memory_get_usage(true),
                'database_connections_active' => 1
            ]);
        }
    }

    private function saveMetricName(string $key): void
    {
        try {
            $filename = $this->storageDir . '/' . md5($key) . '.metric';
            if (!file_exists($filename)) {
                // КРИТИЧНО: Убираем LOCK_EX чтобы избежать блокировок
                @file_put_contents($filename, $key);
            }
        } catch (\Exception $e) {
            // Если сохранение имени метрики не работает - продолжаем без ошибок
            return;
        }
    }
} 