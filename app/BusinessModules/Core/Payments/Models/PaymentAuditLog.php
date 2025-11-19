<?php

namespace App\BusinessModules\Core\Payments\Models;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentAuditLog extends Model
{
    const UPDATED_AT = null; // Только created_at

    protected $fillable = [
        'payment_document_id',
        'organization_id',
        'action',
        'entity_type',
        'entity_id',
        'user_id',
        'user_name',
        'user_role',
        'old_values',
        'new_values',
        'changed_fields',
        'ip_address',
        'user_agent',
        'description',
        'metadata',
    ];

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
        'changed_fields' => 'array',
        'metadata' => 'array',
        'created_at' => 'datetime',
    ];

    // ==========================================
    // RELATIONSHIPS
    // ==========================================

    public function paymentDocument(): BelongsTo
    {
        return $this->belongsTo(PaymentDocument::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // ==========================================
    // SCOPES
    // ==========================================

    public function scopeForDocument($query, int $documentId)
    {
        return $query->where('payment_document_id', $documentId);
    }

    public function scopeForOrganization($query, int $organizationId)
    {
        return $query->where('organization_id', $organizationId);
    }

    public function scopeByAction($query, string $action)
    {
        return $query->where('action', $action);
    }

    public function scopeByUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    // ==========================================
    // HELPERS
    // ==========================================

    /**
     * Получить человекочитаемое название действия
     */
    public function getActionLabel(): string
    {
        return match($this->action) {
            'created' => 'Создан',
            'updated' => 'Обновлен',
            'submitted' => 'Отправлен на утверждение',
            'approved' => 'Утвержден',
            'rejected' => 'Отклонен',
            'scheduled' => 'Запланирован',
            'paid' => 'Оплачен',
            'partially_paid' => 'Частично оплачен',
            'cancelled' => 'Отменен',
            'deleted' => 'Удален',
            default => $this->action,
        };
    }

    /**
     * Получить форматированное описание изменений
     */
    public function getChangesDescription(): string
    {
        if (empty($this->changed_fields)) {
            return $this->description ?? '';
        }

        $changes = [];
        foreach ($this->changed_fields as $field) {
            $oldValue = $this->old_values[$field] ?? 'не задано';
            $newValue = $this->new_values[$field] ?? 'не задано';
            
            $fieldLabel = $this->getFieldLabel($field);
            $changes[] = "{$fieldLabel}: '{$oldValue}' → '{$newValue}'";
        }

        return implode(', ', $changes);
    }

    /**
     * Получить человекочитаемое название поля
     */
    private function getFieldLabel(string $field): string
    {
        return match($field) {
            'status' => 'Статус',
            'amount' => 'Сумма',
            'paid_amount' => 'Оплачено',
            'remaining_amount' => 'Остаток',
            'due_date' => 'Срок оплаты',
            'description' => 'Описание',
            'payment_purpose' => 'Назначение платежа',
            'bank_account' => 'Расчетный счет',
            default => $field,
        };
    }
}

