<?php

namespace App\Services\Landing;

use App\Models\Organization;
use App\Models\OrganizationGroup;
use App\Models\OrganizationAccessPermission;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Hash;

class MultiOrganizationService
{
    public function createOrganizationGroup(User $user, array $data): OrganizationGroup
    {
        $organization = $user->currentOrganization;
        
        if (!$organization) {
            throw new \Exception('Организация не найдена');
        }

        if ($organization->is_holding ?? false) {
            throw new \Exception('Организация уже является холдингом');
        }

        return DB::transaction(function () use ($organization, $user, $data) {
            $organization->update([
                'organization_type' => 'parent',
                'is_holding' => true,
                'hierarchy_level' => 0,
                'hierarchy_path' => (string) $organization->id,
                'multi_org_settings' => $data['settings'] ?? []
            ]);

            $group = OrganizationGroup::create([
                'name' => $data['name'],
                'slug' => Str::slug($data['name']),
                'description' => $data['description'] ?? null,
                'parent_organization_id' => $organization->id,
                'created_by_user_id' => $user->id,
                'max_child_organizations' => $data['max_child_organizations'] ?? 10,
                'settings' => $data['settings'] ?? [],
                'permissions_config' => $data['permissions_config'] ?? $this->getDefaultPermissionsConfig(),
            ]);

            return $group;
        });
    }

    public function addChildOrganization(OrganizationGroup $group, array $organizationData, User $creator): array
    {
        if (!$group->canAddChildOrganization()) {
            throw new \Exception('Достигнут лимит дочерних организаций');
        }

        return DB::transaction(function () use ($group, $organizationData, $creator) {
            $ownerData = $organizationData['owner'] ?? null;
            if (!$ownerData) {
                throw new \Exception('Данные владельца не переданы');
            }

            // ищем или создаём пользователя-владельца
            $owner = \App\Models\User::where('email', $ownerData['email'])->first();

            if (!$owner) {
                $owner = \App\Models\User::create([
                    'name' => $ownerData['name'],
                    'email' => $ownerData['email'],
                    'password' => Hash::make($ownerData['password'] ?? Str::random(12)),
                ]);
            }

            $parentOrg = $group->parentOrganization;
            
            $childOrg = Organization::create([
                'name' => $organizationData['name'],
                'description' => $organizationData['description'] ?? null,
                'parent_organization_id' => $parentOrg->id,
                'organization_type' => 'child',
                'is_holding' => false,
                'hierarchy_level' => 1,
                'hierarchy_path' => $parentOrg->hierarchy_path . '.' . $parentOrg->id,
                'tax_number' => $organizationData['inn'] ?? null,
                'registration_number' => $organizationData['kpp'] ?? null,
                'address' => $organizationData['address'] ?? null,
                'phone' => $organizationData['phone'] ?? null,
                'email' => $organizationData['email'] ?? null,
            ]);

            $this->createDefaultAccessPermissions($parentOrg, $childOrg, $creator);

            // Привязываем владельца
            $childOrg->users()->attach($owner->id, [
                'is_owner' => true,
                'is_active' => true,
                'settings' => json_encode(['role' => 'organization_owner']),
            ]);

            // Обновляем текущий контекст владельца
            $owner->current_organization_id = $childOrg->id;
            $owner->save();

            return [
                'organization' => $childOrg,
                'owner_user' => $owner,
            ];
        });
    }

    public function getOrganizationHierarchy(int $organizationId): array
    {
        $organization = Organization::with([
            'childOrganizations', 
            'parentOrganization',
            'organizationGroup'
        ])->findOrFail($organizationId);
        
        if ($organization->organization_type === 'child') {
            $organization = $organization->parentOrganization->load('organizationGroup');
        }

        return [
            'parent' => $this->formatOrganizationData($organization),
            'children' => $organization->childOrganizations->map(function ($child) {
                return $this->formatOrganizationData($child);
            })->toArray(),
            'total_stats' => $this->getHierarchyStats($organization),
        ];
    }

