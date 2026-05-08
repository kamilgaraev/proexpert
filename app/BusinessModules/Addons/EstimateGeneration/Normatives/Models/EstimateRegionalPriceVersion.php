<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Normatives\Models;

use App\BusinessModules\Addons\EstimateGeneration\Normatives\Enums\RegionalPriceStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EstimateRegionalPriceVersion extends Model
{
    protected $table = 'estimate_regional_price_versions';

    protected $fillable = [
        'source',
        'region_id',
        'price_zone_id',
        'period_id',
        'version_key',
        'status',
        'files_count',
        'rows_read',
        'rows_imported',
        'errors_count',
        'activated_at',
        'superseded_at',
        'rolled_back_at',
        'metadata',
    ];

    protected $casts = [
        'region_id' => 'integer',
        'price_zone_id' => 'integer',
        'period_id' => 'integer',
        'status' => RegionalPriceStatus::class,
        'files_count' => 'integer',
        'rows_read' => 'integer',
        'rows_imported' => 'integer',
        'errors_count' => 'integer',
        'activated_at' => 'datetime',
        'superseded_at' => 'datetime',
        'rolled_back_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function region(): BelongsTo
    {
        return $this->belongsTo(EstimateRegion::class, 'region_id');
    }

    public function priceZone(): BelongsTo
    {
        return $this->belongsTo(EstimatePriceZone::class, 'price_zone_id');
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(EstimatePricePeriod::class, 'period_id');
    }
}
