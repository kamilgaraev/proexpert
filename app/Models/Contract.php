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
        return $this->hasMany(Contract::class, 'parent_contract_id');
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

    // You might want to add accessors for total_paid_amount and total_performed_amount later
    // by summing up related payments and performance_acts.
} 