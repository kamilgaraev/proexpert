<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class BudgetPeriodClosure extends BudgetingModel
{
    protected $fillable = [
        'uuid',
        'budget_period_id',
        'closure_status',
        'closure_mode',
        'reason',
        'closed_by',
        'closed_at',
        'reopened_until',
        'metadata',
    ];

    protected $casts = [
        'closed_at' => 'datetime',
        'reopened_until' => 'datetime',
        'metadata' => 'array',
    ];

    public function period(): BelongsTo
    {
        return $this->belongsTo(BudgetPeriod::class, 'budget_period_id');
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }
}
