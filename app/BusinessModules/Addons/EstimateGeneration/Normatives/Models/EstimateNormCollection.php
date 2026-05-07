<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Normatives\Models;

use App\BusinessModules\Addons\EstimateGeneration\Normatives\Enums\EstimateNormType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EstimateNormCollection extends Model
{
    protected $table = 'estimate_norm_collections';

    protected $fillable = [
        'dataset_version_id',
        'code',
        'name',
        'norm_type',
        'source_file',
    ];

    protected $casts = [
        'dataset_version_id' => 'integer',
        'norm_type' => EstimateNormType::class,
    ];

    public function datasetVersion(): BelongsTo
    {
        return $this->belongsTo(EstimateDatasetVersion::class, 'dataset_version_id');
    }

    public function norms(): HasMany
    {
        return $this->hasMany(EstimateNorm::class, 'collection_id');
    }

    public function sections(): HasMany
    {
        return $this->hasMany(EstimateNormSection::class, 'collection_id');
    }
}
