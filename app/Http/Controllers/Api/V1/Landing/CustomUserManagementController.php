<?php

namespace App\Http\Controllers\Api\V1\Landing;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use App\Domain\Authorization\Services\CustomRoleService;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Domain\Authorization\Services\RoleScanner;
use App\Repositories\UserRepository;
use App\Services\Billing\SubscriptionLimitsService;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Контроллер для управления пользователями с кастомными ролями
 */
class CustomUserManagementController extends Controller
{
    protected CustomRoleService $customRoleService;
    protected AuthorizationService $authService;
    protected RoleScanner $roleScanner;
    protected UserRepository $userRepository;
    protected SubscriptionLimitsService $subscriptionLimitsService;

    public function __construct(
        CustomRoleService $customRoleService,
        AuthorizationService $authService,
        RoleScanner $roleScanner,
        UserRepository $userRepository,
        SubscriptionLimitsService $subscriptionLimitsService
    ) {
        $this->customRoleService = $customRoleService;
        $this->authService = $authService;
        $this->roleScanner = $roleScanner;
        $this->userRepository = $userRepository;
        $this->subscriptionLimitsService = $subscriptionLimitsService;
    }

    /**
     * Создать пользователя с кастомными ролями
     */
    public function createUserWithCustomRoles(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:8|confirmed',
            'custom_role_ids' => 'nullable|array',
            'custom_role_ids.*' => 'integer|exists:organization_custom_roles,id',
            'roles' => 'nullable|array',
            'roles.*' => 'string',
            'send_credentials' => 'sometimes|boolean'
        ]);
        
        $organizationId = $request->attributes->get('current_organization_id');
        
        if (!$organizationId) {
            return response()->json([
                'success' => false,
                'message' => 'Контекст организации не определен'
            ], 400);
        }

        try {
            // Создаем пользователя
            $data['password'] = Hash::make($data['password']);
            // $data['user_type'] = 'custom_role_user'; // Удалена в новой системе авторизации
            $data['current_organization_id'] = $organizationId;
            
            $user = $this->userRepository->create($data);
            
            // Привязываем к организации
            $this->userRepository->attachToOrganization($user->id, $organizationId, false, true);
            
            $authContext = \App\Domain\Authorization\Models\AuthorizationContext::getOrganizationContext($organizationId);

            // Назначаем кастомные роли через новую систему
            if (!empty($data['custom_role_ids'])) {
                foreach ($data['custom_role_ids'] as $roleId) {
                    $role = \App\Domain\Authorization\Models\OrganizationCustomRole::findOrFail($roleId);
                    $this->customRoleService->assignRoleToUser($role, $user, $authContext);
                }
            }

            // Назначаем системные роли
            if (!empty($data['roles'])) {
                foreach ($data['roles'] as $roleSlug) {
                    try {
                        $this->authService->assignRole($user, $roleSlug, $authContext);
                    } catch (\InvalidArgumentException $e) {
                        Log::warning("Skipping invalid system role: {$roleSlug}", ['error' => $e->getMessage()]);
                        // Можно прервать или продолжить. Продолжим.
                    }
                }
            }
            
            // Отправляем учетные данные, если запрошено
            if ($data['send_credentials'] ?? false) {
                // TODO: Реализовать отправку учетных данных
                Log::info('User credentials need to be sent', ['user_id' => $user->id]);
            }
            
            return response()->json([
                'success' => true,
                'data' => [
                    'user' => [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                        'created_at' => $user->created_at
                    ]
                ],
                'message' => 'Пользователь успешно создан с назначенными ролями'
            ], 201);
            
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Error creating user with custom roles', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Ошибка при создании пользователя: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Получить доступные роли для организации
     */
    public function getAvailableRoles(Request $request): JsonResponse
    {
        $organizationId = $request->attributes->get('current_organization_id');
        
        if (!$organizationId) {
            return response()->json([
                'success' => false,
                'message' => 'Контекст организации не определен'
            ], 400);
        }

        try {
            // Системные роли
            $systemRoles = $this->roleScanner->getAllRoles()->toArray();
            
            // Кастомные роли организации
            $customRoles = collect([]);
            try {
                $customRoles = $this->customRoleService->getOrganizationRoles($organizationId);
            } catch (\Exception $e) {
                // Если таблицы новой системы еще не готовы
                $customRoles = collect([]);
                Log::warning('Custom roles not available yet', ['error' => $e->getMessage()]);
            }
            
            return response()->json([
                'success' => true,
                'data' => [
                    'system_roles' => array_keys($systemRoles),
                    'custom_roles' => $customRoles->map(function($role) {
                        return [
                            'id' => $role->id,
                            'name' => $role->name,
                            'slug' => $role->slug,
                            'description' => $role->description,
                            'is_active' => $role->is_active
                        ];
                    })->values()->toArray(),
                    'organization_id' => $organizationId
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Error getting available roles', [
                'error' => $e->getMessage(),
                'organization_id' => $organizationId
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Ошибка при получении доступных ролей'
            ], 500);
        }
    }

    /**
     * Обновить кастомные роли пользователя
     */
    public function updateUserCustomRoles(Request $request, int $userId): JsonResponse
    {
        $data = $request->validate([
            'custom_role_ids' => 'required|array',
            'custom_role_ids.*' => 'integer|exists:organization_custom_roles,id'
        ]);

        $organizationId = $request->attributes->get('current_organization_id');
        
        if (!$organizationId) {
            return response()->json([
                'success' => false,
                'message' => 'Контекст организации не определен'
            ], 400);
        }

        try {
            // Получаем пользователя
            $user = $this->userRepository->find($userId);
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Пользователь не найден'
                ], 404);
            }

            // Проверяем принадлежность к организации
            if (!$user->organizations()->where('organization_user.organization_id', $organizationId)->exists()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Пользователь не принадлежит к данной организации'
                ], 403);
            }

            // Обновляем роли (пока используем простую логику)
            // TODO: Реализовать метод updateUserRoles в CustomRoleService
            $authContext = \App\Domain\Authorization\Models\AuthorizationContext::getOrganizationContext($organizationId);
            foreach ($data['custom_role_ids'] as $roleId) {
                $role = \App\Domain\Authorization\Models\OrganizationCustomRole::findOrFail($roleId);
                $this->customRoleService->assignRoleToUser($role, $user, $authContext);
            }

            return response()->json([
                'success' => true,
                'message' => 'Роли пользователя успешно обновлены'
            ]);

        } catch (\Exception $e) {
            Log::error('Error updating user custom roles', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
                'organization_id' => $organizationId
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Ошибка при обновлении ролей пользователя'
            ], 500);
        }
    }

    /**
     * Назначить кастомную роль пользователю
     */
    public function assignCustomRole(Request $request, int $userId, int $roleId): JsonResponse
    {
        $organizationId = $request->attributes->get('current_organization_id');
        
        if (!$organizationId) {
            return response()->json([
                'success' => false,
                'message' => 'Контекст организации не определен'
            ], 400);
        }

        try {
            // Получаем роль и пользователя для передачи в сервис
            $role = \App\Domain\Authorization\Models\OrganizationCustomRole::findOrFail($roleId);
            $user = $this->userRepository->find($userId);
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Пользователь не найден'
                ], 404);
            }
            
            $authContext = \App\Domain\Authorization\Models\AuthorizationContext::getOrganizationContext($organizationId);
            $this->customRoleService->assignRoleToUser($role, $user, $authContext);

            return response()->json([
                'success' => true,
                'message' => 'Роль успешно назначена пользователю'
            ]);

        } catch (\Exception $e) {
            Log::error('Error assigning custom role', [
                'user_id' => $userId,
                'role_id' => $roleId,
                'error' => $e->getMessage(),
                'organization_id' => $organizationId
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Ошибка при назначении роли'
            ], 500);
        }
    }

    /**
     * Отозвать кастомную роль у пользователя
     */
    public function unassignCustomRole(Request $request, int $userId, int $roleId): JsonResponse
    {
        $organizationId = $request->attributes->get('current_organization_id');
        
        if (!$organizationId) {
            return response()->json([
                'success' => false,
                'message' => 'Контекст организации не определен'
            ], 400);
        }

        try {
            // Получаем роль и пользователя для передачи в сервис  
            $role = \App\Domain\Authorization\Models\OrganizationCustomRole::findOrFail($roleId);
            $user = $this->userRepository->find($userId);
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Пользователь не найден'
                ], 404);
            }
            
            $authContext = \App\Domain\Authorization\Models\AuthorizationContext::getOrganizationContext($organizationId);
            $this->authService->revokeRole($user, $role->slug, $authContext);

            return response()->json([
                'success' => true,
                'message' => 'Роль успешно отозвана у пользователя'
            ]);

        } catch (\Exception $e) {
            Log::error('Error unassigning custom role', [
                'user_id' => $userId,
                'role_id' => $roleId,
                'error' => $e->getMessage(),
                'organization_id' => $organizationId
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Ошибка при отзыве роли'
            ], 500);
        }
    }

    /**
     * Получить лимиты пользователя
     */
    public function getUserLimits(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            $limits = $this->subscriptionLimitsService->getUserLimitsData($user);
            
            return response()->json([
                'success' => true,
                'data' => $limits
            ]);
        } catch (\Exception $e) {
            Log::error('Error getting user limits', [
                'user_id' => $request->user()->id ?? null,
                'error' => $e->getMessage()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Ошибка при получении лимитов пользователя'
            ], 500);
        }
    }
}
