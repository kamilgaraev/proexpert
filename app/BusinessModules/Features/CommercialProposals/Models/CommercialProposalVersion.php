<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\CommercialProposals\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class CommercialProposalVersion extends CommercialProposalModel
{
    protected $fillable = [
        'organization_id',
        'commercial_proposal_id',
        'version_number',
        'status',
        'title',
        'sections_snapshot',
        'source_links_snapshot',
        'terms_snapshot',
        'totals_snapshot',
        'diff_summary',
        'content_hash',
        'template_version_hash',
        'created_by_user_id',
        'submitted_at',
        'approved_at',
        'sent_at',
        'customer_decision_at',
        'locked_at',
    ];

    protected $casts = [
        'version_number' => 'integer',
        'sections_snapshot' => 'array',
        'source_links_snapshot' => 'array',
        'terms_snapshot' => 'array',
        'totals_snapshot' => 'array',
        'diff_summary' => 'array',
        'submitted_at' => 'datetime',
        'approved_at' => 'datetime',
        'sent_at' => 'datetime',
        'customer_decision_at' => 'datetime',
        'locked_at' => 'datetime',
    ];

    protected $attributes = [
        'status' => 'draft',
        'sections_snapshot' => '[]',
        'source_links_snapshot' => '{}',
        'terms_snapshot' => '{}',
        'totals_snapshot' => '{}',
        'diff_summary' => '{}',
    ];

    public function proposal(): BelongsTo
    {
        return $this->belongsTo(CommercialProposal::class, 'commercial_proposal_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function sections(): HasMany
    {
        return $this->hasMany(CommercialProposalSection::class, 'commercial_proposal_version_id')
            ->orderBy('sort_order');
    }

    public function lineItems(): HasMany
    {
        return $this->hasMany(CommercialProposalLineItem::class, 'commercial_proposal_version_id')
            ->orderBy('sort_order');
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(CommercialProposalApproval::class, 'commercial_proposal_version_id')
            ->orderByDesc('requested_at');
    }

    public function exports(): HasMany
    {
        return $this->hasMany(CommercialProposalExport::class, 'commercial_proposal_version_id')
            ->orderByDesc('generated_at');
    }

    public function scopeCurrentForProposal(Builder $query, CommercialProposal $proposal): Builder
    {
        return $query->whereKey($proposal->current_version_id);
    }
}
