<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Enums\ProjectOrganizationRole;
use App\Domain\Project\ValueObjects\ProjectRoleConfig;

/**
 * Модель участия организации в проекте (Custom Pivot)
 * 
 * Преобразована из простого pivot в полноценную Entity
 */
class ProjectOrganization extends Pivot
{
    protected $table = 'project_organization';
    
    protected $fillable = [
        'project_id',
        'organization_id',
        'role',
        'role_new',
        'permissions',
        'is_active',
        'added_by_user_id',
        'invited_at',
        'accepted_at',
        'metadata',
    ];
    
    protected $casts = [
        'permissions' => 'array',
        'is_active' => 'boolean',
        'invited_at' => 'datetime',
        'accepted_at' => 'datetime',
        'metadata' => 'array',
    ];
    
    /**
     * Relations
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
    
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
    
    public function addedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'added_by_user_id');
    }
    
    /**
     * Scopes
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
    
    public function scopeByRole($query, ProjectOrganizationRole $role)
    {
        return $query->where('role_new', $role->value);
    }
    
    /**
     * Accessors - используем role_new для новой системы
     */
    public function getRoleAttribute(): ProjectOrganizationRole
    {
        // Приоритет: role_new, fallback на role
        $roleValue = $this->attributes['role_new'] ?? $this->attributes['role'] ?? 'contractor';
        
        return ProjectOrganizationRole::tryFrom($roleValue) 
            ?? ProjectOrganizationRole::CONTRACTOR;
    }
    
    /**
     * Domain methods
     */
    public function hasPermission(string $permission): bool
    {
        $roleConfig = $this->getRoleConfig();
        return $roleConfig->hasPermission($permission);
    }
    
    public function shouldAutoFillContractor(): bool
    {
        return $this->getRoleConfig()->shouldAutoFillContractor();
    }
    
    public function canCreateWorks(): bool
    {
        return $this->getRoleConfig()->canCreateWorks();
    }
    
    public function canApproveWorks(): bool
    {
        return $this->getRoleConfig()->canApproveWorks();
    }
    
    public function viewsOnlyOwn(): bool
    {
        return $this->getRoleConfig()->viewsOnlyOwn();
    }
    
    public function getRoleConfig(): ProjectRoleConfig
    {
        return $this->role->config();
    }
    
    /**
     * Проверка активности
     */
    public function isActive(): bool
    {
        return $this->is_active;
    }
}

