<?php

namespace App\Services\User;

use App\Models\User;
use App\Models\Role;
use App\Repositories\Interfaces\UserRepositoryInterface;
use App\Repositories\Interfaces\RoleRepositoryInterface;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use App\Exceptions\BusinessLogicException;
use App\Services\BaseService;
use Illuminate\Support\Facades\Log;
use App\Services\Organization\OrganizationContext;

/**
 * @property UserRepositoryInterface $userRepository
 * @property RoleRepositoryInterface $roleRepository
 */
class UserService
{
    protected UserRepositoryInterface $userRepository;
    protected RoleRepositoryInterface $roleRepository;

    public function __construct(
        UserRepositoryInterface $userRepository,
        RoleRepositoryInterface $roleRepository
    )
    {
        $this->userRepository = $userRepository;
        $this->roleRepository = $roleRepository;
    }

    // --- Helper Methods ---

    /**
     * Ensures the requesting user is the owner of the current organization.
     * Throws an exception if not.
     *
     * @param Request $request
     * @throws BusinessLogicException
     */
    protected function ensureUserIsOwner(Request $request): void
    {
        /** @var User $user */
        $user = $request->user();
        $organizationId = $request->attributes->get('current_organization_id');

        if (!$user || !$organizationId || !$user->isOwnerOfOrganization($organizationId)) {
            throw new BusinessLogicException('Действие доступно только владельцу организации.', 403);
        }
    }
    
    /**
     * Ensures the requesting user is an admin of the current organization.
     * Throws an exception if not.
     *
     * @param Request $request
     * @throws BusinessLogicException
     */
    protected function ensureUserIsAdmin(Request $request): void
    {
        /** @var User $user */
        $user = $request->user();
        $organizationId = $request->attributes->get('current_organization_id');

        // Allow system admins as well
        if (!$user || !$organizationId || (!$user->isOrganizationAdmin($organizationId) && !$user->isSystemAdmin())) {
             throw new BusinessLogicException('Действие доступно только администратору организации.', 403);
        }
    }


    /**
     * Finds the specified role or throws an exception.
     *
     * @param string $roleSlug
     * @return Role
     * @throws BusinessLogicException
     */
    protected function findRoleOrFail(string $roleSlug): Role
    {
        $role = $this->roleRepository->findBySlug($roleSlug);
        if (!$role) {
            throw new BusinessLogicException("Роль '{$roleSlug}' не найдена.", 500);
        }
        return $role;
    }


    // --- Admin User Management (for Landing/LK API) ---

    /**
     * Get Admins for the organization associated with the request.
     * Requires the user to be the organization owner.
     *
     * @param Request $request
     * @return Collection
     * @throws BusinessLogicException
     */
    public function getAdminsForCurrentOrg(Request $request): Collection
    {
        $organizationId = $request->attributes->get('current_organization_id');

        if(!$organizationId) {
            throw new BusinessLogicException('Контекст организации не определен.', 500);
        }

        $intOrganizationId = (int) $organizationId;

        $adminRoleSlug = Role::ROLE_ADMIN;
        $ownerRoleSlug = Role::ROLE_OWNER;

        $adminUsers = $this->userRepository->findByRoleInOrganization($intOrganizationId, $adminRoleSlug);
        $ownerUsers = $this->userRepository->findByRoleInOrganization($intOrganizationId, $ownerRoleSlug);

        $allUsers = $adminUsers->merge($ownerUsers)->unique('id');

        return $allUsers;
    }

