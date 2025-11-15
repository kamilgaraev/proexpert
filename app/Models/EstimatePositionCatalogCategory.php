<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EstimatePositionCatalogCategory extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'estimate_position_catalog_categories';

    protected $fillable = [
        'organization_id',
        'parent_id',
        'name',
        'description',
        'sort_order',
        'is_active',
        'metadata',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'is_active' => 'boolean',
        'metadata' => 'array',
    ];

    // ============================================
    // RELATIONSHIPS
    // ============================================

    /**
     * Организация-владелец
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Родительская категория
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(EstimatePositionCatalogCategory::class, 'parent_id');
    }

    /**
     * Дочерние категории
     */
    public function children(): HasMany
    {
        return $this->hasMany(EstimatePositionCatalogCategory::class, 'parent_id')
            ->orderBy('sort_order');
    }

    /**
     * Позиции в категории
     */
    public function positions(): HasMany
    {
        return $this->hasMany(EstimatePositionCatalog::class, 'category_id');
    }

    // ============================================
    // SCOPES
    // ============================================

    /**
     * Scope для активных категорий
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope для корневых категорий (без родителя)
     */
    public function scopeRoots($query)
    {
        return $query->whereNull('parent_id');
    }

    /**
     * Scope для организации
     */
    public function scopeByOrganization($query, int $organizationId)
    {
        return $query->where('organization_id', $organizationId);
    }

    /**
     * Scope с сортировкой
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order');
    }

    // ============================================
    // METHODS
    // ============================================

    /**
     * Получить полный путь категории (от корня)
     */
    public function getFullPath(): string
    {
        $path = [$this->name];
        $parent = $this->parent;

        while ($parent) {
            array_unshift($path, $parent->name);
            $parent = $parent->parent;
        }

        return implode(' / ', $path);
    }

    /**
     * Проверить, есть ли дочерние категории
     */
    public function hasChildren(): bool
    {
        return $this->children()->exists();
    }

    /**
     * Проверить, есть ли позиции в категории
     */
    public function hasPositions(): bool
    {
        return $this->positions()->exists();
    }

    /**
     * Проверить, можно ли удалить категорию
     */
    public function canBeDeleted(): bool
    {
        return !$this->hasChildren() && !$this->hasPositions();
    }

    /**
     * Получить дерево категорий с дочерними элементами
     */
    public function getTree(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'sort_order' => $this->sort_order,
            'is_active' => $this->is_active,
            'positions_count' => $this->positions()->count(),
            'children' => $this->children->map(function ($child) {
                return $child->getTree();
            })->toArray(),
        ];
    }
}

