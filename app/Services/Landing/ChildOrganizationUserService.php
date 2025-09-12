<?php

namespace App\Services\Landing;

use App\Models\Organization;
use App\Models\User;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Domain\Authorization\Models\OrganizationCustomRole;
use App\Domain\Authorization\Services\CustomRoleService;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Services\UserInvitationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Support\Collection;

class ChildOrganizationUserService
{
    protected CustomRoleService $customRoleService;
    protected AuthorizationService $authorizationService;
    protected UserInvitationService $invitationService;

    public function __construct(
        CustomRoleService $customRoleService,
        AuthorizationService $authorizationService,
        UserInvitationService $invitationService
    ) {
        $this->customRoleService = $customRoleService;
        $this->authorizationService = $authorizationService;
        $this->invitationService = $invitationService;
    }

    public function createUserWithRole(int $childOrgId, array $userData, User $createdBy): array
    {
        $childOrg = Organization::findOrFail($childOrgId);
        
        if ($childOrg->organization_type !== 'child') {
            throw new \Exception('Организация не является дочерней');
        }

        return DB::transaction(function () use ($childOrg, $userData, $createdBy) {
            $user = $this->createOrFindUser($userData);
            
            // Создаем кастомную роль или используем существующую
            if (isset($userData['role_data']['is_custom']) && $userData['role_data']['is_custom']) {
                $role = $this->createCustomRole($childOrg->id, $userData['role_data'], $createdBy);
                $roleSlug = $role->slug;
            } else {
                // Используем системную роль
                $roleSlug = $userData['role_data']['slug'] ?? 'organization_user';
                $role = null;
            }
            
            $this->attachUserToOrganization($user, $childOrg, $roleSlug);
            
            if ($userData['send_invitation'] ?? false) {
                $this->sendInvitation($user, $childOrg, $roleSlug);
            }

            return [
                'user' => $this->formatUserData($user, $roleSlug, $role),
                'role' => $role ? $this->formatRoleData($role) : $this->formatSystemRoleData($roleSlug),
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
            $context = AuthorizationContext::getOrganizationContext($childOrg->id);
            
            // Отключаем все старые роли пользователя в этой организации
            UserRoleAssignment::where([
                'user_id' => $user->id,
                'context_id' => $context->id,
                'is_active' => true
            ])->update(['is_active' => false]);
            
            // Назначаем новую роль
            if (isset($roleData['is_custom']) && $roleData['is_custom']) {
                $role = $this->createCustomRole($childOrg->id, $roleData, $updatedBy);
                $roleSlug = $role->slug;
            } else {
                $roleSlug = $roleData['slug'] ?? 'organization_user';
                $role = null;
            }
            
            UserRoleAssignment::create([
                'user_id' => $user->id,
                'role_slug' => $roleSlug,
                'role_type' => $role ? 'custom' : 'system',
                'context_id' => $context->id,
                'assigned_by' => $updatedBy->id,
                'is_active' => true
            ]);

            return [
                'user' => $this->formatUserData($user, $roleSlug, $role),
                'role' => $role ? $this->formatRoleData($role) : $this->formatSystemRoleData($roleSlug),
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

    public function createRoleFromTemplate(int $organizationId, string $templateKey, array $customData, User $createdBy): OrganizationCustomRole
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
        ];

        return $this->customRoleService->createRole($roleData, $organizationId, $createdBy);
    }

    public function getOrganizationRolesWithStats(int $organizationId): array
    {
        $context = AuthorizationContext::getOrganizationContext($organizationId);
        
        // Получаем кастомные роли
        $customRoles = $this->customRoleService->getOrganizationRoles($organizationId);
        
        // Получаем системные роли которые используются в организации
        $systemRoleSlugs = UserRoleAssignment::where('context_id', $context->id)
            ->where('role_type', 'system')
            ->where('is_active', true)
            ->distinct()
            ->pluck('role_slug');
        
        $result = [];
        
        // Добавляем кастомные роли
        foreach ($customRoles as $role) {
            $usersCount = UserRoleAssignment::where('context_id', $context->id)
                ->where('role_slug', $role->slug)
                ->where('is_active', true)
                ->count();
                
            $result[] = [
                'id' => $role->id,
                'name' => $role->name,
                'slug' => $role->slug,
                'description' => $role->description,
                'color' => $role->color,
                'permissions_count' => count($role->permissions ?? []),
                'users_count' => $usersCount,
                'is_system' => false,
                'is_active' => $role->is_active,
                'created_at' => $role->created_at,
            ];
        }
        
        // Добавляем системные роли
        $roleScanner = app(\App\Domain\Authorization\Services\RoleScanner::class);
        $systemRoles = $roleScanner->getAllRoles();
        
        foreach ($systemRoleSlugs as $roleSlug) {
            if (isset($systemRoles[$roleSlug])) {
                $roleData = $systemRoles[$roleSlug];
                $usersCount = UserRoleAssignment::where('context_id', $context->id)
                    ->where('role_slug', $roleSlug)
                    ->where('is_active', true)
                    ->count();
                    
                $result[] = [
                    'id' => $roleSlug,
                    'name' => $roleData['name'],
                    'slug' => $roleSlug,
                    'description' => $roleData['description'] ?? '',
                    'color' => '#6B7280',
                    'permissions_count' => count($roleData['system_permissions'] ?? []) + count($roleData['module_permissions'] ?? []),
                    'users_count' => $usersCount,
                    'is_system' => true,
                    'is_active' => true,
                    'created_at' => null,
                ];
            }
        }
        
        return $result;
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

    private function createCustomRole(int $organizationId, array $roleData, User $createdBy): OrganizationCustomRole
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
        ];

        return $this->customRoleService->createRole($data, $organizationId, $createdBy);
    }

    private function attachUserToOrganization(User $user, Organization $organization, string $roleSlug): void
    {
        if (!$organization->users()->where('user_id', $user->id)->exists()) {
            $organization->users()->attach($user->id, [
                'is_owner' => false, // Определяется через роли
                'is_active' => true,
                'settings' => ['primary_role_slug' => $roleSlug]
            ]);
        }

        $context = AuthorizationContext::getOrganizationContext($organization->id);
        
        // Проверяем, не назначена ли уже эта роль
        $existing = UserRoleAssignment::where([
            'user_id' => $user->id,
            'role_slug' => $roleSlug,
            'context_id' => $context->id,
            'is_active' => true
        ])->exists();
        
        if (!$existing) {
            UserRoleAssignment::create([
                'user_id' => $user->id,
                'role_slug' => $roleSlug,
                'role_type' => 'system', // По умолчанию системная
                'context_id' => $context->id,
                'assigned_by' => Auth::id(),
                'is_active' => true
            ]);
        }
    }

    private function removeUserFromOrganization(User $user, Organization $organization): void
    {
        $context = AuthorizationContext::getOrganizationContext($organization->id);
        
        UserRoleAssignment::where([
            'user_id' => $user->id,
            'context_id' => $context->id,
            'is_active' => true
        ])->update(['is_active' => false]);
    }

    private function getUserRoleInOrganization(int $userId, int $organizationId): ?UserRoleAssignment
    {
        $context = AuthorizationContext::getOrganizationContext($organizationId);
        
        return UserRoleAssignment::where([
            'user_id' => $userId,
            'context_id' => $context->id,
            'is_active' => true
        ])->first();
    }

    private function sendInvitation(User $user, Organization $organization, string $roleSlug): void
    {
        // Используем UserInvitationService для отправки приглашения
        $invitationData = [
            'email' => $user->email,
            'name' => $user->name,
            'role_slugs' => [$roleSlug],
            'metadata' => [
                'organization_type' => 'child',
                'invited_for' => 'child_organization_user'
            ]
        ];
        
        try {
            $this->invitationService->createInvitation(
                $invitationData, 
                $organization->id, 
                Auth::user() ?? $user
            );
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::warning(
                "Failed to send invitation to {$user->email} for organization {$organization->name}: {$e->getMessage()}"
            );
        }
    }

    private function formatUserData(User $user, string $roleSlug, ?OrganizationCustomRole $role = null): array
    {
        if ($role) {
            return [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role_id' => $role->id,
                'role_slug' => $role->slug,
                'role_name' => $role->name,
                'role_color' => $role->color,
                'permissions' => $role->permissions ?? [],
                'is_active' => true,
                'is_system_role' => false,
                'created_at' => $user->created_at,
            ];
        } else {
            // Системная роль
            $roleScanner = app(\App\Domain\Authorization\Services\RoleScanner::class);
            $systemRoles = $roleScanner->getAllRoles();
            $roleData = $systemRoles[$roleSlug] ?? null;
            
            return [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role_id' => $roleSlug,
                'role_slug' => $roleSlug,
                'role_name' => $roleData['name'] ?? $roleSlug,
                'role_color' => '#6B7280',
                'permissions' => array_merge(
                    $roleData['system_permissions'] ?? [],
                    array_values($roleData['module_permissions'] ?? [])
                ),
                'is_active' => true,
                'is_system_role' => true,
                'created_at' => $user->created_at,
            ];
        }
    }

    private function formatRoleData(OrganizationCustomRole $role): array
    {
        return [
            'id' => $role->id,
            'name' => $role->name,
            'slug' => $role->slug,
            'description' => $role->description,
            'color' => $role->color,
            'permissions' => $role->permissions ?? [],
            'permissions_count' => count($role->permissions ?? []),
            'is_system' => false,
            'is_active' => $role->is_active,
            'created_at' => $role->created_at,
        ];
    }
    
    private function formatSystemRoleData(string $roleSlug): array
    {
        $roleScanner = app(\App\Domain\Authorization\Services\RoleScanner::class);
        $systemRoles = $roleScanner->getAllRoles();
        $roleData = $systemRoles[$roleSlug] ?? null;
        
        return [
            'id' => $roleSlug,
            'name' => $roleData['name'] ?? $roleSlug,
            'slug' => $roleSlug,
            'description' => $roleData['description'] ?? '',
            'color' => '#6B7280',
            'permissions' => array_merge(
                $roleData['system_permissions'] ?? [],
                array_values($roleData['module_permissions'] ?? [])
            ),
            'permissions_count' => count($roleData['system_permissions'] ?? []) + count($roleData['module_permissions'] ?? []),
            'is_system' => true,
            'is_active' => true,
            'created_at' => null,
        ];
    }
} 