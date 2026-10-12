<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class EstimateOperationPriceList extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';
    protected $guarded = [];
    protected $casts = ['entries' => 'array', 'includes_vat' => 'boolean', 'vat_rate' => 'decimal:2', 'effective_date' => 'date'];
}
