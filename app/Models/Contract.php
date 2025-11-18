<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use App\Enums\Contract\ContractStatusEnum;
use App\Enums\Contract\ContractWorkTypeCategoryEnum;
use App\Enums\Contract\GpCalculationTypeEnum;
use Carbon\Carbon;
use App\Traits\HasOnboardingDemo;

class Contract extends Model
{
    use HasFactory, SoftDeletes, HasOnboardingDemo;

    protected $fillable = [
        'organization_id',
        'project_id',
        'contractor_id',
        'number',
        'date',
        'subject',
        'work_type_category',
        'payment_terms',
        'base_amount',
        'total_amount',
        'gp_percentage',
        'gp_calculation_type',
        'gp_coefficient',
        'subcontract_amount',
        'planned_advance_amount',
        'actual_advance_amount',
        'status',
        'start_date',
        'end_date',
        'notes',
        'is_onboarding_demo',
    ];

    protected $casts = [
        'date' => 'date',
        'base_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'gp_percentage' => 'decimal:3',
        'gp_calculation_type' => GpCalculationTypeEnum::class,
        'gp_coefficient' => 'decimal:4',
        'subcontract_amount' => 'decimal:2',
        'planned_advance_amount' => 'decimal:2',
        'actual_advance_amount' => 'decimal:2',
        'start_date' => 'date',
        'end_date' => 'date',
        'status' => ContractStatusEnum::class,
        'work_type_category' => ContractWorkTypeCategoryEnum::class,
        'is_onboarding_demo' => 'boolean',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function contractor(): BelongsTo
    {
        return $this->belongsTo(Contractor::class);
    }

    public function performanceActs(): HasMany
    {
        return $this->hasMany(ContractPerformanceAct::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(ContractPayment::class);
    }

    /**
     * Получить выполненные работы по данному договору.
     */
    public function completedWorks(): HasMany
    {
        return $this->hasMany(CompletedWork::class);
    }

    public function agreements(): HasMany
    {
        return $this->hasMany(SupplementaryAgreement::class);
    }

    public function specifications(): BelongsToMany
    {
        return $this->belongsToMany(Specification::class, 'contract_specification')
                    ->withPivot('attached_at', 'is_active')
                    ->withTimestamps();
    }

    /**
     * Активная спецификация (через pivot)
     */
    public function activeSpecification()
    {
        return $this->specifications()
                    ->wherePivot('is_active', true)
                    ->first();
    }

    /**
     * События состояния договора (Event Sourcing)
     */
    public function stateEvents(): HasMany
    {
        return $this->hasMany(ContractStateEvent::class);
    }

    /**
     * Текущее состояние договора (материализованное представление)
     */
    public function currentState(): HasOne
    {
        return $this->hasOne(ContractCurrentState::class);
    }

    /**
     * Проверка, использует ли договор Event Sourcing (новый) или legacy (старый)
     */
    public function usesEventSourcing(): bool
    {
        return $this->stateEvents()->exists();
    }

    /**
     * Получить базовую сумму контракта (без ГП)
     * Если base_amount не задан, возвращаем total_amount (для legacy контрактов)
     */
    public function getBaseAmountAttribute($value): float
    {
        // Если base_amount явно установлен в БД, возвращаем его
        if ($value !== null) {
            return (float) $value;
        }
        
        // Legacy: для старых контрактов без base_amount используем total_amount
        return (float) ($this->attributes['total_amount'] ?? 0);
    }

    /**
     * Рассчитать сумму ГП (генподрядного процента)
     * ВАЖНО: ГП рассчитывается от base_amount (базовой суммы), а не от total_amount!
     */
    public function getGpAmountAttribute(): float
    {
        $baseAmount = $this->base_amount ?? 0;
        $calculationType = $this->gp_calculation_type ?? GpCalculationTypeEnum::PERCENTAGE;
        
        if ($calculationType === GpCalculationTypeEnum::COEFFICIENT) {
            $coefficient = $this->gp_coefficient ?? 0;
            return round($baseAmount * $coefficient, 2);
        }
        
        $percentage = $this->gp_percentage ?? 0;
        if ($percentage != 0 && $baseAmount > 0) {
            // Расчет: base_amount * (gp_percentage / 100)
            // Например: 7961111.72 * (-0.94 / 100) = -74834.45
            return round(($baseAmount * $percentage) / 100, 2);
        }
        
        return 0.00;
    }

    /**
     * Получить итоговую сумму контракта с учетом генподрядного процента
     * total_amount_with_gp = base_amount + gp_amount
     */
    public function getTotalAmountWithGpAttribute(): float
    {
        $baseAmount = $this->base_amount ?? 0;
        $gpAmount = $this->gp_amount ?? 0;
        return round($baseAmount + $gpAmount, 2);
    }

    /**
     * Получить общую сумму контракта с учетом генподряда и субподряда
     */

    /**
     * Получить остаток планируемого аванса для выплаты
     */
    public function getRemainingAdvanceAmountAttribute(): float
    {
        $planned = $this->planned_advance_amount ?? 0;
        $actual = $this->actual_advance_amount ?? 0;
        return max(0, $planned - $actual);
    }

    /**
     * Проверить, выплачен ли планируемый аванс полностью
     */
    public function getIsAdvanceFullyPaidAttribute(): bool
    {
        $planned = $this->planned_advance_amount ?? 0;
        $actual = $this->actual_advance_amount ?? 0;
        return $planned > 0 && $actual >= $planned;
    }

    /**
     * Получить процент выплаченного аванса
     */
    public function getAdvancePaymentPercentageAttribute(): float
    {
        $planned = $this->planned_advance_amount ?? 0;
        $actual = $this->actual_advance_amount ?? 0;
        
        if ($planned <= 0) {
            return 0.0;
        }
        return round(($actual / $planned) * 100, 2);
    }

    /**
     * Получить общую сумму выполненных работ по контракту
     */
    public function getCompletedWorksAmountAttribute(): float
    {
        return (float) $this->completedWorks()
            ->whereIn('status', ['confirmed'])
            ->sum('total_amount');
    }

    /**
     * Получить оставшуюся сумму по контракту
     */
    public function getRemainingAmountAttribute(): float
    {
        $totalAmount = $this->total_amount ?? 0;
        $completedAmount = $this->completed_works_amount ?? 0;
        return max(0, $totalAmount - $completedAmount);
    }

    /**
     * Получить процент выполнения контракта
     */
    public function getCompletionPercentageAttribute(): float
    {
        $totalAmount = $this->total_amount ?? 0;
        $completedAmount = $this->completed_works_amount ?? 0;
        
        if ($totalAmount <= 0) {
            return 0.0;
        }
        return round(($completedAmount / $totalAmount) * 100, 2);
    }

    /**
     * Проверить, можно ли добавить работу на указанную сумму
     */
    public function canAddWork(float $amount): bool
    {
        // Проверяем статус контракта
        if (in_array($this->status, [ContractStatusEnum::COMPLETED, ContractStatusEnum::TERMINATED])) {
            return false;
        }

        $totalAmount = $this->total_amount ?? 0;
        $completedAmount = $this->completed_works_amount ?? 0;
        
        // Проверяем лимит суммы (с допуском 1%)
        $allowedOverrun = $totalAmount * 0.01;
        return ($completedAmount + $amount) <= ($totalAmount + $allowedOverrun);
    }

    /**
     * Проверить, приближается ли контракт к лимиту (90%+)
     */
    public function isNearingLimit(): bool
    {
        return $this->completion_percentage >= 90.0;
    }

    /**
     * Проверить, просрочен ли контракт
     */
    public function getIsOverdueAttribute(): bool
    {
        return $this->end_date && $this->end_date->isPast() && $this->status === ContractStatusEnum::ACTIVE;
    }

    /**
     * Получить сумму всех платежей по контракту
     */
    public function getTotalPaidAmountAttribute(): float
    {
        return (float) $this->payments()
            ->sum('amount');
    }

    /**
     * Получить сумму всех актов по контракту
     */
    public function getTotalPerformedAmountAttribute(): float
    {
        return (float) $this->performanceActs()
            ->where('is_approved', true)
            ->sum('amount');
    }

    /**
     * Автоматически обновить статус контракта на основе выполненных работ
     */
    public function updateStatusBasedOnCompletion(): bool
    {
        $oldStatus = $this->status;
        
        if ($this->completion_percentage >= 100 && $this->status === ContractStatusEnum::ACTIVE) {
            $this->status = ContractStatusEnum::COMPLETED;
        } elseif ($this->completion_percentage > 0 && $this->status === ContractStatusEnum::DRAFT) {
            $this->status = ContractStatusEnum::ACTIVE;
        }

        if ($this->status !== $oldStatus) {
            $this->save();
            
            // Отправляем событие об изменении статуса
            event(new \App\Events\ContractStatusChanged($this, $oldStatus->value, $this->status->value));
            
            return true;
        }

        return false;
    }

    /**
     * Получить текущее состояние через Event Sourcing
     */
    public function getCurrentState()
    {
        if (!$this->usesEventSourcing()) {
            return null; // Legacy договор - возвращаем null
        }

        $service = app(\App\Services\Contract\ContractStateEventService::class);
        return $service->getCurrentState($this);
    }

    /**
     * Получить состояние договора на определенную дату
     */
    public function getStateAtDate(Carbon $date)
    {
        if (!$this->usesEventSourcing()) {
            return null; // Legacy договор - возвращаем null
        }

        $service = app(\App\Services\Contract\ContractStateEventService::class);
        return $service->getStateAtDate($this, $date);
    }

    /**
     * Получить timeline событий
     */
    public function getTimeline(?Carbon $asOfDate = null)
    {
        if (!$this->usesEventSourcing()) {
            return collect(); // Legacy договор - возвращаем пустую коллекцию
        }

        $service = app(\App\Services\Contract\ContractStateEventService::class);
        return $service->getTimeline($this, $asOfDate);
    }
}