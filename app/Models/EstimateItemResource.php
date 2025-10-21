<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EstimateItemResource extends Model
{
    use HasFactory;

    protected $fillable = [
        'estimate_item_id',
        'resource_type',
        'material_id',
        'name',
        'description',
        'measurement_unit_id',
        'quantity_per_unit',
        'total_quantity',
        'unit_price',
        'total_amount',
    ];

    protected $casts = [
        'quantity_per_unit' => 'decimal:4',
        'total_quantity' => 'decimal:4',
        'unit_price' => 'decimal:2',
        'total_amount' => 'decimal:2',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(EstimateItem::class, 'estimate_item_id');
    }

    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }

    public function measurementUnit(): BelongsTo
    {
        return $this->belongsTo(MeasurementUnit::class);
    }

    public function scopeByType($query, string $type)
    {
        return $query->where('resource_type', $type);
    }

    public function scopeMaterials($query)
    {
        return $query->where('resource_type', 'material');
    }

    public function scopeLabor($query)
    {
        return $query->where('resource_type', 'labor');
    }

    public function scopeEquipment($query)
    {
        return $query->where('resource_type', 'equipment');
    }

    public function isMaterial(): bool
    {
        return $this->resource_type === 'material';
    }

    public function isLabor(): bool
    {
        return $this->resource_type === 'labor';
    }

    public function isEquipment(): bool
    {
        return $this->resource_type === 'equipment';
    }
}

