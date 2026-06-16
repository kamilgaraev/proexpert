<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\CommercialProposals\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class CommercialProposalApproval extends CommercialProposalModel
{
    protected $fillable = [
        'organization_id',
        'commercial_proposal_id',
        'commercial_proposal_version_id',
        'requested_by_user_id',
        'decided_by_user_id',
        'status',
        'comment',
        'requested_at',
        'decided_at',
    ];

    protected $casts = [
        'requested_at' => 'datetime',
        'decided_at' => 'datetime',
    ];

    protected $attributes = [
        'status' => 'pending',
    ];

    public function proposal(): BelongsTo
    {
        return $this->belongsTo(CommercialProposal::class, 'commercial_proposal_id');
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(CommercialProposalVersion::class, 'commercial_proposal_version_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function decisionMaker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_user_id');
    }
}
