<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class WipForecastAuditEvent extends BudgetingModel
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'uuid',
        'forecast_version_id',
        'organization_id',
        'event_type',
        'actor_user_id',
        'reason',
        'old_values',
        'new_values',
        'source_snapshot_hash',
        'ip_address',
        'user_agent',
        'created_at',
    ];

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
        'created_at' => 'datetime',
    ];

    public function version(): BelongsTo
    {
        return $this->belongsTo(WipForecastVersion::class, 'forecast_version_id');
    }
}
