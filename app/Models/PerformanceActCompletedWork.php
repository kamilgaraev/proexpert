<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PerformanceActCompletedWork extends Pivot
{
    protected $table = 'performance_act_completed_works';

    protected $fillable = [
        'performance_act_id',
        'completed_work_id',
        'included_quantity',
        'included_amount',
        'notes',
    ];

    protected $casts = [
        'included_quantity' => 'decimal:3',
        'included_amount' => 'decimal:2',
    ];

    public $timestamps = true;

    /**
     * Связь с актом выполненных работ
     */
    public function performanceAct(): BelongsTo
    {
        return $this->belongsTo(ContractPerformanceAct::class, 'performance_act_id');
    }

    /**
     * Связь с выполненной работой
     */
    public function completedWork(): BelongsTo
    {
        return $this->belongsTo(CompletedWork::class, 'completed_work_id');
    }
}