    public function getAccessibleOrganizations(User $user): Collection
    {
        $userOrgId = $user->current_organization_id;
        $userOrg = Organization::find($userOrgId);

        if (!$userOrg) {
            return new Collection([]);
        }

        if ($userOrg->is_holding ?? false) {
            return Organization::where('parent_organization_id', $userOrgId)
                ->orWhere('id', $userOrgId)
                ->get();
        }

        if ($userOrg->parent_organization_id) {
            return new Collection([$userOrg]);
        }

        return new Collection([$userOrg]);
    }

    public function hasAccessToOrganization(User $user, int $targetOrgId, string $permission = 'read'): bool
    {
        $userOrgId = $user->current_organization_id;
        
        if ($userOrgId === $targetOrgId) {
            return true;
        }

        $userOrg = Organization::find($userOrgId);
        $targetOrg = Organization::find($targetOrgId);

        if (!$userOrg || !$targetOrg) {
            return false;
        }

        if (($userOrg->is_holding ?? false) && $targetOrg->parent_organization_id === $userOrgId) {
            return true;
        }

        return OrganizationAccessPermission::where('granted_to_organization_id', $userOrgId)
            ->where('target_organization_id', $targetOrgId)
            ->where('is_active', true)
            ->where(function ($query) {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->exists();
    }

    public function getOrganizationData(int $organizationId, User $user): array
    {
        if (!$this->hasAccessToOrganization($user, $organizationId)) {
            throw new \Exception('Нет доступа к данным организации');
        }

        $organization = Organization::with([
            'users',
            'projects',
            'contracts'
        ])->findOrFail($organizationId);

        return [
            'organization' => $this->formatOrganizationData($organization),
            'stats' => [
                'users_count' => $organization->users()->count(),
                'projects_count' => $organization->projects()->count(),
                'contracts_count' => $organization->contracts()->count(),
                'active_contracts_value' => $organization->contracts()
                    ->where('status', 'active')
                    ->sum('total_amount'),
            ],
            'recent_activity' => $this->getRecentActivity($organization),
        ];
    }

    private function createDefaultAccessPermissions(Organization $parentOrg, Organization $childOrg, User $creator): void
    {
        $defaultPermissions = [
            'projects' => ['read', 'create', 'edit', 'delete'],
            'contracts' => ['read', 'create', 'edit'],
            'materials' => ['read', 'create', 'edit'],
            'reports' => ['read', 'export'],
            'users' => ['read'],
        ];

        foreach ($defaultPermissions as $resourceType => $permissions) {
            OrganizationAccessPermission::create([
                'granted_to_organization_id' => $parentOrg->id,
                'target_organization_id' => $childOrg->id,
                'resource_type' => $resourceType,
                'permissions' => $permissions,
                'access_level' => 'admin',
                'granted_by_user_id' => $creator->id,
            ]);
        }
    }

    private function getDefaultPermissionsConfig(): array
    {
        return [
            'default_child_permissions' => [
                'projects' => ['read', 'create', 'edit'],
                'contracts' => ['read', 'create'],
                'materials' => ['read', 'create'],
                'reports' => ['read'],
                'users' => ['read'],
            ],
            'parent_permissions' => [
                'projects' => ['read', 'create', 'edit', 'delete'],
                'contracts' => ['read', 'create', 'edit', 'delete'],
                'materials' => ['read', 'create', 'edit', 'delete'],
                'reports' => ['read', 'export', 'admin'],
                'users' => ['read', 'create', 'edit'],
            ],
        ];
    }

    private function formatOrganizationData(Organization $organization): array
    {
        $data = [
            'id' => $organization->id,
            'name' => $organization->name,
            'organization_type' => $organization->organization_type ?? 'single',
            'is_holding' => $organization->is_holding ?? false,
            'hierarchy_level' => $organization->hierarchy_level ?? 0,
            'tax_number' => $organization->tax_number,
            'registration_number' => $organization->registration_number,
            'address' => $organization->address,
            'created_at' => $organization->created_at,
        ];

        if ($organization->is_holding && $organization->organizationGroup) {
            $data['slug'] = $organization->organizationGroup->slug;
        }

        return $data;
    }

    private function getHierarchyStats(Organization $parentOrg): array
    {
        $childOrgs = $parentOrg->childOrganizations;
        
        return [
            'total_organizations' => 1 + $childOrgs->count(),
            'total_users' => $parentOrg->users()->count() + $childOrgs->sum(fn($org) => $org->users()->count()),
            'total_projects' => $parentOrg->projects()->count() + $childOrgs->sum(fn($org) => $org->projects()->count()),
            'total_contracts' => $parentOrg->contracts()->count() + $childOrgs->sum(fn($org) => $org->contracts()->count()),
        ];
    }

    private function getRecentActivity(Organization $organization): array
    {
        return [
            'last_project_created' => $organization->projects()->latest()->first()?->created_at,
            'last_contract_signed' => $organization->contracts()->latest()->first()?->created_at,
            'last_user_added' => $organization->users()->latest('pivot_created_at')->first()?->pivot?->created_at,
        ];
    }

    public function getChildOrganizations(int $parentOrgId, array $filters = []): array
    {
        $organization = Organization::findOrFail($parentOrgId);
        
        if (!($organization->is_holding ?? false)) {
            throw new \Exception('Организация не является холдингом');
        }

        $query = $organization->childOrganizations()->with(['users']);

        if (!empty($filters['search'])) {
            $query->where(function ($q) use ($filters) {
                $q->where('name', 'like', '%' . $filters['search'] . '%')
                  ->orWhere('tax_number', 'like', '%' . $filters['search'] . '%');
            });
        }

        if (!empty($filters['status']) && $filters['status'] !== 'all') {
            $active = $filters['status'] === 'active';
            $query->where('is_active', $active);
        }

        switch ($filters['sort_by'] ?? 'name') {
            case 'created_at':
                $query->orderBy('created_at', $filters['sort_direction'] ?? 'asc');
                break;
            case 'users_count':
                $query->withCount('users')->orderBy('users_count', $filters['sort_direction'] ?? 'asc');
                break;
            case 'projects_count':
                $query->withCount('projects')->orderBy('projects_count', $filters['sort_direction'] ?? 'asc');
                break;
            default:
                $query->orderBy('name', $filters['sort_direction'] ?? 'asc');
        }

        $perPage = $filters['per_page'] ?? 15;
        $paginated = $query->paginate($perPage);

        return [
            'organizations' => $paginated->map(function ($org) {
                return array_merge($this->formatOrganizationData($org), [
                    'users_count' => $org->users()->count(),
                    'projects_count' => $org->projects()->count(),
                    'contracts_count' => $org->contracts()->count(),
                    'is_active' => $org->is_active ?? true,
                ]);
            }),
            'pagination' => [
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
            ],
        ];
    }

    public function updateChildOrganization(int $parentOrgId, int $childOrgId, array $data, User $user): array
    {
        $parentOrg = Organization::findOrFail($parentOrgId);
        $childOrg = Organization::findOrFail($childOrgId);

        if (!($parentOrg->is_holding ?? false)) {
            throw new \Exception('Родительская организация не является холдингом');
        }

        if ($childOrg->parent_organization_id !== $parentOrgId) {
            throw new \Exception('Организация не является дочерней для данного холдинга');
        }

        $updateData = [];
        $allowedFields = ['name', 'description', 'tax_number', 'registration_number', 'address', 'phone', 'email', 'is_active'];
        
        foreach ($allowedFields as $field) {
            $requestField = $field === 'tax_number' ? 'inn' : ($field === 'registration_number' ? 'kpp' : $field);
            if (array_key_exists($requestField, $data)) {
                $updateData[$field] = $data[$requestField];
            }
        }

        if (array_key_exists('settings', $data)) {
            $currentSettings = $childOrg->multi_org_settings ?? [];
            $updateData['multi_org_settings'] = array_merge($currentSettings, $data['settings']);
        }

        $childOrg->update($updateData);

        return $this->formatOrganizationData($childOrg->fresh());
    }

    public function deleteChildOrganization(int $parentOrgId, int $childOrgId, User $user, ?int $transferDataTo = null): void
    {
        $parentOrg = Organization::findOrFail($parentOrgId);
        $childOrg = Organization::findOrFail($childOrgId);

        if (!($parentOrg->is_holding ?? false)) {
            throw new \Exception('Родительская организация не является холдингом');
        }

        if ($childOrg->parent_organization_id !== $parentOrgId) {
            throw new \Exception('Организация не является дочерней для данного холдинга');
        }

        if ($childOrg->users()->count() > 0 && !$transferDataTo) {
            throw new \Exception('Нельзя удалить организацию с пользователями без указания организации для перевода данных');
        }

        DB::transaction(function () use ($childOrg, $transferDataTo) {
            if ($transferDataTo) {
                $targetOrg = Organization::findOrFail($transferDataTo);
                
                $childOrg->projects()->update(['organization_id' => $transferDataTo]);
                $childOrg->contracts()->update(['organization_id' => $transferDataTo]);
                
                foreach ($childOrg->users as $user) {
                    if (!$targetOrg->users()->where('user_id', $user->id)->exists()) {
                        $targetOrg->users()->attach($user->id, [
                            'is_owner' => false,
                            'is_active' => true,
                        ]);
                    }
                }
            }

            $childOrg->users()->detach();
            $childOrg->accessPermissionsGranted()->delete();
            $childOrg->accessPermissionsReceived()->delete();
            $childOrg->delete();
        });
    }

    public function getChildOrganizationUsers(int $parentOrgId, int $childOrgId, array $filters = []): array
    {
        $parentOrg = Organization::findOrFail($parentOrgId);
        $childOrg = Organization::findOrFail($childOrgId);

        if (!($parentOrg->is_holding ?? false)) {
            throw new \Exception('Родительская организация не является холдингом');
        }

        if ($childOrg->parent_organization_id !== $parentOrgId) {
            throw new \Exception('Организация не является дочерней для данного холдинга');
        }

        $query = $childOrg->users()->withPivot(['is_owner', 'is_active', 'settings']);

        if (!empty($filters['search'])) {
            $query->where(function ($q) use ($filters) {
                $q->where('name', 'like', '%' . $filters['search'] . '%')
                  ->orWhere('email', 'like', '%' . $filters['search'] . '%');
            });
        }

        if (!empty($filters['status']) && $filters['status'] !== 'all') {
            $active = $filters['status'] === 'active';
            $query->wherePivot('is_active', $active);
        }

        $perPage = $filters['per_page'] ?? 15;
        $paginated = $query->paginate($perPage);

        return [
            'users' => $paginated->map(function ($user) {
                $pivotSettings = json_decode($user->pivot->settings, true) ?? [];
                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'is_owner' => $user->pivot->is_owner,
                    'is_active' => $user->pivot->is_active,
                    'role' => $pivotSettings['role'] ?? 'employee',
                    'joined_at' => $user->pivot->created_at,
                ];
            }),
            'pagination' => [
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
            ],
        ];
    }

    public function addUserToChildOrganization(int $parentOrgId, int $childOrgId, array $data, User $currentUser): array
    {
        $parentOrg = Organization::findOrFail($parentOrgId);
        $childOrg = Organization::findOrFail($childOrgId);

        if (!($parentOrg->is_holding ?? false)) {
            throw new \Exception('Родительская организация не является холдингом');
        }

        if ($childOrg->parent_organization_id !== $parentOrgId) {
            throw new \Exception('Организация не является дочерней для данного холдинга');
        }

        $user = User::where('email', $data['email'])->firstOrFail();

        if ($childOrg->users()->where('user_id', $user->id)->exists()) {
            throw new \Exception('Пользователь уже состоит в этой организации');
        }

        $settings = [
            'role' => $data['role'],
            'permissions' => $data['permissions'] ?? [],
            'added_by' => $currentUser->id,
        ];

        $childOrg->users()->attach($user->id, [
            'is_owner' => $data['role'] === 'admin',
            'is_active' => true,
            'settings' => json_encode($settings),
        ]);

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $data['role'],
            'is_active' => true,
        ];
    }

