<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Normatives\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EstimatePriceZone extends Model
{
    protected $table = 'estimate_price_zones';

    protected $fillable = [
        'estimate_region_id',
        'name',
        'fgiscs_price_zone_id',
    ];

    protected $casts = [
        'estimate_region_id' => 'integer',
        'fgiscs_price_zone_id' => 'integer',
    ];

    public function region(): BelongsTo
    {
        return $this->belongsTo(EstimateRegion::class, 'estimate_region_id');
    }
}
