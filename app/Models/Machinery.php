<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Модель механизма/техники
 * 
 * Справочник механизмов и строительной техники для смет
 */
class Machinery extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'machinery';

    protected $fillable = [
        'organization_id',
        'code',
        'name',
        'description',
        'category',
        'type',
        'measurement_unit_id',
        'model',
        'manufacturer',
        'power',
        'capacity',
        'specifications',
        'hourly_rate',
        'shift_rate',
        'daily_rate',
        'fuel_consumption',
        'fuel_type',
        'maintenance_cost',
        'metadata',
        'is_active',
    ];

    protected $casts = [
        'power' => 'decimal:2',
        'capacity' => 'decimal:2',
        'hourly_rate' => 'decimal:2',
        'shift_rate' => 'decimal:2',
        'daily_rate' => 'decimal:2',
        'fuel_consumption' => 'decimal:2',
        'maintenance_cost' => 'decimal:2',
        'metadata' => 'array',
        'is_active' => 'boolean',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function measurementUnit(): BelongsTo
    {
        return $this->belongsTo(MeasurementUnit::class);
    }

    public function estimateItems(): HasMany
    {
        return $this->hasMany(EstimateItem::class);
    }

    /**
     * Поиск по коду
     */
    public function scopeWithCode($query, string $code)
    {
        return $query->where('code', $code);
    }

    /**
     * Активные механизмы
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * По категории
     */
    public function scopeInCategory($query, string $category)
    {
        return $query->where('category', $category);
    }

    /**
     * Полнотекстовый поиск
     */
    public function scopeFuzzySearch($query, string $term)
    {
        return $query->whereRaw("name % ?", [$term])
            ->orWhereRaw("code % ?", [$term])
            ->orderByRaw("similarity(name, ?) DESC", [$term]);
    }
}

