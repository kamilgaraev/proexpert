<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\CommercialProposals\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class CommercialProposalLineItem extends CommercialProposalModel
{
    protected $fillable = [
        'organization_id',
        'commercial_proposal_id',
        'commercial_proposal_version_id',
        'commercial_proposal_section_id',
        'title',
        'description',
        'unit',
        'quantity',
        'unit_price',
        'discount_amount',
        'vat_rate',
        'subtotal_amount',
        'total_amount',
        'sort_order',
        'metadata',
    ];

    protected $casts = [
        'quantity' => 'decimal:4',
        'unit_price' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'vat_rate' => 'decimal:2',
        'subtotal_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'sort_order' => 'integer',
        'metadata' => 'array',
    ];

    protected $attributes = [
        'quantity' => 1,
        'unit_price' => 0,
        'discount_amount' => 0,
        'subtotal_amount' => 0,
        'total_amount' => 0,
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

    public function section(): BelongsTo
    {
        return $this->belongsTo(CommercialProposalSection::class, 'commercial_proposal_section_id');
    }
}
