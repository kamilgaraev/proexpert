<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrganizationAccessRestriction extends Model
{
    protected $fillable = [
        'organization_id',
        'restriction_type',
        'access_level',
        'allowed_actions',
        'blocked_actions',
        'reason',
        'expires_at',
        'can_be_lifted_early',
        'lift_conditions',
        'metadata',
    ];

    protected $casts = [
        'allowed_actions' => 'array',
        'blocked_actions' => 'array',
        'lift_conditions' => 'array',
        'metadata' => 'array',
        'expires_at' => 'datetime',
        'can_be_lifted_early' => 'boolean',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function isActive(): bool
    {
        return $this->expires_at === null || $this->expires_at->isFuture();
    }

    public function canPerformAction(string $action): bool
    {
        if (!$this->isActive()) {
            return true;
        }

        if (!empty($this->blocked_actions) && in_array($action, $this->blocked_actions)) {
            return false;
        }

        if (!empty($this->allowed_actions)) {
            return in_array($action, $this->allowed_actions);
        }

        return $this->access_level !== 'blocked';
    }

    public function scopeActive($query)
    {
        return $query->where(function($q) {
            $q->whereNull('expires_at')
              ->orWhere('expires_at', '>', now());
        });
    }

    public function scopeExpired($query)
    {
        return $query->whereNotNull('expires_at')
                     ->where('expires_at', '<=', now());
    }
}

