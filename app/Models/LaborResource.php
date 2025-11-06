<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Модель трудового ресурса
 * 
 * Справочник профессий и трудовых ресурсов для смет
 */
class LaborResource extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'labor_resources';

    protected $fillable = [
        'organization_id',
        'code',
        'name',
        'description',
        'category',
        'profession',
        'skill_level',
        'measurement_unit_id',
        'hourly_rate',
        'shift_rate',
        'daily_rate',
        'monthly_rate',
        'coefficient',
        'overhead_rate',
        'work_hours_per_shift',
        'productivity_factor',
        'metadata',
        'is_active',
    ];

    protected $casts = [
        'skill_level' => 'integer',
        'hourly_rate' => 'decimal:2',
        'shift_rate' => 'decimal:2',
        'daily_rate' => 'decimal:2',
        'monthly_rate' => 'decimal:2',
        'coefficient' => 'decimal:4',
        'overhead_rate' => 'decimal:4',
        'work_hours_per_shift' => 'decimal:2',
        'productivity_factor' => 'decimal:4',
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
     * Активные ресурсы
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * По профессии
     */
    public function scopeByProfession($query, string $profession)
    {
        return $query->where('profession', $profession);
    }

    /**
     * По разряду
     */
    public function scopeBySkillLevel($query, int $level)
    {
        return $query->where('skill_level', $level);
    }

    /**
     * Полнотекстовый поиск
     */
    public function scopeFuzzySearch($query, string $term)
    {
        return $query->whereRaw("name % ?", [$term])
            ->orWhereRaw("code % ?", [$term])
            ->orWhereRaw("profession % ?", [$term])
            ->orderByRaw("similarity(name, ?) DESC", [$term]);
    }
}

