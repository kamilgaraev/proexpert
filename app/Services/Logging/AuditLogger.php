<?php

namespace App\Services\Logging;

use App\Services\Logging\Context\RequestContext;
use App\Services\Logging\Context\UserContext;
use App\Services\Logging\Context\PerformanceContext;
use Illuminate\Support\Facades\Log;

class AuditLogger
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
     * Логировать событие аудита
     */
    public function log(string $event, array $context = []): void
    {
        $auditEntry = $this->createAuditEntry($event, $context);
        
        // Логировать в специальный канал для аудита
        Log::channel('audit')->info($event, $auditEntry);
        
        // В production также дублировать в основной лог
        if (config('app.env') === 'production') {
            Log::info("[AUDIT] {$event}", $auditEntry);
        }
    }

    /**
     * Создать запись аудита
     */
    protected function createAuditEntry(string $event, array $context): array
    {
        return [
            'timestamp' => now()->toISOString(),
            'level' => 'INFO',
            'category' => 'AUDIT',
            'event' => $event,
            'correlation_id' => $this->requestContext->getCorrelationId(),
            'user_id' => $this->userContext->getUserId(),
            'organization_id' => $this->userContext->getOrganizationId(),
            'user_type' => $this->userContext->getUserType(),
            'user_roles' => $this->userContext->getRolesSafe(),
            'context' => $this->sanitizeAuditContext($context),
            'metadata' => [
                'ip' => $this->requestContext->getMetadata()['ip'] ?? null,
                'user_agent' => $this->requestContext->getMetadata()['user_agent'] ?? null,
                'interface' => $this->requestContext->getClientInterface(),
                'api_version' => $this->requestContext->getMetadata()['api_version'] ?? null
            ],
            'performance' => $this->performanceContext->getBasicMetrics(),
            'environment' => config('app.env'),
            'application' => 'prohelper',
            'audit_version' => '1.0'
        ];
    }

    /**
     * Очистить контекст от конфиденциальных данных для аудита
     */
    protected function sanitizeAuditContext(array $context): array
    {
        $sanitized = $context;
        
        // Поля, которые нужно исключить из аудит логов
        $sensitiveFields = [
            'password', 'password_confirmation', 'token', 'api_key', 
            'secret', 'private_key', 'credit_card', 'card_number',
            'phone', 'email', 'full_name', 'address', 'passport'
        ];

        // Рекурсивно удалить чувствительные данные
        $sanitized = $this->recursivelyRemoveSensitiveData($sanitized, $sensitiveFields);

        // Добавить информацию о том, какие поля были изменены (для CRUD операций)
        if (isset($context['changes']) && is_array($context['changes'])) {
            $sanitized['fields_changed'] = array_keys($context['changes']);
            $sanitized['changes_count'] = count($context['changes']);
        }

        return $sanitized;
    }

    /**
     * Рекурсивно удалить чувствительные данные
     */
    protected function recursivelyRemoveSensitiveData(array $data, array $sensitiveFields): array
    {
        foreach ($data as $key => $value) {
            if (in_array(strtolower($key), $sensitiveFields)) {
                $data[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $data[$key] = $this->recursivelyRemoveSensitiveData($value, $sensitiveFields);
            } elseif (is_string($value) && $this->containsSensitiveData($value)) {
                $data[$key] = '[REDACTED]';
            }
        }

        return $data;
    }

    /**
     * Проверить, содержит ли строка чувствительные данные
     */
    protected function containsSensitiveData(string $value): bool
    {
        $patterns = [
            '/\b\d{16}\b/',  // Номер карты
            '/\b\d{4}\s\d{4}\s\d{4}\s\d{4}\b/', // Номер карты с пробелами
            '/\b[A-Za-z0-9]{32,}\b/', // Длинные токены
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Специальные методы для частых audit событий
     */

    public function userRegistered(int $userId, array $userData = []): void
    {
        $this->log('user.registration.completed', [
            'target_user_id' => $userId,
            'registration_type' => $userData['type'] ?? 'standard',
            'organization_id' => $userData['organization_id'] ?? null,
            'invitation_used' => !empty($userData['invitation_id'])
        ]);
    }

    public function userLogin(int $userId, bool $success = true, ?string $reason = null): void
    {
        $event = $success ? 'user.login.success' : 'user.login.failed';
        
        $this->log($event, [
            'target_user_id' => $userId,
            'success' => $success,
            'failure_reason' => $reason,
            'interface' => $this->requestContext->getClientInterface()
        ]);
    }

    public function dataChanged(string $model, int $recordId, array $changes = []): void
    {
        $modelName = class_basename($model);
        
        $this->log("data.{$modelName}.updated", [
            'model' => $modelName,
            'record_id' => $recordId,
            'changes' => $this->sanitizeChanges($changes),
            'fields_changed' => array_keys($changes)
        ]);
    }

    public function dataCreated(string $model, int $recordId, array $attributes = []): void
    {
        $modelName = class_basename($model);
        
        $this->log("data.{$modelName}.created", [
            'model' => $modelName,
            'record_id' => $recordId,
            'fields_set' => array_keys($this->sanitizeChanges($attributes))
        ]);
    }

    public function dataDeleted(string $model, int $recordId): void
    {
        $modelName = class_basename($model);
        
        $this->log("data.{$modelName}.deleted", [
            'model' => $modelName,
            'record_id' => $recordId,
            'soft_delete' => method_exists($model, 'trashed')
        ]);
    }

    public function permissionGranted(int $targetUserId, string $permission, ?string $context = null): void
    {
        $this->log('permission.granted', [
            'target_user_id' => $targetUserId,
            'permission' => $permission,
            'context' => $context,
            'granted_by' => $this->userContext->getUserId()
        ]);
    }

    public function permissionRevoked(int $targetUserId, string $permission, ?string $context = null): void
    {
        $this->log('permission.revoked', [
            'target_user_id' => $targetUserId,
            'permission' => $permission,
            'context' => $context,
            'revoked_by' => $this->userContext->getUserId()
        ]);
    }

    /**
     * Очистить изменения от чувствительных данных
     */
    protected function sanitizeChanges(array $changes): array
    {
        $sensitiveFields = ['password', 'password_confirmation', 'token', 'secret'];
        
        foreach ($changes as $field => $value) {
            if (in_array($field, $sensitiveFields)) {
                $changes[$field] = '[REDACTED]';
            }
        }

        return $changes;
    }
}
