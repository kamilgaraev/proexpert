<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrganizationDispute extends Model
{
    protected $fillable = [
        'reporter_user_id',
        'reporter_organization_id',
        'disputed_organization_id',
        'dispute_type',
        'reason',
        'evidence',
        'status',
        'priority',
        'assigned_to_moderator_id',
        'moderator_notes',
        'resolved_at',
        'resolution',
        'actions_taken',
    ];

    protected $casts = [
        'evidence' => 'array',
        'actions_taken' => 'array',
        'resolved_at' => 'datetime',
    ];

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_user_id');
    }

    public function reporterOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'reporter_organization_id');
    }

    public function disputedOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'disputed_organization_id');
    }

    public function moderator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_moderator_id');
    }

    public function isUnderInvestigation(): bool
    {
        return $this->status === 'under_investigation';
    }

    public function isResolved(): bool
    {
        return in_array($this->status, ['resolved', 'dismissed']);
    }

    public function scopeUnderInvestigation($query)
    {
        return $query->where('status', 'under_investigation');
    }

    public function scopeResolved($query)
    {
        return $query->whereIn('status', ['resolved', 'dismissed']);
    }

    public function scopeByPriority($query, string $priority)
    {
        return $query->where('priority', $priority);
    }
}

