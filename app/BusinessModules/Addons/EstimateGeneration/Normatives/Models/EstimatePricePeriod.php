<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Normatives\Models;

use Illuminate\Database\Eloquent\Model;

class EstimatePricePeriod extends Model
{
    protected $table = 'estimate_price_periods';

    protected $fillable = [
        'fgiscs_period_id',
        'name',
        'year',
        'quarter',
        'starts_at',
        'ends_at',
    ];

    protected $casts = [
        'fgiscs_period_id' => 'integer',
        'year' => 'integer',
        'quarter' => 'integer',
        'starts_at' => 'date',
        'ends_at' => 'date',
    ];
}