    /**
     * Create a new Admin user for the organization associated with the request.
     * Requires the user to be the organization owner.
     *
     * @param array $data (Validated data including name, email, password)
     * @param Request $request
     * @return User
     * @throws BusinessLogicException
     */
    public function createAdmin(array $data, Request $request): User
    {
        // Заменяем проверку на ensureUserIsAdmin, чтобы разрешить и админам
        $this->ensureUserIsAdmin($request);
        $organizationId = $request->attributes->get('current_organization_id');
        if(!$organizationId) {
            throw new BusinessLogicException('Контекст организации не определен.', 500);
        }
        $intOrganizationId = (int) $organizationId; // Приводим к int
        $adminRole = $this->findRoleOrFail(Role::ROLE_ADMIN);

        $data['password'] = Hash::make($data['password']);
        $data['user_type'] = Role::ROLE_ADMIN; // Or a more specific type if needed

        // Check if user already exists
        $existingUser = $this->userRepository->findByEmail($data['email']);

        if ($existingUser) {
            // Используем $intOrganizationId
            if ($this->userRepository->hasRoleInOrganization($existingUser->id, $adminRole->id, $intOrganizationId)) {
                 throw new BusinessLogicException('Пользователь с таким email уже является администратором в этой организации.', 409); // 409 Conflict
            }
            // Используем $intOrganizationId
            $this->userRepository->attachToOrganization($existingUser->id, $intOrganizationId);
            $this->userRepository->assignRole($existingUser->id, $adminRole->id, $intOrganizationId);
             // Optionally update user data if provided (name, etc.)?
            $this->userRepository->update($existingUser->id, ['name' => $data['name']]); // Update name if needed
            return $this->userRepository->find($existingUser->id); // Return the existing user
        } else {
             // If user doesn't exist, create them and assign role/org
            $newUser = $this->userRepository->create($data);
            // Используем $intOrganizationId
            $this->userRepository->attachToOrganization($newUser->id, $intOrganizationId);
            $this->userRepository->assignRole($newUser->id, $adminRole->id, $intOrganizationId);
            return $newUser;
        }
    }

    /**
     * Find an Admin user by ID within the organization associated with the request.
     * Requires the requesting user to be an organization admin or owner.
     *
     * @param int $adminUserId
     * @param Request $request
     * @return User|null
     * @throws BusinessLogicException
     */
    public function findAdminById(int $adminUserId, Request $request): ?User
    {
        // Меняем проверку на admin
        $this->ensureUserIsAdmin($request);
        $organizationId = $request->attributes->get('current_organization_id');
        if(!$organizationId) {
            throw new BusinessLogicException('Контекст организации не определен.', 500);
        }
        $intOrganizationId = (int) $organizationId;

        $user = $this->userRepository->find($adminUserId);

        // Проверяем, что пользователь найден и принадлежит ТЕКУЩЕЙ организации запроса
        if (!$user || !$user->organizations()->where('organization_user.organization_id', $intOrganizationId)->exists()) {
            return null; // Не найден или не в той организации
        }

        // Дополнительно: Убедимся, что у пользователя есть роль админа или владельца В ЭТОЙ ОРГАНИЗАЦИИ
        // Это важно, чтобы случайно не показать/изменить пользователя с другой ролью
        if (!($user->hasRole(Role::ROLE_ADMIN, $intOrganizationId) || $user->hasRole(Role::ROLE_OWNER, $intOrganizationId))) {
            // Можно вернуть null или выбросить исключение, если это считается ошибкой прав
            Log::warning('Attempted to find user by ID who is not an Admin or Owner in the current org', [
                'requesting_user_id' => $request->user()->id,
                'target_user_id' => $adminUserId,
                'organization_id' => $intOrganizationId
            ]);
            return null; // Пользователь найден, но он не админ/владелец в этой организации
        }

        return $user;
    }

