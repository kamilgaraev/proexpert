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
        'normative_rate_id',
        'normative_rate_code',
        'item_type',
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
        'materials_cost',
        'machinery_cost',
        'labor_cost',
        'equipment_cost',
        'labor_hours',
        'machinery_hours',
        'base_materials_cost',
        'base_machinery_cost',
        'base_labor_cost',
        'materials_index',
        'machinery_index',
        'labor_index',
        'applied_coefficients',
        'coefficient_total',
        'resource_calculation',
        'custom_resources',
        'overhead_amount',
        'profit_amount',
        'total_amount',
        'current_total_amount',
        'justification',
        'notes',
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
        'materials_cost' => 'decimal:2',
        'machinery_cost' => 'decimal:2',
        'labor_cost' => 'decimal:2',
        'equipment_cost' => 'decimal:2',
        'labor_hours' => 'decimal:4',
        'machinery_hours' => 'decimal:4',
        'base_materials_cost' => 'decimal:2',
        'base_machinery_cost' => 'decimal:2',
        'base_labor_cost' => 'decimal:2',
        'materials_index' => 'decimal:4',
        'machinery_index' => 'decimal:4',
        'labor_index' => 'decimal:4',
        'applied_coefficients' => 'array',
        'coefficient_total' => 'decimal:4',
        'resource_calculation' => 'array',
        'custom_resources' => 'array',
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

    public function normativeRate(): BelongsTo
    {
        return $this->belongsTo(NormativeRate::class, 'normative_rate_id');
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

    public function scopeWorks($query)
    {
        return $query->where('item_type', 'work');
    }

    public function scopeMaterials($query)
    {
        return $query->where('item_type', 'material');
    }

    public function scopeEquipment($query)
    {
        return $query->where('item_type', 'equipment');
    }

    public function scopeLabor($query)
    {
        return $query->where('item_type', 'labor');
    }

    public function scopeSummary($query)
    {
        return $query->where('item_type', 'summary');
    }

    public function scopeByType($query, string $type)
    {
        return $query->where('item_type', $type);
    }

    public function scopeFromNormative($query)
    {
        return $query->whereNotNull('normative_rate_id');
    }

    public function scopeWithNormativeRate($query)
    {
        return $query->with('normativeRate');
    }

    public function isWork(): bool
    {
        return $this->item_type === 'work';
    }

    public function isMaterial(): bool
    {
        return $this->item_type === 'material';
    }

    public function isEquipment(): bool
    {
        return $this->item_type === 'equipment';
    }

    public function isLabor(): bool
    {
        return $this->item_type === 'labor';
    }

    public function isSummary(): bool
    {
        return $this->item_type === 'summary';
    }

    public function calculateTotal(): float
    {
        return $this->direct_costs + $this->overhead_amount + $this->profit_amount;
    }

    public function hasResources(): bool
    {
        return $this->resources()->exists();
    }

    public function isFromNormative(): bool
    {
        return !is_null($this->normative_rate_id);
    }

    public function hasAppliedCoefficients(): bool
    {
        return !empty($this->applied_coefficients);
    }

    public function getTotalCostBreakdown(): array
    {
        return [
            'materials' => (float) $this->materials_cost,
            'machinery' => (float) $this->machinery_cost,
            'labor' => (float) $this->labor_cost,
            'equipment' => (float) $this->equipment_cost,
            'overhead' => (float) $this->overhead_amount,
            'profit' => (float) $this->profit_amount,
            'total' => (float) $this->total_amount,
        ];
    }

    public function resolveRouteBinding($value, $field = null)
    {
        $item = static::with('estimate')
            ->where($this->getRouteKeyName(), $value)
            ->firstOrFail();
        
        $user = request()->user();
        if ($user && $user->current_organization_id) {
            if ($item->estimate && $item->estimate->organization_id !== $user->current_organization_id) {
                abort(403, 'У вас нет доступа к этой позиции сметы');
            }
        }
        
        return $item;
    }

    public function getEffectiveCoefficient(): float
    {
        return (float) ($this->coefficient_total ?? 1.0);
    }
}


