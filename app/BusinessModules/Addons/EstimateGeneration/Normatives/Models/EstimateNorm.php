<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Normatives\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EstimateNorm extends Model
{
    protected $table = 'estimate_norms';

    protected $fillable = [
        'collection_id',
        'code',
        'name',
        'unit',
        'section_code',
        'section_name',
        'work_composition',
        'raw_payload',
    ];

    protected $casts = [
        'collection_id' => 'integer',
        'work_composition' => 'array',
        'raw_payload' => 'array',
    ];

    public function collection(): BelongsTo
    {
        return $this->belongsTo(EstimateNormCollection::class, 'collection_id');
    }

    public function resources(): HasMany
    {
        return $this->hasMany(EstimateNormResource::class, 'estimate_norm_id');
    }
}
