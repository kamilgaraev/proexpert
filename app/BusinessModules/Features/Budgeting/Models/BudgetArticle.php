<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

final class BudgetArticle extends Model
{
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'uuid',
        'organization_id',
        'parent_id',
        'code',
        'name',
        'budget_kind',
        'flow_direction',
        'is_leaf',
        'is_active',
        'cost_category_id',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'is_leaf' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function mappings(): HasMany
    {
        return $this->hasMany(BudgetArticleMapping::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(BudgetLine::class);
    }

    public function scopeForOrganization(Builder $query, int $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }
}
