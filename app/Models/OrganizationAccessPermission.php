<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrganizationAccessPermission extends Model
{
    use HasFactory;

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

    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by_user_id');
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isValid(): bool
    {
        return $this->is_active && !$this->isExpired();
    }

    public function hasPermission(string $permission): bool
    {
        if (!$this->isValid()) {
            return false;
        }

        if (!$this->permissions) {
            return false;
        }

        return in_array($permission, $this->permissions) || in_array('*', $this->permissions);
    }

    public function canAccess(string $resourceType, string $permission): bool
    {
        return $this->resource_type === $resourceType && $this->hasPermission($permission);
    }
} 