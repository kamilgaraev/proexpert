<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Normatives\Models;

use App\BusinessModules\Addons\EstimateGeneration\Normatives\Enums\EstimateResourceType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EstimateResourcePrice extends Model
{
    protected $table = 'estimate_resource_prices';

    protected $fillable = [
        'dataset_version_id',
        'construction_resource_id',
        'resource_code',
        'resource_name',
        'unit',
        'base_price',
        'price_type',
        'raw_payload',
    ];

    protected $casts = [
        'dataset_version_id' => 'integer',
        'construction_resource_id' => 'integer',
        'base_price' => 'decimal:4',
        'price_type' => EstimateResourceType::class,
        'raw_payload' => 'array',
    ];

    public function datasetVersion(): BelongsTo
    {
        return $this->belongsTo(EstimateDatasetVersion::class, 'dataset_version_id');
    }

    public function constructionResource(): BelongsTo
    {
        return $this->belongsTo(ConstructionResource::class, 'construction_resource_id');
    }
}
