<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Normatives\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EstimateRegionalPriceActivation extends Model
{
    protected $table = 'estimate_regional_price_activations';

    protected $fillable = [
        'region_id',
        'price_zone_id',
        'active_version_id',
        'previous_version_id',
        'activated_at',
        'activation_reason',
    ];

    protected $casts = [
        'region_id' => 'integer',
        'price_zone_id' => 'integer',
        'active_version_id' => 'integer',
        'previous_version_id' => 'integer',
        'activated_at' => 'datetime',
    ];

    public function activeVersion(): BelongsTo
    {
        return $this->belongsTo(EstimateRegionalPriceVersion::class, 'active_version_id');
    }

    public function previousVersion(): BelongsTo
    {
        return $this->belongsTo(EstimateRegionalPriceVersion::class, 'previous_version_id');
    }
}
