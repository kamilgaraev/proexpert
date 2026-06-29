<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EstimateGenerationPackageItem extends Model
{
    public const QUANTITY_REVIEW_ITEM_TYPE = 'quantity_review';

    public const SERVICE_ITEM_TYPES = ['operation', 'resource_note', 'review_note'];

    protected $table = 'estimate_generation_package_items';

    protected $fillable = [
        'package_id',
        'key',
        'parent_key',
        'level',
        'item_type',
        'name',
        'unit',
        'quantity',
        'quantity_basis',
        'price_source',
        'normative_status',
        'normative_confidence',
        'unit_price',
        'direct_cost',
        'overhead_cost',
        'profit_cost',
        'total_cost',
        'resources',
        'flags',
        'metadata',
        'sort_order',
    ];

    protected $casts = [
        'level' => 'integer',
        'quantity' => 'float',
        'quantity_basis' => 'array',
        'normative_confidence' => 'float',
        'unit_price' => 'float',
        'direct_cost' => 'float',
        'overhead_cost' => 'float',
        'profit_cost' => 'float',
        'total_cost' => 'float',
        'resources' => 'array',
        'flags' => 'array',
        'metadata' => 'array',
        'sort_order' => 'integer',
    ];

    public function package(): BelongsTo
    {
        return $this->belongsTo(EstimateGenerationPackage::class, 'package_id');
    }
}
