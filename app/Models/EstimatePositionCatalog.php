<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EstimatePositionCatalog extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'estimate_position_catalog';

    protected $fillable = [
        'organization_id',
        'category_id',
        'name',
        'code',
        'description',
        'item_type',
        'measurement_unit_id',
        'work_type_id',
        'unit_price',
        'direct_costs',
        'overhead_percent',
        'profit_percent',
        'is_active',
        'usage_count',
        'metadata',
        'created_by_user_id',
    ];

    protected $casts = [
        'unit_price' => 'decimal:2',
        'direct_costs' => 'decimal:2',
        'overhead_percent' => 'decimal:4',
        'profit_percent' => 'decimal:4',
        'is_active' => 'boolean',
        'usage_count' => 'integer',
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
     * Категория позиции
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(EstimatePositionCatalogCategory::class, 'category_id');
    }

    /**
     * Единица измерения
     */
    public function measurementUnit(): BelongsTo
    {
        return $this->belongsTo(MeasurementUnit::class);
    }

    /**
     * Тип работ
     */
    public function workType(): BelongsTo
    {
        return $this->belongsTo(WorkType::class);
    }

    /**
     * Пользователь-создатель
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * История изменения цен
     */
    public function priceHistory(): HasMany
    {
        return $this->hasMany(EstimatePositionPriceHistory::class, 'catalog_item_id');
    }

    /**
     * Позиции смет, созданные из этой записи справочника
     */
    public function estimateItems(): HasMany
    {
        return $this->hasMany(EstimateItem::class, 'catalog_item_id');
    }

    // ============================================
    // SCOPES
    // ============================================

    /**
     * Scope для активных позиций
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope для организации
     */
    public function scopeByOrganization($query, int $organizationId)
    {
        return $query->where('organization_id', $organizationId);
    }

    /**
     * Scope для категории
     */
    public function scopeByCategory($query, ?int $categoryId)
    {
        if (is_null($categoryId)) {
            return $query->whereNull('category_id');
        }
        
        return $query->where('category_id', $categoryId);
    }

    /**
     * Scope для типа позиции
     */
    public function scopeByType($query, string $itemType)
    {
        return $query->where('item_type', $itemType);
    }

    /**
     * Scope для поиска по имени, коду или описанию
     */
    public function scopeSearch($query, string $searchTerm)
    {
        return $query->where(function ($q) use ($searchTerm) {
            $q->where('name', 'ilike', "%{$searchTerm}%")
                ->orWhere('code', 'ilike', "%{$searchTerm}%")
                ->orWhere('description', 'ilike', "%{$searchTerm}%");
        });
    }

    // ============================================
    // METHODS
    // ============================================

    /**
     * Увеличить счетчик использований
     */
    public function incrementUsage(): void
    {
        $this->increment('usage_count');
    }

    /**
     * Проверить, можно ли удалить позицию
     */
    public function canBeDeleted(): bool
    {
        // Проверяем, не используется ли в сметах
        return !$this->estimateItems()->exists();
    }

    /**
     * Получить форматированную цену
     */
    public function getFormattedPriceAttribute(): string
    {
        return number_format($this->unit_price, 2, '.', ' ') . ' ₽';
    }
}

