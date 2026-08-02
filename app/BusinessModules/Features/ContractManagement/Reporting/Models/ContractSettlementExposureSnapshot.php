<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ContractManagement\Reporting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class ContractSettlementExposureSnapshot extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = [
        'as_of' => 'immutable_datetime',
        'generated_at' => 'immutable_datetime',
        'stale_at' => 'immutable_datetime',
        'totals' => 'array',
    ];

    public function rows(): HasMany
    {
        return $this->hasMany(ContractSettlementExposureRecord::class, 'snapshot_id');
    }
}
