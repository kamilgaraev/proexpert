<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Material extends Model
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
     * Получить организацию, которой принадлежит материал.
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Получить единицу измерения материала.
     */
    public function measurementUnit(): BelongsTo
    {
        return $this->belongsTo(MeasurementUnit::class);
    }

    /**
     * Получить приемки данного материала.
     */
    public function receipts(): HasMany
    {
        return $this->hasMany(MaterialReceipt::class);
    }

    /**
     * Получить списания данного материала.
     */
    public function writeOffs(): HasMany
    {
        return $this->hasMany(MaterialWriteOff::class);
    }

    /**
     * Получить остатки данного материала по проектам.
     */
    public function balances(): HasMany
    {
        return $this->hasMany(MaterialBalance::class);
    }
}
