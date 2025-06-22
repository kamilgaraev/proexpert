<?php

namespace App\Services;

use App\Models\OrganizationRole;
use App\Models\User;
use App\Models\Organization;
use App\Exceptions\BusinessLogicException;
use Illuminate\Support\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OrganizationRoleService
{
    public function getAllRolesForOrganization(int $organizationId): Collection
    {
        return OrganizationRole::forOrganization($organizationId)
            ->active()
            ->ordered()
            ->get();
    }

    public function createRole(array $data, int $organizationId, User $createdBy): OrganizationRole
    {
        $this->validateRoleData($data, $organizationId);
        
        DB::beginTransaction();
        try {
            $role = OrganizationRole::create([
                'organization_id' => $organizationId,
                'name' => $data['name'],
                'slug' => $data['slug'] ?? Str::slug($data['name']),
                'description' => $data['description'] ?? null,
                'permissions' => $data['permissions'] ?? [],
                'color' => $data['color'] ?? '#6B7280',
                'is_active' => $data['is_active'] ?? true,
                'display_order' => $data['display_order'] ?? 0,
            ]);

            DB::commit();
            return $role;
        } catch (\Exception $e) {
            DB::rollBack();
            throw new BusinessLogicException('Ошибка при создании роли: ' . $e->getMessage());
        }
    }

    public function updateRole(int $roleId, array $data, int $organizationId): OrganizationRole
    {
        $role = $this->findRoleOrFail($roleId, $organizationId);
        
        if ($role->is_system) {
            throw new BusinessLogicException('Системные роли нельзя изменять');
        }

        $this->validateRoleData($data, $organizationId, $roleId);

        DB::beginTransaction();
        try {
            $role->update([
                'name' => $data['name'] ?? $role->name,
                'slug' => $data['slug'] ?? $role->slug,
                'description' => $data['description'] ?? $role->description,
                'permissions' => $data['permissions'] ?? $role->permissions,
                'color' => $data['color'] ?? $role->color,
                'is_active' => $data['is_active'] ?? $role->is_active,
                'display_order' => $data['display_order'] ?? $role->display_order,
            ]);

            DB::commit();
            return $role->fresh();
        } catch (\Exception $e) {
            DB::rollBack();
            throw new BusinessLogicException('Ошибка при обновлении роли: ' . $e->getMessage());
        }
    }

    public function deleteRole(int $roleId, int $organizationId): bool
    {
        $role = $this->findRoleOrFail($roleId, $organizationId);
        
        if ($role->is_system) {
            throw new BusinessLogicException('Системные роли нельзя удалять');
        }

        $usersCount = $role->users()->count();
        if ($usersCount > 0) {
            throw new BusinessLogicException("Нельзя удалить роль, которая назначена {$usersCount} пользователям");
        }

        return $role->delete();
    }

    public function assignRoleToUser(int $roleId, int $userId, int $organizationId, User $assignedBy): void
    {
        $role = $this->findRoleOrFail($roleId, $organizationId);
        $user = User::findOrFail($userId);

        if (!$user->belongsToOrganization($organizationId)) {
            throw new BusinessLogicException('Пользователь не принадлежит к данной организации');
        }

        if ($role->users()->where('user_id', $userId)->exists()) {
            throw new BusinessLogicException('Пользователь уже имеет эту роль');
        }

        $role->users()->attach($userId, [
            'organization_id' => $organizationId,
            'assigned_at' => now(),
            'assigned_by_user_id' => $assignedBy->id,
        ]);
    }

    public function removeRoleFromUser(int $roleId, int $userId, int $organizationId): void
    {
        $role = $this->findRoleOrFail($roleId, $organizationId);
        
        $role->users()->detach($userId);
    }

    public function getUserRoles(int $userId, int $organizationId): Collection
    {
        return OrganizationRole::whereHas('users', function ($query) use ($userId, $organizationId) {
            $query->where('user_id', $userId)
                  ->where('organization_id', $organizationId);
        })->get();
    }

    public function getRoleUsers(int $roleId, int $organizationId): Collection
    {
        $role = $this->findRoleOrFail($roleId, $organizationId);
        
        return $role->users()
            ->wherePivot('organization_id', $organizationId)
            ->with(['profile'])
            ->get();
    }

    public function userHasPermission(int $userId, int $organizationId, string $permission): bool
    {
        $userRoles = $this->getUserRoles($userId, $organizationId);
        
        foreach ($userRoles as $role) {
            if ($role->hasPermission($permission)) {
                return true;
            }
        }
        
        return false;
    }

    public function getAllAvailablePermissions(): array
    {
        return OrganizationRole::getAllAvailablePermissions();
    }

    public function getPermissionsGrouped(): array
    {
        $permissions = $this->getAllAvailablePermissions();
        return collect($permissions)->groupBy('group')->toArray();
    }

    private function findRoleOrFail(int $roleId, int $organizationId): OrganizationRole
    {
        $role = OrganizationRole::where('id', $roleId)
            ->where('organization_id', $organizationId)
            ->first();

        if (!$role) {
            throw new BusinessLogicException('Роль не найдена');
        }

        return $role;
    }

    private function validateRoleData(array $data, int $organizationId, ?int $excludeRoleId = null): void
    {
        if (empty($data['name'])) {
            throw new BusinessLogicException('Название роли обязательно');
        }

        $slug = $data['slug'] ?? Str::slug($data['name']);
        
        $query = OrganizationRole::where('organization_id', $organizationId)
            ->where('slug', $slug);
            
        if ($excludeRoleId) {
            $query->where('id', '!=', $excludeRoleId);
        }
        
        if ($query->exists()) {
            throw new BusinessLogicException('Роль с таким названием уже существует в организации');
        }

        if (isset($data['permissions']) && !is_array($data['permissions'])) {
            throw new BusinessLogicException('Разрешения должны быть массивом');
        }

        if (isset($data['permissions'])) {
            $availablePermissions = collect($this->getAllAvailablePermissions())->pluck('slug')->toArray();
            $invalidPermissions = array_diff($data['permissions'], $availablePermissions);
            
            if (!empty($invalidPermissions)) {
                throw new BusinessLogicException('Недопустимые разрешения: ' . implode(', ', $invalidPermissions));
            }
        }
    }

    public function duplicateRole(int $roleId, int $organizationId, string $newName, User $createdBy): OrganizationRole
    {
        $originalRole = $this->findRoleOrFail($roleId, $organizationId);
        
        return $this->createRole([
            'name' => $newName,
            'description' => $originalRole->description,
            'permissions' => $originalRole->permissions,
            'color' => $originalRole->color,
            'is_active' => true,
            'display_order' => $originalRole->display_order + 1,
        ], $organizationId, $createdBy);
    }

    public function getSystemRolesForOrganization(): array
    {
        return [
            [
                'slug' => 'organization_owner',
                'name' => 'Владелец организации',
                'description' => 'Полные права в организации',
                'color' => '#DC2626',
                'is_system' => true,
                'permissions' => ['*'],
            ],
            [
                'slug' => 'organization_admin',
                'name' => 'Администратор организации',
                'description' => 'Административные права в организации',
                'color' => '#EA580C',
                'is_system' => true,
                'permissions' => ['users.*', 'projects.*', 'contracts.*', 'materials.*', 'reports.*', 'settings.view'],
            ],
            [
                'slug' => 'foreman',
                'name' => 'Прораб',
                'description' => 'Управление проектами и материалами',
                'color' => '#0891B2',
                'is_system' => true,
                'permissions' => ['projects.view', 'projects.edit', 'materials.view', 'materials.edit', 'reports.view'],
            ],
            [
                'slug' => 'web_admin',
                'name' => 'Веб-администратор',
                'description' => 'Управление пользователями и настройками',
                'color' => '#7C3AED',
                'is_system' => true,
                'permissions' => ['users.*', 'settings.*', 'reports.view'],
            ],
            [
                'slug' => 'accountant',
                'name' => 'Бухгалтер',
                'description' => 'Доступ к финансовым данным и отчетам',
                'color' => '#059669',
                'is_system' => true,
                'permissions' => ['finance.*', 'reports.*'],
            ],
        ];
    }
} 