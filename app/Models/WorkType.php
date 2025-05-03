<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkType extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'organization_id',
        'name',
        'code',
        'measurement_unit_id',
        'description',
        'category',
        'default_price',
        'additional_properties',
        'is_active',
    ];

    protected $casts = [
        'default_price' => 'decimal:2',
        'additional_properties' => 'array',
        'is_active' => 'boolean',
    ];

    /**
     * Получить организацию, которой принадлежит вид работ.
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Получить единицу измерения для вида работ.
     */
    public function measurementUnit(): BelongsTo
    {
        return $this->belongsTo(MeasurementUnit::class);
    }

    /**
     * Получить выполненные работы данного вида.
     */
    public function completedWorks(): HasMany
    {
        return $this->hasMany(CompletedWork::class);
    }

    /**
     * Получить списания материалов по данному виду работ.
     */
    public function materialWriteOffs(): HasMany
    {
        return $this->hasMany(MaterialWriteOff::class);
    }
}
