<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\CommercialProposals\Models;

use App\BusinessModules\Features\CommercialProposals\Enums\CommercialProposalStatus;
use App\BusinessModules\Features\Crm\Models\CrmDeal;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

final class CommercialProposal extends CommercialProposalModel
{
    use SoftDeletes;

    protected $fillable = [
        'organization_id',
        'current_version_id',
        'accepted_version_id',
        'crm_deal_id',
        'tender_id',
        'presale_estimate_id',
        'project_id',
        'contract_id',
        'number',
        'title',
        'status',
        'customer_name',
        'customer_email',
        'customer_phone',
        'subtotal_amount',
        'discount_amount',
        'vat_amount',
        'total_amount',
        'currency',
        'valid_until',
        'sent_at',
        'customer_decision_at',
        'archived_at',
        'created_by_user_id',
        'updated_by_user_id',
        'metadata',
    ];

    protected $casts = [
        'status' => CommercialProposalStatus::class,
        'project_id' => 'integer',
        'contract_id' => 'integer',
        'subtotal_amount' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'vat_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'valid_until' => 'date',
        'sent_at' => 'datetime',
        'customer_decision_at' => 'datetime',
        'archived_at' => 'datetime',
        'metadata' => 'array',
    ];

    protected $attributes = [
        'status' => 'draft',
        'currency' => 'RUB',
        'metadata' => '{}',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function crmDeal(): BelongsTo
    {
        return $this->belongsTo(CrmDeal::class, 'crm_deal_id');
    }

    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(CommercialProposalVersion::class, 'current_version_id');
    }

    public function acceptedVersion(): BelongsTo
    {
        return $this->belongsTo(CommercialProposalVersion::class, 'accepted_version_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(CommercialProposalVersion::class, 'commercial_proposal_id')
            ->orderByDesc('version_number');
    }

    public function files(): HasMany
    {
        return $this->hasMany(CommercialProposalFile::class, 'commercial_proposal_id')
            ->orderByDesc('created_at');
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(CommercialProposalApproval::class, 'commercial_proposal_id')
            ->orderByDesc('requested_at');
    }

    public function pendingApproval(): HasOne
    {
        return $this->hasOne(CommercialProposalApproval::class, 'commercial_proposal_id')
            ->where('status', 'pending');
    }

    public function sentEvents(): HasMany
    {
        return $this->hasMany(CommercialProposalSentEvent::class, 'commercial_proposal_id')
            ->orderByDesc('sent_at');
    }

    public function timelineEvents(): HasMany
    {
        return $this->hasMany(CommercialProposalTimelineEvent::class, 'commercial_proposal_id')
            ->orderByDesc('occurred_at');
    }

    public function exports(): HasMany
    {
        return $this->hasMany(CommercialProposalExport::class, 'commercial_proposal_id')
            ->orderByDesc('generated_at')
            ->orderByDesc('created_at');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    public function scopeWithStatus(Builder $query, string $status): Builder
    {
        return $query->where($this->getTable() . '.status', $status);
    }

    public function scopeSearch(Builder $query, string $search): Builder
    {
        $term = trim($search);

        if ($term === '') {
            return $query;
        }

        return $query->where(function (Builder $builder) use ($term): void {
            $builder
                ->where($this->getTable() . '.number', 'ilike', "%{$term}%")
                ->orWhere($this->getTable() . '.title', 'ilike', "%{$term}%")
                ->orWhere($this->getTable() . '.customer_name', 'ilike', "%{$term}%");
        });
    }
}
