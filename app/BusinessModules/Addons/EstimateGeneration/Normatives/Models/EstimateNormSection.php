<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Normatives\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EstimateNormSection extends Model
{
    protected $table = 'estimate_norm_sections';

    protected $fillable = [
        'collection_id',
        'parent_id',
        'code',
        'name',
        'section_type',
        'depth',
        'path',
        'raw_payload',
    ];

    protected $casts = [
        'collection_id' => 'integer',
        'parent_id' => 'integer',
        'depth' => 'integer',
        'raw_payload' => 'array',
    ];

    public function collection(): BelongsTo
    {
        return $this->belongsTo(EstimateNormCollection::class, 'collection_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function norms(): HasMany
    {
        return $this->hasMany(EstimateNorm::class, 'section_id');
    }
}
