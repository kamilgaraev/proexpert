<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MeasurementUnit extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'organization_id',
        'name',
        'short_name',
        'type',
        'description',
        'is_default',
        'is_system',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'is_system' => 'boolean',
    ];

    /**
     * Получить организацию, которой принадлежит единица измерения.
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Получить материалы, использующие эту единицу измерения.
     */
    public function materials(): HasMany
    {
        return $this->hasMany(Material::class);
    }

    /**
     * Получить виды работ, использующие эту единицу измерения.
     */
    public function workTypes(): HasMany
    {
        return $this->hasMany(WorkType::class);
    }
}
