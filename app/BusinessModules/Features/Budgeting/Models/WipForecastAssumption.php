<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

final class WipForecastAssumption extends BudgetingModel
{
    use SoftDeletes;

    protected $fillable = [
        'uuid',
        'forecast_version_id',
        'organization_id',
        'assumption_type',
        'scope',
        'scope_id',
        'title',
        'description',
        'amount',
        'percent',
        'currency',
        'status',
        'owner_user_id',
        'valid_until',
        'source_row_refs',
        'source_snapshot_hash',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'percent' => 'decimal:4',
        'valid_until' => 'date',
        'source_row_refs' => 'array',
    ];

    public function version(): BelongsTo
    {
        return $this->belongsTo(WipForecastVersion::class, 'forecast_version_id');
    }
}
