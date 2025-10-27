<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Estimate extends Model
{
    use HasFactory, SoftDeletes;

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
        'total_amount',
        'total_amount_with_vat',
        'vat_rate',
        'overhead_rate',
        'profit_rate',
        'calculation_method',
        'approved_at',
        'approved_by_user_id',
        'metadata',
    ];

    protected $casts = [
        'estimate_date' => 'date',
        'base_price_date' => 'date',
        'total_direct_costs' => 'decimal:2',
        'total_overhead_costs' => 'decimal:2',
        'total_estimated_profit' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'total_amount_with_vat' => 'decimal:2',
        'vat_rate' => 'decimal:2',
        'overhead_rate' => 'decimal:2',
        'profit_rate' => 'decimal:2',
        'approved_at' => 'datetime',
        'metadata' => 'array',
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
        return $this->hasMany(EstimateItem::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    public function importHistory(): HasMany
    {
        return $this->hasMany(EstimateImportHistory::class);
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
        
        \Log::info('[Estimate::resolveRouteBinding] Resolving', [
            'value' => $value,
            'field' => $field,
            'user_id' => $user?->id,
            'organization_id' => $orgId,
            'project_id' => $projectId,
        ]);
        
        $query = static::where($this->getRouteKeyName(), $value);
        
        if ($projectId) {
            $query->where('project_id', $projectId);
        }
        
        $estimate = $query->first();
        
        if (!$estimate) {
            \Log::warning('[Estimate::resolveRouteBinding] Not found', [
                'value' => $value,
                'project_id' => $projectId,
            ]);
            return null;
        }
        
        \Log::info('[Estimate::resolveRouteBinding] Found estimate', [
            'estimate_id' => $estimate->id,
            'estimate_org_id' => $estimate->organization_id,
            'estimate_project_id' => $estimate->project_id,
            'user_org_id' => $orgId,
            'route_project_id' => $projectId,
        ]);
        
        if ($orgId && $estimate->organization_id !== $orgId) {
            \Log::warning('[Estimate::resolveRouteBinding] Organization mismatch', [
                'estimate_id' => $estimate->id,
                'estimate_org_id' => $estimate->organization_id,
                'user_org_id' => $orgId,
            ]);
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

