<?php

namespace App\Services\Logging;

use App\Services\Logging\Context\RequestContext;
use App\Services\Logging\Context\UserContext;
use App\Services\Logging\Context\PerformanceContext;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class SecurityLogger
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
     * Логировать событие безопасности
     */
    public function log(string $event, array $context = [], string $level = 'warning'): void
    {
        $securityEntry = $this->createSecurityEntry($event, $context, $level);
        
        // Логировать с соответствующим уровнем
        match(strtolower($level)) {
            'critical' => Log::critical("[SECURITY] {$event}", $securityEntry),
            'error' => Log::error("[SECURITY] {$event}", $securityEntry),
            'warning' => Log::warning("[SECURITY] {$event}", $securityEntry),
            default => Log::info("[SECURITY] {$event}", $securityEntry)
        };

        // Критические события безопасности отправляем в мониторинг
        if (in_array($level, ['critical', 'error'])) {
            $this->sendSecurityAlert($event, $context, $level);
        }
    }

    /**
     * Создать запись события безопасности
     */
    protected function createSecurityEntry(string $event, array $context, string $level): array
    {
        $metadata = $this->requestContext->getMetadata();
        
        return [
            'timestamp' => now()->toISOString(),
            'level' => strtoupper($level),
            'category' => 'SECURITY',
            'event' => $event,
            'correlation_id' => $this->requestContext->getCorrelationId(),
            'user_id' => $this->userContext->getUserId(),
            'organization_id' => $this->userContext->getOrganizationId(),
            'context' => $context,
            'security_context' => [
                'ip' => $metadata['ip'] ?? null,
                'user_agent' => $metadata['user_agent'] ?? null,
                'referer' => $metadata['referer'] ?? null,
                'interface' => $this->requestContext->getClientInterface(),
                'is_authenticated' => $this->userContext->isAuthenticated(),
                'user_type' => $this->userContext->getUserType(),
                'user_roles' => $this->userContext->getRolesSafe(),
                'request_method' => $metadata['method'] ?? null,
                'request_uri' => $metadata['uri'] ?? null,
                'is_ajax' => $metadata['is_ajax'] ?? false,
                'is_json' => $metadata['is_json'] ?? false
            ],
            'risk_level' => $this->calculateRiskLevel($event, $context, $level),
            'performance' => $this->performanceContext->getBasicMetrics(),
            'environment' => config('app.env'),
            'application' => 'prohelper'
        ];
    }

    /**
     * Рассчитать уровень риска события
     */
    protected function calculateRiskLevel(string $event, array $context, string $level): string
    {
        // Критические события
        if (in_array($level, ['critical', 'error'])) {
            return 'high';
        }

        // События высокого риска по типу
        $highRiskEvents = [
            'auth.multiple_failures',
            'auth.suspicious_login',
            'permission.elevation_attempt',
            'security.injection_attempt',
            'security.brute_force'
        ];

        if (in_array($event, $highRiskEvents)) {
            return 'high';
        }

        // Средний риск для предупреждений
        if ($level === 'warning') {
            return 'medium';
        }

        return 'low';
    }

    /**
     * Отправить алерт о критическом событии безопасности
     */
    protected function sendSecurityAlert(string $event, array $context, string $level): void
    {
        try {
            if (app()->bound(\App\Services\Monitoring\PrometheusService::class)) {
                $prometheus = app(\App\Services\Monitoring\PrometheusService::class);
                // Используем существующий метод incrementExceptions для security событий
                $prometheus->incrementExceptions('SecurityIncident', $event);
            }
        } catch (\Exception $e) {
            // Не прерываем выполнение
        }
    }

    /**
     * Специальные методы для событий безопасности ProHelper
     */

    public function loginAttempt(bool $success, ?string $email = null, ?string $reason = null): void
    {
        $event = $success ? 'auth.login.success' : 'auth.login.failed';
        $level = $success ? 'info' : 'warning';

        $this->log($event, [
            'success' => $success,
            'email_provided' => !empty($email),
            'failure_reason' => $reason,
            'interface' => $this->requestContext->getClientInterface(),
            'attempt_count' => $this->getRecentLoginAttempts()
        ], $level);
    }

    public function multipleFailedLogins(string $identifier, int $attemptCount, int $timeWindowMinutes = 15): void
    {
        $this->log('auth.multiple_failures', [
            'identifier_type' => filter_var($identifier, FILTER_VALIDATE_EMAIL) ? 'email' : 'other',
            'attempt_count' => $attemptCount,
            'time_window_minutes' => $timeWindowMinutes,
            'interface' => $this->requestContext->getClientInterface()
        ], 'error');
    }

    public function suspiciousLogin(?int $userId = null, array $suspiciousFactors = []): void
    {
        $this->log('auth.suspicious_login', [
            'target_user_id' => $userId,
            'suspicious_factors' => $suspiciousFactors,
            'interface' => $this->requestContext->getClientInterface(),
            'geo_change' => in_array('geo_change', $suspiciousFactors),
            'device_change' => in_array('device_change', $suspiciousFactors),
            'time_pattern_change' => in_array('time_pattern', $suspiciousFactors)
        ], 'warning');
    }

    public function permissionDenied(string $permission, ?string $resource = null): void
    {
        $this->log('auth.permission.denied', [
            'permission' => $permission,
            'resource' => $resource,
            'user_id' => $this->userContext->getUserId(),
            'organization_id' => $this->userContext->getOrganizationId(),
            'user_roles' => $this->userContext->getRolesSafe(),
            'interface' => $this->requestContext->getClientInterface()
        ], 'warning');
    }

    public function unauthorizedApiAccess(string $endpoint, ?string $token = null): void
    {
        $this->log('api.unauthorized_access', [
            'endpoint' => $endpoint,
            'has_token' => !empty($token),
            'token_type' => $this->identifyTokenType($token),
            'api_version' => $this->requestContext->getMetadata()['api_version'] ?? null,
            'interface' => $this->requestContext->getClientInterface()
        ], 'warning');
    }

    public function rateLimitExceeded(string $limitType, int $requests, int $limit): void
    {
        $this->log('security.rate_limit_exceeded', [
            'limit_type' => $limitType,
            'requests_made' => $requests,
            'requests_limit' => $limit,
            'interface' => $this->requestContext->getClientInterface()
        ], 'warning');
    }

    public function dataAccessAttempt(string $resourceType, int $resourceId, bool $authorized = true): void
    {
        if (!$authorized) {
            $this->log('data.unauthorized_access_attempt', [
                'resource_type' => $resourceType,
                'resource_id' => $resourceId,
                'user_id' => $this->userContext->getUserId(),
                'organization_id' => $this->userContext->getOrganizationId(),
                'interface' => $this->requestContext->getClientInterface()
            ], 'warning');
        }
    }

    public function injectionAttempt(string $type, string $payload = null): void
    {
        $this->log('security.injection_attempt', [
            'injection_type' => $type, // sql, xss, command, etc.
            'has_payload' => !empty($payload),
            'payload_length' => $payload ? strlen($payload) : 0,
            'interface' => $this->requestContext->getClientInterface(),
            'blocked' => true // Предполагаем, что атака была заблокирована
        ], 'error');
    }

    public function organizationContextViolation(?int $attemptedOrgId = null): void
    {
        $this->log('security.org_context_violation', [
            'attempted_org_id' => $attemptedOrgId,
            'current_org_id' => $this->userContext->getOrganizationId(),
            'user_id' => $this->userContext->getUserId(),
            'interface' => $this->requestContext->getClientInterface()
        ], 'warning');
    }

    public function fileUploadSecurityIssue(string $filename, string $issue, array $fileInfo = []): void
    {
        $this->log('security.file_upload.issue', [
            'filename' => basename($filename), // Только имя файла, не полный путь
            'security_issue' => $issue,
            'file_size' => $fileInfo['size'] ?? null,
            'file_type' => $fileInfo['type'] ?? null,
            'interface' => $this->requestContext->getClientInterface()
        ], 'warning');
    }

    public function tokenMisuse(string $tokenType, ?string $action = null): void
    {
        $this->log('auth.token.misuse', [
            'token_type' => $tokenType,
            'misuse_action' => $action,
            'interface' => $this->requestContext->getClientInterface()
        ], 'warning');
    }

    /**
     * Вспомогательные методы
     */

    protected function getRecentLoginAttempts(): int
    {
        try {
            $ip = $this->requestContext->getMetadata()['ip'] ?? 'unknown';
            $key = "login_attempts_{$ip}";
            
            return (int) Cache::get($key, 0);
        } catch (\Exception $e) {
            return 0;
        }
    }

    protected function identifyTokenType(?string $token): ?string
    {
        if (!$token) {
            return null;
        }

        if (str_starts_with($token, 'Bearer ')) {
            $token = substr($token, 7);
        }

        // JWT токены обычно имеют 3 части, разделенные точками
        if (substr_count($token, '.') === 2) {
            return 'jwt';
        }

        // API ключи обычно длинные
        if (strlen($token) > 32) {
            return 'api_key';
        }

        // Короткие токены могут быть session токенами
        if (strlen($token) <= 32) {
            return 'session';
        }

        return 'unknown';
    }
}