     /**
     * Update an Admin user's details.
     * Requires the requesting user to be an organization admin or owner.
     * Cannot update the owner themselves via this method.
     *
     * @param int $adminUserId ID пользователя для обновления
     * @param array $data (Validated data: name, email, password [optional])
     * @param Request $request Текущий запрос
     * @return User Обновленный пользователь
     * @throws BusinessLogicException
     */
    public function updateAdmin(int $adminUserId, array $data, Request $request): User
    {
        // Меняем проверку на admin
        $this->ensureUserIsAdmin($request);
        $organizationId = $request->attributes->get('current_organization_id');
        if(!$organizationId) {
            throw new BusinessLogicException('Контекст организации не определен.', 500);
        }
        $intOrganizationId = (int) $organizationId;
        $requestingUser = $request->user();

        // Запрещаем обновлять владельца (если мы не системный админ)
        $targetUser = $this->userRepository->find($adminUserId); // Найдем пользователя, которого хотим обновить
        if (!$targetUser) {
            throw new BusinessLogicException('Пользователь для обновления не найден.', 404);
        }
        // Проверяем, что обновляемый пользователь из той же организации
        if (!$targetUser->organizations()->where('organization_user.organization_id', $intOrganizationId)->exists()) {
            throw new BusinessLogicException('Пользователь не принадлежит текущей организации.', 404);
        }

        // Проверяем, является ли целевой пользователь владельцем
        if ($targetUser->hasRole(Role::ROLE_OWNER, $intOrganizationId)) {
            // Разрешаем обновление владельца только системному администратору
            if (!$requestingUser->isSystemAdmin()) {
                 throw new BusinessLogicException('Только системный администратор может изменять данные владельца организации.', 403);
            }
            // Дополнительно: Запретить изменять роль владельца?
        }
        // Старая проверка: запрещала обновлять СЕБЯ, если ты владелец. Новая логика выше покрывает это.
        // if ($adminUserId === $requestingUserId) {
        //     throw new BusinessLogicException('Владелец не может изменять свои данные через этот интерфейс.', 403);
        // }

        // Используем findAdminById для финальной проверки, что пользователь - админ/владелец
        // Это может быть избыточно после проверок выше, но для консистентности
        $adminUser = $this->findAdminById($adminUserId, $request);
        if (!$adminUser) {
             throw new BusinessLogicException('Администратор/Владелец не найден в этой организации или нет прав на его просмотр.', 404);
        }

        // Подготовка данных
        if (!empty($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        } else {
            unset($data['password']);
        }
        // Запрет смены email для существующего пользователя?
        // unset($data['email']);

        $this->userRepository->update($adminUserId, $data);

        // Возвращаем обновленного пользователя (перезагружаем для свежести)
        return $this->userRepository->find($adminUserId);
    }

    /**
     * Delete an Admin user from the organization.
     * Requires the requesting user to be an organization admin or owner.
     * Cannot delete the owner themselves.
     *
     * @param int $adminUserId
     * @param Request $request
     * @return bool
     * @throws BusinessLogicException
     */
    public function deleteAdmin(int $adminUserId, Request $request): bool
    {
        // Меняем проверку на admin
        $this->ensureUserIsAdmin($request);
        $organizationId = $request->attributes->get('current_organization_id');
        if(!$organizationId) {
            throw new BusinessLogicException('Контекст организации не определен.', 500);
        }
        $intOrganizationId = (int) $organizationId;
        $requestingUser = $request->user();

        // Используем findAdminById, чтобы убедиться, что пользователь существует, является админом/владельцем и в нужной организации
        $adminUser = $this->findAdminById($adminUserId, $request);
        if (!$adminUser) {
            throw new BusinessLogicException('Администратор/Владелец не найден в этой организации или нет прав на его просмотр.', 404);
        }

        // Запрещаем удалять Владельца организации
        if ($adminUser->hasRole(Role::ROLE_OWNER, $intOrganizationId)) {
            throw new BusinessLogicException('Нельзя удалить владельца организации.', 403);
        }
        // Запрещаем удалять себя (даже если ты админ)
        if ($adminUserId === $requestingUser->id) {
            throw new BusinessLogicException('Нельзя удалить самого себя.', 403);
        }

        // Вместо удаления пользователя, отвязываем его от организации и удаляем роль админа
        $adminRole = $this->findRoleOrFail(Role::ROLE_ADMIN);
        $this->userRepository->revokeRole($adminUserId, $adminRole->id, $intOrganizationId);

        // Отвязываем от организации, если нет других ролей в этой организации
        $otherRolesCount = $adminUser->rolesInOrganization($intOrganizationId)->count();
        if ($otherRolesCount === 0) {
             $this->userRepository->detachFromOrganization($adminUserId, $intOrganizationId);
        }

        return true;
    }

    // --- Foreman User Management (for Admin API) ---

    /**
     * Get Foremen for the organization associated with the request.
     * Supports pagination and filtering by name.
     *
     * @param Request $request
     * @param int $perPage
     * @return LengthAwarePaginator
     * @throws BusinessLogicException
     */
    public function getForemenForCurrentOrg(Request $request, int $perPage = 15): LengthAwarePaginator
    {
        $organizationId = $request->attributes->get('current_organization_id');
        if(!$organizationId) {
            throw new BusinessLogicException('Контекст организации не определен.', 500);
        }
        $intOrganizationId = (int) $organizationId;

        // Параметры фильтрации из запроса
        $filters = [
            'name' => $request->query('name'), // Фильтр по имени
            'is_active' => $request->query('is_active') // Фильтр по статусу активности (true/false/null)
        ];
        // Убираем null значения из фильтров
        $filters = array_filter($filters, fn($value) => !is_null($value) && $value !== '');

        $foremanRoleSlug = Role::ROLE_FOREMAN;

        // Используем метод репозитория, который поддерживает фильтрацию и пагинацию
        return $this->userRepository->paginateByRoleInOrganization(
            $foremanRoleSlug,
            $intOrganizationId,
            $perPage,
            $filters
        );
    }

    /**
     * Create a new Foreman user for the organization associated with the request.
     * Requires the user to be an organization admin.
     *
     * @param array $data (Validated data: name, email, password)
     * @param Request $request
     * @return User
     * @throws BusinessLogicException
     */
    public function createForeman(array $data, Request $request): User
    {
        // Получаем ID организации из атрибутов запроса
        $organizationId = $request->attributes->get('current_organization_id');
        
        // Если ID не найден в атрибутах, пробуем получить из контекста
        if (!$organizationId) {
            try {
                // Используем статический метод для получения ID организации
                $organizationId = OrganizationContext::getOrganizationId();
                
                if ($organizationId) {
                    Log::info('[UserService] Используем ID организации из статического контекста', [
                        'organization_id' => $organizationId
                    ]);
                }
            } catch (\Throwable $e) {
                Log::error('[UserService] Ошибка при получении контекста организации', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
            }
        }
        
        // Если все еще нет ID, пробуем получить текущую организацию из пользователя
        if (!$organizationId) {
            $user = $request->user();
            if ($user && $user->current_organization_id) {
                $organizationId = $user->current_organization_id;
                Log::info('[UserService] Используем current_organization_id пользователя', [
                    'user_id' => $user->id,
                    'organization_id' => $organizationId
                ]);
            }
        }
        
        if(!$organizationId) {
            throw new BusinessLogicException('Контекст организации не определен.', 500);
        }
        $foremanRole = $this->findRoleOrFail(Role::ROLE_FOREMAN);

        $data['password'] = Hash::make($data['password']);
        $data['user_type'] = Role::ROLE_FOREMAN; // Set user type

        // Check if user already exists
        $existingUser = $this->userRepository->findByEmail($data['email']);

        if ($existingUser) {
            // If user exists, check if they are already a foreman in this org
            if ($this->userRepository->hasRoleInOrganization($existingUser->id, $foremanRole->id, $organizationId)) {
                 throw new BusinessLogicException('Пользователь с таким email уже является прорабом в этой организации.', 409);
            }
            // If user exists but not foreman, add them to the org with the foreman role
            $this->userRepository->attachToOrganization($existingUser->id, $organizationId, false); // Ensure attached, NOT as owner
            $this->userRepository->assignRole($existingUser->id, $foremanRole->id, $organizationId);
            $this->userRepository->update($existingUser->id, ['name' => $data['name']]); // Update name
            return $this->userRepository->find($existingUser->id);
        } else {
             // If user doesn't exist, create them and assign role/org
            $newUser = $this->userRepository->create($data);
            $this->userRepository->attachToOrganization($newUser->id, $organizationId, false); // Attach as NOT an owner
            $this->userRepository->assignRole($newUser->id, $foremanRole->id, $organizationId);
            return $newUser;
        }
    }

     /**
     * Find a Foreman user by ID within the organization associated with the request.
     * Requires the user to be an organization admin.
     *
     * @param int $foremanUserId
     * @param Request $request
     * @return User|null
     * @throws BusinessLogicException
     */
    public function findForemanById(int $foremanUserId, Request $request): ?User
    {
        $organizationId = $request->attributes->get('current_organization_id');
        if(!$organizationId) {
            throw new BusinessLogicException('Контекст организации не определен.', 500);
        }

        $user = $this->userRepository->find($foremanUserId);

        // Check if user exists and has the foreman role in this specific organization
        if (!$user || !$this->userRepository->hasRoleInOrganization($user->id, Role::ROLE_FOREMAN, $organizationId)) {
            return null;
        }

        return $user;
    }

    /**
     * Update a Foreman user's details.
     * Requires the user to be an organization admin.
     *
     * @param int $foremanUserId
     * @param array $data (Validated data: name, password [optional])
     * @param Request $request
     * @return User
     * @throws BusinessLogicException
     */
    public function updateForeman(int $foremanUserId, array $data, Request $request): User
    {
        $organizationId = $request->attributes->get('current_organization_id');
        if(!$organizationId) {
            throw new BusinessLogicException('Контекст организации не определен.', 500);
        }

        // Use findForemanById to ensure the user exists and is a foreman in this org
        $foremanUser = $this->findForemanById($foremanUserId, $request);

        if (!$foremanUser) {
             throw new BusinessLogicException('Прораб не найден в этой организации.', 404);
        }

        // Prepare data for update
        if (!empty($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        } else {
            unset($data['password']); // Don't update password if empty
        }
        // Unset email - typically foreman email shouldn't be updated by admin?
        unset($data['email']);

        // Update user
        $this->userRepository->update($foremanUserId, $data);

        return $this->userRepository->find($foremanUserId); // Return updated user
    }

    /**
     * Delete a Foreman user from the organization.
     * Requires the user to be an organization admin.
     * This typically means revoking the role and potentially detaching from the org.
     *
     * @param int $foremanUserId
     * @param Request $request
     * @return bool
     * @throws BusinessLogicException
     */
    public function deleteForeman(int $foremanUserId, Request $request): bool
    {
        $organizationId = $request->attributes->get('current_organization_id');
        if(!$organizationId) {
            throw new BusinessLogicException('Контекст организации не определен.', 500);
        }

        $foremanUser = $this->findForemanById($foremanUserId, $request);

        if (!$foremanUser) {
            throw new BusinessLogicException('Прораб не найден в этой организации.', 404);
        }
        
        // Cannot delete the admin themselves if they happen to have foreman role too
        if ($foremanUserId === $request->user()->id) {
             throw new BusinessLogicException('Администратор не может удалить сам себя.', 403);
        }

        // Revoke the foreman role in this organization
        $foremanRole = $this->findRoleOrFail(Role::ROLE_FOREMAN);
        $this->userRepository->revokeRole($foremanUserId, $foremanRole->id, $organizationId);

        // Detach from organization if they have no other roles in this org
        // Reload user to get fresh role count
        $foremanUser = $this->userRepository->find($foremanUserId); 
        $otherRolesCount = $foremanUser->rolesInOrganization($organizationId)->count();
        if ($otherRolesCount === 0) {
             $this->userRepository->detachFromOrganization($foremanUserId, $organizationId);
        }
        
        // Consider soft deleting the user if they are not part of any other organization?
        // $organizationsCount = $foremanUser->organizations()->count();
        // if ($organizationsCount === 0) { // Check after detaching
        //    return $this->userRepository->delete($foremanUserId); // Soft delete
        // }

        return true;
    }

    /**
     * Create a new user for the Admin Panel (e.g., web_admin, accountant).
     * Requires the requesting user to be an organization admin or owner.
     *
     * @param array $userData (Validated data: name, email, password)
     * @param string $roleSlug The slug of the role to assign (e.g., 'web_admin')
     * @param Request $request The current request
     * @return User The created or updated user.
     * @throws BusinessLogicException
     */
    public function createAdminPanelUser(array $userData, string $roleSlug, Request $request): User
    {
        // Проверяем права создающего пользователя (админ/владелец организации)
        $this->ensureUserIsAdmin($request);
        $organizationId = $request->attributes->get('current_organization_id');
        if (!$organizationId) {
            throw new BusinessLogicException('Контекст организации не определен.', 500);
        }
        $intOrganizationId = (int) $organizationId;

        // Находим нужную роль по слагу или выбрасываем исключение
        $role = $this->findRoleOrFail($roleSlug);
        // Дополнительная проверка, что роль системная (не привязана к конкретной организации)
        if ($role->type !== Role::TYPE_SYSTEM) {
             throw new BusinessLogicException("Роль '{$roleSlug}' не является системной и не может быть назначена таким образом.", 400);
        }


        $userData['password'] = Hash::make($userData['password']);
        // Устанавливаем user_type в соответствии с назначенной ролью
        $userData['user_type'] = $roleSlug;

        // Проверяем, существует ли пользователь с таким email
        $existingUser = $this->userRepository->findByEmail($userData['email']);

        if ($existingUser) {
            // Пользователь существует. Проверяем, есть ли у него уже ЭТА роль В ЭТОЙ организации.
            if ($this->userRepository->hasRoleInOrganization($existingUser->id, $role->id, $intOrganizationId)) {
                 throw new BusinessLogicException("Пользователь с таким email уже имеет роль '{$role->name}' в этой организации.", 409);
            }
            // Пользователь существует, но роли нет. Привязываем к организации и назначаем роль.
            $this->userRepository->attachToOrganization($existingUser->id, $intOrganizationId, false, true); // Не владелец
            $this->userRepository->assignRole($existingUser->id, $role->id, $intOrganizationId);
            // Обновляем имя, если оно изменилось
            $this->userRepository->update($existingUser->id, ['name' => $userData['name']]);
            return $this->userRepository->find($existingUser->id);
        } else {
            // Пользователь не существует. Создаем его.
            // Устанавливаем текущую организацию перед созданием
            $userData['current_organization_id'] = $intOrganizationId;
            $newUser = $this->userRepository->create($userData);
            // Привязываем к организации и назначаем роль.
            $this->userRepository->attachToOrganization($newUser->id, $intOrganizationId, false, true); // Не владелец
            $this->userRepository->assignRole($newUser->id, $role->id, $intOrganizationId);
            return $newUser;
        }
    }

    // --- Admin Panel User Management (for Landing/LK API) ---

    /**
     * Get Admin Panel Users for the organization associated with the request.
     * Requires the requesting user to be an organization admin or owner.
     *
     * @param Request $request
     * @param array $rolesToFetch Slugs of roles to fetch (e.g., ['web_admin', 'accountant'])
     * @return Collection
     * @throws BusinessLogicException
     */
    public function getAdminPanelUsersForCurrentOrg(Request $request, array $rolesToFetch = ['web_admin', 'accountant']): Collection
    {
        Log::info('[UserService@getAdminPanelUsersForCurrentOrg] Method entered.', [
            'user_id' => $request->user() ? $request->user()->id : null, 
            'organization_id' => $request->attributes->get('current_organization_id'),
            'roles_to_fetch' => $rolesToFetch
        ]);

        /** @var \App\Models\User $requestingUser */
        $requestingUser = $request->user();
        if (!$requestingUser instanceof \App\Models\User) {
            Log::error('[UserService@getAdminPanelUsersForCurrentOrg] Requesting user is not a User instance or is null.', [
                'user_object_type' => is_object($requestingUser) ? get_class($requestingUser) : gettype($requestingUser)
            ]);
            throw new BusinessLogicException('Ошибка аутентификации пользователя.', 401);
        }

        $organizationId = $request->attributes->get('current_organization_id');

        if (!$organizationId) {
            Log::error('[UserService@getAdminPanelUsersForCurrentOrg] Organization ID not found in request attributes.');
            throw new BusinessLogicException('Контекст организации не определен.', 500);
        }
        $intOrganizationId = (int) $organizationId;

        Log::debug('[UserService@getAdminPanelUsersForCurrentOrg] Calling userRepository->findByRolesInOrganization.', ['org_id' => $intOrganizationId, 'roles' => $rolesToFetch]);
        try {
            $users = $this->userRepository->findByRolesInOrganization($intOrganizationId, $rolesToFetch);
            Log::info('[UserService@getAdminPanelUsersForCurrentOrg] Users received from repository.', ['count' => count($users)]);
            return $users;
        } catch (\Throwable $e) {
            Log::error('[UserService@getAdminPanelUsersForCurrentOrg] Exception caught during repository call.', [
                'message' => $e->getMessage(),
                'file' => $e->getFile() . ':' . $e->getLine(),
            ]);
            throw $e;
        }
    }
    
    /**
     * Find an Admin Panel user by ID within the organization associated with the request.
     * Requires the requesting user to be an organization admin or owner.
     *
     * @param int $targetUserId
     * @param Request $request
     * @param array $allowedRoles Roles that the target user must have (e.g., ['web_admin', 'accountant'])
     * @return User|null
     * @throws BusinessLogicException
     */
    public function findAdminPanelUserById(int $targetUserId, Request $request, array $allowedRoles = ['web_admin', 'accountant']): ?User
    {
        $this->ensureUserIsAdmin($request); // Проверяем права запрашивающего
        $organizationId = $request->attributes->get('current_organization_id');
        if(!$organizationId) {
            throw new BusinessLogicException('Контекст организации не определен.', 500);
        }
        $intOrganizationId = (int) $organizationId;

        $user = $this->userRepository->find($targetUserId);

        // Проверяем, что пользователь найден и принадлежит ТЕКУЩЕЙ организации
        if (!$user || !$user->organizations()->where('organization_user.organization_id', $intOrganizationId)->exists()) {
            return null;
        }

        // Проверяем, что у пользователя есть хотя бы одна из разрешенных ролей в ЭТОЙ организации
        $hasAllowedRole = false;
        foreach ($allowedRoles as $roleSlug) {
            if ($user->hasRole($roleSlug, $intOrganizationId)) {
                $hasAllowedRole = true;
                break;
            }
        }

        if (!$hasAllowedRole) {
             Log::warning('Attempted to find user by ID who does not have an allowed admin panel role in the current org', [
                 'requesting_user_id' => $request->user()->id,
                 'target_user_id' => $targetUserId,
                 'organization_id' => $intOrganizationId,
                 'allowed_roles' => $allowedRoles
             ]);
            return null; // Пользователь найден, но у него нет нужной роли админ-панели
        }

        return $user;
    }

     /**
     * Update an Admin Panel user's details.
     * Requires the requesting user to be an organization admin or owner.
     * Role cannot be changed via this method.
     *
     * @param int $targetUserId ID пользователя для обновления
     * @param array $data (Validated data: name, password [optional])
     * @param Request $request Текущий запрос
     * @param array $allowedRoles Roles that the target user must have (e.g., ['web_admin', 'accountant'])
     * @return User Обновленный пользователь
     * @throws BusinessLogicException
     */
    public function updateAdminPanelUser(int $targetUserId, array $data, Request $request, array $allowedRoles = ['web_admin', 'accountant']): User
    {
        $this->ensureUserIsAdmin($request); // Проверяем права запрашивающего
        $organizationId = $request->attributes->get('current_organization_id');
        if(!$organizationId) {
            throw new BusinessLogicException('Контекст организации не определен.', 500);
        }
        $intOrganizationId = (int) $organizationId;

        // Используем findAdminPanelUserById для проверки существования, принадлежности к орг. и наличия нужной роли
        $targetUser = $this->findAdminPanelUserById($targetUserId, $request, $allowedRoles);
        if (!$targetUser) {
             throw new BusinessLogicException('Пользователь админ-панели не найден или нет прав на его просмотр/изменение.', 404);
        }

        // Запрещаем обновлять владельца организации через этот интерфейс
        if ($targetUser->hasRole(Role::ROLE_OWNER, $intOrganizationId)) {
            // Эта проверка может быть избыточной, если owner не входит в $allowedRoles, но на всякий случай
            throw new BusinessLogicException('Владельца организации нельзя изменять через этот интерфейс.', 403);
        }

        // Подготовка данных (только name и password)
        $updateData = [];
        if (isset($data['name'])) {
            $updateData['name'] = $data['name'];
        }
        if (!empty($data['password'])) {
            $updateData['password'] = Hash::make($data['password']);
        }

        if (empty($updateData)) {
             // Ничего не передано для обновления
             return $targetUser; // Возвращаем как есть
        }

        $this->userRepository->update($targetUserId, $updateData);

        return $this->userRepository->find($targetUserId); // Возвращаем обновленного пользователя
    }

    /**
     * Delete an Admin Panel user from the organization.
     * Requires the requesting user to be an organization admin or owner.
     * Detaches user from the organization and revokes relevant roles.
     *
     * @param int $targetUserId
     * @param Request $request
     * @param array $rolesToDelete Roles to revoke (e.g., ['web_admin', 'accountant'])
     * @return bool
     * @throws BusinessLogicException
     */
    public function deleteAdminPanelUser(int $targetUserId, Request $request, array $rolesToDelete = ['web_admin', 'accountant']): bool
    {
        $this->ensureUserIsAdmin($request); // Проверяем права запрашивающего
        $organizationId = $request->attributes->get('current_organization_id');
        if(!$organizationId) {
            throw new BusinessLogicException('Контекст организации не определен.', 500);
        }
        $intOrganizationId = (int) $organizationId;
        $requestingUser = $request->user();

        // Используем findAdminPanelUserById для проверки
        $targetUser = $this->findAdminPanelUserById($targetUserId, $request, $rolesToDelete);
        if (!$targetUser) {
            throw new BusinessLogicException('Пользователь админ-панели не найден или нет прав на его просмотр/удаление.', 404);
        }

        // Запрещаем удалять владельца
        if ($targetUser->hasRole(Role::ROLE_OWNER, $intOrganizationId)) {
             throw new BusinessLogicException('Владельца организации удалить нельзя.', 403);
        }
        // Запрещаем удалять себя
        if ($targetUserId === $requestingUser->id) {
            throw new BusinessLogicException('Нельзя удалить самого себя.', 403);
        }

        // Отзываем все указанные роли
        $revokedAny = false;
        foreach($rolesToDelete as $roleSlug) {
            $role = $this->roleRepository->findBySlug($roleSlug);
            if ($role) {
               $revoked = $this->userRepository->revokeRole($targetUserId, $role->id, $intOrganizationId);
               if ($revoked) $revokedAny = true;
            }
        }

        // Отвязываем от организации, если нет других ролей в этой организации
        // Перезагружаем пользователя, чтобы получить актуальные роли после отзыва
        $targetUser = $this->userRepository->find($targetUserId);
        if ($targetUser && $targetUser->rolesInOrganization($intOrganizationId)->count() === 0) {
             $this->userRepository->detachFromOrganization($targetUserId, $intOrganizationId);
        }

        return $revokedAny; // Возвращаем true, если хотя бы одна роль была отозвана
    }

    /**
     * Block a foreman user.
     *
     * @param int $foremanUserId
     * @param Request $request
     * @return bool
     * @throws BusinessLogicException
     */
    public function blockForeman(int $foremanUserId, Request $request): bool
    {
        $organizationId = $request->attributes->get('current_organization_id');
        $intOrganizationId = (int) $organizationId;
        $requestingUser = $request->user();

        $foreman = $this->findForemanById($foremanUserId, $request); // Используем существующий метод для поиска и проверки принадлежности к организации

        if (!$foreman) {
            throw new BusinessLogicException('Прораб не найден в текущей организации.', 404);
        }

        // Нельзя блокировать самого себя
        if ($requestingUser->id === $foreman->id) {
            throw new BusinessLogicException('Вы не можете заблокировать самого себя.', 403);
        }

        // Дополнительные проверки (например, нельзя блокировать последнего админа/владельца) можно добавить здесь

        Log::info('Blocking foreman', ['user_id' => $foremanUserId, 'org_id' => $intOrganizationId, 'admin_id' => $requestingUser->id]);
        return $this->userRepository->update($foremanUserId, ['is_active' => false]);
    }

    /**
     * Unblock a foreman user.
     *
     * @param int $foremanUserId
     * @param Request $request
     * @return bool
     * @throws BusinessLogicException
     */
    public function unblockForeman(int $foremanUserId, Request $request): bool
    {
        $organizationId = $request->attributes->get('current_organization_id');
        $intOrganizationId = (int) $organizationId;
        $requestingUser = $request->user();

        $foreman = $this->findForemanById($foremanUserId, $request); // Используем существующий метод для поиска и проверки принадлежности к организации

        if (!$foreman) {
            throw new BusinessLogicException('Прораб не найден в текущей организации.', 404);
        }

        Log::info('Unblocking foreman', ['user_id' => $foremanUserId, 'org_id' => $intOrganizationId, 'admin_id' => $requestingUser->id]);
        return $this->userRepository->update($foremanUserId, ['is_active' => true]);
    }

    /**
     * Get ALL users with Admin Panel access roles for the current organization.
     *
     * @param Request $request
     * @return Collection
     * @throws BusinessLogicException
     */
    public function getAllAdminPanelUsersForCurrentOrg(Request $request): Collection
    {
        $this->ensureUserIsAdmin($request); // Проверяем, что запрашивающий - админ/владелец
        $organizationId = $request->attributes->get('current_organization_id');
        if(!$organizationId) {
            throw new BusinessLogicException('Контекст организации не определен.', 500);
        }
        $intOrganizationId = (int) $organizationId;

        $adminPanelRoles = User::ADMIN_PANEL_ACCESS_ROLES; // Получаем все роли из константы

        $users = $this->userRepository->findByRolesInOrganization($intOrganizationId, $adminPanelRoles);

        return $users->unique('id'); // Возвращаем уникальных пользователей
    }
} 