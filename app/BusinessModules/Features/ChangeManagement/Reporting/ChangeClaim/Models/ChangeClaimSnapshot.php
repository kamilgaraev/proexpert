<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ChangeManagement\Reporting\ChangeClaim\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class ChangeClaimSnapshot extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = [
        'as_of' => 'immutable_datetime',
        'generated_at' => 'immutable_datetime',
        'stale_at' => 'immutable_datetime',
        'totals' => 'array',
        'warnings' => 'array',
    ];

    public function rows(): HasMany
    {
        return $this->hasMany(ChangeClaimRow::class, 'snapshot_id');
    }
}
