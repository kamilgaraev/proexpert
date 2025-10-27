<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NormativeRateResource extends Model
{
    use HasFactory;

    protected $fillable = [
        'rate_id',
        'resource_type',
        'code',
        'name',
        'measurement_unit',
        'consumption',
        'unit_price',
        'total_cost',
        'notes',
        'metadata',
    ];

    protected $casts = [
        'consumption' => 'decimal:4',
        'unit_price' => 'decimal:2',
        'total_cost' => 'decimal:2',
        'metadata' => 'array',
    ];

    public function rate(): BelongsTo
    {
        return $this->belongsTo(NormativeRate::class, 'rate_id');
    }

    public function scopeByRate($query, int $rateId)
    {
        return $query->where('rate_id', $rateId);
    }

    public function scopeByType($query, string $type)
    {
        return $query->where('resource_type', $type);
    }

    public function scopeMaterials($query)
    {
        return $query->where('resource_type', 'material');
    }

    public function scopeMachinery($query)
    {
        return $query->where('resource_type', 'machinery');
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

    public function isMachinery(): bool
    {
        return $this->resource_type === 'machinery';
    }

    public function isLabor(): bool
    {
        return $this->resource_type === 'labor';
    }

    public function calculateCost(float $quantity = 1.0): float
    {
        return (float) ($this->consumption * $this->unit_price * $quantity);
    }
}
