<?php

namespace App\BusinessModules\Features\Procurement\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use App\Models\Organization;
use App\Models\User;

/**
 * Модель audit log для модуля закупок
 * 
 * Отслеживает все изменения в сущностях:
 * PurchaseRequest, PurchaseOrder, SupplierProposal
 */
class ProcurementAuditLog extends Model
{
    protected $table = 'procurement_audit_logs';

    protected $fillable = [
        'organization_id',
        'user_id',
        'auditable_type',
        'auditable_id',
        'action',
        'old_values',
        'new_values',
        'ip_address',
        'user_agent',
        'notes',
    ];

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
    ];

    // ============================================
    // RELATIONSHIPS
    // ============================================

    /**
     * Организация
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Пользователь, выполнивший действие
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Polymorphic связь с сущностью
     */
    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }

    // ============================================
    // HELPER METHODS
    // ============================================

    /**
     * Создать запись audit log
     */
    public static function logAction(
        Model $model,
        string $action,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?int $userId = null,
        ?string $notes = null
    ): self {
        return self::create([
            'organization_id' => $model->organization_id,
            'user_id' => $userId ?? auth()->id(),
            'auditable_type' => get_class($model),
            'auditable_id' => $model->id,
            'action' => $action,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'notes' => $notes,
        ]);
    }

    /**
     * Получить изменения в читаемом формате
     */
    public function getChanges(): array
    {
        $changes = [];

        if ($this->old_values && $this->new_values) {
            foreach ($this->new_values as $key => $newValue) {
                $oldValue = $this->old_values[$key] ?? null;
                if ($oldValue != $newValue) {
                    $changes[$key] = [
                        'old' => $oldValue,
                        'new' => $newValue,
                    ];
                }
            }
        }

        return $changes;
    }
}

