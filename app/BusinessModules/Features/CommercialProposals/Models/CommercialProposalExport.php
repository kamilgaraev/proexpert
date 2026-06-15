<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\CommercialProposals\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class CommercialProposalExport extends CommercialProposalModel
{
    protected $fillable = [
        'organization_id',
        'commercial_proposal_id',
        'commercial_proposal_version_id',
        'requested_by_user_id',
        'format',
        'status',
        'content_hash',
        'template_version_hash',
        'options',
        'storage_path',
        'error_message',
        'generated_at',
    ];

    protected $casts = [
        'options' => 'array',
        'generated_at' => 'datetime',
    ];

    protected $attributes = [
        'format' => 'pdf',
        'status' => 'pending',
        'options' => '{}',
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
}
