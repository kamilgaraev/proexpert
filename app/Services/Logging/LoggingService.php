<?php

namespace App\Services\Logging;

use App\Services\Logging\Context\RequestContext;
use App\Services\Logging\Context\UserContext;
use App\Services\Logging\Context\PerformanceContext;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class LoggingService
{
    protected RequestContext $requestContext;
    protected UserContext $userContext;
    protected PerformanceContext $performanceContext;
    
    protected AuditLogger $auditLogger;
    protected BusinessLogger $businessLogger;
    protected SecurityLogger $securityLogger;
    protected TechnicalLogger $technicalLogger;
    protected AccessLogger $accessLogger;

    public function __construct(
        RequestContext $requestContext,
        UserContext $userContext,
        PerformanceContext $performanceContext,
        AuditLogger $auditLogger,
        BusinessLogger $businessLogger,
        SecurityLogger $securityLogger,
        TechnicalLogger $technicalLogger,
        AccessLogger $accessLogger
    ) {
        $this->requestContext = $requestContext;
        $this->userContext = $userContext;
        $this->performanceContext = $performanceContext;
        $this->auditLogger = $auditLogger;
        $this->businessLogger = $businessLogger;
        $this->securityLogger = $securityLogger;
        $this->technicalLogger = $technicalLogger;
        $this->accessLogger = $accessLogger;
    }

    /**
     * Создать базовую структуру лога с контекстом
     */
    public function createLogEntry(string $level, string $category, string $event, array $context = []): array
    {
        return [
            'timestamp' => now()->toISOString(),
            'level' => strtoupper($level),
            'category' => strtoupper($category),
            'event' => $event,
            'correlation_id' => $this->requestContext->getCorrelationId(),
            'user_id' => $this->userContext->getUserId(),
            'organization_id' => $this->userContext->getOrganizationId(),
            'context' => $context,
            'metadata' => $this->requestContext->getMetadata(),
            'performance' => $this->performanceContext->getMetrics(),
            'environment' => config('app.env'),
            'application' => 'prohelper'
        ];
    }

    /**
     * Логирование аудита (GDPR/SOX compliance)
     */
    public function audit(string $event, array $context = []): void
    {
        $this->auditLogger->log($event, $context);
    }

    /**
     * Логирование бизнес-событий
     */
    public function business(string $event, array $context = []): void
    {
        $this->businessLogger->log($event, $context);
    }

    /**
     * Логирование событий безопасности
     */
    public function security(string $event, array $context = [], string $level = 'warning'): void
    {
        $this->securityLogger->log($event, $context, $level);
    }

    /**
     * Логирование технических событий
     */
    public function technical(string $event, array $context = [], string $level = 'info'): void
    {
        $this->technicalLogger->log($event, $context, $level);
    }

    /**
     * Логирование доступа к API
     */
    public function access(array $requestData, array $responseData = []): void
    {
        $this->accessLogger->logRequest($requestData, $responseData);
    }

    /**
     * Быстрое логирование критической ошибки
     */
    public function critical(string $message, array $context = [], ?\Throwable $exception = null): void
    {
        $logEntry = $this->createLogEntry('critical', 'technical', 'system.critical_error', [
            'message' => $message,
            'context' => $context
        ]);

        if ($exception) {
            $logEntry['exception'] = [
                'class' => get_class($exception),
                'message' => $exception->getMessage(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => $exception->getTraceAsString()
            ];
        }

        Log::channel('single')->critical($message, $logEntry);
        
        // Отправить уведомление в мониторинг
        if (app()->bound(\App\Services\Monitoring\PrometheusService::class)) {
            app(\App\Services\Monitoring\PrometheusService::class)->incrementExceptions('critical_error');
        }
    }

    /**
     * Логирование с автоматическим определением категории по event
     */
    public function event(string $event, array $context = [], string $level = 'info'): void
    {
        $category = $this->determineCategoryByEvent($event);
        
        switch ($category) {
            case 'audit':
                $this->audit($event, $context);
                break;
            case 'business':
                $this->business($event, $context);
                break;
            case 'security':
                $this->security($event, $context, $level);
                break;
            default:
                $this->technical($event, $context, $level);
        }
    }

    /**
     * Определить категорию логирования на основе события
     */
    protected function determineCategoryByEvent(string $event): string
    {
        // Паттерны для автоматического определения категории
        $patterns = [
            'audit' => ['created', 'updated', 'deleted', 'changed', 'modified'],
            'business' => ['registration', 'payment', 'subscription', 'conversion', 'usage'],
            'security' => ['login', 'logout', 'permission', 'access', 'auth', 'intrusion', 'suspicious'],
        ];

        foreach ($patterns as $category => $keywords) {
            foreach ($keywords as $keyword) {
                if (Str::contains(strtolower($event), $keyword)) {
                    return $category;
                }
            }
        }

        return 'technical';
    }

    /**
     * Получить текущий контекст для внешнего использования
     */
    public function getContext(): array
    {
        return [
            'correlation_id' => $this->requestContext->getCorrelationId(),
            'user_id' => $this->userContext->getUserId(),
            'organization_id' => $this->userContext->getOrganizationId(),
            'metadata' => $this->requestContext->getMetadata(),
            'performance' => $this->performanceContext->getMetrics()
        ];
    }
}
