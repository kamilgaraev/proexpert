<?php

namespace App\Domain\Authorization\Http\Controllers\Api\V1\Landing;

use App\Http\Controllers\Controller;
use App\Domain\Authorization\Services\CustomRoleService;
use App\Domain\Authorization\Models\OrganizationCustomRole;
use App\Domain\Authorization\Http\Requests\CreateCustomRoleRequest;
use App\Domain\Authorization\Http\Requests\UpdateCustomRoleRequest;
use App\Domain\Authorization\Http\Resources\CustomRoleResource;
use App\Services\PermissionTranslationService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

/**
 * Контроллер для управления кастомными ролями организаций
 */
class CustomRoleController extends Controller
{
    protected CustomRoleService $roleService;

    public function __construct(CustomRoleService $roleService)
    {
        $this->roleService = $roleService;
        
        // Применяем middleware авторизации
        $this->middleware('authorize:roles.view_custom,organization')->only(['index', 'show']);
        $this->middleware('authorize:roles.create_custom,organization')->only(['store', 'getAvailablePermissions']);
        $this->middleware('authorize:roles.manage_custom,organization')->only(['update', 'destroy', 'clone']);
    }

    /**
     * Получить список кастомных ролей организации
     */
    public function index(Request $request): JsonResponse
    {
        $organizationId = $this->getOrganizationId($request);
        
        $roles = $this->roleService->getOrganizationRoles($organizationId);
        
        return response()->json([
            'data' => CustomRoleResource::collection($roles),
            'meta' => [
                'total' => $roles->count(),
                'organization_id' => $organizationId
            ]
        ]);
    }

    /**
     * Создать новую кастомную роль
     */
    public function store(CreateCustomRoleRequest $request): JsonResponse
    {
        $organizationId = $this->getOrganizationId($request);
        
        $role = $this->roleService->createRole(
            $organizationId,
            $request->validated('name'),
            $request->validated('system_permissions', []),
            $request->validated('module_permissions', []),
            $request->validated('interface_access', ['lk']),
            $request->validated('conditions'),
            $request->validated('description'),
            $request->user()
        );

        return response()->json([
            'data' => new CustomRoleResource($role),
            'message' => 'Роль успешно создана'
        ], 201);
    }

    /**
     * Показать детали кастомной роли
     */
    public function show(Request $request, OrganizationCustomRole $role): JsonResponse
    {
        $this->authorize('view', $role);
        
        return response()->json([
            'data' => new CustomRoleResource($role->load(['createdBy', 'assignments.user']))
        ]);
    }

    /**
     * Обновить кастомную роль
     */
    public function update(UpdateCustomRoleRequest $request, OrganizationCustomRole $role): JsonResponse
    {
        $this->authorize('update', $role);
        
        $this->roleService->updateRole($role, $request->validated(), $request->user());
        
        return response()->json([
            'data' => new CustomRoleResource($role->fresh()),
            'message' => 'Роль успешно обновлена'
        ]);
    }

    /**
     * Удалить кастомную роль
     */
    public function destroy(Request $request, OrganizationCustomRole $role): JsonResponse
    {
        $this->authorize('delete', $role);
        
        $this->roleService->deleteRole($role);
        
        return response()->json([
            'message' => 'Роль успешно удалена'
        ]);
    }

    /**
     * Клонировать роль
     */
    public function clone(Request $request, OrganizationCustomRole $role): JsonResponse
    {
        $this->authorize('update', $role);
        
        $request->validate([
            'name' => 'required|string|max:255',
            'target_organization_id' => 'sometimes|required|integer|exists:organizations,id'
        ]);
        
        $targetOrganizationId = $request->input('target_organization_id', $role->organization_id);
        
        $clonedRole = $this->roleService->cloneRole(
            $role,
            $targetOrganizationId,
            $request->input('name'),
            $request->user()
        );
        
        return response()->json([
            'data' => new CustomRoleResource($clonedRole),
            'message' => 'Роль успешно клонирована'
        ], 201);
    }

    /**
     * Получить доступные права для создания роли
     */
    public function getAvailablePermissions(Request $request): JsonResponse
    {
        $organizationId = $this->getOrganizationId($request);
        
        $systemPermissions = $this->roleService->getAvailableSystemPermissions($organizationId);
        $modulePermissions = $this->roleService->getAvailableModulePermissions($organizationId);
        
        $permissionsData = [
            'system_permissions' => $systemPermissions,
            'module_permissions' => $modulePermissions,
            'interface_access' => [
                'lk' => 'Личный кабинет',
                'mobile' => 'Мобильное приложение',
                'admin' => 'Административная панель'
            ]
        ];
        
        $translatedData = app(PermissionTranslationService::class)
            ->processPermissionsForFrontend($permissionsData);
        
        return response()->json([
            'data' => $translatedData
        ]);
    }

    /**
     * Получить пользователей с указанной ролью
     */
    public function getUsers(Request $request, OrganizationCustomRole $role): JsonResponse
    {
        $this->authorize('view', $role);
        
        $users = $this->roleService->getRoleUsers($role);
        
        return response()->json([
            'data' => $users->map(function ($user) {
                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'assigned_at' => $user->roleAssignments()
                        ->where('role_slug', request()->route('role')->slug)
                        ->first()?->created_at
                ];
            })
        ]);
    }

    /**
     * Назначить роль пользователю
     */
    public function assignToUser(Request $request, OrganizationCustomRole $role): JsonResponse
    {
        $this->authorize('update', $role);
        
        $request->validate([
            'user_id' => 'required|integer|exists:users,id',
            'context_type' => 'required|in:organization,project',
            'context_id' => 'required|integer',
            'expires_at' => 'sometimes|nullable|date|after:now'
        ]);
        
        // Здесь нужна дополнительная логика для назначения роли
        // Это зависит от контекста и может требовать дополнительной авторизации
        
        return response()->json([
            'message' => 'Роль успешно назначена пользователю'
        ]);
    }

    /**
     * Получить ID организации из запроса
     */
    protected function getOrganizationId(Request $request): int
    {
        $organizationId = $request->route('organization_id') 
            ?? $request->get('organization_id')
            ?? $request->input('organization_id')
            ?? $request->user()->current_organization_id;
            
        if (!$organizationId) {
            abort(400, 'Organization ID is required');
        }
        
        return (int) $organizationId;
    }
}
