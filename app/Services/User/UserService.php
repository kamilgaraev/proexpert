<?php

namespace App\Services\User;

use App\Models\User;
use App\Repositories\Interfaces\UserRepositoryInterface;
// Интеграция с новой системой авторизации
use App\Domain\Authorization\Services\AuthorizationService;
use App\Helpers\AdminPanelAccessHelper;
use App\Services\Logging\LoggingService;
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
 */
class UserService
{
    protected UserRepositoryInterface $userRepository;
    protected AuthorizationService $authorizationService;
    protected AdminPanelAccessHelper $adminPanelHelper;
    protected LoggingService $logging;

    public function __construct(
        UserRepositoryInterface $userRepository,
        AuthorizationService $authorizationService,
        AdminPanelAccessHelper $adminPanelHelper,
        LoggingService $logging
    )
    {
        $this->userRepository = $userRepository;
        $this->authorizationService = $authorizationService;
        $this->adminPanelHelper = $adminPanelHelper;
        $this->logging = $logging;
    }

    // --- Helper Methods ---

    /**
     * Получить ID контекста авторизации для организации
     */
    protected function getOrganizationContextId(int $organizationId): ?int
    {
        $context = \App\Domain\Authorization\Models\AuthorizationContext::getOrganizationContext($organizationId);
        return $context ? $context->id : null;
    }

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
        $contextId = $organizationId ? $this->getOrganizationContextId($organizationId) : null;

        if (!$user || !$organizationId || !$this->authorizationService->hasRole($user, 'organization_owner', $contextId)) {
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
        if (!$organizationId && $user) {
            $organizationId = $user->current_organization_id; // Фолбэк, если атрибут не установлен middleware
        }

        if (!$user || !$organizationId) { // Базовые проверки
            throw new BusinessLogicException('Действие доступно только авторизованному пользователю в контексте организации.', 403);
        }

        // Получаем ID контекста авторизации для организации
        $contextId = $this->getOrganizationContextId($organizationId);

        // Проверяем права через новую систему авторизации
        $canManage = $this->authorizationService->can($user, 'organization.manage', ['organization_id' => $organizationId]);
        $isSystemAdmin = $this->authorizationService->hasRole($user, 'system_admin');
        $isOrgOwner = $this->authorizationService->hasRole($user, 'organization_owner', $contextId);
        $isOrgAdmin = $this->authorizationService->hasRole($user, 'organization_admin', $contextId);
        $isWebAdmin = $this->authorizationService->hasRole($user, 'web_admin', $contextId);
        
        \Illuminate\Support\Facades\Log::info('[UserService::ensureUserIsAdmin] Checking permissions', [
            'user_id' => $user->id,
            'organization_id' => $organizationId,
            'can_manage' => $canManage,
            'is_system_admin' => $isSystemAdmin,
            'is_org_owner' => $isOrgOwner,
            'is_org_admin' => $isOrgAdmin,
            'is_web_admin' => $isWebAdmin,
            'user_current_org' => $user->current_organization_id
        ]);
        
        if (!($canManage || $isSystemAdmin || $isOrgOwner || $isOrgAdmin || $isWebAdmin)) {
            throw new BusinessLogicException('Действие доступно только администратору организации или веб-администратору.', 403);
        }
    }


    /**
     * Проверяет, существует ли роль в новой системе авторизации
     * Проверяет как системные роли, так и кастомные роли организации
     *
     * @param string $roleSlug
     * @param int|null $organizationId Опционально - для проверки кастомных ролей
     * @throws BusinessLogicException
     */
    protected function validateRoleExists(string $roleSlug, ?int $organizationId = null): void
    {
        $roleScanner = app(\App\Domain\Authorization\Services\RoleScanner::class);
        $allRoles = $roleScanner->getAllRoles();
        
        // Сначала проверяем системные роли
        if (isset($allRoles[$roleSlug])) {
            return;
        }
        
        // Если системной роли нет и есть контекст организации, проверяем кастомные роли
        if ($organizationId) {
            $customRole = \App\Domain\Authorization\Models\OrganizationCustomRole::where('slug', $roleSlug)
                ->where('organization_id', $organizationId)
                ->where('is_active', true)
                ->exists();
                
            if ($customRole) {
                return;
            }
        }
        
        throw new BusinessLogicException("Роль '{$roleSlug}' не найдена в системе.", 422);
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
            throw new BusinessLogicException('Контекст организации не определен.', 400);
        }

