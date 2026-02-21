<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Traits\HasOnboardingDemo;

class Estimate extends Model
{
    use HasFactory, SoftDeletes, HasOnboardingDemo;

    protected $fillable = [
        'organization_id',
        'project_id',
        'contract_id',
        'number',
        'name',
        'description',
        'type',
        'status',
        'version',
        'parent_estimate_id',
        'estimate_date',
        'base_price_date',
        'total_direct_costs',
        'total_overhead_costs',
        'total_estimated_profit',
        'total_equipment_costs',
        'total_amount',
        'total_amount_with_vat',
        'vat_rate',
        'overhead_rate',
        'profit_rate',
        'calculation_method',
        'approved_at',
        'approved_by_user_id',
        'metadata',
        'import_diagnostics',
        'is_onboarding_demo',
        'total_base_direct_costs',
        'total_base_materials_cost',
        'total_base_machinery_cost',
        'total_base_labor_cost',
        'statistics',
        'structure_cache_path',
    ];

    protected $casts = [
        'estimate_date' => 'date',
        'base_price_date' => 'date',
        'total_direct_costs' => 'decimal:2',
        'total_overhead_costs' => 'decimal:2',
        'total_estimated_profit' => 'decimal:2',
        'total_equipment_costs' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'total_amount_with_vat' => 'decimal:2',
        'total_base_direct_costs' => 'decimal:2',
        'total_base_materials_cost' => 'decimal:2',
        'total_base_machinery_cost' => 'decimal:2',
        'total_base_labor_cost' => 'decimal:2',
        'vat_rate' => 'decimal:2',
        'overhead_rate' => 'decimal:2',
        'profit_rate' => 'decimal:2',
        'approved_at' => 'datetime',
        'metadata' => 'array',
        'import_diagnostics' => 'array',
        'statistics' => 'array',
        'is_onboarding_demo' => 'boolean',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function parentEstimate(): BelongsTo
    {
        return $this->belongsTo(Estimate::class, 'parent_estimate_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(Estimate::class, 'parent_estimate_id');
    }

    public function sections(): HasMany
    {
        return $this->hasMany(EstimateSection::class)->orderBy('sort_order');
    }

    public function items(): HasMany
    {
        return $this->hasMany(EstimateItem::class)->orderBy('position_number');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    public function importHistory(): HasMany
    {
        return $this->hasMany(EstimateImportHistory::class);
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(ProjectSchedule::class, 'estimate_id');
    }

    public function scopeDraft($query)
    {
        return $query->where('status', 'draft');
    }

    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }

    public function scopeInReview($query)
    {
        return $query->where('status', 'in_review');
    }

    public function scopeByOrganization($query, int $organizationId)
    {
        return $query->where('organization_id', $organizationId);
    }

    public function scopeByProject($query, int $projectId)
    {
        return $query->where('project_id', $projectId);
    }

    public function scopeByContract($query, int $contractId)
    {
        return $query->where('contract_id', $contractId);
    }

    public function resolveRouteBinding($value, $field = null)
    {
        $user = request()->user();
        $orgId = $user?->current_organization_id;
        $projectId = request()->route('project');
        
        $query = static::where($this->getRouteKeyName(), $value);
        
        if ($projectId) {
            $query->where('project_id', (int)$projectId);
        }
        
        $estimate = $query->first();
        
        if (!$estimate) {
            return null;
        }
        
        if ($orgId && $estimate->organization_id !== $orgId) {
            return null;
        }
        
        return $estimate;
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function canBeEdited(): bool
    {
        return $this->status === 'draft';
    }

    public function canBeApproved(): bool
    {
        return $this->status === 'in_review';
    }
}

