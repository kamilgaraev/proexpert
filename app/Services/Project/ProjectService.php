<?php declare(strict_types=1);

namespace App\Services\Project;

use App\Repositories\Interfaces\ProjectRepositoryInterface;
use App\Repositories\Interfaces\UserRepositoryInterface;
use App\Repositories\Interfaces\MaterialRepositoryInterface;
use App\Repositories\Interfaces\WorkTypeRepositoryInterface;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectOrganization;
use App\Models\User;
use App\Models\Role;
use App\Services\Logging\LoggingService;
use App\Services\Organization\OrganizationProfileService;
use App\Services\Project\ProjectContextService;
use App\Enums\ProjectOrganizationRole;
use App\BusinessModules\Core\MultiOrganization\Contracts\OrganizationScopeInterface;
use App\Events\ProjectOrganizationAdded;
use App\Events\ProjectOrganizationRoleChanged;
use App\Events\ProjectOrganizationRemoved;
use App\Events\ProjectCreated;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Collection;
use App\Exceptions\BusinessLogicException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use App\DTOs\Project\ProjectDTO;

use function trans_message;

class ProjectService
{
    private const ALLOWED_PROJECT_SORTS = [
        'name' => 'name',
        'status' => 'status',
        'start_date' => 'start_date',
        'end_date' => 'end_date',
        'created_at' => 'created_at',
        'updated_at' => 'updated_at',
    ];

    protected ProjectRepositoryInterface $projectRepository;
    protected UserRepositoryInterface $userRepository;
    protected MaterialRepositoryInterface $materialRepository;
    protected WorkTypeRepositoryInterface $workTypeRepository;
    protected LoggingService $logging;
    protected OrganizationProfileService $organizationProfileService;
    protected ProjectContextService $projectContextService;
    protected OrganizationScopeInterface $orgScope;
    protected ProjectParticipantService $projectParticipantService;
    protected ProjectTeamService $projectTeamService;
    protected ProjectBudgetAmountService $projectBudgetAmountService;

    public function __construct(
        ProjectRepositoryInterface $projectRepository,
        UserRepositoryInterface $userRepository,
        MaterialRepositoryInterface $materialRepository,
        WorkTypeRepositoryInterface $workTypeRepository,
        LoggingService $logging,
        OrganizationProfileService $organizationProfileService,
        ProjectContextService $projectContextService,
        OrganizationScopeInterface $orgScope,
        ProjectParticipantService $projectParticipantService,
        ProjectTeamService $projectTeamService,
        ProjectBudgetAmountService $projectBudgetAmountService
    ) {
        $this->projectRepository = $projectRepository;
        $this->userRepository = $userRepository;
        $this->materialRepository = $materialRepository;
        $this->workTypeRepository = $workTypeRepository;
        $this->logging = $logging;
        $this->organizationProfileService = $organizationProfileService;
        $this->projectContextService = $projectContextService;
        $this->orgScope = $orgScope;
        $this->projectParticipantService = $projectParticipantService;
        $this->projectTeamService = $projectTeamService;
        $this->projectBudgetAmountService = $projectBudgetAmountService;
    }

    private function resolveProjectRoleFromValues(?string $roleNew, ?string $roleLegacy): ?ProjectOrganizationRole
    {
        $roleValue = $roleNew ?: $roleLegacy;

        if (!is_string($roleValue) || $roleValue === '') {
            return null;
        }

        return ProjectOrganizationRole::tryFrom($roleValue) ?? match ($roleValue) {
            'owner' => ProjectOrganizationRole::OWNER,
            'contractor' => ProjectOrganizationRole::CONTRACTOR,
            'child_contractor' => ProjectOrganizationRole::SUBCONTRACTOR,
            'observer' => ProjectOrganizationRole::OBSERVER,
            default => null,
        };
    }

    private function invalidateProjectParticipantContexts(int $projectId): void
    {
        $organizationIds = DB::table('project_organization')
            ->useWritePdo()
            ->where('project_id', $projectId)
            ->pluck('organization_id')
            ->push(Project::query()->useWritePdo()->whereKey($projectId)->value('organization_id'))
            ->filter()
            ->unique()
            ->values();

        foreach ($organizationIds as $organizationId) {
            $this->projectContextService->invalidateContext($projectId, (int) $organizationId);
        }
    }

    /**
     * Helper для получения ID организации из запроса.
     */
    protected function getCurrentOrgId(Request $request): int
    {
        /** @var User|null $user */
        $user = $request->user(); // Получаем пользователя из запроса
        $organizationId = $request->attributes->get('current_organization_id');
        if (!$organizationId && $user) {
            $organizationId = $user->current_organization_id;
        }
        
        if (!$organizationId) {
            Log::error('Failed to determine organization context', ['user_id' => $user?->id, 'request_attributes' => $request->attributes->all()]);
            throw new BusinessLogicException(trans_message('project.organization_context_missing'), 500);
        }
        return (int)$organizationId;
    }

