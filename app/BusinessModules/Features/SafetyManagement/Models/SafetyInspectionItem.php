<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\SafetyManagement\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

final class SafetyInspectionItem extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'inspection_id',
        'item_code',
        'title',
        'requirement_text',
        'severity',
        'status',
        'comment',
        'evidence_files',
        'metadata',
    ];

    protected $casts = [
        'evidence_files' => 'array',
        'metadata' => 'array',
    ];

    public function inspection(): BelongsTo
    {
        return $this->belongsTo(SafetyInspection::class, 'inspection_id');
    }

    public function finding(): HasOne
    {
        return $this->hasOne(SafetyInspectionFinding::class, 'inspection_item_id');
    }

    public function scopeWithStatus(Builder $query, string $status): Builder
    {
        return $query->where('status', $status);
    }
}
