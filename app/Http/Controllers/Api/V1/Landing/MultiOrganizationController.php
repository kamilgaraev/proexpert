<?php

namespace App\Http\Controllers\Api\V1\Landing;

use App\Http\Controllers\Controller;
use App\Services\Landing\MultiOrganizationService;
use App\Services\Landing\OrganizationModuleService;
use App\Services\Landing\ChildOrganizationUserService;
use App\Services\Landing\HoldingReportService;
use App\Models\OrganizationGroup;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use App\Http\Responses\Api\V1\ErrorResponse;

class MultiOrganizationController extends Controller
{
    protected MultiOrganizationService $multiOrgService;
    protected OrganizationModuleService $moduleService;
    protected ChildOrganizationUserService $childUserService;
    protected HoldingReportService $holdingReportService;

    public function __construct(
        MultiOrganizationService $multiOrgService,
        OrganizationModuleService $moduleService,
        ChildOrganizationUserService $childUserService,
        HoldingReportService $holdingReportService
    ) {
        $this->multiOrgService = $multiOrgService;
        $this->moduleService = $moduleService;
        $this->childUserService = $childUserService;
        $this->holdingReportService = $holdingReportService;
    }

    public function checkAvailability(Request $request): JsonResponse
    {
        $user = Auth::user();
        $organizationId = $request->attributes->get('current_organization_id') ?? $user->current_organization_id;
        
        $hasModule = $this->moduleService->hasModuleAccess($organizationId, 'multi_organization');
        
        if (!$hasModule) {
            return response()->json([
                'success' => false,
                'available' => false,
                'message' => 'Модуль "Мультиорганизация" не активирован',
                'required_module' => 'multi_organization'
            ], 403);
        }

        $organization = $user->currentOrganization;
        
        return response()->json([
            'success' => true,
            'available' => true,
            'can_create_holding' => !($organization->is_holding ?? false),
            'current_type' => $organization->organization_type ?? 'single',
            'is_holding' => $organization->is_holding ?? false,
        ]);
    }

