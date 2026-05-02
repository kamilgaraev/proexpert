<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Models;

use App\BusinessModules\Features\Procurement\Enums\SupplierProposalDecisionEnum;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class SupplierProposalDecision extends Model
{
    protected $table = 'supplier_proposal_decisions';

    protected $fillable = [
        'organization_id',
        'supplier_request_id',
        'winning_supplier_proposal_id',
        'winning_supplier_proposal_version_id',
        'cheapest_supplier_proposal_id',
        'cheapest_supplier_proposal_version_id',
        'status',
        'is_lowest_price_selected',
        'decision_reason',
        'comparison_snapshot',
        'selected_by',
        'selected_at',
    ];

    protected $casts = [
        'status' => SupplierProposalDecisionEnum::class,
        'is_lowest_price_selected' => 'boolean',
        'comparison_snapshot' => 'array',
        'selected_at' => 'datetime',
    ];

    protected $attributes = [
        'status' => 'draft',
        'is_lowest_price_selected' => false,
        'comparison_snapshot' => '[]',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function supplierRequest(): BelongsTo
    {
        return $this->belongsTo(SupplierRequest::class);
    }

    public function winningProposal(): BelongsTo
    {
        return $this->belongsTo(SupplierProposal::class, 'winning_supplier_proposal_id');
    }

    public function winningProposalVersion(): BelongsTo
    {
        return $this->belongsTo(SupplierProposalVersion::class, 'winning_supplier_proposal_version_id');
    }

    public function cheapestProposal(): BelongsTo
    {
        return $this->belongsTo(SupplierProposal::class, 'cheapest_supplier_proposal_id');
    }

    public function cheapestProposalVersion(): BelongsTo
    {
        return $this->belongsTo(SupplierProposalVersion::class, 'cheapest_supplier_proposal_version_id');
    }

    public function selectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'selected_by');
    }

    public function approvals(): MorphMany
    {
        return $this->morphMany(ProcurementApproval::class, 'approvable');
    }

    public function auditEvents(): HasMany
    {
        return $this->hasMany(ProcurementAuditEvent::class, 'subject_id')
            ->where('subject_type', $this->getMorphClass())
            ->orderBy('occurred_at')
            ->orderBy('id');
    }

    public function scopeForOrganization($query, int $organizationId)
    {
        return $query->where('organization_id', $organizationId);
    }
}
