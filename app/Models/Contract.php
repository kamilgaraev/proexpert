<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Enums\Contract\ContractStatusEnum;
use App\Enums\Contract\ContractTypeEnum;
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
        'type',
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
        'type' => ContractTypeEnum::class,
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

    // Accessor for calculated GP Amount
    public function getGpAmountAttribute(): float
    {
        if ($this->gp_percentage > 0 && $this->total_amount > 0) {
            return round(($this->total_amount * $this->gp_percentage) / 100, 2);
        }
        return 0.00;
    }

    /**
     * Получить сумму контракта с учетом генподрядного процента
     */
    public function getTotalAmountWithGpAttribute(): float
    {
        return $this->total_amount + $this->gp_amount;
    }

    /**
     * Получить остаток планируемого аванса для выплаты
     */
    public function getRemainingAdvanceAmountAttribute(): float
    {
        return max(0, $this->planned_advance_amount - $this->actual_advance_amount);
    }

    /**
     * Проверить, выплачен ли планируемый аванс полностью
     */
    public function isAdvanceFullyPaidAttribute(): bool
    {
        return $this->actual_advance_amount >= $this->planned_advance_amount;
    }

    /**
     * Получить процент выплаченного аванса
     */
    public function getAdvancePaymentPercentageAttribute(): float
    {
        if ($this->planned_advance_amount <= 0) {
            return 0.0;
        }
        return round(($this->actual_advance_amount / $this->planned_advance_amount) * 100, 2);
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
        return max(0, $this->total_amount - $this->completed_works_amount);
    }

    /**
     * Получить процент выполнения контракта
     */
    public function getCompletionPercentageAttribute(): float
    {
        if ($this->total_amount <= 0) {
            return 0.0;
        }
        return round(($this->completed_works_amount / $this->total_amount) * 100, 2);
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

        // Проверяем лимит суммы (с допуском 1%)
        $allowedOverrun = $this->total_amount * 0.01;
        return ($this->completed_works_amount + $amount) <= ($this->total_amount + $allowedOverrun);
    }

    /**
     * Проверить, приближается ли контракт к лимиту (90%+)
     */
    public function isNearingLimit(): bool
    {
        return $this->completion_percentage >= 90.0;
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