<?php

namespace App\Models;

use App\Exceptions\BusinessLogicException;
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
        'currency',
        'notes',
    ];

    protected $casts = [
        'included_quantity' => 'decimal:3',
        'included_amount' => 'decimal:2',
    ];

    public $timestamps = true;

    protected static function booted(): void
    {
        static::updating(function (self $pivot): void {
            $pivot->assertAcceptanceSourceMutable();
        });
        static::deleting(function (self $pivot): void {
            $pivot->assertAcceptanceSourceMutable();
        });
    }

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

    private function assertAcceptanceSourceMutable(): void
    {
        $accepted = $this->performanceAct()
            ->where(function ($builder): void {
                $builder
                    ->where('is_approved', true)
                    ->orWhereIn('status', [
                        ContractPerformanceAct::STATUS_APPROVED,
                        ContractPerformanceAct::STATUS_SIGNED,
                    ]);
            })
            ->exists();
        if ($accepted) {
            throw new BusinessLogicException(trans_message('act_reports.accepted_act_lines_immutable'));
        }
    }
}
