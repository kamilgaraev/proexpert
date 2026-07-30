<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Reporting\Supply\Models;

use Illuminate\Database\Eloquent\Model;

final class SupplyReliabilityBackfillWatermark extends Model
{
    protected $table = 'supply_reliability_backfill_watermarks';

    protected $primaryKey = 'organization_id';

    public $incrementing = false;

    protected $guarded = [];

    protected $casts = [
        'target_sent_at' => 'immutable_datetime',
        'completed_at' => 'immutable_datetime',
    ];
}
