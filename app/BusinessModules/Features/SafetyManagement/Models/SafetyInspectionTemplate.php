<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\SafetyManagement\Models;

use App\Models\Organization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

final class SafetyInspectionTemplate extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'organization_id',
        'name',
        'inspection_type',
        'checklist_items',
        'is_active',
        'metadata',
    ];

    protected $casts = [
        'checklist_items' => 'array',
        'is_active' => 'boolean',
        'metadata' => 'array',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function scopeForOrganization(Builder $query, int $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }
}