    public function updateUserInChildOrganization(int $parentOrgId, int $childOrgId, int $userId, array $data, User $currentUser): array
    {
        $parentOrg = Organization::findOrFail($parentOrgId);
        $childOrg = Organization::findOrFail($childOrgId);

        if (!($parentOrg->is_holding ?? false)) {
            throw new \Exception('Родительская организация не является холдингом');
        }

        if ($childOrg->parent_organization_id !== $parentOrgId) {
            throw new \Exception('Организация не является дочерней для данного холдинга');
        }

        $user = User::findOrFail($userId);
        $pivot = $childOrg->users()->where('user_id', $userId)->first();

        if (!$pivot) {
            throw new \Exception('Пользователь не состоит в этой организации');
        }

        $updateData = [];
        $settings = json_decode($pivot->pivot->settings, true) ?? [];

        if (array_key_exists('role', $data)) {
            $settings['role'] = $data['role'];
            $updateData['is_owner'] = $data['role'] === 'admin';
        }

        if (array_key_exists('permissions', $data)) {
            $settings['permissions'] = $data['permissions'];
        }

        if (array_key_exists('is_active', $data)) {
            $updateData['is_active'] = $data['is_active'];
        }

        $updateData['settings'] = json_encode($settings);

        $childOrg->users()->updateExistingPivot($userId, $updateData);

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $settings['role'] ?? 'employee',
            'is_active' => $updateData['is_active'] ?? $pivot->pivot->is_active,
        ];
    }

    public function removeUserFromChildOrganization(int $parentOrgId, int $childOrgId, int $userId, User $currentUser): void
    {
        $parentOrg = Organization::findOrFail($parentOrgId);
        $childOrg = Organization::findOrFail($childOrgId);

        if (!($parentOrg->is_holding ?? false)) {
            throw new \Exception('Родительская организация не является холдингом');
        }

        if ($childOrg->parent_organization_id !== $parentOrgId) {
            throw new \Exception('Организация не является дочерней для данного холдинга');
        }

        if (!$childOrg->users()->where('user_id', $userId)->exists()) {
            throw new \Exception('Пользователь не состоит в этой организации');
        }

        if ($childOrg->users()->where('user_id', $userId)->wherePivot('is_owner', true)->exists()) {
            if ($childOrg->users()->wherePivot('is_owner', true)->count() <= 1) {
                throw new \Exception('Нельзя удалить единственного владельца организации');
            }
        }

        $childOrg->users()->detach($userId);
    }

    public function getChildOrganizationStats(int $parentOrgId, int $childOrgId): array
    {
        $parentOrg = Organization::findOrFail($parentOrgId);
        $childOrg = Organization::with(['users', 'projects', 'contracts'])->findOrFail($childOrgId);

        if (!($parentOrg->is_holding ?? false)) {
            throw new \Exception('Родительская организация не является холдингом');
        }

        if ($childOrg->parent_organization_id !== $parentOrgId) {
            throw new \Exception('Организация не является дочерней для данного холдинга');
        }

        return [
            'users' => [
                'total' => $childOrg->users()->count(),
                'active' => $childOrg->users()->wherePivot('is_active', true)->count(),
                'owners' => $childOrg->users()->wherePivot('is_owner', true)->count(),
            ],
            'projects' => [
                'total' => $childOrg->projects()->count(),
                'active' => $childOrg->projects()->where('status', 'active')->count(),
                'completed' => $childOrg->projects()->where('status', 'completed')->count(),
            ],
            'contracts' => [
                'total' => $childOrg->contracts()->count(),
                'active' => $childOrg->contracts()->where('status', 'active')->count(),
                'total_value' => $childOrg->contracts()->sum('total_amount'),
                'active_value' => $childOrg->contracts()->where('status', 'active')->sum('total_amount'),
            ],
            'financial' => [
                'balance' => $childOrg->balance?->current_balance ?? 0,
                'monthly_expenses' => $childOrg->contracts()
                    ->where('created_at', '>=', now()->startOfMonth())
                    ->sum('total_amount'),
            ],
        ];
    }

    public function updateHoldingSettings(int $groupId, array $data, User $user): OrganizationGroup
    {
        $group = OrganizationGroup::findOrFail($groupId);

        if ($group->parent_organization_id !== $user->current_organization_id) {
            throw new \Exception('Нет прав для изменения настроек данного холдинга');
        }

        $updateData = [];
        $allowedFields = ['name', 'description', 'max_child_organizations'];
        
        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                $updateData[$field] = $data[$field];
            }
        }

        if (array_key_exists('name', $data)) {
            $updateData['slug'] = Str::slug($data['name']);
        }

        if (array_key_exists('settings', $data)) {
            $currentSettings = $group->settings ?? [];
            $updateData['settings'] = array_merge($currentSettings, $data['settings']);
        }

        if (array_key_exists('permissions_config', $data)) {
            $currentPermissions = $group->permissions_config ?? [];
            $updateData['permissions_config'] = array_merge($currentPermissions, $data['permissions_config']);
        }

        $group->update($updateData);

        return $group->fresh();
    }

    public function getHoldingDashboard(int $organizationId): array
    {
        $organization = Organization::with(['childOrganizations', 'organizationGroup'])->findOrFail($organizationId);

        if (!($organization->is_holding ?? false)) {
            throw new \Exception('Организация не является холдингом');
        }

        $childOrgs = $organization->childOrganizations;
        
        return [
            'holding_info' => [
                'name' => $organization->name,
                'group_id' => $organization->organizationGroup?->id,
                'group_name' => $organization->organizationGroup?->name,
                'total_child_organizations' => $childOrgs->count(),
                'max_child_organizations' => $organization->organizationGroup?->max_child_organizations ?? 10,
                'created_at' => $organization->created_at,
            ],
            'summary_stats' => [
                'total_users' => $organization->users()->count() + $childOrgs->sum(fn($org) => $org->users()->count()),
                'total_projects' => $organization->projects()->count() + $childOrgs->sum(fn($org) => $org->projects()->count()),
                'total_contracts' => $organization->contracts()->count() + $childOrgs->sum(fn($org) => $org->contracts()->count()),
                'total_balance' => $organization->balance?->current_balance ?? 0 + $childOrgs->sum(fn($org) => $org->balance?->current_balance ?? 0),
            ],
            'child_organizations' => $childOrgs->map(function ($org) {
                return [
                    'id' => $org->id,
                    'name' => $org->name,
                    'is_active' => $org->is_active ?? true,
                    'users_count' => $org->users()->count(),
                    'projects_count' => $org->projects()->count(),
                    'created_at' => $org->created_at,
                ];
            }),
            'recent_activity' => [
                'last_child_added' => $childOrgs->sortByDesc('created_at')->first()?->created_at,
                'most_active_child' => $childOrgs->sortByDesc(fn($org) => $org->projects()->count())->first(),
            ],
        ];
    }
} 