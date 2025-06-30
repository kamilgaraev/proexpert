<?php

namespace App\Services\Landing;

use App\Models\Organization;
use App\Models\OrganizationRole;
use App\Models\User;
use App\Services\OrganizationRoleService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class ChildOrganizationUserService
{
    protected OrganizationRoleService $roleService;

    public function __construct(OrganizationRoleService $roleService)
    {
        $this->roleService = $roleService;
    }

    public function createUserWithRole(int $childOrgId, array $userData, User $createdBy): array
    {
        $childOrg = Organization::findOrFail($childOrgId);
        
        if ($childOrg->organization_type !== 'child') {
            throw new \Exception('Организация не является дочерней');
        }

        return DB::transaction(function () use ($childOrg, $userData, $createdBy) {
            $user = $this->createOrFindUser($userData);
            $role = $this->createCustomRole($childOrg->id, $userData['role_data'], $createdBy);
            
            $this->attachUserToOrganization($user, $childOrg, $role);
            
            if ($userData['send_invitation'] ?? false) {
                $this->sendInvitation($user, $childOrg, $role);
            }

            return [
                'user' => $this->formatUserData($user, $role),
                'role' => $this->formatRoleData($role),
            ];
        });
    }

    public function updateUserRole(int $childOrgId, int $userId, array $roleData, User $updatedBy): array
    {
        $childOrg = Organization::findOrFail($childOrgId);
        $user = User::findOrFail($userId);

        if (!$childOrg->users()->where('user_id', $userId)->exists()) {
            throw new \Exception('Пользователь не состоит в этой организации');
        }

        return DB::transaction(function () use ($childOrg, $user, $roleData, $updatedBy) {
            $currentRole = $this->getUserRoleInOrganization($user->id, $childOrg->id);
            
            if ($currentRole && !$currentRole->is_system) {
                $this->roleService->updateRole($currentRole->id, $roleData, $childOrg->id);
                $updatedRole = $currentRole->fresh();
            } else {
                $this->removeUserFromOrganization($user, $childOrg);
                $updatedRole = $this->createCustomRole($childOrg->id, $roleData, $updatedBy);
                $this->attachUserToOrganization($user, $childOrg, $updatedRole);
            }

            return [
                'user' => $this->formatUserData($user, $updatedRole),
                'role' => $this->formatRoleData($updatedRole),
            ];
        });
    }

    public function getAvailableRoleTemplates(): array
    {
        return [
            'administrator' => [
                'name' => 'Администратор организации',
                'description' => 'Полные права в рамках организации',
                'permissions' => [
                    'users.view', 'users.create', 'users.edit', 'users.delete',
                    'roles.view', 'roles.create', 'roles.edit', 'roles.delete',
                    'projects.view', 'projects.create', 'projects.edit', 'projects.delete',
                    'contracts.view', 'contracts.create', 'contracts.edit', 'contracts.delete',
                    'materials.view', 'materials.create', 'materials.edit', 'materials.delete',
                    'reports.view', 'reports.create', 'reports.export',
                    'finance.view', 'finance.edit'
                ],
                'color' => '#DC2626'
            ],
            'project_manager' => [
                'name' => 'Менеджер проектов',
                'description' => 'Управление проектами и командой',
                'permissions' => [
                    'users.view', 'users.create', 'users.edit',
                    'projects.view', 'projects.create', 'projects.edit',
                    'contracts.view', 'contracts.create', 'contracts.edit',
                    'materials.view', 'materials.create', 'materials.edit',
                    'reports.view', 'reports.create'
                ],
                'color' => '#2563EB'
            ],
            'foreman' => [
                'name' => 'Прораб',
                'description' => 'Управление строительными работами',
                'permissions' => [
                    'projects.view', 'projects.edit',
                    'materials.view', 'materials.create', 'materials.edit',
                    'work_types.view', 'work_types.create', 'work_types.edit',
                    'completed_work.view', 'completed_work.create', 'completed_work.edit',
                    'reports.view'
                ],
                'color' => '#059669'
            ],
            'accountant' => [
                'name' => 'Бухгалтер',
                'description' => 'Финансовый учет и отчетность',
                'permissions' => [
                    'contracts.view', 'contracts.edit',
                    'finance.view', 'finance.edit',
                    'reports.view', 'reports.create', 'reports.export',
                    'materials.view',
                    'projects.view'
                ],
                'color' => '#7C3AED'
            ],
            'sales_manager' => [
                'name' => 'Менеджер продаж',
                'description' => 'Работа с клиентами и сделками',
                'permissions' => [
                    'projects.view', 'projects.create', 'projects.edit',
                    'contracts.view', 'contracts.create', 'contracts.edit',
                    'clients.view', 'clients.create', 'clients.edit',
                    'reports.view'
                ],
                'color' => '#EA580C'
            ],
            'worker' => [
                'name' => 'Рабочий',
                'description' => 'Выполнение работ и заполнение отчетов',
                'permissions' => [
                    'projects.view',
                    'materials.view',
                    'work_types.view',
                    'completed_work.view', 'completed_work.create',
                    'time_tracking.create', 'time_tracking.edit'
                ],
                'color' => '#6B7280'
            ],
            'observer' => [
                'name' => 'Наблюдатель',
                'description' => 'Только просмотр данных',
                'permissions' => [
                    'projects.view',
                    'contracts.view',
                    'materials.view',
                    'reports.view'
                ],
                'color' => '#9CA3AF'
            ]
        ];
    }

    public function createRoleFromTemplate(int $organizationId, string $templateKey, array $customData, User $createdBy): OrganizationRole
    {
        $templates = $this->getAvailableRoleTemplates();
        
        if (!isset($templates[$templateKey])) {
            throw new \Exception('Шаблон роли не найден');
        }

        $template = $templates[$templateKey];
        
        $roleData = [
            'name' => $customData['name'] ?? $template['name'],
            'description' => $customData['description'] ?? $template['description'],
            'permissions' => $customData['permissions'] ?? $template['permissions'],
            'color' => $customData['color'] ?? $template['color'],
            'is_active' => true,
            'display_order' => $customData['display_order'] ?? 0,
        ];

        return $this->roleService->createRole($roleData, $organizationId, $createdBy);
    }

    public function getOrganizationRolesWithStats(int $organizationId): array
    {
        $roles = OrganizationRole::forOrganization($organizationId)
            ->withCount('users')
            ->ordered()
            ->get();

        return $roles->map(function ($role) {
            return [
                'id' => $role->id,
                'name' => $role->name,
                'slug' => $role->slug,
                'description' => $role->description,
                'color' => $role->color,
                'permissions_count' => count($role->permissions ?? []),
                'users_count' => $role->users_count,
                'is_system' => $role->is_system,
                'is_active' => $role->is_active,
                'created_at' => $role->created_at,
            ];
        })->toArray();
    }

    public function createBulkUsers(int $childOrgId, array $usersData, User $createdBy): array
    {
        $childOrg = Organization::findOrFail($childOrgId);
        $results = [];

        return DB::transaction(function () use ($childOrg, $usersData, $createdBy, &$results) {
            foreach ($usersData as $userData) {
                try {
                    $result = $this->createUserWithRole($childOrg->id, $userData, $createdBy);
                    $results[] = [
                        'success' => true,
                        'user' => $result['user'],
                        'role' => $result['role']
                    ];
                } catch (\Exception $e) {
                    $results[] = [
                        'success' => false,
                        'error' => $e->getMessage(),
                        'user_data' => [
                            'name' => $userData['name'] ?? null,
                            'email' => $userData['email'] ?? null
                        ]
                    ];
                }
            }

            return [
                'total' => count($usersData),
                'successful' => count(array_filter($results, fn($r) => $r['success'])),
                'failed' => count(array_filter($results, fn($r) => !$r['success'])),
                'results' => $results
            ];
        });
    }

    private function createOrFindUser(array $userData): User
    {
        $user = User::where('email', $userData['email'])->first();
        
        if (!$user) {
            $user = User::create([
                'name' => $userData['name'],
                'email' => $userData['email'],
                'password' => Hash::make($userData['password'] ?? Str::random(12)),
                'email_verified_at' => isset($userData['auto_verify']) && $userData['auto_verify'] ? now() : null,
            ]);
        }

        return $user;
    }

    private function createCustomRole(int $organizationId, array $roleData, User $createdBy): OrganizationRole
    {
        if (isset($roleData['template'])) {
            return $this->createRoleFromTemplate($organizationId, $roleData['template'], $roleData, $createdBy);
        }

        $data = [
            'name' => $roleData['name'],
            'description' => $roleData['description'] ?? null,
            'permissions' => $roleData['permissions'] ?? [],
            'color' => $roleData['color'] ?? '#6B7280',
            'is_active' => true,
            'display_order' => $roleData['display_order'] ?? 0,
        ];

        return $this->roleService->createRole($data, $organizationId, $createdBy);
    }

    private function attachUserToOrganization(User $user, Organization $organization, OrganizationRole $role): void
    {
        if (!$organization->users()->where('user_id', $user->id)->exists()) {
            $organization->users()->attach($user->id, [
                'is_owner' => in_array('users.delete', $role->permissions ?? []),
                'is_active' => true,
                'settings' => ['primary_role_id' => $role->id]
            ]);
        }

        $this->roleService->assignRoleToUser($role->id, $user->id, $organization->id, Auth::user() ?? $user);
    }

    private function removeUserFromOrganization(User $user, Organization $organization): void
    {
        $userRoles = $this->roleService->getUserRoles($user->id, $organization->id);
        
        foreach ($userRoles as $role) {
            $this->roleService->removeRoleFromUser($role->id, $user->id, $organization->id);
        }
    }

    private function getUserRoleInOrganization(int $userId, int $organizationId): ?OrganizationRole
    {
        return OrganizationRole::whereHas('users', function ($query) use ($userId, $organizationId) {
            $query->where('user_id', $userId)
                  ->where('organization_id', $organizationId);
        })->first();
    }

    private function sendInvitation(User $user, Organization $organization, OrganizationRole $role): void
    {
        \Illuminate\Support\Facades\Log::info("Invitation sent to {$user->email} for organization {$organization->name} with role {$role->name}");
    }

    private function formatUserData(User $user, OrganizationRole $role): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role_id' => $role->id,
            'role_name' => $role->name,
            'role_color' => $role->color,
            'permissions' => $role->permissions ?? [],
            'is_active' => true,
            'created_at' => $user->created_at,
        ];
    }

    private function formatRoleData(OrganizationRole $role): array
    {
        return [
            'id' => $role->id,
            'name' => $role->name,
            'slug' => $role->slug,
            'description' => $role->description,
            'color' => $role->color,
            'permissions' => $role->permissions ?? [],
            'permissions_count' => count($role->permissions ?? []),
            'is_system' => $role->is_system,
            'created_at' => $role->created_at,
        ];
    }
} 