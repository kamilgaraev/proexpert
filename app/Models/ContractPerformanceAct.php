<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class ContractPerformanceAct extends Model
{
    use HasFactory;

    protected $fillable = [
        'contract_id',
        'project_id',
        'act_document_number',
        'act_date',
        'amount',
        'description',
        'is_approved',
        'approval_date',
    ];

    protected $casts = [
        'act_date' => 'date',
        'amount' => 'decimal:2',
        'is_approved' => 'boolean',
        'approval_date' => 'date',
    ];

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Выполненные работы включенные в данный акт
     */
    public function completedWorks(): BelongsToMany
    {
        return $this->belongsToMany(CompletedWork::class, 'performance_act_completed_works', 'performance_act_id', 'completed_work_id')
            ->using(PerformanceActCompletedWork::class)
            ->withPivot(['included_quantity', 'included_amount', 'notes'])
            ->withTimestamps();
    }

    /**
     * Автоматически пересчитать сумму акта на основе включенных работ
     */
    public function recalculateAmount(): float
    {
        $totalAmount = $this->completedWorks()->sum('performance_act_completed_works.included_amount');
        $this->update(['amount' => $totalAmount]);
        return $totalAmount;
    }

    public function files(): MorphMany
    {
        return $this->morphMany(File::class, 'fileable');
    }
} 