        $intOrganizationId = (int) $organizationId;

        $adminRoleSlug = 'organization_admin';
        $ownerRoleSlug = 'organization_owner';

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
            throw new BusinessLogicException('Контекст организации не определен.', 400);
        }
        $intOrganizationId = (int) $organizationId;
        $adminRoleSlug = 'organization_admin';
        
        // BUSINESS: Начало процесса создания администратора - важная бизнес-метрика
        $this->logging->business('user.admin.creation.started', [
            'target_email' => $data['email'],
            'target_name' => $data['name'],
            'organization_id' => $intOrganizationId,
            'created_by_user_id' => $request->user()?->id,
            'role_slug' => $adminRoleSlug
        ]);
        
        $this->validateRoleExists($adminRoleSlug, $intOrganizationId);

        $data['password'] = Hash::make($data['password']);
        // $data['user_type'] = 'organization_admin'; // Удалена в новой системе авторизации

        // Check if user already exists
        $existingUser = $this->userRepository->findByEmail($data['email']);

        if ($existingUser) {
            // Проверяем, есть ли уже роль администратора
            if ($this->authorizationService->hasRole($existingUser, $adminRoleSlug, $intOrganizationId)) {
                // BUSINESS: Попытка создать дублирующего админа
                $this->logging->business('user.admin.creation.duplicate', [
                    'existing_user_id' => $existingUser->id,
                    'email' => $data['email'],
                    'organization_id' => $intOrganizationId,
                    'attempted_by_user_id' => $request->user()?->id
                ], 'warning');
                throw new BusinessLogicException('Пользователь с таким email уже является администратором в этой организации.', 409);
            }
            
            // Назначаем роль существующему пользователю
            $this->userRepository->attachToOrganization($existingUser->id, $intOrganizationId);
            $this->userRepository->assignRoleToUser($existingUser->id, $adminRoleSlug, $intOrganizationId);
            $this->userRepository->update($existingUser->id, ['name' => $data['name']]);
            
            // AUDIT: Назначение роли администратора существующему пользователю
            $this->logging->audit('user.admin.role.assigned.existing', [
                'user_id' => $existingUser->id,
                'email' => $existingUser->email,
                'name' => $data['name'],
                'organization_id' => $intOrganizationId,
                'role_slug' => $adminRoleSlug,
                'assigned_by' => $request->user()?->id,
                'was_existing_user' => true
            ]);
            
            // BUSINESS: Успешное назначение роли админа существующему пользователю
            $this->logging->business('user.admin.assigned.existing', [
                'user_id' => $existingUser->id,
                'email' => $existingUser->email,
                'organization_id' => $intOrganizationId,
                'assigned_by' => $request->user()?->id
            ]);
            
            return $this->userRepository->find($existingUser->id);
        } else {
            // Создаем нового пользователя
            $newUser = $this->userRepository->create($data);
            $this->userRepository->attachToOrganization($newUser->id, $intOrganizationId);
            $this->userRepository->assignRoleToUser($newUser->id, $adminRoleSlug, $intOrganizationId);
            
            // AUDIT: Создание нового пользователя с ролью администратора
            $this->logging->audit('user.admin.created.new', [
                'user_id' => $newUser->id,
                'email' => $newUser->email,
                'name' => $newUser->name,
                'organization_id' => $intOrganizationId,
                'role_slug' => $adminRoleSlug,
                'created_by' => $request->user()?->id,
                'was_new_user' => true
            ]);
            
            // BUSINESS: Успешное создание нового администратора - ключевая метрика роста
            $this->logging->business('user.admin.created.new', [
                'user_id' => $newUser->id,
                'email' => $newUser->email,
                'organization_id' => $intOrganizationId,
                'created_by' => $request->user()?->id,
                'user_creation_date' => $newUser->created_at?->toISOString()
            ]);
            
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
            throw new BusinessLogicException('Контекст организации не определен.', 400);
        }
        $intOrganizationId = (int) $organizationId;

        $user = $this->userRepository->find($adminUserId);

        // Проверяем, что пользователь найден и принадлежит ТЕКУЩЕЙ организации запроса
        if (!$user || !$user->organizations()->where('organization_user.organization_id', $intOrganizationId)->exists()) {
            return null; // Не найден или не в той организации
        }

        // Дополнительно: Убедимся, что у пользователя есть роль админа или владельца В ЭТОЙ ОРГАНИЗАЦИИ
        // Это важно, чтобы случайно не показать/изменить пользователя с другой ролью
        if (!($this->authorizationService->hasRole($user, 'organization_admin', $intOrganizationId) || 
              $this->authorizationService->hasRole($user, 'organization_owner', $intOrganizationId))) {
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
            throw new BusinessLogicException('Контекст организации не определен.', 400);
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
        if ($this->authorizationService->hasRole($targetUser, 'organization_owner', $intOrganizationId)) {
            // Разрешаем обновление владельца только системному администратору
            if (!$this->authorizationService->hasRole($requestingUser, 'system_admin')) {
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
            throw new BusinessLogicException('Контекст организации не определен.', 400);
        }
        $intOrganizationId = (int) $organizationId;
        $requestingUser = $request->user();
        
        // SECURITY: Попытка удаления администратора - критическое security событие
        $this->logging->security('user.admin.deletion.attempt', [
            'target_user_id' => $adminUserId,
            'organization_id' => $intOrganizationId,
            'requested_by' => $requestingUser?->id,
            'requested_by_email' => $requestingUser?->email
        ]);

        // Используем findAdminById, чтобы убедиться, что пользователь существует, является админом/владельцем и в нужной организации
        $adminUser = $this->findAdminById($adminUserId, $request);
        if (!$adminUser) {
            throw new BusinessLogicException('Администратор/Владелец не найден в этой организации или нет прав на его просмотр.', 404);
        }

        // Запрещаем удалять Владельца организации
        if ($this->authorizationService->hasRole($adminUser, 'organization_owner', $intOrganizationId)) {
            // SECURITY: Попытка удалить владельца - критическая угроза безопасности
            $this->logging->security('user.owner.deletion.blocked', [
                'target_user_id' => $adminUserId,
                'target_email' => $adminUser->email,
                'organization_id' => $intOrganizationId,
                'attempted_by' => $requestingUser?->id,
                'attempted_by_email' => $requestingUser?->email,
                'reason' => 'Cannot delete organization owner'
            ], 'warning');
            throw new BusinessLogicException('Нельзя удалить владельца организации.', 403);
        }
        // Запрещаем удалять себя (даже если ты админ)
        if ($adminUserId === $requestingUser->id) {
            // SECURITY: Попытка самоудаления админа
            $this->logging->security('user.admin.self_deletion.blocked', [
                'user_id' => $adminUserId,
                'organization_id' => $intOrganizationId,
                'reason' => 'Cannot delete self'
            ], 'warning');
            throw new BusinessLogicException('Нельзя удалить самого себя.', 403);
        }

        // Вместо удаления пользователя, отвязываем его от организации и удаляем роль админа
        $adminRoleSlug = 'organization_admin';
        $this->userRepository->revokeRole($adminUserId, 0, $intOrganizationId); // Передаем 0 для совместимости, метод deprecated

        // Отвязываем от организации, если нет других ролей в этой организации
        $context = \App\Domain\Authorization\Models\AuthorizationContext::getOrganizationContext($intOrganizationId);
        $otherRolesCount = \App\Domain\Authorization\Models\UserRoleAssignment::where([
            'user_id' => $adminUserId,
            'context_id' => $context->id,
            'is_active' => true
        ])->count();
        
        $wasDetachedFromOrg = false;
        if ($otherRolesCount === 0) {
            $this->userRepository->detachFromOrganization($adminUserId, $intOrganizationId);
            $wasDetachedFromOrg = true;
        }

        // AUDIT: Успешное удаление роли администратора - критически важно для compliance
        $this->logging->audit('user.admin.role.revoked', [
            'target_user_id' => $adminUserId,
            'target_email' => $adminUser->email,
            'target_name' => $adminUser->name,
            'organization_id' => $intOrganizationId,
            'role_revoked' => $adminRoleSlug,
            'revoked_by' => $requestingUser?->id,
            'revoked_by_email' => $requestingUser?->email,
            'was_detached_from_org' => $wasDetachedFromOrg,
            'remaining_roles_count' => $otherRolesCount
        ]);

        // BUSINESS: Удаление администратора - важная бизнес-метрика
        $this->logging->business('user.admin.removed', [
            'target_user_id' => $adminUserId,
            'organization_id' => $intOrganizationId,
            'removed_by' => $requestingUser?->id,
            'was_completely_removed' => $wasDetachedFromOrg
        ]);

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

        // Параметры сортировки из запроса
        $sortBy = $request->query('sort_by', 'created_at'); // Значение по умолчанию 'created_at'
        $sortDirection = $request->query('sort_direction', 'desc'); // Значение по умолчанию 'desc'

        $foremanRoleSlug = 'foreman';

        // Используем метод репозитория, который поддерживает фильтрацию и пагинацию
        return $this->userRepository->paginateByRoleInOrganization(
            $foremanRoleSlug,
            $intOrganizationId,
            $perPage,
            $filters,
            $sortBy,             // Передаем sortBy
            $sortDirection       // Передаем sortDirection
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
        $foremanRoleSlug = 'foreman';
        $this->validateRoleExists($foremanRoleSlug, $organizationId);

        $data['password'] = Hash::make($data['password']);
        // user_type колонка удалена в новой системе авторизации - роли управляются через UserRoleAssignment

        // Check if user already exists
        $existingUser = $this->userRepository->findByEmail($data['email']);

        if ($existingUser) {
            // If user exists, check if they are already a foreman in this org
            $contextId = $this->getOrganizationContextId($organizationId);
            if ($this->authorizationService->hasRole($existingUser, $foremanRoleSlug, $contextId)) {
                 throw new BusinessLogicException('Пользователь с таким email уже является прорабом в этой организации.', 409);
            }
            // If user exists but not foreman, add them to the org with the foreman role
            $this->userRepository->attachToOrganization($existingUser->id, $organizationId, false); // Ensure attached, NOT as owner
            $this->userRepository->assignRoleToUser($existingUser->id, $foremanRoleSlug, $organizationId);
            $this->userRepository->update($existingUser->id, ['name' => $data['name']]); // Update name
            return $this->userRepository->find($existingUser->id);
        } else {
             // If user doesn't exist, create them and assign role/org
            $newUser = $this->userRepository->create($data);
            $this->userRepository->attachToOrganization($newUser->id, $organizationId, false); // Attach as NOT an owner
            $this->userRepository->assignRoleToUser($newUser->id, $foremanRoleSlug, $organizationId);
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
        $this->ensureUserIsAdmin($request);
        $organizationId = $request->attributes->get('current_organization_id');
        if(!$organizationId) {
            throw new BusinessLogicException('Контекст организации не определен.', 500);
        }
        $intOrganizationId = (int) $organizationId;

        $foremanRoleSlug = 'foreman';
        $context = \App\Domain\Authorization\Models\AuthorizationContext::getOrganizationContext($intOrganizationId);

        $user = User::where('id', $foremanUserId)
            ->whereHas('roleAssignments', function ($q) use ($foremanRoleSlug, $context) {
                $q->where('role_slug', $foremanRoleSlug);
                $q->where('context_id', $context->id);
                $q->where('is_active', true);
            })
            ->first();

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
        $foremanRoleSlug = 'foreman';
        $this->userRepository->revokeRole($foremanUserId, 0, $organizationId); // Deprecated method, passing 0 for compatibility

        // Detach from organization if they have no other roles in this org
        // Reload user to get fresh role count
        $foremanUser = $this->userRepository->find($foremanUserId); 
        $context = \App\Domain\Authorization\Models\AuthorizationContext::getOrganizationContext($organizationId);
        $otherRolesCount = \App\Domain\Authorization\Models\UserRoleAssignment::where([
            'user_id' => $foremanUserId,
            'context_id' => $context->id,
            'is_active' => true
        ])->count();
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

        // Проверяем существование роли (системной или кастомной в организации)
        $this->validateRoleExists($roleSlug, $intOrganizationId);


        $userData['password'] = Hash::make($userData['password']);
        // user_type колонка удалена в новой системе авторизации - роли управляются через UserRoleAssignment

        // Проверяем, существует ли пользователь с таким email
        $existingUser = $this->userRepository->findByEmail($userData['email']);

        if ($existingUser) {
            // Пользователь существует. Проверяем, есть ли у него уже ЭТА роль В ЭТОЙ организации.
            if ($this->authorizationService->hasRole($existingUser, $roleSlug, $intOrganizationId)) {
                 throw new BusinessLogicException("Пользователь с таким email уже имеет роль '{$roleSlug}' в этой организации.", 409);
            }
            // Пользователь существует, но роли нет. Привязываем к организации и назначаем роль.
            $this->userRepository->attachToOrganization($existingUser->id, $intOrganizationId, false, true); // Не владелец, активный
            $this->userRepository->assignRoleToUser($existingUser->id, $roleSlug, $intOrganizationId);
            // Обновляем имя, если оно изменилось
            $this->userRepository->update($existingUser->id, ['name' => $userData['name']]);
            return $this->userRepository->find($existingUser->id);
        } else {
            $userData['current_organization_id'] = $intOrganizationId;
            $newUser = $this->userRepository->create($userData);
            $this->userRepository->attachToOrganization($newUser->id, $intOrganizationId, false, true);
            $this->userRepository->assignRoleToUser($newUser->id, $roleSlug, $intOrganizationId);
            
            if (!$newUser->hasVerifiedEmail()) {
                try {
                    $newUser->sendEmailVerificationNotification();
                    Log::info('[UserService] Email verification sent to new admin panel user', [
                        'user_id' => $newUser->id,
                        'email' => $newUser->email
                    ]);
                } catch (\Exception $e) {
                    Log::error('[UserService] Failed to send email verification', [
                        'user_id' => $newUser->id,
                        'error' => $e->getMessage()
                    ]);
                }
            }
            
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
    public function getAdminPanelUsersForCurrentOrg(Request $request, array $rolesToFetch = null): Collection
    {
        Log::info('[UserService@getAdminPanelUsersForCurrentOrg] Method entered.', [
            'user_id' => $request->user() ? $request->user()->id : null, 
            'organization_id' => $request->attributes->get('current_organization_id'),
            'roles_to_fetch' => $rolesToFetch
        ]);

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

        // Получаем роли с помощью AdminPanelAccessHelper
        $currentInterface = $request->input('current_interface', 'lk');
        $adminPanelRoles = $this->adminPanelHelper->getAdminPanelRoles($intOrganizationId, $currentInterface);
        $rolesToFetch = $rolesToFetch ?? $adminPanelRoles;

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
        $userRoles = [];
        foreach ($allowedRoles as $roleSlug) {
            $hasRole = $this->authorizationService->hasRole($user, $roleSlug, $intOrganizationId);
            $userRoles[$roleSlug] = $hasRole;
            if ($hasRole) {
                $hasAllowedRole = true;
                break;
            }
        }

        if (!$hasAllowedRole) {
             Log::warning('[UserService@findAdminPanelUserById] User does not have an allowed admin panel role', [
                 'requesting_user_id' => $request->user()->id,
                 'target_user_id' => $targetUserId,
                 'organization_id' => $intOrganizationId,
                 'allowed_roles' => $allowedRoles,
                 'user_roles_check' => $userRoles,
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
        if ($this->authorizationService->hasRole($targetUser, 'organization_owner', $intOrganizationId)) {
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
    public function deleteAdminPanelUser(int $targetUserId, Request $request, array $rolesToDelete = null): bool
    {
        $this->ensureUserIsAdmin($request);
        $organizationId = $request->attributes->get('current_organization_id');
        if(!$organizationId) {
            throw new BusinessLogicException('Контекст организации не определен.', 500);
        }
        $intOrganizationId = (int) $organizationId;
        $requestingUser = $request->user();

        // Получаем интерфейс из запроса (как в getAdminPanelUsersForCurrentOrg)
        $currentInterface = $request->input('current_interface', 'lk');
        $rolesToDelete = $rolesToDelete ?? $this->adminPanelHelper->getAdminPanelRoles($intOrganizationId, $currentInterface);
        
        Log::info('[UserService@deleteAdminPanelUser] Attempting to delete user', [
            'target_user_id' => $targetUserId,
            'organization_id' => $intOrganizationId,
            'current_interface' => $currentInterface,
            'roles_to_delete' => $rolesToDelete,
        ]);
        
        // Используем тот же метод поиска, что и в getAdminPanelUsersForCurrentOrg
        $adminPanelUsers = $this->userRepository->findByRolesInOrganization($intOrganizationId, $rolesToDelete);
        $targetUser = $adminPanelUsers->firstWhere('id', $targetUserId);
        
        if (!$targetUser) {
            Log::warning('[UserService@deleteAdminPanelUser] User not found in admin panel users', [
                'target_user_id' => $targetUserId,
                'organization_id' => $intOrganizationId,
                'roles_checked' => $rolesToDelete,
                'found_users_count' => $adminPanelUsers->count(),
                'found_user_ids' => $adminPanelUsers->pluck('id')->toArray(),
            ]);
            throw new BusinessLogicException('Пользователь админ-панели не найден или нет прав на его просмотр/удаление.', 404);
        }

        // Запрещаем удалять владельца
        if ($this->authorizationService->hasRole($targetUser, 'organization_owner', $intOrganizationId)) {
             throw new BusinessLogicException('Владельца организации удалить нельзя.', 403);
        }
        // Запрещаем удалять себя
        if ($targetUserId === $requestingUser->id) {
            throw new BusinessLogicException('Нельзя удалить самого себя.', 403);
        }

        // Отзываем все указанные роли через новую систему
        $revokedAny = false;
        $context = \App\Domain\Authorization\Models\AuthorizationContext::getOrganizationContext($intOrganizationId);
        foreach($rolesToDelete as $roleSlug) {
            $revoked = \App\Domain\Authorization\Models\UserRoleAssignment::where([
                'user_id' => $targetUserId,
                'role_slug' => $roleSlug,
                'context_id' => $context->id,
                'is_active' => true
            ])->update(['is_active' => false]);
            if ($revoked) $revokedAny = true;
        }

        // Отвязываем от организации, если нет других ролей в этой организации
        $targetUser = $this->userRepository->find($targetUserId);
        $remainingRoles = \App\Domain\Authorization\Models\UserRoleAssignment::where([
            'user_id' => $targetUserId,
            'context_id' => $context->id,
            'is_active' => true
        ])->count();
        
        if ($targetUser && $remainingRoles === 0) {
             $this->userRepository->detachFromOrganization($targetUserId, $intOrganizationId);
        }

        Log::info('[UserService@deleteAdminPanelUser] User deleted from admin panel', [
            'target_user_id' => $targetUserId,
            'organization_id' => $intOrganizationId,
            'revoked_any' => $revokedAny
        ]);

        return $revokedAny;
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

        $currentInterface = $request->input('current_interface', 'lk');
        $adminPanelRoles = $this->adminPanelHelper->getAdminPanelRoles($intOrganizationId, $currentInterface);

        $users = $this->userRepository->findByRolesInOrganization($intOrganizationId, $adminPanelRoles);

        return $users->unique('id'); // Возвращаем уникальных пользователей
    }

} 