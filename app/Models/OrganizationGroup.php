<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrganizationGroup extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'parent_organization_id',
        'created_by_user_id',
        'status',
        'settings',
        'permissions_config',
        'max_child_organizations',
    ];

    protected $casts = [
        'settings' => 'array',
        'permissions_config' => 'array',
        'max_child_organizations' => 'integer',
    ];

    public function parentOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'parent_organization_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function childOrganizations(): HasMany
    {
        return $this->hasMany(Organization::class, 'parent_organization_id', 'parent_organization_id');
    }

    public function accessPermissions(): HasMany
    {
        return $this->hasMany(OrganizationAccessPermission::class, 'granted_to_organization_id', 'parent_organization_id');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function canAddChildOrganization(): bool
    {
        return $this->childOrganizations()->count() < $this->max_child_organizations;
    }

    public function getActiveChildOrganizations()
    {
        return $this->childOrganizations()
            ->where('organization_type', 'child')
            ->where('status', 'active');
    }

    public function getTotalUsers(): int
    {
        $parentUsers = $this->parentOrganization->users()->count();
        $childUsers = $this->childOrganizations()->withCount('users')->get()->sum('users_count');
        
        return $parentUsers + $childUsers;
    }

    public function getTotalProjects(): int
    {
        $parentProjects = $this->parentOrganization->projects()->count();
        $childProjects = $this->childOrganizations()
            ->withCount('projects')
            ->get()
            ->sum('projects_count');
        
        return $parentProjects + $childProjects;
    }
} 