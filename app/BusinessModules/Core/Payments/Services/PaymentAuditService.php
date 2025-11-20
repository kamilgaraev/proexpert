<?php

namespace App\BusinessModules\Core\Payments\Services;

use App\BusinessModules\Core\Payments\Models\PaymentAuditLog;
use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Сервис для логирования изменений платежных документов
 */
class PaymentAuditService
{
    /**
     * Залогировать действие
     */
    public function log(
        string $action,
        Model $entity,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?string $description = null
    ): PaymentAuditLog {
        $user = Auth::user();
        $request = request();

        $changedFields = $this->detectChangedFields($oldValues, $newValues);

        $documentId = null;
        $organizationId = null;

        if ($entity instanceof PaymentDocument) {
            $documentId = $entity->id;
            $organizationId = $entity->organization_id;
        }

        return PaymentAuditLog::create([
            'payment_document_id' => $documentId,
            'organization_id' => $organizationId,
            'action' => $action,
            'entity_type' => get_class($entity),
            'entity_id' => $entity->id,
            'user_id' => $user?->id,
            'user_name' => $user?->name,
            'user_role' => $this->getUserRole($user),
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'changed_fields' => $changedFields,
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
            'description' => $description ?? $this->generateDescription($action, $entity, $changedFields),
            'metadata' => [
                'timestamp' => now()->toDateTimeString(),
                'request_url' => $request?->fullUrl(),
            ],
        ]);
    }

    /**
     * Залогировать создание
     */
    public function logCreated(PaymentDocument $document): PaymentAuditLog
    {
        return $this->log(
            'created',
            $document,
            null,
            $document->toArray(),
            "Создан документ №{$document->document_number}"
        );
    }

    /**
     * Залогировать обновление
     */
    public function logUpdated(PaymentDocument $document, array $changes): PaymentAuditLog
    {
        $oldValues = [];
        $newValues = [];

        foreach ($changes as $field => $values) {
            if (is_array($values) && count($values) === 2) {
                $oldValues[$field] = $values[0];
                $newValues[$field] = $values[1];
            }
        }

        return $this->log(
            'updated',
            $document,
            $oldValues,
            $newValues,
            "Обновлен документ №{$document->document_number}"
        );
    }

    /**
     * Залогировать отправку на утверждение
     */
    public function logSubmitted(PaymentDocument $document): PaymentAuditLog
    {
        return $this->log(
            'submitted',
            $document,
            ['status' => 'draft'],
            ['status' => $document->status->value],
            "Документ №{$document->document_number} отправлен на утверждение"
        );
    }

    /**
     * Залогировать утверждение
     */
    public function logApproved(PaymentDocument $document, int $approvedByUserId): PaymentAuditLog
    {
        $user = \App\Models\User::find($approvedByUserId);
        
        return $this->log(
            'approved',
            $document,
            ['status' => 'pending_approval'],
            ['status' => 'approved'],
            "Документ №{$document->document_number} утвержден пользователем {$user?->name}"
        );
    }

    /**
     * Залогировать отклонение
     */
    public function logRejected(PaymentDocument $document, string $reason, int $rejectedByUserId): PaymentAuditLog
    {
        $user = \App\Models\User::find($rejectedByUserId);
        
        return $this->log(
            'rejected',
            $document,
            ['status' => 'pending_approval'],
            ['status' => 'rejected'],
            "Документ №{$document->document_number} отклонен пользователем {$user?->name}. Причина: {$reason}"
        );
    }

    /**
     * Залогировать оплату
     */
    public function logPaid(PaymentDocument $document, float $amount): PaymentAuditLog
    {
        return $this->log(
            'paid',
            $document,
            [
                'paid_amount' => $document->paid_amount - $amount,
                'status' => 'approved',
            ],
            [
                'paid_amount' => $document->paid_amount,
                'status' => $document->status->value,
            ],
            "Зарегистрирован платеж на сумму {$amount} по документу №{$document->document_number}"
        );
    }

    /**
     * Залогировать отмену
     */
    public function logCancelled(PaymentDocument $document, string $reason): PaymentAuditLog
    {
        return $this->log(
            'cancelled',
            $document,
            ['status' => $document->getOriginal('status')],
            ['status' => 'cancelled'],
            "Документ №{$document->document_number} отменен. Причина: {$reason}"
        );
    }

    /**
     * Получить историю изменений документа
     */
    public function getDocumentHistory(int $documentId): \Illuminate\Support\Collection
    {
        return PaymentAuditLog::forDocument($documentId)
            ->with(['user'])
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * Получить активность пользователя
     */
    public function getUserActivity(int $userId, int $organizationId, int $days = 30): \Illuminate\Support\Collection
    {
        return PaymentAuditLog::forOrganization($organizationId)
            ->byUser($userId)
            ->where('created_at', '>=', now()->subDays($days))
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * Получить последние изменения по организации
     */
    public function getRecentActivity(int $organizationId, int $limit = 50): \Illuminate\Support\Collection
    {
        return PaymentAuditLog::forOrganization($organizationId)
            ->with(['paymentDocument', 'user'])
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
    }

    /**
     * Определить измененные поля
     */
    private function detectChangedFields(?array $oldValues, ?array $newValues): array
    {
        if (!$oldValues || !$newValues) {
            return [];
        }

        $changed = [];
        
        foreach ($newValues as $key => $newValue) {
            $oldValue = $oldValues[$key] ?? null;
            
            if ($oldValue != $newValue) {
                $changed[] = $key;
            }
        }

        return $changed;
    }

    /**
     * Получить роль пользователя
     */
    private function getUserRole($user): ?string
    {
        if (!$user) {
            return null;
        }

        // Получить роли пользователя в организации
        $organizationId = request()->attributes->get('current_organization_id');
        $roleSlugs = $user->getRoleSlugs($organizationId);
        
        // Вернуть первую роль или null
        return !empty($roleSlugs) ? $roleSlugs[0] : null;
    }

    /**
     * Генерировать автоматическое описание
     */
    private function generateDescription(string $action, Model $entity, array $changedFields): string
    {
        $entityType = class_basename($entity);
        $actionLabel = match($action) {
            'created' => 'Создан',
            'updated' => 'Обновлен',
            'deleted' => 'Удален',
            default => $action,
        };

        $description = "{$actionLabel} {$entityType} #{$entity->id}";

        if (!empty($changedFields)) {
            $description .= '. Изменены поля: ' . implode(', ', $changedFields);
        }

        return $description;
    }
}

