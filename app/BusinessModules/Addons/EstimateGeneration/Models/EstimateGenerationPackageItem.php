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
        'logical_key',
        'revision',
        'supersedes_item_id',
        'parent_key',
        'level',
        'item_type',
        'name',
        'unit',
        'quantity',
        'quantity_basis',
        'price_source',
        'price_snapshot',
        'quantity_evidence_id',
        'quantity_evidence_fingerprint',
        'estimate_norm_id',
        'region_id',
        'price_zone_id',
        'period_id',
        'regional_price_version_id',
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
        'revision' => 'integer',
        'quantity' => 'float',
        'quantity_basis' => 'array',
        'price_snapshot' => 'array',
        'normative_confidence' => 'float',
        'unit_price' => 'decimal:6',
        'direct_cost' => 'decimal:2',
        'overhead_cost' => 'decimal:2',
        'profit_cost' => 'decimal:2',
        'total_cost' => 'decimal:2',
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
