<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Normatives\Models;

use App\BusinessModules\Addons\EstimateGeneration\Normatives\Enums\EstimateImportStatus;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Enums\EstimateSourceType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EstimateDatasetVersion extends Model
{
    protected $table = 'estimate_dataset_versions';

    protected $fillable = [
        'source_type',
        'version_key',
        'bucket',
        'prefix',
        'status',
        'files_count',
        'rows_read',
        'rows_imported',
        'errors_count',
        'started_at',
        'finished_at',
        'meta',
    ];

    protected $casts = [
        'source_type' => EstimateSourceType::class,
        'status' => EstimateImportStatus::class,
        'files_count' => 'integer',
        'rows_read' => 'integer',
        'rows_imported' => 'integer',
        'errors_count' => 'integer',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'meta' => 'array',
    ];

    public function constructionResources(): HasMany
    {
        return $this->hasMany(ConstructionResource::class, 'dataset_version_id');
    }

    public function normCollections(): HasMany
    {
        return $this->hasMany(EstimateNormCollection::class, 'dataset_version_id');
    }

    public function resourcePrices(): HasMany
    {
        return $this->hasMany(EstimateResourcePrice::class, 'dataset_version_id');
    }

    public function importErrors(): HasMany
    {
        return $this->hasMany(EstimateImportError::class, 'dataset_version_id');
    }
}
