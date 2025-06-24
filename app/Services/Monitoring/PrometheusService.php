<?php

namespace App\Services\Monitoring;

use Prometheus\CollectorRegistry;
use Prometheus\Storage\InMemory;
use Prometheus\Counter;
use Prometheus\Histogram;
use Prometheus\Gauge;
use Prometheus\RenderTextFormat;
use Illuminate\Support\Facades\DB;

class PrometheusService
{
    private CollectorRegistry $registry;
    private Counter $requestsTotal;
    private Histogram $requestDuration;
    private Gauge $memoryUsage;
    private Gauge $databaseConnections;
    private Counter $exceptionsTotal;
    private Gauge $queueSize;

    public function __construct()
    {
        $this->registry = new CollectorRegistry(new InMemory());
        $this->initializeMetrics();
    }

    private function initializeMetrics()
    {
        $this->requestsTotal = $this->registry->getOrRegisterCounter(
            'laravel',
            'http_requests_total',
            'Total number of HTTP requests',
            ['method', 'route', 'status']
        );

        $this->requestDuration = $this->registry->getOrRegisterHistogram(
            'laravel',
            'http_request_duration_seconds',
            'Duration of HTTP requests in seconds',
            ['method', 'route'],
            [0.1, 0.25, 0.5, 1.0, 2.5, 5.0, 10.0]
        );

        $this->memoryUsage = $this->registry->getOrRegisterGauge(
            'laravel',
            'memory_usage_bytes',
            'Current memory usage in bytes'
        );

        $this->databaseConnections = $this->registry->getOrRegisterGauge(
            'laravel',
            'database_connections_active',
            'Number of active database connections'
        );

        $this->exceptionsTotal = $this->registry->getOrRegisterCounter(
            'laravel',
            'exceptions_total',
            'Total number of exceptions',
            ['class', 'type']
        );

        $this->queueSize = $this->registry->getOrRegisterGauge(
            'laravel',
            'queue_size',
            'Number of jobs in queue',
            ['queue']
        );
    }

    public function incrementHttpRequests(string $method, string $route, int $status): void
    {
        $this->requestsTotal->inc([
            'method' => $method,
            'route' => $route,
            'status' => (string)$status
        ]);
    }

    public function observeRequestDuration(string $method, string $route, float $duration): void
    {
        $this->requestDuration->observe($duration, [
            'method' => $method,
            'route' => $route
        ]);
    }

    public function setMemoryUsage(): void
    {
        $this->memoryUsage->set(memory_get_usage(true));
    }

    public function setDatabaseConnections(): void
    {
        try {
            $connections = collect(config('database.connections'))
                ->keys()
                ->sum(function ($connection) {
                    try {
                        return DB::connection($connection)->select('SELECT 1')[0] ? 1 : 0;
                    } catch (\Exception $e) {
                        return 0;
                    }
                });
            
            $this->databaseConnections->set($connections);
        } catch (\Exception $e) {
            $this->databaseConnections->set(0);
        }
    }

    public function incrementExceptions(string $exceptionClass, string $type = 'uncaught'): void
    {
        $this->exceptionsTotal->inc([
            'class' => $exceptionClass,
            'type' => $type
        ]);
    }

    public function setQueueSize(string $queue = 'default'): void
    {
        try {
            $size = DB::table('jobs')->where('queue', $queue)->count();
            $this->queueSize->set($size, ['queue' => $queue]);
        } catch (\Exception $e) {
            $this->queueSize->set(0, ['queue' => $queue]);
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
        
        $renderer = new RenderTextFormat();
        return $renderer->render($this->registry->getMetricFamilySamples());
    }

    public function getRegistry(): CollectorRegistry
    {
        return $this->registry;
    }
} 