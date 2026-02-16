<?php

namespace App\Services\Logging;

use App\Services\Logging\Context\RequestContext;
use App\Services\Logging\Context\UserContext;
use App\Services\Logging\Context\PerformanceContext;
use Illuminate\Support\Facades\Log;

class TechnicalLogger
{
    protected RequestContext $requestContext;
    protected UserContext $userContext;
    protected PerformanceContext $performanceContext;

    public function __construct(
        RequestContext $requestContext,
        UserContext $userContext,
        PerformanceContext $performanceContext
    ) {
        $this->requestContext = $requestContext;
        $this->userContext = $userContext;
        $this->performanceContext = $performanceContext;
    }

    /**
     * Логировать техническое событие
     */
    public function log(string $event, array $context = [], string $level = 'info'): void
    {
        $technicalEntry = $this->createTechnicalEntry($event, $context, $level);
        
        match(strtolower($level)) {
            'critical' => Log::channel('technical')->critical("[TECHNICAL] {$event}", $technicalEntry),
            'error' => Log::channel('technical')->error("[TECHNICAL] {$event}", $technicalEntry),
            'warning' => Log::channel('technical')->warning("[TECHNICAL] {$event}", $technicalEntry),
            'debug' => Log::channel('technical')->debug("[TECHNICAL] {$event}", $technicalEntry),
            // default => Log::channel('technical')->info("[TECHNICAL] {$event}", $technicalEntry)
            default => null // Disable info level logs
        };

        // Отправить критические события в мониторинг
        if (in_array($level, ['critical', 'error'])) {
            $this->sendTechnicalAlert($event, $context, $level);
        }
    }

    /**
     * Создать запись технического события
     */
    protected function createTechnicalEntry(string $event, array $context, string $level): array
    {
        return [
            'timestamp' => now()->toISOString(),
            'level' => strtoupper($level),
            'category' => 'TECHNICAL',
            'event' => $event,
            'correlation_id' => $this->requestContext->getCorrelationId(),
            'user_id' => $this->userContext->getUserId(),
            'organization_id' => $this->userContext->getOrganizationId(),
            'context' => $context,
            'technical_context' => [
                'interface' => $this->requestContext->getClientInterface(),
                'api_version' => $this->requestContext->getMetadata()['api_version'] ?? null,
                'request_method' => $this->requestContext->getMetadata()['method'] ?? null,
                'request_uri' => $this->requestContext->getMetadata()['uri'] ?? null,
                'user_agent' => $this->requestContext->getMetadata()['user_agent'] ?? null,
                'is_ajax' => $this->requestContext->getMetadata()['is_ajax'] ?? false
            ],
            'performance' => $this->performanceContext->getMetrics(),
            'system_info' => [
                'environment' => config('app.env'),
                'php_version' => PHP_VERSION,
                'laravel_version' => app()->version(),
                'memory_limit' => ini_get('memory_limit'),
                'max_execution_time' => ini_get('max_execution_time')
            ],
            'application' => 'prohelper'
        ];
    }

    /**
     * Отправить техническую метрику в мониторинг
     */
    protected function sendTechnicalAlert(string $event, array $context, string $level): void
    {
        try {
            if (app()->bound(\App\Services\Monitoring\PrometheusService::class)) {
                $prometheus = app(\App\Services\Monitoring\PrometheusService::class);
                // Используем существующий метод incrementExceptions для technical событий
                $prometheus->incrementExceptions('TechnicalIssue', $event);
            }
        } catch (\Exception $e) {
            // Не прерываем выполнение
        }
    }

    /**
     * Специальные методы для технических событий ProHelper
     */

    public function databaseError(\Throwable $exception, ?string $query = null): void
    {
        $this->log('database.error', [
            'exception_class' => get_class($exception),
            'exception_message' => $exception->getMessage(),
            'exception_code' => $exception->getCode(),
            'has_query' => !empty($query),
            'query_length' => $query ? strlen($query) : 0,
            'file' => $exception->getFile(),
            'line' => $exception->getLine()
        ], 'error');
    }

    public function slowQuery(string $sql, float $executionTimeMs, array $bindings = []): void
    {
        $this->log('database.slow_query', [
            'execution_time_ms' => $executionTimeMs,
            'sql_length' => strlen($sql),
            'bindings_count' => count($bindings),
            'sql_type' => $this->identifyQueryType($sql),
            'has_joins' => stripos($sql, 'JOIN') !== false,
            'has_subqueries' => stripos($sql, 'SELECT') !== strrpos($sql, 'SELECT')
        ], 'warning');
    }

    public function cacheEvent(string $operation, string $key, bool $success = true, ?float $durationMs = null): void
    {
        $this->log("cache.{$operation}", [
            'cache_key' => $key,
            'success' => $success,
            'duration_ms' => $durationMs,
            'key_length' => strlen($key),
            'cache_driver' => config('cache.default')
        ], $success ? 'info' : 'warning');
    }

