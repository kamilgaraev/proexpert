<?php

namespace App\Models;

use App\Services\Security\SystemAdminRoleService;
use Filament\Panel;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Filament\Models\Contracts\FilamentUser;

class SystemAdmin extends Authenticatable implements FilamentUser
{
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'is_active',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'is_active' => 'boolean',
    ];

    public function canAccessPanel(Panel $panel): bool
    {
        return $panel->getId() === 'admin'
            && $this->isActive()
            && app(SystemAdminRoleService::class)->canAccessInterface($this, 'admin')
            && $this->hasSystemPermission('system_admin.access');
    }

    public function getRoleSlug(): string
    {
        return app(SystemAdminRoleService::class)->resolveRoleSlug($this);
    }

    public function getRoleDefinition(): ?array
    {
        return app(SystemAdminRoleService::class)->getRole($this->getRoleSlug());
    }

    public function getRoleName(): string
    {
        return (string) ($this->getRoleDefinition()['name'] ?? $this->getRoleSlug());
    }

    public function getPermissionLabels(): array
    {
        return app(SystemAdminRoleService::class)->getPermissionLabels($this);
    }

    public function hasSystemRole(string $roleSlug): bool
    {
        return $this->getRoleSlug() === $roleSlug;
    }

    public function hasSystemPermission(string $permission): bool
    {
        return app(SystemAdminRoleService::class)->hasPermission($this, $permission);
    }

    public function isSuperAdmin(): bool
    {
        return app(SystemAdminRoleService::class)->isSuperAdmin($this);
    }

    public function canManageRoleSlug(string $roleSlug): bool
    {
        return app(SystemAdminRoleService::class)->canManageRole($this, $roleSlug);
    }

    public function isActive(): bool
    {
        return (bool) ($this->is_active ?? true);
    }
}