    /**
     * Получить пагинированный список проектов для текущей организации.
     * Поддерживает фильтрацию и сортировку.
     */
    public function getProjectsForCurrentOrg(Request $request, int $perPage = 15): LengthAwarePaginator
    {
        $organizationId = $this->getCurrentOrgId($request);
        
        // Собираем фильтры из запроса
        $filters = [
            'name' => $request->query('name'),
            'status' => $request->query('status'),
            'is_archived' => $request->query('is_archived'), // Принимаем 'true', 'false', '1', '0' или null
        ];
        // Обрабатываем is_archived, чтобы можно было передавать булевы значения
        if (isset($filters['is_archived'])) {
            $filters['is_archived'] = filter_var($filters['is_archived'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        } else {
             unset($filters['is_archived']); // Удаляем, если не передан
        }
        $filters = array_filter($filters, fn($value) => !is_null($value) && $value !== '');

        // Параметры сортировки
        $sortBy = $request->query('sort_by', 'created_at');
        $sortDirection = $request->query('sort_direction', 'desc');

        $sortBy = is_string($sortBy) ? strtolower($sortBy) : 'created_at';
        $sortDirection = is_string($sortDirection) ? strtolower($sortDirection) : 'desc';

        $sortBy = self::ALLOWED_PROJECT_SORTS[$sortBy] ?? 'created_at';
        $sortDirection = in_array($sortDirection, ['asc', 'desc'], true) ? $sortDirection : 'desc';

        return $this->projectRepository->getProjectsForOrganizationPaginated(
            $organizationId,
            $perPage,
            $filters,
            $sortBy,
            $sortDirection
        );
    }

    /**
     * Создать новый проект.
     *
     * @param ProjectDTO $projectDTO
     * @param Request $request // Для получения organization_id
     * @return Project
     * @throws BusinessLogicException
     */
    public function createProject(ProjectDTO $projectDTO, Request $request): Project
    {
        $organizationId = $this->getCurrentOrgId($request);
        $user = $request->user();
        
        // BUSINESS: Начало создания проекта - ключевая бизнес-метрика
        $this->logging->business('project.creation.started', [
            'project_name' => $projectDTO->name,
            'project_description' => $projectDTO->description ?? null,
            'organization_id' => $organizationId,
            'created_by_user_id' => $user?->id,
            'created_by_email' => $user?->email,
            'project_address' => $projectDTO->address ?? null
        ]);
        
        $dataToCreate = $this->withGeocodingState($projectDTO->toArray());
        $dataToCreate = $this->projectBudgetAmountService->applyProjectPlannedCost(
            $dataToCreate,
            $projectDTO->budget_amount,
            $this->resolveProjectBudgetAmountSource($dataToCreate)
        );
        $dataToCreate['organization_id'] = $organizationId;
        $dataToCreate['is_head'] = true;
        
        $project = $this->projectRepository->create($dataToCreate);
        
        event(new ProjectCreated($project));
        
        // AUDIT: Создание проекта - важно для compliance и отслеживания изменений
        $this->logging->audit('project.created', [
            'project_id' => $project->id,
            'project_name' => $project->name,
            'project_description' => $project->description,
            'organization_id' => $organizationId,
            'created_by' => $user?->id,
            'created_by_email' => $user?->email,
            'is_head_project' => true,
            'creation_date' => $project->created_at?->toISOString()
        ]);
        
        // BUSINESS: Успешное создание проекта - ключевая метрика роста
        $this->logging->business('project.created', [
            'project_id' => $project->id,
            'project_name' => $project->name,
            'organization_id' => $organizationId,
            'created_by' => $user?->id,
            'timestamp' => now()->toISOString()
        ]);
        
        return $project;
    }

    public function findProjectByIdForCurrentOrg(int $id, Request $request): ?Project
    {
        $organizationId = $this->getCurrentOrgId($request);
        $project = Project::query()
            ->useWritePdo()
            ->find($id);

        if (!$project) {
            return null;
        }

        $belongsToOrg = $project->organization_id === $organizationId
            || ProjectOrganization::query()
                ->useWritePdo()
                ->where('project_id', $project->id)
                ->where('organization_id', $organizationId)
                ->where('is_active', true)
                ->exists();

        return $belongsToOrg ? $project : null;
    }

    /**
     * Обновить существующий проект.
     *
     * @param int $id ID проекта
     * @param ProjectDTO $projectDTO
     * @param Request $request // Для проверки организации
     * @return Project|null
     * @throws BusinessLogicException
     */
    public function updateProject(int $id, ProjectDTO $projectDTO, Request $request): ?Project
    {
        $project = $this->findProjectByIdForCurrentOrg($id, $request);
        if (!$project) {
            throw new BusinessLogicException(trans_message('project.not_found_or_access_denied'), 404);
        }

        $dataToUpdate = $this->withGeocodingState($projectDTO->toArray());

        if ($this->projectBudgetAmountService->amountsDiffer($project->budget_amount, $projectDTO->budget_amount)) {
            $dataToUpdate = $this->projectBudgetAmountService->applyProjectPlannedCost(
                $dataToUpdate,
                $projectDTO->budget_amount,
                'manual'
            );
        } else {
            $dataToUpdate = $this->projectBudgetAmountService->preserveProjectPlannedCostContext(
                $dataToUpdate,
                $project->additional_info,
                'manual'
            );
        }

        $updated = $this->projectRepository->update($id, $dataToUpdate);
        return $updated ? $this->projectRepository->find($id) : null;
    }

    private function resolveProjectBudgetAmountSource(array $payload): string
    {
        $additionalInfo = $payload['additional_info'] ?? [];

        if (is_array($additionalInfo) && ($additionalInfo['source'] ?? null) === 'crm_conversion') {
            return 'crm_conversion';
        }

        return 'manual';
    }

    private function withGeocodingState(array $data): array
    {
        if (($data['latitude'] ?? null) !== null && ($data['longitude'] ?? null) !== null) {
            $data['geocoded_at'] = now();
            $data['geocoding_status'] = 'geocoded';
        }

        return $data;
    }

    public function deleteProject(int $id, Request $request): bool
    {
        $project = $this->findProjectByIdForCurrentOrg($id, $request);
        if (!$project) {
            throw new BusinessLogicException(trans_message('project.not_found_in_organization'), 404);
        }
        
        $user = $request->user();
        $organizationId = $this->getCurrentOrgId($request);
        
        // SECURITY: Попытка удаления проекта - важное security событие
        $this->logging->security('project.deletion.attempt', [
            'project_id' => $project->id,
            'project_name' => $project->name,
            'organization_id' => $organizationId,
            'requested_by' => $user?->id,
            'requested_by_email' => $user?->email
        ]);
        
        // Сохраняем данные проекта для логирования до удаления
        $projectData = [
            'project_id' => $project->id,
            'project_name' => $project->name,
            'project_description' => $project->description,
            'project_address' => $project->address,
            'organization_id' => $organizationId,
            'was_head_project' => $project->is_head,
            'created_at' => $project->created_at?->toISOString()
        ];
        
        $result = $this->projectRepository->delete($id);
        
        if ($result) {
            // AUDIT: Успешное удаление проекта - критически важно для compliance
            $this->logging->audit('project.deleted', array_merge($projectData, [
                'deleted_by' => $user?->id,
                'deleted_by_email' => $user?->email,
                'deleted_at' => now()->toISOString()
            ]));
            
            // BUSINESS: Удаление проекта - важная бизнес-метрика (может указывать на проблемы)
            $this->logging->business('project.deleted', [
                'project_id' => $projectData['project_id'],
                'project_name' => $projectData['project_name'],
                'organization_id' => $organizationId,
                'deleted_by' => $user?->id,
                'project_lifetime_days' => $project->created_at ? $project->created_at->diffInDays(now()) : null
            ]);
        } else {
            // TECHNICAL: Неудачное удаление проекта
            $this->logging->technical('project.deletion.failed', [
                'project_id' => $project->id,
                'project_name' => $project->name,
                'organization_id' => $organizationId,
                'attempted_by' => $user?->id,
                'error' => 'Repository delete returned false'
            ], 'error');
        }
        
        return $result;
    }

    public function assignForemanToProject(int $projectId, int $userId, Request $request): bool
    {
        $organizationId = $this->getCurrentOrgId($request);
        
        $project = $this->findProjectByIdForCurrentOrg($projectId, $request);
        if (!$project) {
            throw new BusinessLogicException(trans_message('project.not_found_in_organization'), 404);
        }

        $user = $this->userRepository->find($userId);
        
        if (!$user 
            || !$user->is_active 
            || !$user->organizations()
                ->where('organization_user.organization_id', $organizationId)
                ->where('organization_user.is_active', true)
                ->exists()
           ) { 
            throw new BusinessLogicException(trans_message('project.team_member_not_found'), 404);
        }

        try {
            $actor = $request->user();
            if (!$actor) {
                throw new BusinessLogicException(trans_message('project.unauthorized'), 401);
            }

            $this->projectTeamService->assignMember($project, $user, $actor, $organizationId);

            return true;
        } catch (\Illuminate\Database\QueryException $e) {
            if ($e->getCode() == 23505) {
                Log::warning('Attempted to assign already assigned project team member', ['project_id' => $projectId, 'user_id' => $userId]);
                return true; 
            }
            Log::error('Database error assigning project team member', ['project_id' => $projectId, 'user_id' => $userId, 'exception' => $e]);
            throw new BusinessLogicException(trans_message('project.team_member_assign_error'), 500, $e);
        }
    }

    public function detachForemanFromProject(int $projectId, int $userId, Request $request): bool
    {
        $organizationId = $this->getCurrentOrgId($request);
        
        $project = $this->findProjectByIdForCurrentOrg($projectId, $request);
        if (!$project) {
            throw new BusinessLogicException(trans_message('project.not_found_in_organization'), 404);
        }

        $user = $this->userRepository->find($userId);

        if (!$user) {
            throw new BusinessLogicException(trans_message('project.team_member_not_found'), 404);
        }

        return $this->projectTeamService->detachMember($project, $user, $organizationId);
    }

    /**
     * Получить все проекты для текущей организации (без пагинации).
     * @deprecated Используйте getProjectsForCurrentOrg с пагинацией.
     */
    public function getAllProjectsForCurrentOrg(Request $request): Collection 
    { 
        $organizationId = $this->getCurrentOrgId($request); 
        // Метод getProjectsForOrganization должен возвращать пагинатор,
        // если нужна коллекция, нужен другой метод репозитория или ->get()
        // Возвращаем пустую коллекцию или выбрасываем исключение, т.к. метод неясен
        Log::warning('Deprecated method getAllProjectsForCurrentOrg called.');
        // return $this->projectRepository->getProjectsForOrganization($organizationId, -1)->items(); // Пример обхода пагинации
        return new Collection(); // Возвращаем пустую коллекцию
    }

    /**
     * Получить активные проекты для текущей организации.
     */
    public function getActiveProjectsForCurrentOrg(Request $request): Collection
    {
        $organizationId = $this->getCurrentOrgId($request);
        return $this->projectRepository->getActiveProjects($organizationId);
    }

    /**
     * Получить проекты, назначенные пользователю в текущей организации.
     */
    public function getProjectsForUser(Request $request): Collection
    {
        $user = $request->user();
        if (!$user) {
             throw new BusinessLogicException(trans_message('project.user_not_authenticated'), 401);
        }
        $userId = $user->id;
        $organizationId = $this->getCurrentOrgId($request);
        return $this->projectRepository->getProjectsForUser($userId, $organizationId);
    }

    /**
     * Получить детали проекта по ID (с отношениями).
     * Проверяет принадлежность проекта текущей организации.
     */
    public function getProjectDetails(int $id, Request $request): ?Project
    { 
        $project = $this->findProjectByIdForCurrentOrg($id, $request); // Используем уже существующий метод
        if (!$project) {
             return null;
        }
        // Загружаем нужные связи
        return $project->load(['materials', 'workTypes', 'users']); 
    }
    
    public function getProjectStatistics(int $id): array
    {
        $project = $this->projectRepository->find($id);
        if (!$project) {
            throw new BusinessLogicException(trans_message('project.not_found'), 404);
        }

        try {
            // ===== ИСТОЧНИК ИСТИНЫ: СКЛАД (warehouse_balances + warehouse_movements) =====
            // Статистика по материалам - берем из движений склада, связанных с проектом
            $materialStats = DB::table('warehouse_movements as wm')
                ->join('warehouse_balances as wb', function($join) {
                    $join->on('wm.warehouse_id', '=', 'wb.warehouse_id')
                         ->on('wm.material_id', '=', 'wb.material_id')
                         ->on('wm.organization_id', '=', 'wb.organization_id');
                })
                ->where('wm.project_id', $id)
                ->selectRaw("
                    COUNT(DISTINCT wm.material_id) as unique_materials_count,
                    SUM(CASE WHEN wm.movement_type = 'receipt' THEN wm.quantity ELSE 0 END) as total_received,
                    SUM(CASE WHEN wm.movement_type = 'write_off' THEN wm.quantity ELSE 0 END) as total_used,
                    SUM(CASE WHEN wm.movement_type = 'receipt' THEN (wm.quantity * wm.price) ELSE 0 END) as total_received_value,
                    SUM(CASE WHEN wm.movement_type = 'write_off' THEN (wm.quantity * wm.price) ELSE 0 END) as total_used_value
                ")
                ->first();
            
            // Если нет движений по проекту, проверяем распределения (но без финансовых данных)
            if (!$materialStats || $materialStats->unique_materials_count == 0) {
                $allocationStats = DB::table('warehouse_project_allocations as wpa')
                    ->join('warehouse_balances as wb', function($join) {
                        $join->on('wpa.warehouse_id', '=', 'wb.warehouse_id')
                             ->on('wpa.material_id', '=', 'wb.material_id')
                             ->on('wpa.organization_id', '=', 'wb.organization_id');
                    })
                    ->where('wpa.project_id', $id)
                    ->selectRaw("
                        COUNT(DISTINCT wpa.material_id) as unique_materials_count,
                        SUM(wpa.allocated_quantity) as total_allocated,
                        SUM(wpa.allocated_quantity * wb.unit_price) as allocated_value
                    ")
                    ->first();
                
                // Используем данные распределений, если есть
                if ($allocationStats && $allocationStats->unique_materials_count > 0) {
                    $materialStats = (object)[
                        'unique_materials_count' => $allocationStats->unique_materials_count,
                        'total_received' => 0,
                        'total_used' => 0,
                        'total_received_value' => 0,
                        'total_used_value' => 0,
                    ];
                }
            }

            // Статистика по выполненным работам
            $workStats = DB::table('completed_works as cw')
                ->where('cw.project_id', $id)
                ->selectRaw("\n                    COUNT(*) as total_works_count,\n                    SUM(cw.quantity) as total_work_quantity,\n                    COUNT(DISTINCT cw.work_type_id) as unique_work_types_count,\n                    SUM(cw.total_amount) as total_work_cost\n                ")
                ->first();

            // Команда проекта
            $teamMembers = DB::table('project_user as pu')
                ->join('users as u', 'u.id', '=', 'pu.user_id')
                ->where('pu.project_id', $id)
                ->select(['u.id', 'u.name', 'pu.role'])
                ->get();

            $userStats = (object) ['assigned_users_count' => $teamMembers->count()];

            // Акты выполненных работ по проекту
            // Фильтруем напрямую по project_id для корректной работы с мультипроектными контрактами
            $acts = DB::table('contract_performance_acts as a')
                ->join('contracts as c', 'c.id', '=', 'a.contract_id')
                ->where('a.project_id', $id)
                ->select(['a.id', 'a.contract_id', 'a.act_document_number', 'a.act_date', 'a.amount', 'a.is_approved'])
                ->orderBy('a.act_date', 'desc')
                ->get();

            // Последние операции - ИСТОЧНИК ИСТИНЫ: СКЛАД
            $lastMaterialOperation = DB::table('warehouse_movements')
                ->where('project_id', $id)
                ->whereIn('movement_type', ['receipt', 'write_off'])
                ->orderBy('movement_date', 'desc')
                ->first(['movement_date', 'movement_type']);

            $lastWorkCompletion = DB::table('completed_works')
                ->where('project_id', $id)
                ->orderBy('completion_date', 'desc')
                ->first(['completion_date']);

            return [
                'project_id' => $id,
                'project_name' => $project->name,
                'materials' => [
                    'unique_materials_count' => $materialStats->unique_materials_count ?? 0,
                    'total_received' => $materialStats->total_received ?? 0,
                    'total_used' => $materialStats->total_used ?? 0,
                    'current_balance' => ($materialStats->total_received ?? 0) - ($materialStats->total_used ?? 0),
                    'total_received_value' => $materialStats->total_received_value ?? 0,
                    'total_used_value' => $materialStats->total_used_value ?? 0,
                    'last_operation_date' => $lastMaterialOperation->movement_date ?? null,
                    'last_operation_type' => $lastMaterialOperation->movement_type ?? null
                ],
                'works' => [
                    'total_works_count' => $workStats->total_works_count ?? 0,
                    'total_work_quantity' => $workStats->total_work_quantity ?? 0,
                    'unique_work_types_count' => $workStats->unique_work_types_count ?? 0,
                    'total_work_cost' => $workStats->total_work_cost ?? 0,
                    'last_completion_date' => $lastWorkCompletion->completion_date ?? null
                ],
                'team' => [
                    'assigned_users_count' => $userStats->assigned_users_count ?? 0,
                    'members' => $teamMembers,
                ],
                'performance_acts' => $acts,
                'project_info' => [
                    'start_date' => $project->start_date,
                    'end_date' => $project->end_date,
                    'status' => $project->status,
                    'created_at' => $project->created_at,
                    'updated_at' => $project->updated_at
                ]
            ];
        } catch (\Exception $e) {
            Log::error('Error getting project statistics', [
                'project_id' => $id,
                'error' => $e->getMessage()
            ]);
            throw new BusinessLogicException(trans_message('project.statistics_fetch_error'), 500);
        }
    }

    public function getProjectMaterials(int $id, int $perPage = 15, ?string $search = null, string $sortBy = 'allocated_quantity', string $sortDirection = 'desc'): array
    {
        $project = $this->projectRepository->find($id);
        if (!$project) {
            throw new BusinessLogicException(trans_message('project.not_found'), 404);
        }

        try {
            // СКЛАДСКАЯ СИСТЕМА: показываем материалы, распределенные на проект + доступный остаток на складах
            $query = DB::table('warehouse_project_allocations as wpa')
                ->join('materials as m', 'wpa.material_id', '=', 'm.id')
                ->join('organization_warehouses as w', 'wpa.warehouse_id', '=', 'w.id')
                ->leftJoin('measurement_units as mu', 'm.measurement_unit_id', '=', 'mu.id')
                ->leftJoin('users as u', 'wpa.allocated_by_user_id', '=', 'u.id')
                // Подтягиваем общий остаток материала на всех складах организации
                ->leftJoin(DB::raw('(
                    SELECT 
                        wb.material_id,
                        wb.organization_id,
                        SUM(wb.available_quantity) as total_warehouse_available,
                        SUM(wb.available_quantity * wb.unit_price) as total_val
                    FROM warehouse_balances wb
                    JOIN organization_warehouses ow ON wb.warehouse_id = ow.id
                    WHERE ow.is_active = true
                    GROUP BY wb.material_id, wb.organization_id
                ) as warehouse_totals'), function($join) {
                    $join->on('wpa.material_id', '=', 'warehouse_totals.material_id')
                         ->on('wpa.organization_id', '=', 'warehouse_totals.organization_id');
                })
                ->where('wpa.project_id', $id)
                ->select([
                    'wpa.id as allocation_id',
                    'm.id as material_id',
                    'm.name as material_name',
                    'm.code as material_code',
                    'mu.short_name as unit',
                    'w.name as warehouse_name',
                    'w.id as warehouse_id',
                    'wpa.allocated_quantity as allocated_quantity',
                    DB::raw('COALESCE(warehouse_totals.total_warehouse_available, 0) as warehouse_available_total'),
                    DB::raw('CASE WHEN COALESCE(warehouse_totals.total_warehouse_available, 0) > 0 THEN warehouse_totals.total_val / warehouse_totals.total_warehouse_available ELSE COALESCE(m.default_price, 0) END as average_price'),
                    'wpa.allocated_at as last_operation_date',
                    'u.name as allocated_by',
                    'wpa.notes'
                ]);

            if ($search) {
                $query->where(function($q) use ($search) {
                    $q->where('m.name', 'like', "%{$search}%")
                      ->orWhere('m.code', 'like', "%{$search}%")
                      ->orWhere('w.name', 'like', "%{$search}%");
                });
            }

            $allowedSortBy = ['material_name', 'material_code', 'warehouse_name', 'allocated_quantity', 'warehouse_available_total', 'last_operation_date'];
            if (!in_array($sortBy, $allowedSortBy)) {
                $sortBy = 'last_operation_date';
            }

            if (!in_array(strtolower($sortDirection), ['asc', 'desc'])) {
                $sortDirection = 'desc';
            }

            $query->orderBy($sortBy, $sortDirection);

            $paginatedResults = $query->paginate($perPage);

            return [
                'data' => collect($paginatedResults->items())->map(function($item) {
                    $warehouseAvailable = (float)$item->warehouse_available_total;
                    $allocated = (float)$item->allocated_quantity;
                    
                    // КРИТИЧНО: Проверяем валидность данных
                    // Если материал распределен, но его НЕТ на складе - это некорректные данные!
                    $isValid = $warehouseAvailable > 0;
                    $hasWarning = !$isValid && $allocated > 0;
                    
                    return [
                        'allocation_id' => $item->allocation_id,
                        'material_id' => $item->material_id,
                        'material_name' => $item->material_name,
                        'material_code' => $item->material_code,
                        'unit' => $item->unit,
                        'warehouse_name' => $item->warehouse_name,
                        'warehouse_id' => $item->warehouse_id,
                        'allocated_quantity' => $allocated, // Распределено на проект
                        'warehouse_available_total' => $warehouseAvailable, // Доступно на всех складах
                        'average_price' => (float)$item->average_price,
                        'allocated_value' => $allocated * (float)$item->average_price,
                        'last_operation_date' => $item->last_operation_date,
                        'allocated_by' => $item->allocated_by,
                        'notes' => $item->notes,
                        // Флаги валидности данных
                        'is_valid' => $isValid,
                        'has_warning' => $hasWarning,
                        'warning_message' => $hasWarning ? trans_message('project.material_missing_warehouse_warning') : null,
                    ];
                }),
                'links' => [
                    'first' => $paginatedResults->url(1),
                    'last' => $paginatedResults->url($paginatedResults->lastPage()),
                    'prev' => $paginatedResults->previousPageUrl(),
                    'next' => $paginatedResults->nextPageUrl()
                ],
                'meta' => [
                    'current_page' => $paginatedResults->currentPage(),
                    'last_page' => $paginatedResults->lastPage(),
                    'per_page' => $paginatedResults->perPage(),
                    'total' => $paginatedResults->total(),
                    'from' => $paginatedResults->firstItem(),
                    'to' => $paginatedResults->lastItem()
                ]
            ];
        } catch (\Exception $e) {
            Log::error('Error getting project materials', [
                'project_id' => $id,
                'error' => $e->getMessage()
            ]);
            throw new BusinessLogicException(trans_message('project.materials_fetch_error'), 500);
        }
    }

    public function getProjectWorkTypes(int $id, int $perPage = 15, ?string $search = null, string $sortBy = 'created_at', string $sortDirection = 'desc'): array
    {
        $project = $this->projectRepository->find($id);
        if (!$project) {
            throw new BusinessLogicException(trans_message('project.not_found'), 404);
        }

        try {
            $withoutWorkType = str_replace("'", "''", trans_message('project.without_work_type'));
            $completedWorkTypeId = 'COALESCE(cw.work_type_id, ei.work_type_id)';
            $completedGroupKey = "CASE WHEN {$completedWorkTypeId} IS NOT NULL THEN 'work_type:' || CAST({$completedWorkTypeId} AS TEXT) WHEN cw.estimate_item_id IS NOT NULL THEN 'estimate_item:' || CAST(cw.estimate_item_id AS TEXT) WHEN cw.schedule_task_id IS NOT NULL THEN 'schedule_task:' || CAST(cw.schedule_task_id AS TEXT) ELSE 'untyped' END";
            $completedDisplayId = "CASE WHEN {$completedWorkTypeId} IS NOT NULL THEN {$completedWorkTypeId} WHEN cw.estimate_item_id IS NOT NULL THEN -cw.estimate_item_id WHEN cw.schedule_task_id IS NOT NULL THEN (-1000000000 - cw.schedule_task_id) ELSE -2000000000 END";
            $completedName = "COALESCE(cw_wt.name, ei_wt.name, ei.name, st.name, '{$withoutWorkType}')";
            $completedUnit = 'COALESCE(cw_mu.short_name, ei_mu.short_name, st_mu.short_name)';

            $completedAggregates = DB::table('completed_works as cw')
                ->leftJoin('work_types as cw_wt', 'cw_wt.id', '=', 'cw.work_type_id')
                ->leftJoin('measurement_units as cw_mu', 'cw_mu.id', '=', 'cw_wt.measurement_unit_id')
                ->leftJoin('estimate_items as ei', 'ei.id', '=', 'cw.estimate_item_id')
                ->leftJoin('work_types as ei_wt', 'ei_wt.id', '=', 'ei.work_type_id')
                ->leftJoin('measurement_units as ei_mu', 'ei_mu.id', '=', 'ei.measurement_unit_id')
                ->leftJoin('schedule_tasks as st', 'st.id', '=', 'cw.schedule_task_id')
                ->leftJoin('measurement_units as st_mu', 'st_mu.id', '=', 'st.measurement_unit_id')
                ->where('cw.project_id', $id)
                ->whereNull('cw.deleted_at')
                ->selectRaw("{$completedGroupKey} as group_key")
                ->selectRaw("{$completedDisplayId} as work_type_id")
                ->selectRaw("{$completedName} as work_type_name")
                ->selectRaw("{$completedUnit} as unit")
                ->selectRaw('0 as planned_quantity')
                ->selectRaw('SUM(COALESCE(cw.completed_quantity, cw.quantity, 0)) as completed_quantity')
                ->selectRaw('COUNT(cw.id) as works_count')
                ->selectRaw('SUM(COALESCE(cw.total_amount, 0)) as total_cost')
                ->selectRaw('SUM(COALESCE(cw.price, 0)) as price_sum')
                ->selectRaw('COUNT(cw.price) as price_count')
                ->selectRaw('MAX(cw.completion_date) as last_completion_date')
                ->selectRaw('COUNT(DISTINCT cw.user_id) as workers_count')
                ->groupByRaw("{$completedGroupKey}, {$completedDisplayId}, {$completedName}, {$completedUnit}");

            $scheduleWorkTypeId = 'COALESCE(st.work_type_id, ei.work_type_id)';
            $scheduleGroupKey = "CASE WHEN {$scheduleWorkTypeId} IS NOT NULL THEN 'work_type:' || CAST({$scheduleWorkTypeId} AS TEXT) WHEN st.estimate_item_id IS NOT NULL THEN 'estimate_item:' || CAST(st.estimate_item_id AS TEXT) ELSE 'schedule_task:' || CAST(st.id AS TEXT) END";
            $scheduleDisplayId = "CASE WHEN {$scheduleWorkTypeId} IS NOT NULL THEN {$scheduleWorkTypeId} WHEN st.estimate_item_id IS NOT NULL THEN -st.estimate_item_id ELSE (-1000000000 - st.id) END";
            $scheduleName = "COALESCE(st_wt.name, ei_wt.name, ei.name, st.name, '{$withoutWorkType}')";
            $scheduleUnit = 'COALESCE(st_mu.short_name, ei_mu.short_name)';

            $plannedAggregates = DB::table('schedule_tasks as st')
                ->join('project_schedules as ps', 'st.schedule_id', '=', 'ps.id')
                ->leftJoin('work_types as st_wt', 'st_wt.id', '=', 'st.work_type_id')
                ->leftJoin('measurement_units as st_mu', 'st_mu.id', '=', 'st.measurement_unit_id')
                ->leftJoin('estimate_items as ei', 'ei.id', '=', 'st.estimate_item_id')
                ->leftJoin('work_types as ei_wt', 'ei_wt.id', '=', 'ei.work_type_id')
                ->leftJoin('measurement_units as ei_mu', 'ei_mu.id', '=', 'ei.measurement_unit_id')
                ->where('ps.project_id', $id)
                ->whereNull('st.deleted_at')
                ->whereNull('ps.deleted_at')
                ->selectRaw("{$scheduleGroupKey} as group_key")
                ->selectRaw("{$scheduleDisplayId} as work_type_id")
                ->selectRaw("{$scheduleName} as work_type_name")
                ->selectRaw("{$scheduleUnit} as unit")
                ->selectRaw('SUM(COALESCE(st.quantity, ei.quantity_total, ei.quantity, 0)) as planned_quantity')
                ->selectRaw('0 as completed_quantity')
                ->selectRaw('0 as works_count')
                ->selectRaw('0 as total_cost')
                ->selectRaw('0 as price_sum')
                ->selectRaw('0 as price_count')
                ->selectRaw('NULL as last_completion_date')
                ->selectRaw('0 as workers_count')
                ->groupByRaw("{$scheduleGroupKey}, {$scheduleDisplayId}, {$scheduleName}, {$scheduleUnit}");

            $estimateWorkTypeId = 'ei.work_type_id';
            $estimateGroupKey = "CASE WHEN {$estimateWorkTypeId} IS NOT NULL THEN 'work_type:' || CAST({$estimateWorkTypeId} AS TEXT) ELSE 'estimate_item:' || CAST(ei.id AS TEXT) END";
            $estimateDisplayId = "CASE WHEN {$estimateWorkTypeId} IS NOT NULL THEN {$estimateWorkTypeId} ELSE -ei.id END";
            $estimateName = "COALESCE(ei_wt.name, ei.name, '{$withoutWorkType}')";
            $estimateUnit = 'ei_mu.short_name';

            $estimatePlanAggregates = DB::table('estimate_items as ei')
                ->join('estimates as e', 'e.id', '=', 'ei.estimate_id')
                ->leftJoin('work_types as ei_wt', 'ei_wt.id', '=', 'ei.work_type_id')
                ->leftJoin('measurement_units as ei_mu', 'ei_mu.id', '=', 'ei.measurement_unit_id')
                ->where('e.project_id', $id)
                ->whereNull('ei.deleted_at')
                ->whereNull('e.deleted_at')
                ->whereExists(function ($query) use ($id): void {
                    $query
                        ->select(DB::raw(1))
                        ->from('completed_works as cw_plan')
                        ->whereColumn('cw_plan.estimate_item_id', 'ei.id')
                        ->where('cw_plan.project_id', $id)
                        ->whereNull('cw_plan.deleted_at');
                })
                ->whereNotExists(function ($query) use ($id): void {
                    $query
                        ->select(DB::raw(1))
                        ->from('schedule_tasks as st_plan')
                        ->join('project_schedules as ps_plan', 'ps_plan.id', '=', 'st_plan.schedule_id')
                        ->whereColumn('st_plan.estimate_item_id', 'ei.id')
                        ->where('ps_plan.project_id', $id)
                        ->whereNull('st_plan.deleted_at')
                        ->whereNull('ps_plan.deleted_at');
                })
                ->selectRaw("{$estimateGroupKey} as group_key")
                ->selectRaw("{$estimateDisplayId} as work_type_id")
                ->selectRaw("{$estimateName} as work_type_name")
                ->selectRaw("{$estimateUnit} as unit")
                ->selectRaw('SUM(COALESCE(ei.quantity_total, ei.quantity, 0)) as planned_quantity')
                ->selectRaw('0 as completed_quantity')
                ->selectRaw('0 as works_count')
                ->selectRaw('0 as total_cost')
                ->selectRaw('0 as price_sum')
                ->selectRaw('0 as price_count')
                ->selectRaw('NULL as last_completion_date')
                ->selectRaw('0 as workers_count')
                ->groupByRaw("{$estimateGroupKey}, {$estimateDisplayId}, {$estimateName}, {$estimateUnit}");

            $summaryRows = $completedAggregates
                ->unionAll($plannedAggregates)
                ->unionAll($estimatePlanAggregates);

            $summaryAggregates = DB::query()
                ->fromSub($summaryRows, 'work_summary')
                ->selectRaw('group_key')
                ->selectRaw('MAX(work_type_id) as work_type_id')
                ->selectRaw('MAX(work_type_name) as work_type_name')
                ->selectRaw('MAX(unit) as unit')
                ->selectRaw('SUM(planned_quantity) as planned_quantity')
                ->selectRaw('SUM(completed_quantity) as completed_quantity')
                ->selectRaw('SUM(works_count) as works_count')
                ->selectRaw('SUM(total_cost) as total_cost')
                ->selectRaw('SUM(price_sum) as price_sum')
                ->selectRaw('SUM(price_count) as price_count')
                ->selectRaw('MAX(last_completion_date) as last_completion_date')
                ->selectRaw('SUM(workers_count) as workers_count')
                ->groupBy('group_key');

            $query = DB::query()
                ->fromSub($summaryAggregates, 'summary')
                ->select([
                    'work_type_id',
                    'work_type_name',
                    DB::raw('NULL as work_type_description'),
                    'unit',
                    DB::raw('COALESCE(planned_quantity, 0) as planned_quantity'),
                    DB::raw('COALESCE(completed_quantity, 0) as completed_quantity'),
                    DB::raw('COALESCE(completed_quantity, 0) as actual_quantity'),
                    DB::raw('COALESCE(completed_quantity, 0) as total_quantity'),
                    DB::raw('CASE WHEN COALESCE(planned_quantity, 0) > 0 THEN ROUND((COALESCE(completed_quantity, 0) * 1.0 / COALESCE(planned_quantity, 0)) * 100, 2) ELSE 0 END as completion_percentage'),
                    DB::raw('COALESCE(works_count, 0) as works_count'),
                    DB::raw('COALESCE(total_cost, 0) as total_cost'),
                    DB::raw('CASE WHEN COALESCE(price_count, 0) > 0 THEN ROUND(COALESCE(price_sum, 0) * 1.0 / price_count, 2) ELSE 0 END as average_unit_price'),
                    'last_completion_date',
                    DB::raw('COALESCE(workers_count, 0) as workers_count'),
                ]);

            if ($search) {
                $query->where(function($q) use ($search) {
                    $q->where('work_type_name', 'like', "%{$search}%");
                });
            }

            $allowedSortBy = ['work_type_name', 'works_count', 'planned_quantity', 'completed_quantity', 'total_quantity', 'total_cost', 'last_completion_date'];
            if (!in_array($sortBy, $allowedSortBy)) {
                $sortBy = 'last_completion_date';
            }

            if (!in_array(strtolower($sortDirection), ['asc', 'desc'])) {
                $sortDirection = 'desc';
            }

            $query->orderBy($sortBy, $sortDirection);

            $paginatedResults = $query->paginate($perPage);

            return [
                'data' => $paginatedResults->items(),
                'links' => [
                    'first' => $paginatedResults->url(1),
                    'last' => $paginatedResults->url($paginatedResults->lastPage()),
                    'prev' => $paginatedResults->previousPageUrl(),
                    'next' => $paginatedResults->nextPageUrl()
                ],
                'meta' => [
                    'current_page' => $paginatedResults->currentPage(),
                    'last_page' => $paginatedResults->lastPage(),
                    'per_page' => $paginatedResults->perPage(),
                    'total' => $paginatedResults->total(),
                    'from' => $paginatedResults->firstItem(),
                    'to' => $paginatedResults->lastItem()
                ]
            ];
        } catch (\Exception $e) {
            Log::error('Error getting project work types', [
                'project_id' => $id,
                'error' => $e->getMessage()
            ]);
            throw new BusinessLogicException(trans_message('project.work_types_fetch_error'), 500);
        }
    }

    public function getProjectDashboard(int $projectId, Request $request): array
    {
        $project = $this->findProjectByIdForCurrentOrg($projectId, $request);
        if (!$project) {
            throw new BusinessLogicException(trans_message('project.not_found_or_access_denied'), 404);
        }

        try {
            $statistics = $this->getProjectStatistics($projectId);
            $workTypes = collect($this->getProjectWorkTypes($projectId, 8, null, 'total_cost', 'desc')['data'] ?? [])
                ->map(fn (mixed $item): array => $this->normalizeProjectDashboardWorkStage($item))
                ->values()
                ->all();
            $materials = $this->buildProjectDashboardMaterialSlices($project);
            $schedule = $this->buildProjectDashboardSchedule($projectId);
            $payments = $this->buildProjectDashboardPayments($projectId);
            $overview = $this->buildProjectDashboardOverview($project, $statistics, $workTypes, $schedule, $payments);

            return [
                'project_id' => $projectId,
                'generated_at' => now()->toISOString(),
                'overview' => $overview,
                'finance' => [
                    'total_budget' => $overview['total_budget'],
                    'spent_budget' => $overview['spent_budget'],
                    'remaining_budget' => $overview['remaining_budget'],
                    'budget_usage_percentage' => $overview['budget_usage_percentage'],
                    'cost_breakdown' => [
                        'works' => $this->toFloat($statistics['works']['total_work_cost'] ?? 0),
                        'materials' => $this->toFloat($statistics['materials']['total_used_value'] ?? 0),
                        'paid' => $payments['paid_amount'],
                        'outgoing_due' => $payments['outgoing_remaining_amount'],
                    ],
                ],
                'payments' => $payments,
                'schedule' => $schedule,
                'work_stages' => $workTypes,
                'material_slices' => $materials,
                'risks' => $this->buildProjectDashboardRisks($overview, $schedule, $payments, $materials),
            ];
        } catch (BusinessLogicException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('Error getting project dashboard', [
                'project_id' => $projectId,
                'error' => $e->getMessage(),
            ]);
            throw new BusinessLogicException(trans_message('project.dashboard_fetch_error'), 500);
        }
    }

    private function buildProjectDashboardOverview(
        Project $project,
        array $statistics,
        array $workTypes,
        array $schedule,
        array $payments
    ): array {
        $totalBudget = $this->toFloat($project->budget_amount);
        $worksCost = $this->toFloat($statistics['works']['total_work_cost'] ?? 0);
        $materialsCost = $this->toFloat($statistics['materials']['total_used_value'] ?? 0);
        $spentBudget = max($worksCost + $materialsCost, $payments['paid_amount']);
        $remainingBudget = $totalBudget > 0 ? $totalBudget - $spentBudget : 0.0;
        $budgetUsage = $totalBudget > 0 ? round(($spentBudget / $totalBudget) * 100, 2) : 0.0;
        $completion = $schedule['active_schedule']['progress_percentage'] ?? $this->calculateProjectDashboardWorkCompletion($workTypes);
        $calendar = $this->calculateProjectDashboardCalendarProgress($project->start_date, $project->end_date);

        return [
            'status' => $project->status,
            'total_budget' => round($totalBudget, 2),
            'spent_budget' => round($spentBudget, 2),
            'remaining_budget' => round($remainingBudget, 2),
            'budget_usage_percentage' => $budgetUsage,
            'completion_percentage' => round($completion, 2),
            'calendar_progress_percentage' => $calendar['progress_percentage'],
            'days_remaining' => $calendar['days_remaining'],
            'is_overdue' => $calendar['is_overdue'] && $project->status !== 'completed',
            'start_date' => $project->start_date?->toDateString(),
            'end_date' => $project->end_date?->toDateString(),
            'team_members_count' => (int) ($statistics['team']['assigned_users_count'] ?? 0),
        ];
    }

    private function buildProjectDashboardPayments(int $projectId): array
    {
        $documents = DB::table('payment_documents')
            ->where('project_id', $projectId)
            ->whereNull('deleted_at')
            ->select([
                'id',
                'document_number',
                'amount',
                'paid_amount',
                'remaining_amount',
                'status',
                'direction',
                'due_date',
                'scheduled_at',
                'paid_at',
            ])
            ->get();

        $activeStatuses = ['submitted', 'pending_approval', 'approved', 'scheduled', 'partially_paid'];
        $today = now()->startOfDay();
        $dueLimit = now()->startOfDay()->addDays(14);

        $normalized = $documents->map(function (object $document) use ($activeStatuses, $today, $dueLimit): array {
            $amount = $this->toFloat($document->amount ?? 0);
            $paidAmount = $this->toFloat($document->paid_amount ?? 0);
            $remainingAmount = $this->toFloat($document->remaining_amount ?? max($amount - $paidAmount, 0));
            $status = (string) ($document->status ?? 'draft');
            $dueDate = $document->due_date ? Carbon::parse($document->due_date)->startOfDay() : null;
            $isActive = in_array($status, $activeStatuses, true);

            return [
                'id' => (int) $document->id,
                'document_number' => $document->document_number,
                'amount' => round($amount, 2),
                'paid_amount' => round($paidAmount, 2),
                'remaining_amount' => round($remainingAmount, 2),
                'status' => $status,
                'direction' => $document->direction,
                'due_date' => $document->due_date,
                'scheduled_at' => $document->scheduled_at,
                'paid_at' => $document->paid_at,
                'is_active' => $isActive,
                'is_overdue' => $isActive && $remainingAmount > 0 && $dueDate !== null && $dueDate->lt($today),
                'is_due_soon' => $isActive && $remainingAmount > 0 && $dueDate !== null && $dueDate->betweenIncluded($today, $dueLimit),
            ];
        });

        $payable = $normalized->filter(fn (array $document): bool => $document['is_active'] && $document['remaining_amount'] > 0);
        $outgoing = $payable->filter(fn (array $document): bool => $document['direction'] === 'outgoing');
        $incoming = $payable->filter(fn (array $document): bool => $document['direction'] === 'incoming');
        $overdue = $payable->filter(fn (array $document): bool => $document['is_overdue']);
        $dueSoon = $payable->filter(fn (array $document): bool => $document['is_due_soon']);

        return [
            'documents_count' => $normalized->count(),
            'total_amount' => round($normalized->sum('amount'), 2),
            'paid_amount' => round($normalized->sum('paid_amount'), 2),
            'remaining_amount' => round($payable->sum('remaining_amount'), 2),
            'outgoing_remaining_amount' => round($outgoing->sum('remaining_amount'), 2),
            'incoming_remaining_amount' => round($incoming->sum('remaining_amount'), 2),
            'overdue_amount' => round($overdue->sum('remaining_amount'), 2),
            'overdue_count' => $overdue->count(),
            'due_soon_amount' => round($dueSoon->sum('remaining_amount'), 2),
            'due_soon_count' => $dueSoon->count(),
            'status_breakdown' => $normalized
                ->groupBy('status')
                ->map(fn ($items, string $status): array => [
                    'status' => $status,
                    'count' => $items->count(),
                    'amount' => round($items->sum('amount'), 2),
                    'remaining_amount' => round($items->sum('remaining_amount'), 2),
                ])
                ->values()
                ->all(),
            'upcoming' => $payable
                ->filter(fn (array $document): bool => !empty($document['due_date']))
                ->sortBy('due_date')
                ->take(6)
                ->values()
                ->all(),
        ];
    }

    private function buildProjectDashboardSchedule(int $projectId): array
    {
        $schedule = DB::table('project_schedules')
            ->where('project_id', $projectId)
            ->whereNull('deleted_at')
            ->orderByRaw("CASE status WHEN 'active' THEN 0 WHEN 'draft' THEN 1 WHEN 'paused' THEN 2 ELSE 3 END")
            ->orderByDesc('updated_at')
            ->first([
                'id',
                'name',
                'status',
                'planned_start_date',
                'planned_end_date',
                'overall_progress_percent',
                'total_estimated_cost',
                'total_actual_cost',
                'critical_path_calculated',
                'critical_path_duration_days',
            ]);

        if (!$schedule) {
            return [
                'active_schedule' => null,
                'tasks' => [],
                'tasks_count' => 0,
                'completed_tasks_count' => 0,
                'critical_tasks_count' => 0,
                'overdue_tasks_count' => 0,
            ];
        }

        $counts = DB::table('schedule_tasks')
            ->where('schedule_id', $schedule->id)
            ->whereNull('deleted_at')
            ->selectRaw('COUNT(*) as tasks_count')
            ->selectRaw("SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_tasks_count")
            ->selectRaw("SUM(CASE WHEN COALESCE(is_critical, false) = true THEN 1 ELSE 0 END) as critical_tasks_count")
            ->selectRaw("SUM(CASE WHEN planned_end_date < CURRENT_DATE AND status NOT IN ('completed', 'cancelled') THEN 1 ELSE 0 END) as overdue_tasks_count")
            ->first();

        $tasks = DB::table('schedule_tasks as st')
            ->leftJoin('work_types as wt', 'wt.id', '=', 'st.work_type_id')
            ->where('st.schedule_id', $schedule->id)
            ->whereNull('st.deleted_at')
            ->whereNotNull('st.planned_start_date')
            ->whereNotNull('st.planned_end_date')
            ->orderByRaw('COALESCE(st.is_critical, false) DESC')
            ->orderBy('st.planned_start_date')
            ->limit(8)
            ->get([
                'st.id',
                'st.name',
                'st.status',
                'st.planned_start_date',
                'st.planned_end_date',
                'st.progress_percent',
                'st.estimated_cost',
                'st.actual_cost',
                'st.is_critical',
                'wt.name as work_type_name',
            ])
            ->map(fn (object $task): array => [
                'id' => (int) $task->id,
                'name' => $task->name,
                'work_type_name' => $task->work_type_name,
                'status' => $task->status,
                'start_date' => $task->planned_start_date,
                'end_date' => $task->planned_end_date,
                'progress_percentage' => round($this->toFloat($task->progress_percent), 2),
                'estimated_cost' => round($this->toFloat($task->estimated_cost), 2),
                'actual_cost' => round($this->toFloat($task->actual_cost), 2),
                'is_critical' => (bool) $task->is_critical,
            ])
            ->values()
            ->all();

        return [
            'active_schedule' => [
                'id' => (int) $schedule->id,
                'name' => $schedule->name,
                'status' => $schedule->status,
                'planned_start_date' => $schedule->planned_start_date,
                'planned_end_date' => $schedule->planned_end_date,
                'progress_percentage' => round($this->toFloat($schedule->overall_progress_percent), 2),
                'estimated_cost' => round($this->toFloat($schedule->total_estimated_cost), 2),
                'actual_cost' => round($this->toFloat($schedule->total_actual_cost), 2),
                'critical_path_calculated' => (bool) $schedule->critical_path_calculated,
                'critical_path_duration_days' => $schedule->critical_path_duration_days !== null ? (int) $schedule->critical_path_duration_days : null,
            ],
            'tasks' => $tasks,
            'tasks_count' => (int) ($counts->tasks_count ?? 0),
            'completed_tasks_count' => (int) ($counts->completed_tasks_count ?? 0),
            'critical_tasks_count' => (int) ($counts->critical_tasks_count ?? 0),
            'overdue_tasks_count' => (int) ($counts->overdue_tasks_count ?? 0),
        ];
    }

    private function buildProjectDashboardMaterialSlices(Project $project): array
    {
        $allocatedRows = DB::table('warehouse_project_allocations as wpa')
            ->join('materials as m', 'm.id', '=', 'wpa.material_id')
            ->join('organization_warehouses as w', 'w.id', '=', 'wpa.warehouse_id')
            ->leftJoin('measurement_units as mu', 'mu.id', '=', 'm.measurement_unit_id')
            ->leftJoin(DB::raw('(
                SELECT
                    wb.material_id,
                    wb.organization_id,
                    SUM(wb.available_quantity) as total_available_quantity,
                    SUM(wb.available_quantity * COALESCE(NULLIF(wb.unit_price, 0), m_inner.default_price, 0)) as total_available_value
                FROM warehouse_balances wb
                JOIN materials m_inner ON m_inner.id = wb.material_id
                JOIN organization_warehouses ow ON ow.id = wb.warehouse_id
                WHERE ow.is_active = true
                GROUP BY wb.material_id, wb.organization_id
            ) as warehouse_totals'), function ($join): void {
                $join->on('warehouse_totals.material_id', '=', 'wpa.material_id')
                    ->on('warehouse_totals.organization_id', '=', 'wpa.organization_id');
            })
            ->where('wpa.project_id', $project->id)
            ->select([
                'wpa.id',
                'm.id as material_id',
                'm.name',
                'm.code',
                'mu.short_name as unit',
                'w.name as warehouse_name',
                'wpa.allocated_quantity',
                DB::raw('COALESCE(warehouse_totals.total_available_quantity, 0) as available_quantity'),
                DB::raw('CASE WHEN COALESCE(warehouse_totals.total_available_quantity, 0) > 0 THEN warehouse_totals.total_available_value / warehouse_totals.total_available_quantity ELSE COALESCE(m.default_price, 0) END as average_price'),
                DB::raw('wpa.allocated_quantity * CASE WHEN COALESCE(warehouse_totals.total_available_quantity, 0) > 0 THEN warehouse_totals.total_available_value / warehouse_totals.total_available_quantity ELSE COALESCE(m.default_price, 0) END as allocated_value'),
                DB::raw('GREATEST(wpa.allocated_quantity * CASE WHEN COALESCE(warehouse_totals.total_available_quantity, 0) > 0 THEN warehouse_totals.total_available_value / warehouse_totals.total_available_quantity ELSE COALESCE(m.default_price, 0) END, COALESCE(warehouse_totals.total_available_value, 0)) as chart_value'),
                'wpa.allocated_at as last_operation_date',
            ])
            ->orderByDesc('chart_value')
            ->limit(12)
            ->get()
            ->map(fn (object $item): array => [
                'id' => (int) $item->id,
                'material_id' => (int) $item->material_id,
                'name' => $item->name,
                'code' => $item->code,
                'unit' => $item->unit,
                'warehouse_name' => $item->warehouse_name,
                'allocated_quantity' => round($this->toFloat($item->allocated_quantity), 4),
                'available_quantity' => round($this->toFloat($item->available_quantity), 4),
                'average_price' => round($this->toFloat($item->average_price), 2),
                'allocated_value' => round($this->toFloat($item->allocated_value), 2),
                'chart_value' => round($this->toFloat($item->chart_value), 2),
                'last_operation_date' => $item->last_operation_date,
                'has_warning' => $this->toFloat($item->available_quantity) <= 0 && $this->toFloat($item->allocated_quantity) > 0,
                'source' => 'project_allocation',
            ])
            ->keyBy('material_id');

        $stockRows = DB::table('warehouse_balances as wb')
            ->join('materials as m', 'm.id', '=', 'wb.material_id')
            ->join('organization_warehouses as w', 'w.id', '=', 'wb.warehouse_id')
            ->leftJoin('measurement_units as mu', 'mu.id', '=', 'm.measurement_unit_id')
            ->where('wb.organization_id', $project->organization_id)
            ->where('w.is_active', true)
            ->select([
                DB::raw('MIN(wb.id) as id'),
                'm.id as material_id',
                'm.name',
                'm.code',
                'mu.short_name as unit',
                DB::raw('MIN(w.name) as warehouse_name'),
                DB::raw('SUM(wb.available_quantity) as available_quantity'),
                DB::raw('SUM(wb.available_quantity * COALESCE(NULLIF(wb.unit_price, 0), m.default_price, 0)) as chart_value'),
                DB::raw('CASE WHEN SUM(wb.available_quantity) > 0 THEN SUM(wb.available_quantity * COALESCE(NULLIF(wb.unit_price, 0), m.default_price, 0)) / SUM(wb.available_quantity) ELSE COALESCE(MAX(m.default_price), 0) END as average_price'),
                DB::raw('MAX(wb.last_movement_at) as last_operation_date'),
            ])
            ->groupBy('m.id', 'm.name', 'm.code', 'mu.short_name')
            ->havingRaw('SUM(wb.available_quantity) > 0')
            ->orderByDesc('chart_value')
            ->limit(12)
            ->get()
            ->map(fn (object $item): array => [
                'id' => (int) $item->id,
                'material_id' => (int) $item->material_id,
                'name' => $item->name,
                'code' => $item->code,
                'unit' => $item->unit,
                'warehouse_name' => $item->warehouse_name,
                'allocated_quantity' => 0.0,
                'available_quantity' => round($this->toFloat($item->available_quantity), 4),
                'average_price' => round($this->toFloat($item->average_price), 2),
                'allocated_value' => 0.0,
                'chart_value' => round($this->toFloat($item->chart_value), 2),
                'last_operation_date' => $item->last_operation_date,
                'has_warning' => false,
                'source' => 'warehouse_stock',
            ])
            ->keyBy('material_id');

        return $allocatedRows
            ->union($stockRows)
            ->values()
            ->sortByDesc('chart_value')
            ->take(8)
            ->values()
            ->all();
    }

    private function buildProjectDashboardRisks(array $overview, array $schedule, array $payments, array $materials): array
    {
        $risks = [];

        if ($overview['is_overdue'] || $schedule['overdue_tasks_count'] > 0) {
            $risks[] = [
                'key' => 'schedule_overdue',
                'category' => 'schedule',
                'tone' => 'error',
                'value' => $schedule['overdue_tasks_count'],
                'metric' => 'tasks',
            ];
        } elseif ($overview['days_remaining'] !== null && $overview['days_remaining'] <= 30) {
            $risks[] = [
                'key' => 'schedule_due_soon',
                'category' => 'schedule',
                'tone' => 'warning',
                'value' => $overview['days_remaining'],
                'metric' => 'days',
            ];
        }

        if ($overview['total_budget'] > 0 && $overview['spent_budget'] > $overview['total_budget']) {
            $risks[] = [
                'key' => 'budget_overrun',
                'category' => 'finance',
                'tone' => 'error',
                'value' => round($overview['spent_budget'] - $overview['total_budget'], 2),
                'metric' => 'amount',
            ];
        } elseif ($overview['budget_usage_percentage'] >= 90) {
            $risks[] = [
                'key' => 'budget_limit_close',
                'category' => 'finance',
                'tone' => 'warning',
                'value' => $overview['budget_usage_percentage'],
                'metric' => 'percent',
            ];
        }

        if ($payments['overdue_count'] > 0) {
            $risks[] = [
                'key' => 'payments_overdue',
                'category' => 'payments',
                'tone' => 'error',
                'value' => $payments['overdue_amount'],
                'metric' => 'amount',
            ];
        } elseif ($payments['due_soon_count'] > 0) {
            $risks[] = [
                'key' => 'payments_due_soon',
                'category' => 'payments',
                'tone' => 'warning',
                'value' => $payments['due_soon_amount'],
                'metric' => 'amount',
            ];
        }

        $materialWarnings = collect($materials)->filter(fn (array $material): bool => (bool) ($material['has_warning'] ?? false))->count();
        if ($materialWarnings > 0) {
            $risks[] = [
                'key' => 'materials_missing_stock',
                'category' => 'materials',
                'tone' => 'warning',
                'value' => $materialWarnings,
                'metric' => 'items',
            ];
        }

        if ($overview['completion_percentage'] + 15 < $overview['calendar_progress_percentage']) {
            $risks[] = [
                'key' => 'production_lag',
                'category' => 'works',
                'tone' => 'warning',
                'value' => round($overview['calendar_progress_percentage'] - $overview['completion_percentage'], 2),
                'metric' => 'percent',
            ];
        }

        if ($risks === []) {
            $risks[] = [
                'key' => 'stable',
                'category' => 'project',
                'tone' => 'success',
                'value' => 0,
                'metric' => 'none',
            ];
        }

        return $risks;
    }

    private function normalizeProjectDashboardWorkStage(mixed $item): array
    {
        $row = (array) $item;

        return [
            'id' => (int) ($row['work_type_id'] ?? 0),
            'name' => $row['work_type_name'] ?? null,
            'unit' => $row['unit'] ?? null,
            'planned_quantity' => round($this->toFloat($row['planned_quantity'] ?? 0), 4),
            'completed_quantity' => round($this->toFloat($row['completed_quantity'] ?? 0), 4),
            'completion_percentage' => round($this->toFloat($row['completion_percentage'] ?? 0), 2),
            'works_count' => (int) ($row['works_count'] ?? 0),
            'total_cost' => round($this->toFloat($row['total_cost'] ?? 0), 2),
            'average_unit_price' => round($this->toFloat($row['average_unit_price'] ?? 0), 2),
            'last_completion_date' => $row['last_completion_date'] ?? null,
            'workers_count' => (int) ($row['workers_count'] ?? 0),
        ];
    }

    private function normalizeProjectDashboardMaterial(mixed $item): array
    {
        $row = (array) $item;

        return [
            'id' => (int) ($row['allocation_id'] ?? 0),
            'material_id' => (int) ($row['material_id'] ?? 0),
            'name' => $row['material_name'] ?? null,
            'code' => $row['material_code'] ?? null,
            'unit' => $row['unit'] ?? null,
            'warehouse_name' => $row['warehouse_name'] ?? null,
            'allocated_quantity' => round($this->toFloat($row['allocated_quantity'] ?? 0), 4),
            'available_quantity' => round($this->toFloat($row['warehouse_available_total'] ?? 0), 4),
            'average_price' => round($this->toFloat($row['average_price'] ?? 0), 2),
            'allocated_value' => round($this->toFloat($row['allocated_value'] ?? 0), 2),
            'last_operation_date' => $row['last_operation_date'] ?? null,
            'has_warning' => (bool) ($row['has_warning'] ?? false),
        ];
    }

    private function calculateProjectDashboardWorkCompletion(array $workTypes): float
    {
        $planned = collect($workTypes)->sum('planned_quantity');
        $completed = collect($workTypes)->sum('completed_quantity');

        if ($planned > 0) {
            return min(round(($completed / $planned) * 100, 2), 100.0);
        }

        $percentages = collect($workTypes)
            ->pluck('completion_percentage')
            ->filter(fn (mixed $value): bool => $this->toFloat($value) > 0);

        return $percentages->count() > 0 ? round($percentages->avg(), 2) : 0.0;
    }

    private function calculateProjectDashboardCalendarProgress(mixed $startDate, mixed $endDate): array
    {
        if (!$startDate || !$endDate) {
            return [
                'progress_percentage' => 0.0,
                'days_remaining' => null,
                'is_overdue' => false,
            ];
        }

        $start = $startDate instanceof Carbon ? $startDate->copy()->startOfDay() : Carbon::parse($startDate)->startOfDay();
        $end = $endDate instanceof Carbon ? $endDate->copy()->startOfDay() : Carbon::parse($endDate)->startOfDay();
        $today = now()->startOfDay();

        if ($end->lessThanOrEqualTo($start)) {
            return [
                'progress_percentage' => 0.0,
                'days_remaining' => $today->diffInDays($end, false),
                'is_overdue' => $today->gt($end),
            ];
        }

        $totalDays = max($start->diffInDays($end), 1);
        $elapsedDays = $start->diffInDays($today, false);
        $progress = min(max(($elapsedDays / $totalDays) * 100, 0), 100);

        return [
            'progress_percentage' => round($progress, 2),
            'days_remaining' => $today->diffInDays($end, false),
            'is_overdue' => $today->gt($end),
        ];
    }

    private function toFloat(mixed $value): float
    {
        if ($value === null || $value === '') {
            return 0.0;
        }

        if (is_numeric($value)) {
            return (float) $value;
        }

        return 0.0;
    }

    /**
     * Добавить дочернюю организацию к проекту.
     */
    public function addOrganizationToProject(
        int $projectId, 
        int $organizationId, 
        ProjectOrganizationRole $role,
        Request $request
    ): void {
        $project = $this->findProjectByIdForCurrentOrg($projectId, $request);
        if (!$project) {
            throw new BusinessLogicException(trans_message('project.not_found'), 404);
        }

        $this->projectParticipantService->attach($project, $organizationId, $role, $request->user());
    }

    public function attachOrganizationToProjectEntity(
        Project $project,
        int $organizationId,
        ProjectOrganizationRole $role,
        ?User $user = null
    ): void {
        $this->projectParticipantService->attach($project, $organizationId, $role, $user);
    }

    private function ensureContractorExists(int $forOrgId, int $sourceOrgId): void
    {
        $sourceOrg = \App\Models\Organization::find($sourceOrgId);
        if (!$sourceOrg) {
            return;
        }

        $exists = \App\Models\Contractor::where('organization_id', $forOrgId)
            ->where('source_organization_id', $sourceOrgId)
            ->exists();

        if ($exists) {
            return;
        }

        \App\Models\Contractor::create([
            'organization_id' => $forOrgId,
            'source_organization_id' => $sourceOrgId,
            'name' => $sourceOrg->name,
            'inn' => $sourceOrg->tax_number,
            'legal_address' => $sourceOrg->address,
            'phone' => $sourceOrg->phone,
            'email' => $sourceOrg->email,
            'contractor_type' => \App\Models\Contractor::TYPE_INVITED_ORGANIZATION,
            'connected_at' => now(),
            'sync_settings' => [
                'sync_fields' => ['name', 'phone', 'email', 'legal_address', 'inn'],
                'sync_interval_hours' => 24,
            ],
        ]);

        $this->logging->business('Contractor created from project participant', [
            'for_organization_id' => $forOrgId,
            'source_organization_id' => $sourceOrgId,
            'contractor_name' => $sourceOrg->name,
        ]);
    }

    /**
     * Удалить организацию из проекта.
     */
    public function removeOrganizationFromProject(int $projectId, int $organizationId, Request $request): void
    {
        $project = $this->findProjectByIdForCurrentOrg($projectId, $request);
        if (!$project) {
            throw new BusinessLogicException(trans_message('project.not_found'), 404);
        }

        $this->projectParticipantService->remove($project, $organizationId, $request->user());
    }
    
    /**
     * Изменить роль организации в проекте.
     */
    public function updateOrganizationRole(
        int $projectId, 
        int $organizationId, 
        ProjectOrganizationRole $newRole,
        Request $request
    ): void {
        $project = $this->findProjectByIdForCurrentOrg($projectId, $request);
        if (!$project) {
            throw new BusinessLogicException(trans_message('project.not_found'), 404);
        }

        $this->projectParticipantService->updateRole($project, $organizationId, $newRole, $request->user());
    }

    public function updateOrganizationRoleForProjectEntity(
        Project $project,
        int $organizationId,
        ProjectOrganizationRole $newRole,
        ?User $user = null
    ): void {
        $this->projectParticipantService->updateRole($project, $organizationId, $newRole, $user);
    }

    public function setOrganizationActiveState(Project $project, int $organizationId, bool $isActive): void
    {
        $this->projectParticipantService->setActiveState($project, $organizationId, $isActive);
    }

    private function assertCustomerRoleAvailable(
        Project $project,
        ProjectOrganizationRole $role,
        ?int $organizationId = null
    ): void {
        $this->projectParticipantService->enforceUniqueCustomer($project, $role, $organizationId);
    }

    private function resolveLegacyProjectRoleValue(ProjectOrganizationRole $role): string
    {
        return match ($role) {
            ProjectOrganizationRole::OWNER => 'owner',
            ProjectOrganizationRole::CONTRACTOR,
            ProjectOrganizationRole::GENERAL_CONTRACTOR => 'contractor',
            ProjectOrganizationRole::SUBCONTRACTOR => 'child_contractor',
            ProjectOrganizationRole::CUSTOMER,
            ProjectOrganizationRole::CONSTRUCTION_SUPERVISION,
            ProjectOrganizationRole::DESIGNER,
            ProjectOrganizationRole::OBSERVER,
            ProjectOrganizationRole::PARENT_ADMINISTRATOR => 'observer',
        };
    }

    private function resolveOrganizationRoleForProject(
        Project $project,
        int $organizationId,
        bool $includeInactive = false
    ): ?ProjectOrganizationRole
    {
        if ($organizationId === (int) $project->organization_id) {
            return ProjectOrganizationRole::OWNER;
        }

        $pivotQuery = ProjectOrganization::query()
            ->where('project_id', $project->id)
            ->where('organization_id', $organizationId);

        if (!$includeInactive) {
            $pivotQuery->where('is_active', true);
        }

        $pivot = $pivotQuery->first();

        if (!$pivot instanceof ProjectOrganization) {
            return null;
        }

        $roleValue = $pivot->getRawOriginal('role_new') ?: $pivot->getRawOriginal('role');
        if (!is_string($roleValue) || $roleValue === '') {
            return null;
        }

        return ProjectOrganizationRole::tryFrom($roleValue) ?? match ($roleValue) {
            'owner' => ProjectOrganizationRole::OWNER,
            'contractor' => ProjectOrganizationRole::CONTRACTOR,
            'child_contractor' => ProjectOrganizationRole::SUBCONTRACTOR,
            'observer' => ProjectOrganizationRole::OBSERVER,
            default => null,
        };
    }

    private function resolveProjectRoleFromPivot(ProjectOrganization $pivot): ?ProjectOrganizationRole
    {
        $roleValue = $pivot->getRawOriginal('role_new') ?: $pivot->getRawOriginal('role');
        if (!is_string($roleValue) || $roleValue === '') {
            return null;
        }

        return ProjectOrganizationRole::tryFrom($roleValue) ?? match ($roleValue) {
            'owner' => ProjectOrganizationRole::OWNER,
            'contractor' => ProjectOrganizationRole::CONTRACTOR,
            'child_contractor' => ProjectOrganizationRole::SUBCONTRACTOR,
            'observer' => ProjectOrganizationRole::OBSERVER,
            default => null,
        };
    }

    /**
     * Получить полную информацию по проекту: финансы, статистика, разбивка по организациям.
     */
    public function getFullProjectDetails(int $projectId, Request $request): array
    {
        $project = $this->findProjectByIdForCurrentOrg($projectId, $request);
        if (!$project) {
            throw new BusinessLogicException(trans_message('project.not_found_or_not_in_organization'), 404);
        }

        // Загружаем организации и контракты с актами/платежами
        $project->load([
            'organizations:id,name',
            'contracts:id,project_id,total_amount,status',
            'contracts.performanceActs:id,contract_id,amount,is_approved',
            'contracts.payments:id,invoiceable_id,invoiceable_type,paid_amount',
        ]);

        // Общие суммы
        $totalContractsAmount = $project->contracts->sum('total_amount');
        $totalPerformanceActsAmount = $project->contracts->flatMap(fn($c) => $c->performanceActs)->where('is_approved', true)->sum('amount');
        $totalPaymentsAmount = $project->contracts->flatMap(fn($c) => $c->payments)->sum('paid_amount');

        // Сумма выполненных работ и материалов
        $completedWorksQuery = DB::table('completed_works')
            ->where('project_id', $projectId)
            ->selectRaw('organization_id, COUNT(*) as works_count, SUM(total_amount) as works_amount')
            ->groupBy('organization_id')
            ->get();

        $materialsQuery = DB::table('completed_work_materials as cwm')
            ->join('completed_works as cw', 'cw.id', '=', 'cwm.completed_work_id')
            ->where('cw.project_id', $projectId)
            ->selectRaw('cw.organization_id, SUM(cwm.total_amount) as materials_amount')
            ->groupBy('cw.organization_id')
            ->get();

        // Формируем словари для быстрого доступа
        $worksByOrg = $completedWorksQuery->keyBy('organization_id');
        $materialsByOrg = $materialsQuery->keyBy('organization_id');

        $organizationsStats = $project->organizations->map(function ($org) use ($worksByOrg, $materialsByOrg) {
            $works = $worksByOrg[$org->id] ?? null;
            $materials = $materialsByOrg[$org->id] ?? null;

            return [
                'id' => $org->id,
                'name' => $org->name,
                'works_count' => (int) ($works->works_count ?? 0),
                'works_amount' => (float) ($works->works_amount ?? 0),
                'materials_amount' => (float) ($materials->materials_amount ?? 0),
                'total_cost' => (float) (($works->works_amount ?? 0) + ($materials->materials_amount ?? 0)),
            ];
        })->toArray();

        // Общая статистика по выполненным работам и материалам
        $totalWorksAmount = array_sum(array_column($organizationsStats, 'works_amount'));
        $totalMaterialsAmount = array_sum(array_column($organizationsStats, 'materials_amount'));

        $analytics = [
            'financial' => [
                'contracts_total_amount' => (float) $totalContractsAmount,
                'performed_amount_by_acts' => (float) $totalPerformanceActsAmount,
                'received_payments_amount' => (float) $totalPaymentsAmount,
                'works_total_amount' => (float) $totalWorksAmount,
                'materials_total_amount' => (float) $totalMaterialsAmount,
                'overall_cost' => (float) ($totalWorksAmount + $totalMaterialsAmount),
            ],
            'counts' => [
                'organizations' => count($organizationsStats),
                'contracts' => $project->contracts->count(),
                'performance_acts' => $project->contracts->flatMap(fn($c) => $c->performanceActs)->count(),
            ],
        ];

        return [
            'project' => $project,
            'analytics' => $analytics,
            'organizations_stats' => $organizationsStats,
        ];
    }
} 
