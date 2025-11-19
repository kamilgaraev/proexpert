<?php

namespace App\BusinessModules\Core\Payments\Models;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentApproval extends Model
{
    use HasFactory;

    protected $fillable = [
        'payment_document_id',
        'organization_id',
        'approval_role',
        'approver_user_id',
        'approval_level',
        'approval_order',
        'status',
        'amount_threshold',
        'conditions',
        'decision_comment',
        'decided_at',
        'notified_at',
        'reminder_count',
        'last_reminder_at',
    ];

    protected $casts = [
        'amount_threshold' => 'decimal:2',
        'conditions' => 'array',
        'decided_at' => 'datetime',
        'notified_at' => 'datetime',
        'last_reminder_at' => 'datetime',
    ];

    protected $attributes = [
        'status' => 'pending',
        'approval_level' => 1,
        'approval_order' => 1,
        'reminder_count' => 0,
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

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_user_id');
    }

    // ==========================================
    // SCOPES
    // ==========================================

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }

    public function scopeRejected($query)
    {
        return $query->where('status', 'rejected');
    }

    public function scopeForUser($query, int $userId)
    {
        return $query->where('approver_user_id', $userId);
    }

    public function scopeForRole($query, string $role)
    {
        return $query->where('approval_role', $role);
    }

    public function scopeByLevel($query, int $level)
    {
        return $query->where('approval_level', $level);
    }

    // ==========================================
    // HELPERS
    // ==========================================

    /**
     * Утвердить
     */
    public function approve(string $comment = null): bool
    {
        $this->status = 'approved';
        $this->decision_comment = $comment;
        $this->decided_at = now();
        
        return $this->save();
    }

    /**
     * Отклонить
     */
    public function reject(string $comment): bool
    {
        $this->status = 'rejected';
        $this->decision_comment = $comment;
        $this->decided_at = now();
        
        return $this->save();
    }

    /**
     * Пропустить (для необязательных утверждений)
     */
    public function skip(string $reason = null): bool
    {
        $this->status = 'skipped';
        $this->decision_comment = $reason;
        $this->decided_at = now();
        
        return $this->save();
    }

    /**
     * Отправлено ли уведомление
     */
    public function isNotified(): bool
    {
        return $this->notified_at !== null;
    }

    /**
     * Можно ли отправить напоминание
     */
    public function canSendReminder(): bool
    {
        if ($this->status !== 'pending') {
            return false;
        }

        // Если напоминаний еще не было
        if (!$this->last_reminder_at) {
            return true;
        }

        // Если прошло более 24 часов с последнего напоминания
        return $this->last_reminder_at->diffInHours(now()) >= 24;
    }

    /**
     * Отметить отправку уведомления
     */
    public function markAsNotified(): bool
    {
        $this->notified_at = now();
        return $this->save();
    }

    /**
     * Отметить отправку напоминания
     */
    public function markReminderSent(): bool
    {
        $this->reminder_count++;
        $this->last_reminder_at = now();
        return $this->save();
    }

    /**
     * Проверить, может ли пользователь утвердить данную сумму
     */
    public function canApproveAmount(float $amount): bool
    {
        if ($this->amount_threshold === null) {
            return true; // нет лимита
        }

        return $amount <= $this->amount_threshold;
    }

    /**
     * Проверить условия утверждения
     */
    public function checkConditions(PaymentDocument $document): bool
    {
        if (!$this->conditions || empty($this->conditions)) {
            return true; // нет условий
        }

        // Проверка лимита суммы
        if (isset($this->conditions['max_amount'])) {
            if ($document->amount > $this->conditions['max_amount']) {
                return false;
            }
        }

        // Проверка типов документов
        if (isset($this->conditions['allowed_types'])) {
            if (!in_array($document->document_type->value, $this->conditions['allowed_types'])) {
                return false;
            }
        }

        // Проверка проектов
        if (isset($this->conditions['allowed_projects'])) {
            if (!in_array($document->project_id, $this->conditions['allowed_projects'])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Получить читаемое название роли
     */
    public function getRoleLabel(): string
    {
        return match($this->approval_role) {
            'financial_director' => 'Финансовый директор',
            'chief_accountant' => 'Главный бухгалтер',
            'accountant' => 'Бухгалтер',
            'project_manager' => 'Руководитель проекта',
            'department_head' => 'Начальник отдела',
            'general_director' => 'Генеральный директор',
            default => $this->approval_role,
        };
    }

    /**
     * Получить читаемый статус
     */
    public function getStatusLabel(): string
    {
        return match($this->status) {
            'pending' => 'Ожидает',
            'approved' => 'Утверждено',
            'rejected' => 'Отклонено',
            'skipped' => 'Пропущено',
            default => $this->status,
        };
    }
}

