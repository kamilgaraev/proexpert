<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class PerformanceActReversal extends Model
{
    protected $fillable = [
        'organization_id',
        'performance_act_id',
        'reversed_by_user_id',
        'source_status',
        'amount',
        'currency',
        'reason',
        'invoice_ids',
        'idempotency_key',
        'reversed_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'invoice_ids' => 'array',
        'reversed_at' => 'immutable_datetime',
    ];

    public function performanceAct(): BelongsTo
    {
        return $this->belongsTo(ContractPerformanceAct::class);
    }
}
