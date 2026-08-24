<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class ContractPerformanceAct extends Model
{
    use HasFactory;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PENDING_APPROVAL = 'pending_approval';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_SIGNED = 'signed';

    public const STATUS_ANNULLED = 'annulled';

    protected $fillable = [
        'contract_id',
        'project_id',
        'estimate_version_id',
        'act_document_number',
        'act_date',
        'period_start',
        'period_end',
        'amount',
        'vat_rate',
        'vat_amount',
        'amount_without_vat',
        'currency',
        'description',
        'status',
        'is_approved',
        'approval_date',
        'created_by_user_id',
        'submitted_by_user_id',
        'submitted_at',
        'approved_by_user_id',
        'rejected_by_user_id',
        'rejected_at',
        'rejection_reason',
        'annulled_at',
        'annulled_by_user_id',
        'annulment_reason',
        'signed_file_id',
        'signed_by_user_id',
        'signed_at',
        'locked_by_user_id',
        'locked_at',
    ];

    protected $casts = [
        'act_date' => 'date',
        'period_start' => 'date',
        'period_end' => 'date',
        'amount' => 'decimal:2',
        'vat_rate' => 'decimal:2',
        'vat_amount' => 'decimal:2',
        'amount_without_vat' => 'decimal:2',
        'is_approved' => 'boolean',
        'approval_date' => 'date',
        'submitted_at' => 'datetime',
        'rejected_at' => 'datetime',
        'annulled_at' => 'immutable_datetime',
        'signed_at' => 'datetime',
        'locked_at' => 'datetime',
    ];

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function estimateVersion(): BelongsTo
    {
        return $this->belongsTo(EstimateVersion::class);
    }

    /**
     * Выполненные работы включенные в данный акт
     */
    public function completedWorks(): BelongsToMany
    {
        return $this->belongsToMany(CompletedWork::class, 'performance_act_completed_works', 'performance_act_id', 'completed_work_id')
            ->using(PerformanceActCompletedWork::class)
            ->withPivot(['included_quantity', 'included_amount', 'currency', 'notes'])
            ->withTimestamps();
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PerformanceActLine::class, 'performance_act_id');
    }

    /**
     * Автоматически пересчитать сумму акта на основе включенных работ
     */
    public function recalculateAmount(): float
    {
        $totalAmount = $this->lines()->exists()
            ? $this->lines()->sum('amount')
            : $this->completedWorks()->sum('performance_act_completed_works.included_amount');

        $this->update(['amount' => $totalAmount]);

        return (float) $totalAmount;
    }

    public function isReadyForPayment(): bool
    {
        return $this->status === self::STATUS_APPROVED && (float) $this->amount > 0;
    }

    public function isLocked(): bool
    {
        return $this->locked_at !== null || in_array($this->status, [self::STATUS_APPROVED, self::STATUS_SIGNED], true);
    }

    public function files(): MorphMany
    {
        return $this->morphMany(File::class, 'fileable');
    }
}
