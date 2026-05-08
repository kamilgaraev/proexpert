<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Normatives\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EstimateRegion extends Model
{
    protected $table = 'estimate_regions';

    protected $fillable = [
        'code',
        'name',
        'fgiscs_subject_id',
        'is_supported',
    ];

    protected $casts = [
        'fgiscs_subject_id' => 'integer',
        'is_supported' => 'boolean',
    ];

    public function priceZones(): HasMany
    {
        return $this->hasMany(EstimatePriceZone::class, 'estimate_region_id');
    }
}
