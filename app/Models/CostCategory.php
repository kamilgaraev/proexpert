<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CostCategory extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * Атрибуты, которые можно массово присваивать.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'code',
        'external_code',
        'description',
        'organization_id',
        'parent_id',
        'is_active',
        'sort_order',
        'additional_attributes',
    ];

    /**
     * Атрибуты, которые должны быть приведены к native типам.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'is_active' => 'boolean',
        'additional_attributes' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /**
     * Организация, которой принадлежит категория затрат.
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Родительская категория затрат.
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(CostCategory::class, 'parent_id');
    }

    /**
     * Дочерние категории затрат.
     */
    public function children(): HasMany
    {
        return $this->hasMany(CostCategory::class, 'parent_id');
    }

    /**
     * Проекты, относящиеся к этой категории затрат.
     */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class, 'cost_category_id');
    }

    /**
     * Активные категории затрат для указанной организации.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param int $organizationId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeActiveForOrganization($query, $organizationId)
    {
        return $query->where('organization_id', $organizationId)
                     ->where('is_active', true)
                     ->orderBy('sort_order');
    }
}
