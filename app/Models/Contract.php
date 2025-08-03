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

class Contract extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'organization_id',
        'project_id',
        'contractor_id',
        'parent_contract_id',
        'number',
        'date',
        'subject',
        'work_type_category',
        'payment_terms',
        'total_amount',
        'gp_percentage',
        'planned_advance_amount',
        'actual_advance_amount',
        'status',
        'start_date',
        'end_date',
        'notes',
    ];

    protected $casts = [
        'date' => 'date',
        'total_amount' => 'decimal:2',
        'gp_percentage' => 'decimal:2',
        'planned_advance_amount' => 'decimal:2',
        'actual_advance_amount' => 'decimal:2',
        'start_date' => 'date',
        'end_date' => 'date',
        'status' => ContractStatusEnum::class,
        'work_type_category' => ContractWorkTypeCategoryEnum::class,
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

    public function parentContract(): BelongsTo
    {
        return $this->belongsTo(Contract::class, 'parent_contract_id');
    }

    public function childContracts(): HasMany
    {
        return $this->hasMany(Contract::class, 'parent_contract_id')
                    ->where(function($query) {
                        $query->where('organization_id', $this->organization_id);
                    });
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
                    ->withPivot('attached_at')
                    ->withTimestamps();
    }

    // Accessor for calculated GP Amount
    public function getGpAmountAttribute(): float
    {
        $percentage = $this->gp_percentage ?? 0;
        $amount = $this->total_amount ?? 0;
        
        if ($percentage > 0 && $amount > 0) {
            return round(($amount * $percentage) / 100, 2);
        }
        return 0.00;
    }

    /**
     * Получить сумму контракта с учетом генподрядного процента
     */
    public function getTotalAmountWithGpAttribute(): float
    {
        $totalAmount = $this->total_amount ?? 0;
        $gpAmount = $this->gp_amount ?? 0;
        return $totalAmount + $gpAmount;
    }

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
    public function isAdvanceFullyPaidAttribute(): bool
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
}