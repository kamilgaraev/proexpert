<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\CommercialProposals\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class CommercialProposalSection extends CommercialProposalModel
{
    protected $fillable = [
        'organization_id',
        'commercial_proposal_id',
        'commercial_proposal_version_id',
        'title',
        'body',
        'sort_order',
        'metadata',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'metadata' => 'array',
    ];

    protected $attributes = [
        'metadata' => '{}',
    ];

    public function proposal(): BelongsTo
    {
        return $this->belongsTo(CommercialProposal::class, 'commercial_proposal_id');
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(CommercialProposalVersion::class, 'commercial_proposal_version_id');
    }

    public function lineItems(): HasMany
    {
        return $this->hasMany(CommercialProposalLineItem::class, 'commercial_proposal_section_id')
            ->orderBy('sort_order');
    }
}
