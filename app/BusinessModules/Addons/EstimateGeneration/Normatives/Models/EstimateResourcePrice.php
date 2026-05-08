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
        'machine_salary_price',
        'machine_price_without_salary',
        'machine_labor_quantity',
        'driver_code',
        'machinist_category',
        'price_type',
        'source_price_kind',
        'raw_payload',
    ];

    protected $casts = [
        'dataset_version_id' => 'integer',
        'construction_resource_id' => 'integer',
        'base_price' => 'decimal:4',
        'machine_salary_price' => 'decimal:4',
        'machine_price_without_salary' => 'decimal:4',
        'machine_labor_quantity' => 'decimal:6',
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
