<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Normatives\Models;

use App\BusinessModules\Addons\EstimateGeneration\Normatives\Enums\EstimateResourceType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EstimateNormResource extends Model
{
    protected $table = 'estimate_norm_resources';

    protected $fillable = [
        'estimate_norm_id',
        'construction_resource_id',
        'resource_code',
        'resource_name',
        'unit',
        'quantity',
        'resource_type',
        'raw_payload',
    ];

    protected $casts = [
        'estimate_norm_id' => 'integer',
        'construction_resource_id' => 'integer',
        'quantity' => 'decimal:6',
        'resource_type' => EstimateResourceType::class,
        'raw_payload' => 'array',
    ];

    public function estimateNorm(): BelongsTo
    {
        return $this->belongsTo(EstimateNorm::class, 'estimate_norm_id');
    }

    public function constructionResource(): BelongsTo
    {
        return $this->belongsTo(ConstructionResource::class, 'construction_resource_id');
    }
}
