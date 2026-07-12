<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EstimateGenerationPackage extends Model
{
    protected $table = 'estimate_generation_packages';

    protected $fillable = [
        'session_id',
        'input_version',
        'key',
        'title',
        'scope_type',
        'status',
        'generation_stage',
        'generation_progress',
        'target_items_min',
        'target_items_max',
        'actual_items_count',
        'totals',
        'quality_summary',
        'assumptions',
        'source_refs',
        'metadata',
        'sort_order',
        'started_at',
        'finished_at',
        'failed_at',
        'cancelled_at',
        'approved_at',
        'last_error_code',
    ];

    protected $casts = [
        'generation_progress' => 'integer',
        'target_items_min' => 'integer',
        'target_items_max' => 'integer',
        'actual_items_count' => 'integer',
        'totals' => 'array',
        'quality_summary' => 'array',
        'assumptions' => 'array',
        'source_refs' => 'array',
        'metadata' => 'array',
        'sort_order' => 'integer',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'failed_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'approved_at' => 'datetime',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(EstimateGenerationSession::class, 'session_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(EstimateGenerationPackageItem::class, 'package_id')
            ->orderBy('sort_order')
            ->orderBy('id');
    }
}
