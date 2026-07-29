<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Reporting\ManagementPnl\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class ManagementPnlSnapshot extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = [
        'component_snapshots' => 'array',
        'as_of' => 'immutable_datetime',
        'generated_at' => 'immutable_datetime',
        'stale_at' => 'immutable_datetime',
        'totals' => 'array',
    ];

    public function rows(): HasMany
    {
        return $this->hasMany(ManagementPnlRecord::class, 'snapshot_id');
    }
}
