<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class BudgetAmount extends Model
{
    protected $fillable = [
        'budget_line_id',
        'month',
        'plan_amount',
        'forecast_amount',
        'currency',
    ];

    protected $casts = [
        'month' => 'date',
        'plan_amount' => 'decimal:2',
        'forecast_amount' => 'decimal:2',
    ];

    public function line(): BelongsTo
    {
        return $this->belongsTo(BudgetLine::class, 'budget_line_id');
    }
}