    public function createHolding(Request $request): JsonResponse
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'max_child_organizations' => 'sometimes|integer|min:1|max:50',
            'settings' => 'sometimes|array',
            'permissions_config' => 'sometimes|array',
        ]);

        $user = Auth::user();
        
        try {
            $group = $this->multiOrgService->createOrganizationGroup($user, $request->all());
            
            return response()->json([
                'success' => true,
                'message' => 'Холдинг успешно создан',
                'data' => $group,
            ]);
        } catch (\Exception $e) {
            return (new ErrorResponse($e->getMessage(), 400))->toResponse($request);
        }
    }

    public function addChildOrganization(Request $request): JsonResponse
    {
        $request->validate([
            'group_id' => 'required|integer|exists:organization_groups,id',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'inn' => 'nullable|string|max:12',
            'kpp' => 'nullable|string|max:9',
            'address' => 'nullable|string|max:500',
            'phone' => 'nullable|string|max:20',
            'email' => 'nullable|email|max:255',
        ]);

        $user = Auth::user();
        $group = OrganizationGroup::findOrFail($request->input('group_id'));
        
        if ($group->parent_organization_id !== $user->current_organization_id) {
            return (new ErrorResponse('Нет прав для добавления дочерней организации', 403))->toResponse($request);
        }

        try {
            $childOrg = $this->multiOrgService->addChildOrganization($group, $request->all(), $user);
            
            return response()->json([
                'success' => true,
                'message' => 'Дочерняя организация успешно добавлена',
                'data' => $childOrg,
            ]);
        } catch (\Exception $e) {
            return (new ErrorResponse($e->getMessage(), 400))->toResponse($request);
        }
    }

    public function getHierarchy(Request $request): JsonResponse
    {
        $user = Auth::user();
        $organizationId = $request->attributes->get('current_organization_id') ?? $user->current_organization_id;
        
        try {
            $hierarchy = $this->multiOrgService->getOrganizationHierarchy($organizationId);
            
            return response()->json([
                'success' => true,
                'data' => $hierarchy,
            ]);
        } catch (\Exception $e) {
            return (new ErrorResponse($e->getMessage(), 400))->toResponse($request);
        }
    }

    public function getAccessibleOrganizations(Request $request): JsonResponse
    {
        $user = Auth::user();
        $organizations = $this->multiOrgService->getAccessibleOrganizations($user);
        
        return response()->json([
            'success' => true,
            'data' => $organizations->map(function ($org) {
                return [
                    'id' => $org->id,
                    'name' => $org->name,
                    'organization_type' => $org->organization_type ?? 'single',
                    'is_holding' => $org->is_holding ?? false,
                    'hierarchy_level' => $org->hierarchy_level ?? 0,
                ];
            }),
        ]);
    }

    public function getOrganizationData(Request $request, int $organizationId): JsonResponse
    {
        $user = Auth::user();
        
        try {
            $data = $this->multiOrgService->getOrganizationData($organizationId, $user);
            
            return response()->json([
                'success' => true,
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            return (new ErrorResponse($e->getMessage(), 403))->toResponse($request);
        }
    }

    public function switchOrganizationContext(Request $request): JsonResponse
    {
        $request->validate([
            'organization_id' => 'required|integer|exists:organizations,id',
        ]);

        $authUser = Auth::user();
        $targetOrgId = $request->input('organization_id');
        
        if (!$this->multiOrgService->hasAccessToOrganization($authUser, $targetOrgId)) {
            return (new ErrorResponse('Нет доступа к выбранной организации', 403))->toResponse($request);
        }

        $user = \App\Models\User::findOrFail($authUser->id);
        $user->current_organization_id = $targetOrgId;
        $user->save();
        
        return response()->json([
            'success' => true,
            'message' => 'Контекст организации изменен',
            'current_organization_id' => $targetOrgId,
        ]);
    }

    public function getChildOrganizations(Request $request): JsonResponse
    {
        $request->validate([
            'search' => 'nullable|string|max:255',
            'status' => 'nullable|in:active,inactive,all',
            'sort_by' => 'nullable|in:name,created_at,users_count,projects_count',
            'sort_direction' => 'nullable|in:asc,desc',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $user = Auth::user();
        $organizationId = $request->attributes->get('current_organization_id') ?? $user->current_organization_id;

        try {
            $result = $this->multiOrgService->getChildOrganizations($organizationId, [
                'search' => $request->input('search'),
                'status' => $request->input('status', 'active'),
                'sort_by' => $request->input('sort_by', 'name'),
                'sort_direction' => $request->input('sort_direction', 'asc'),
                'per_page' => $request->input('per_page', 15),
            ]);

            return response()->json([
                'success' => true,
                'data' => $result,
            ]);
        } catch (\Exception $e) {
            return (new ErrorResponse($e->getMessage(), 400))->toResponse($request);
        }
    }

    public function updateChildOrganization(Request $request, int $childOrgId): JsonResponse
    {
        $request->validate([
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string|max:1000',
            'inn' => 'nullable|string|max:12',
            'kpp' => 'nullable|string|max:9',
            'address' => 'nullable|string|max:500',
            'phone' => 'nullable|string|max:20',
            'email' => 'nullable|email|max:255',
            'is_active' => 'sometimes|boolean',
            'settings' => 'sometimes|array',
        ]);

        $user = Auth::user();
        $parentOrgId = $request->attributes->get('current_organization_id') ?? $user->current_organization_id;

        try {
            $updatedOrg = $this->multiOrgService->updateChildOrganization($parentOrgId, $childOrgId, $request->all(), $user);

            return response()->json([
                'success' => true,
                'message' => 'Дочерняя организация обновлена',
                'data' => $updatedOrg,
            ]);
        } catch (\Exception $e) {
            return (new ErrorResponse($e->getMessage(), 400))->toResponse($request);
        }
    }

    public function deleteChildOrganization(Request $request, int $childOrgId): JsonResponse
    {
        $request->validate([
            'transfer_data_to' => 'nullable|integer|exists:organizations,id',
            'confirm_deletion' => 'required|boolean|accepted',
        ]);

        $user = Auth::user();
        $parentOrgId = $request->attributes->get('current_organization_id') ?? $user->current_organization_id;

        try {
            $this->multiOrgService->deleteChildOrganization(
                $parentOrgId, 
                $childOrgId, 
                $user,
                $request->input('transfer_data_to')
            );

            return response()->json([
                'success' => true,
                'message' => 'Дочерняя организация удалена',
            ]);
        } catch (\Exception $e) {
            return (new ErrorResponse($e->getMessage(), 400))->toResponse($request);
        }
    }

    public function getChildOrganizationUsers(Request $request, int $childOrgId): JsonResponse
    {
        $request->validate([
            'search' => 'nullable|string|max:255',
            'role' => 'nullable|string',
            'status' => 'nullable|in:active,inactive,all',
            'per_page' => 'nullable|integer|min:1|max:50',
        ]);

        $user = Auth::user();
        $parentOrgId = $request->attributes->get('current_organization_id') ?? $user->current_organization_id;

        try {
            $result = $this->multiOrgService->getChildOrganizationUsers($parentOrgId, $childOrgId, [
                'search' => $request->input('search'),
                'role' => $request->input('role'),
                'status' => $request->input('status', 'active'),
                'per_page' => $request->input('per_page', 15),
            ]);

            return response()->json([
                'success' => true,
                'data' => $result,
            ]);
        } catch (\Exception $e) {
            return (new ErrorResponse($e->getMessage(), 400))->toResponse($request);
        }
    }

    public function addUserToChildOrganization(Request $request, int $childOrgId): JsonResponse
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'password' => 'sometimes|string|min:8',
            'auto_verify' => 'sometimes|boolean',
            'send_invitation' => 'sometimes|boolean',
            'role_data' => 'required|array',
            'role_data.template' => 'sometimes|string|in:administrator,project_manager,foreman,accountant,sales_manager,worker,observer',
            'role_data.name' => 'required_without:role_data.template|string|max:255',
            'role_data.description' => 'sometimes|string|max:1000',
            'role_data.permissions' => 'required_without:role_data.template|array',
            'role_data.permissions.*' => 'string',
            'role_data.color' => 'sometimes|string|regex:/^#[0-9A-Fa-f]{6}$/',
        ]);

        $user = Auth::user();
        $parentOrgId = $request->attributes->get('current_organization_id') ?? $user->current_organization_id;

        try {
            if (!$this->multiOrgService->hasAccessToOrganization($user, $parentOrgId)) {
                throw new \Exception('Нет доступа к родительской организации');
            }

            $childOrg = \App\Models\Organization::findOrFail($childOrgId);
            if ($childOrg->parent_organization_id !== $parentOrgId) {
                throw new \Exception('Организация не является дочерней для данного холдинга');
            }

            $result = $this->childUserService->createUserWithRole($childOrgId, $request->all(), $user);

            return response()->json([
                'success' => true,
                'message' => 'Пользователь добавлен в дочернюю организацию с персональной ролью',
                'data' => $result,
            ]);
        } catch (\Exception $e) {
            return (new ErrorResponse($e->getMessage(), 400))->toResponse($request);
        }
    }

    public function updateUserInChildOrganization(Request $request, int $childOrgId, int $userId): JsonResponse
    {
        $request->validate([
            'role' => 'sometimes|string|in:admin,manager,employee',
            'permissions' => 'sometimes|array',
            'is_active' => 'sometimes|boolean',
        ]);

        $user = Auth::user();
        $parentOrgId = $request->attributes->get('current_organization_id') ?? $user->current_organization_id;

        try {
            $result = $this->multiOrgService->updateUserInChildOrganization(
                $parentOrgId, 
                $childOrgId, 
                $userId, 
                $request->all(), 
                $user
            );

            return response()->json([
                'success' => true,
                'message' => 'Данные пользователя обновлены',
                'data' => $result,
            ]);
        } catch (\Exception $e) {
            return (new ErrorResponse($e->getMessage(), 400))->toResponse($request);
        }
    }

    public function removeUserFromChildOrganization(Request $request, int $childOrgId, int $userId): JsonResponse
    {
        $user = Auth::user();
        $parentOrgId = $request->attributes->get('current_organization_id') ?? $user->current_organization_id;

        try {
            $this->multiOrgService->removeUserFromChildOrganization($parentOrgId, $childOrgId, $userId, $user);

            return response()->json([
                'success' => true,
                'message' => 'Пользователь исключен из дочерней организации',
            ]);
        } catch (\Exception $e) {
            return (new ErrorResponse($e->getMessage(), 400))->toResponse($request);
        }
    }

    public function getChildOrganizationStats(Request $request, int $childOrgId): JsonResponse
    {
        $user = Auth::user();
        $parentOrgId = $request->attributes->get('current_organization_id') ?? $user->current_organization_id;

        try {
            $stats = $this->multiOrgService->getChildOrganizationStats($parentOrgId, $childOrgId);

            return response()->json([
                'success' => true,
                'data' => $stats,
            ]);
        } catch (\Exception $e) {
            return (new ErrorResponse($e->getMessage(), 400))->toResponse($request);
        }
    }

    public function updateHoldingSettings(Request $request): JsonResponse
    {
        $request->validate([
            'group_id' => 'required|integer|exists:organization_groups,id',
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string|max:1000',
            'max_child_organizations' => 'sometimes|integer|min:1|max:100',
            'settings' => 'sometimes|array',
            'permissions_config' => 'sometimes|array',
        ]);

        $user = Auth::user();

        try {
            $group = $this->multiOrgService->updateHoldingSettings($request->input('group_id'), $request->all(), $user);

            return response()->json([
                'success' => true,
                'message' => 'Настройки холдинга обновлены',
                'data' => $group,
            ]);
        } catch (\Exception $e) {
            return (new ErrorResponse($e->getMessage(), 400))->toResponse($request);
        }
    }

    public function getHoldingDashboard(Request $request): JsonResponse
    {
        $user = Auth::user();
        $organizationId = $request->attributes->get('current_organization_id') ?? $user->current_organization_id;

        try {
            $dashboard = $this->multiOrgService->getHoldingDashboard($organizationId);

            return response()->json([
                'success' => true,
                'data' => $dashboard,
            ]);
        } catch (\Exception $e) {
            return (new ErrorResponse($e->getMessage(), 400))->toResponse($request);
        }
    }

    public function getRoleTemplates(Request $request): JsonResponse
    {
        try {
            $templates = $this->childUserService->getAvailableRoleTemplates();

            return response()->json([
                'success' => true,
                'data' => [
                    'templates' => $templates,
                    'permissions_groups' => $this->getPermissionsGroups(),
                ],
            ]);
        } catch (\Exception $e) {
            return (new ErrorResponse($e->getMessage(), 400))->toResponse($request);
        }
    }

    public function getChildOrganizationRoles(Request $request, int $childOrgId): JsonResponse
    {
        $user = Auth::user();
        $parentOrgId = $request->attributes->get('current_organization_id') ?? $user->current_organization_id;

        try {
            $childOrg = \App\Models\Organization::findOrFail($childOrgId);
            if ($childOrg->parent_organization_id !== $parentOrgId) {
                throw new \Exception('Организация не является дочерней для данного холдинга');
            }

            $roles = \App\Models\OrganizationRole::forOrganization($childOrgId)
                ->withCount('users')
                ->ordered()
                ->get();

            return response()->json([
                'success' => true,
                'data' => $roles->map(function ($role) {
                    return [
                        'id' => $role->id,
                        'name' => $role->name,
                        'slug' => $role->slug,
                        'description' => $role->description,
                        'color' => $role->color,
                        'permissions' => $role->permissions ?? [],
                        'permissions_count' => count($role->permissions ?? []),
                        'users_count' => $role->users_count,
                        'is_system' => $role->is_system,
                        'is_active' => $role->is_active,
                        'created_at' => $role->created_at,
                    ];
                }),
            ]);
        } catch (\Exception $e) {
            return (new ErrorResponse($e->getMessage(), 400))->toResponse($request);
        }
    }

    public function createBulkUsers(Request $request, int $childOrgId): JsonResponse
    {
        $request->validate([
            'users' => 'required|array|min:1|max:20',
            'users.*.name' => 'required|string|max:255',
            'users.*.email' => 'required|email|max:255',
            'users.*.password' => 'sometimes|string|min:8',
            'users.*.auto_verify' => 'sometimes|boolean',
            'users.*.send_invitation' => 'sometimes|boolean',
            'users.*.role_data' => 'required|array',
            'users.*.role_data.template' => 'sometimes|string|in:administrator,project_manager,foreman,accountant,sales_manager,worker,observer',
            'users.*.role_data.name' => 'required_without:users.*.role_data.template|string|max:255',
            'users.*.role_data.permissions' => 'required_without:users.*.role_data.template|array',
        ]);

        $user = Auth::user();
        $parentOrgId = $request->attributes->get('current_organization_id') ?? $user->current_organization_id;

        try {
            $childOrg = \App\Models\Organization::findOrFail($childOrgId);
            if ($childOrg->parent_organization_id !== $parentOrgId) {
                throw new \Exception('Организация не является дочерней для данного холдинга');
            }

            $results = $this->childUserService->createBulkUsers($childOrgId, $request->input('users'), $user);

            return response()->json([
                'success' => true,
                'message' => "Обработано пользователей: {$results['total']}, успешно: {$results['successful']}, ошибок: {$results['failed']}",
                'data' => $results,
            ]);
        } catch (\Exception $e) {
            return (new ErrorResponse($e->getMessage(), 400))->toResponse($request);
        }
    }

    public function getHoldingContracts(Request $request): JsonResponse
    {
        $user = Auth::user();
        $orgIds = $this->multiOrgService->getAccessibleOrganizations($user)->pluck('id')->all();

        $contracts = $this->holdingReportService->getConsolidatedContracts($orgIds, [
            'date_from' => $request->input('date_from'),
            'date_to' => $request->input('date_to'),
            'status' => $request->input('status'),
        ]);

        return response()->json([
            'success' => true,
            'data' => \App\Http\Resources\Api\V1\Landing\Report\ConsolidatedContractResource::collection($contracts),
        ]);
    }

    public function getHoldingContractsSummary(Request $request): JsonResponse
    {
        $user = Auth::user();
        $orgIds = $this->multiOrgService->getAccessibleOrganizations($user)->pluck('id')->all();

        $summary = $this->holdingReportService->getContractsSummary($orgIds, [
            'date_from' => $request->input('date_from'),
            'date_to' => $request->input('date_to'),
            'status' => $request->input('status'),
        ]);

        return response()->json([
            'success' => true,
            'data' => $summary,
        ]);
    }

    public function getHoldingActs(Request $request): JsonResponse
    {
        $user = Auth::user();
        $orgIds = $this->multiOrgService->getAccessibleOrganizations($user)->pluck('id')->all();

        $acts = $this->holdingReportService->getConsolidatedActs($orgIds, [
            'date_from' => $request->input('date_from'),
            'date_to' => $request->input('date_to'),
            'is_approved' => $request->input('is_approved'),
        ]);

        return response()->json([
            'success' => true,
            'data' => \App\Http\Resources\Api\V1\Landing\Report\ConsolidatedActResource::collection($acts),
        ]);
    }

    public function getHoldingMovements(Request $request): JsonResponse
    {
        $user = Auth::user();
        $orgIds = $this->multiOrgService->getAccessibleOrganizations($user)->pluck('id')->all();

        $movements = $this->holdingReportService->getMoneyMovements($orgIds, [
            'date_from' => $request->input('date_from'),
            'date_to' => $request->input('date_to'),
            'type' => $request->input('type'),
        ]);

        return response()->json([
            'success' => true,
            'data' => \App\Http\Resources\Billing\BalanceTransactionResource::collection($movements),
        ]);
    }

    private function getPermissionsGroups(): array
    {
        return [
            'Пользователи' => [
                'users.view' => 'Просмотр пользователей',
                'users.create' => 'Создание пользователей',
                'users.edit' => 'Редактирование пользователей',
                'users.delete' => 'Удаление пользователей',
            ],
            'Роли' => [
                'roles.view' => 'Просмотр ролей',
                'roles.create' => 'Создание ролей',
                'roles.edit' => 'Редактирование ролей',
                'roles.delete' => 'Удаление ролей',
            ],
            'Проекты' => [
                'projects.view' => 'Просмотр проектов',
                'projects.create' => 'Создание проектов',
                'projects.edit' => 'Редактирование проектов',
                'projects.delete' => 'Удаление проектов',
            ],
            'Договоры' => [
                'contracts.view' => 'Просмотр договоров',
                'contracts.create' => 'Создание договоров',
                'contracts.edit' => 'Редактирование договоров',
                'contracts.delete' => 'Удаление договоров',
            ],
            'Материалы' => [
                'materials.view' => 'Просмотр материалов',
                'materials.create' => 'Создание материалов',
                'materials.edit' => 'Редактирование материалов',
                'materials.delete' => 'Удаление материалов',
            ],
            'Отчеты' => [
                'reports.view' => 'Просмотр отчетов',
                'reports.create' => 'Создание отчетов',
                'reports.export' => 'Экспорт отчетов',
            ],
            'Финансы' => [
                'finance.view' => 'Просмотр финансов',
                'finance.edit' => 'Управление финансами',
            ],
            'Работы' => [
                'work_types.view' => 'Просмотр видов работ',
                'work_types.create' => 'Создание видов работ',
                'work_types.edit' => 'Редактирование видов работ',
                'completed_work.view' => 'Просмотр выполненных работ',
                'completed_work.create' => 'Добавление выполненных работ',
                'completed_work.edit' => 'Редактирование выполненных работ',
            ],
            'Клиенты' => [
                'clients.view' => 'Просмотр клиентов',
                'clients.create' => 'Создание клиентов',
                'clients.edit' => 'Редактирование клиентов',
            ],
            'Учет времени' => [
                'time_tracking.create' => 'Создание записей времени',
                'time_tracking.edit' => 'Редактирование записей времени',
            ],
        ];
    }
} 