<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrganizationAccessPermission extends Model
{
    protected $fillable = [
        'granted_to_organization_id',
        'target_organization_id',
        'resource_type',
        'permissions',
        'access_level',
        'is_active',
        'expires_at',
        'granted_by_user_id',
    ];

    protected $casts = [
        'permissions' => 'array',
        'is_active' => 'boolean',
        'expires_at' => 'datetime',
    ];

    public function grantedToOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'granted_to_organization_id');
    }

    public function targetOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'target_organization_id');
    }

    public function grantedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by_user_id');
    }

    public function isActive(): bool
    {
        if (!$this->is_active) {
            return false;
        }

        if ($this->expires_at && $this->expires_at->isPast()) {
            return false;
        }

        return true;
    }

    public function hasPermission(string $permission): bool
    {
        if (!$this->isActive()) {
            return false;
        }

        $permissions = $this->permissions ?? [];
        return in_array($permission, $permissions);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('expires_at')
                  ->orWhere('expires_at', '>', now());
            });
    }

    public function scopeForOrganizations($query, int $grantedToId, int $targetId)
    {
        return $query->where('granted_to_organization_id', $grantedToId)
            ->where('target_organization_id', $targetId);
    }

    public function scopeByResourceType($query, string $resourceType)
    {
        return $query->where('resource_type', $resourceType);
    }
}
