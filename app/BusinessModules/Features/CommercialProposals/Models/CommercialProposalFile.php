<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\CommercialProposals\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class CommercialProposalFile extends CommercialProposalModel
{
    protected $fillable = [
        'organization_id',
        'commercial_proposal_id',
        'commercial_proposal_version_id',
        'uploaded_by_user_id',
        'category',
        'original_name',
        'storage_path',
        'mime_type',
        'size_bytes',
        'checksum',
        'metadata',
    ];

    protected $casts = [
        'size_bytes' => 'integer',
        'metadata' => 'array',
    ];

    protected $attributes = [
        'category' => 'attachment',
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

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }
}
