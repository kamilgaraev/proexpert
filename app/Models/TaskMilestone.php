<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Enums\Schedule\PriorityEnum;

class TaskMilestone extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'task_id',
        'schedule_id',
        'organization_id',
        'created_by_user_id',
        'name',
        'description',
        'milestone_type',
        'target_date',
        'baseline_date',
        'actual_date',
        'status',
        'priority',
        'is_critical',
        'is_external',
        'completion_criteria',
        'completion_percent',
        'responsible_user_id',
        'stakeholders',
        'deliverables',
        'approvals_required',
        'notification_settings',
        'alert_days_before',
        'risk_level',
        'risk_description',
        'mitigation_plan',
        'budget_impact',
        'triggers_payment',
        'payment_amount',
        'notes',
        'custom_fields',
        'tags',
        'external_id',
        'external_data',
    ];

    protected $casts = [
        'target_date' => 'date',
        'baseline_date' => 'date',
        'actual_date' => 'date',
        'is_critical' => 'boolean',
        'is_external' => 'boolean',
        'triggers_payment' => 'boolean',
        'completion_criteria' => 'array',
        'completion_percent' => 'decimal:2',
        'stakeholders' => 'array',
        'deliverables' => 'array',
        'approvals_required' => 'array',
        'notification_settings' => 'array',
        'budget_impact' => 'decimal:2',
        'payment_amount' => 'decimal:2',
        'priority' => PriorityEnum::class,
        'custom_fields' => 'array',
        'tags' => 'array',
        'external_data' => 'array',
    ];

    // === RELATIONSHIPS ===

    public function task(): BelongsTo
    {
        return $this->belongsTo(ScheduleTask::class, 'task_id');
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(ProjectSchedule::class, 'schedule_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function responsibleUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_user_id');
    }

    // === COMPUTED PROPERTIES ===

    public function getVarianceDaysAttribute(): ?int
    {
        if (!$this->baseline_date) {
            return null;
        }

        $compareDate = $this->actual_date ?? $this->target_date;
        return $this->baseline_date->diffInDays($compareDate, false);
    }

    public function getDaysUntilTargetAttribute(): int
    {
        return now()->diffInDays($this->target_date, false);
    }

    public function getIsOverdueAttribute(): bool
    {
        return $this->target_date->isPast() && $this->status !== 'achieved';
    }

    public function getHealthStatusAttribute(): string
    {
        if ($this->status === 'achieved') {
            return 'achieved';
        }

        if ($this->status === 'missed') {
            return 'missed';
        }

        if ($this->status === 'cancelled') {
            return 'cancelled';
        }

        $daysUntilTarget = $this->getDaysUntilTargetAttribute();
        
        if ($daysUntilTarget < 0) {
            return 'overdue';
        }

        if ($daysUntilTarget <= $this->alert_days_before) {
            return 'warning';
        }

        if ($this->risk_level === 'high' || $this->risk_level === 'critical') {
            return 'at_risk';
        }

        return 'on_track';
    }

    public function getRiskScoreAttribute(): int
    {
        $score = 0;

        // Риск по времени
        $daysUntilTarget = $this->getDaysUntilTargetAttribute();
        if ($daysUntilTarget < 0) {
            $score += 50; // Просрочена
        } elseif ($daysUntilTarget <= 3) {
            $score += 30; // Критическая близость
        } elseif ($daysUntilTarget <= 7) {
            $score += 15; // Близко к дедлайну
        }

        // Риск по прогрессу
        if ($this->completion_percent < 50 && $daysUntilTarget <= 7) {
            $score += 25;
        }

        // Внешняя зависимость
        if ($this->is_external) {
            $score += 10;
        }

        // Критичность
        if ($this->is_critical) {
            $score += 15;
        }

        // Приоритет
        $score += match($this->priority) {
            PriorityEnum::CRITICAL => 20,
            PriorityEnum::HIGH => 10,
            PriorityEnum::NORMAL => 5,
            PriorityEnum::LOW => 0,
        };

        return min($score, 100);
    }

    // === BUSINESS METHODS ===

    public function achieve(?string $notes = null): bool
    {
        if ($this->status === 'achieved') {
            return true;
        }

        if (!$this->canBeAchieved()) {
            return false;
        }

        $this->update([
            'status' => 'achieved',
            'actual_date' => now()->toDateString(),
            'completion_percent' => 100,
            'notes' => $notes ? ($this->notes . "\n" . $notes) : $this->notes,
        ]);

        // Если веха связана с платежом, создаем запись
        if ($this->triggers_payment && $this->payment_amount) {
            $this->triggerPayment();
        }

        // Уведомляем заинтересованные стороны
        $this->notifyStakeholders('achieved');

        return true;
    }

    public function miss(?string $reason = null): bool
    {
        if ($this->status === 'achieved' || $this->status === 'missed') {
            return false;
        }

        $this->update([
            'status' => 'missed',
            'actual_date' => now()->toDateString(),
            'notes' => $reason ? ($this->notes . "\nПропущена: " . $reason) : $this->notes,
        ]);

        // Уведомляем заинтересованные стороны
        $this->notifyStakeholders('missed');

        return true;
    }

    public function updateProgress(float $percent): bool
    {
        if ($percent < 0 || $percent > 100) {
            return false;
        }

        $oldPercent = $this->completion_percent;
        $this->update(['completion_percent' => $percent]);

        // Автоматически достигаем веху при 100%
        if ($percent == 100 && $this->status !== 'achieved') {
            $this->achieve('Автоматически достигнута при 100% прогресса');
        }

        // Меняем статус на "в работе" если началось выполнение
        if ($percent > 0 && $this->status === 'pending') {
            $this->update(['status' => 'in_progress']);
        }

        return true;
    }

    public function reschedule(string $newTargetDate, ?string $reason = null): bool
    {
        if ($this->status === 'achieved' || $this->status === 'cancelled') {
            return false;
        }

        $oldDate = $this->target_date;
        $this->update([
            'target_date' => $newTargetDate,
            'notes' => $reason ? 
                ($this->notes . "\nПеренесена с {$oldDate} на {$newTargetDate}: {$reason}") : 
                $this->notes,
        ]);

        // Уведомляем о переносе
        $this->notifyStakeholders('rescheduled', [
            'old_date' => $oldDate,
            'new_date' => $newTargetDate,
            'reason' => $reason,
        ]);

        return true;
    }

    protected function canBeAchieved(): bool
    {
        // Проверяем критерии завершения
        $criteria = $this->completion_criteria ?? [];
        
        foreach ($criteria as $criterion) {
            if (!$this->checkCriterion($criterion)) {
                return false;
            }
        }

        // Проверяем необходимые согласования
        $approvals = $this->approvals_required ?? [];
        
        foreach ($approvals as $approval) {
            if (!$this->checkApproval($approval)) {
                return false;
            }
        }

        return true;
    }

    protected function checkCriterion(array $criterion): bool
    {
        // Логика проверки конкретного критерия
        // Это может быть проверка статуса задач, наличия документов и т.д.
        return true; // Заглушка для сложной логики
    }

    protected function checkApproval(array $approval): bool
    {
        // Логика проверки согласования
        return true; // Заглушка для сложной логики
    }

    protected function triggerPayment(): void
    {
        // Логика создания записи о платеже
        // Можно интегрировать с существующей системой платежей
    }

    protected function notifyStakeholders(string $event, array $data = []): void
    {
        // Логика уведомления заинтересованных сторон
        // Можно отправлять email, push-уведомления и т.д.
    }

    public function sendAlerts(): bool
    {
        if ($this->alert_days_before <= 0) {
            return false;
        }

        $daysUntilTarget = $this->getDaysUntilTargetAttribute();
        
        if ($daysUntilTarget <= $this->alert_days_before && $daysUntilTarget > 0) {
            $this->notifyStakeholders('alert', [
                'days_remaining' => $daysUntilTarget,
                'milestone_name' => $this->name,
                'target_date' => $this->target_date,
            ]);
            
            return true;
        }

        return false;
    }

    // === SCOPES ===

    public function scopeUpcoming($query, int $days = 30)
    {
        return $query->where('target_date', '>=', now())
                    ->where('target_date', '<=', now()->addDays($days))
                    ->whereNotIn('status', ['achieved', 'cancelled']);
    }

    public function scopeOverdue($query)
    {
        return $query->where('target_date', '<', now())
                    ->whereNotIn('status', ['achieved', 'cancelled']);
    }

    public function scopeCritical($query)
    {
        return $query->where('is_critical', true);
    }

    public function scopeByType($query, string $type)
    {
        return $query->where('milestone_type', $type);
    }

    public function scopeTriggersPayment($query)
    {
        return $query->where('triggers_payment', true);
    }

    public function scopeForUser($query, int $userId)
    {
        return $query->where('responsible_user_id', $userId);
    }

    public function scopeAtRisk($query)
    {
        return $query->whereIn('risk_level', ['high', 'critical'])
                    ->orWhere('target_date', '<=', now()->addDays(7))
                    ->whereNotIn('status', ['achieved', 'cancelled']);
    }
} 