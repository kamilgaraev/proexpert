<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\CommercialProposals\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class CommercialProposalTimelineEvent extends CommercialProposalModel
{
    protected $fillable = [
        'organization_id',
        'commercial_proposal_id',
        'commercial_proposal_version_id',
        'actor_user_id',
        'event_type',
        'from_status',
        'to_status',
        'payload',
        'occurred_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'occurred_at' => 'datetime',
    ];

    protected $attributes = [
        'payload' => '{}',
    ];

    public function proposal(): BelongsTo
    {
        return $this->belongsTo(CommercialProposal::class, 'commercial_proposal_id');
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(CommercialProposalVersion::class, 'commercial_proposal_version_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
