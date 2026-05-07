<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Normatives\Models;

use App\BusinessModules\Addons\EstimateGeneration\Normatives\Enums\EstimateResourceType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ConstructionResource extends Model
{
    protected $table = 'construction_resources';

    protected $fillable = [
        'dataset_version_id',
        'ksr_code',
        'name',
        'unit',
        'resource_type',
        'okpd2_code',
        'raw_payload',
    ];

    protected $casts = [
        'dataset_version_id' => 'integer',
        'resource_type' => EstimateResourceType::class,
        'raw_payload' => 'array',
    ];

    public function datasetVersion(): BelongsTo
    {
        return $this->belongsTo(EstimateDatasetVersion::class, 'dataset_version_id');
    }

    public function normResources(): HasMany
    {
        return $this->hasMany(EstimateNormResource::class, 'construction_resource_id');
    }

    public function resourcePrices(): HasMany
    {
        return $this->hasMany(EstimateResourcePrice::class, 'construction_resource_id');
    }
}
