<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EstimateItem extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'estimate_id',
        'estimate_section_id',
        'position_number',
        'name',
        'description',
        'work_type_id',
        'measurement_unit_id',
        'quantity',
        'quantity_coefficient',
        'quantity_total',
        'unit_price',
        'base_unit_price',
        'price_index',
        'current_unit_price',
        'price_coefficient',
        'direct_costs',
        'overhead_amount',
        'profit_amount',
        'total_amount',
        'current_total_amount',
        'justification',
        'is_manual',
        'metadata',
    ];

    protected $casts = [
        'quantity' => 'decimal:4',
        'quantity_coefficient' => 'decimal:4',
        'quantity_total' => 'decimal:4',
        'unit_price' => 'decimal:2',
        'base_unit_price' => 'decimal:2',
        'price_index' => 'decimal:4',
        'current_unit_price' => 'decimal:2',
        'price_coefficient' => 'decimal:4',
        'direct_costs' => 'decimal:2',
        'overhead_amount' => 'decimal:2',
        'profit_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'current_total_amount' => 'decimal:2',
        'is_manual' => 'boolean',
        'metadata' => 'array',
    ];

    public function estimate(): BelongsTo
    {
        return $this->belongsTo(Estimate::class);
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(EstimateSection::class, 'estimate_section_id');
    }

    public function workType(): BelongsTo
    {
        return $this->belongsTo(WorkType::class);
    }

    public function measurementUnit(): BelongsTo
    {
        return $this->belongsTo(MeasurementUnit::class);
    }

    public function resources(): HasMany
    {
        return $this->hasMany(EstimateItemResource::class);
    }

    public function scopeByEstimate($query, int $estimateId)
    {
        return $query->where('estimate_id', $estimateId);
    }

    public function scopeBySection($query, int $sectionId)
    {
        return $query->where('estimate_section_id', $sectionId);
    }

    public function scopeManual($query)
    {
        return $query->where('is_manual', true);
    }

    public function scopeFromCatalog($query)
    {
        return $query->where('is_manual', false);
    }

    public function calculateTotal(): float
    {
        return $this->direct_costs + $this->overhead_amount + $this->profit_amount;
    }

    public function hasResources(): bool
    {
        return $this->resources()->exists();
    }
}