    public function apiIntegrationError(string $service, \Throwable $exception, ?array $requestData = null): void
    {
        $this->log('integration.api.error', [
            'external_service' => $service,
            'exception_class' => get_class($exception),
            'exception_message' => $exception->getMessage(),
            'http_code' => ($exception instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface) ? $exception->getStatusCode() : null,
            'has_request_data' => !empty($requestData),
            'request_size' => $requestData ? strlen(json_encode($requestData)) : 0
        ], 'error');
    }

    public function s3Operation(string $operation, string $bucket, ?string $key = null, bool $success = true, ?float $durationMs = null): void
    {
        $this->log("s3.{$operation}", [
            'bucket' => $bucket,
            'has_key' => !empty($key),
            'key_length' => $key ? strlen($key) : 0,
            'success' => $success,
            'duration_ms' => $durationMs,
            'organization_id' => $this->userContext->getOrganizationId()
        ], $success ? 'info' : 'warning');
    }

    public function fileProcessing(string $operation, string $filename, bool $success = true, ?array $fileInfo = null): void
    {
        $this->log("file.{$operation}", [
            'filename' => basename($filename),
            'success' => $success,
            'file_size' => $fileInfo['size'] ?? null,
            'file_type' => $fileInfo['type'] ?? null,
            'file_extension' => pathinfo($filename, PATHINFO_EXTENSION),
            'processing_context' => $operation // upload, download, import, export
        ], $success ? 'info' : 'warning');
    }

    public function queueJob(string $jobClass, bool $success = true, ?float $executionTimeMs = null, ?\Throwable $exception = null): void
    {
        $this->log('queue.job.processed', [
            'job_class' => $jobClass,
            'success' => $success,
            'execution_time_ms' => $executionTimeMs,
            'queue_driver' => config('queue.default'),
            'has_exception' => !empty($exception),
            'exception_message' => $exception?->getMessage()
        ], $success ? 'info' : 'error');
    }

    public function performanceAlert(string $metric, $value, $threshold, string $unit = ''): void
    {
        $this->log('performance.alert', [
            'metric' => $metric,
            'current_value' => $value,
            'threshold' => $threshold,
            'unit' => $unit,
            'severity' => $this->calculatePerformanceSeverity($value, $threshold),
            'interface' => $this->requestContext->getClientInterface()
        ], 'warning');
    }

    public function memoryLimit(float $currentMb, float $limitMb, ?string $context = null): void
    {
        $this->log('system.memory_limit_approached', [
            'current_memory_mb' => $currentMb,
            'memory_limit_mb' => $limitMb,
            'usage_percentage' => round(($currentMb / $limitMb) * 100, 2),
            'context' => $context,
            'peak_memory_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2)
        ], $currentMb > $limitMb * 0.9 ? 'error' : 'warning');
    }

    public function configurationError(string $configKey, $expectedType = null, $actualValue = null): void
    {
        $this->log('system.configuration_error', [
            'config_key' => $configKey,
            'expected_type' => $expectedType,
            'actual_type' => gettype($actualValue),
            'has_value' => !empty($actualValue),
            'environment' => config('app.env')
        ], 'error');
    }

    public function moduleDependencyIssue(string $moduleSlug, array $missingDependencies = []): void
    {
        $this->log('module.dependency_issue', [
            'module_slug' => $moduleSlug,
            'missing_dependencies' => $missingDependencies,
            'dependencies_count' => count($missingDependencies),
            'organization_id' => $this->userContext->getOrganizationId()
        ], 'warning');
    }

    public function migrationEvent(string $migration, string $direction, bool $success = true, ?float $durationMs = null): void
    {
        $this->log("migration.{$direction}", [
            'migration_name' => $migration,
            'direction' => $direction, // up, down
            'success' => $success,
            'duration_ms' => $durationMs,
            'environment' => config('app.env')
        ], $success ? 'info' : 'error');
    }

    /**
     * Вспомогательные методы
     */

    protected function identifyQueryType(string $sql): string
    {
        $sql = strtoupper(trim($sql));
        
        if (str_starts_with($sql, 'SELECT')) return 'SELECT';
        if (str_starts_with($sql, 'INSERT')) return 'INSERT';
        if (str_starts_with($sql, 'UPDATE')) return 'UPDATE';
        if (str_starts_with($sql, 'DELETE')) return 'DELETE';
        if (str_starts_with($sql, 'CREATE')) return 'CREATE';
        if (str_starts_with($sql, 'ALTER')) return 'ALTER';
        if (str_starts_with($sql, 'DROP')) return 'DROP';
        
        return 'OTHER';
    }

    protected function calculatePerformanceSeverity($current, $threshold): string
    {
        if ($current > $threshold * 2) {
            return 'critical';
        } elseif ($current > $threshold * 1.5) {
            return 'high';
        } elseif ($current > $threshold) {
            return 'medium';
        }
        
        return 'low';
    }
}
