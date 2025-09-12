<?php

namespace App\Services\Project;

use App\Repositories\Interfaces\ProjectRepositoryInterface;
use App\Repositories\Interfaces\UserRepositoryInterface;
use App\Repositories\Interfaces\MaterialRepositoryInterface;
use App\Repositories\Interfaces\WorkTypeRepositoryInterface;
use App\Models\Project;
use App\Models\User;
use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Collection;
use App\Exceptions\BusinessLogicException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use App\DTOs\Project\ProjectDTO;

class ProjectService
{
    protected ProjectRepositoryInterface $projectRepository;
    protected UserRepositoryInterface $userRepository;
    protected MaterialRepositoryInterface $materialRepository;
    protected WorkTypeRepositoryInterface $workTypeRepository;

    public function __construct(
        ProjectRepositoryInterface $projectRepository,
        UserRepositoryInterface $userRepository,
        MaterialRepositoryInterface $materialRepository,
        WorkTypeRepositoryInterface $workTypeRepository
    ) {
        $this->projectRepository = $projectRepository;
        $this->userRepository = $userRepository;
        $this->materialRepository = $materialRepository;
        $this->workTypeRepository = $workTypeRepository;
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
            throw new BusinessLogicException('Контекст организации не определен.', 500);
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

        // TODO: Добавить валидацию sortBy, чтобы разрешить только определенные поля
        $allowedSortBy = ['name', 'status', 'start_date', 'end_date', 'created_at', 'updated_at'];
        if (!in_array(strtolower($sortBy), $allowedSortBy)) {
            $sortBy = 'created_at'; // По умолчанию, если передано невалидное поле
        }
        if (!in_array(strtolower($sortDirection), ['asc', 'desc'])) {
            $sortDirection = 'desc';
        }

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
        
        $dataToCreate = $projectDTO->toArray();
        $dataToCreate['organization_id'] = $organizationId;
        $dataToCreate['is_head'] = true;
        
        return $this->projectRepository->create($dataToCreate);
    }

    public function findProjectByIdForCurrentOrg(int $id, Request $request): ?Project
    {
        $organizationId = $this->getCurrentOrgId($request);
        $project = $this->projectRepository->find($id);

        if (!$project) {
            return null;
        }

        // Принадлежит ли текущей организации напрямую или через pivot
        $belongsToOrg = $project->organization_id === $organizationId ||
            $project->organizations()->where('organizations.id', $organizationId)->exists();

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
            throw new BusinessLogicException('Project not found in your organization or you do not have permission.', 404);
        }

        $updated = $this->projectRepository->update($id, $projectDTO->toArray());
        return $updated ? $this->projectRepository->find($id) : null;
    }

    public function deleteProject(int $id, Request $request): bool
    {
        $project = $this->findProjectByIdForCurrentOrg($id, $request);
        if (!$project) {
            throw new BusinessLogicException('Project not found in your organization', 404);
        }
        return $this->projectRepository->delete($id);
    }

    public function assignForemanToProject(int $projectId, int $userId, Request $request): bool
    {
        $organizationId = $this->getCurrentOrgId($request);
        
        $project = $this->findProjectByIdForCurrentOrg($projectId, $request);
        if (!$project) {
            throw new BusinessLogicException('Проект не найден в вашей организации.', 404);
        }

        $user = $this->userRepository->find($userId);
        if (!$user 
            || !$user->is_active 
            || !$user->hasRole('foreman', null) // Обновлено для новой системы авторизации
            || !$user->organizations()->where('organization_user.organization_id', $organizationId)->exists()
           ) { 
            throw new BusinessLogicException('Пользователь не найден, неактивен или не является прорабом в вашей организации.', 404);
        }

        try {
            // Добавляем роль foreman в pivot. Если запись уже есть — обновляем.
            $project->users()->syncWithoutDetaching([$userId => ['role' => 'foreman']]);
            Log::info('Foreman assigned to project', ['project_id' => $projectId, 'user_id' => $userId, 'admin_id' => $request->user()->id]);
            return true;
        } catch (\Illuminate\Database\QueryException $e) {
            if ($e->getCode() == 23505) {
                Log::warning('Attempted to assign already assigned foreman to project', ['project_id' => $projectId, 'user_id' => $userId]);
                return true; 
            }
            Log::error('Database error assigning foreman to project', ['project_id' => $projectId, 'user_id' => $userId, 'exception' => $e]);
            throw new BusinessLogicException('Ошибка базы данных при назначении прораба.', 500, $e);
        }
    }

    public function detachForemanFromProject(int $projectId, int $userId, Request $request): bool
    {
        $organizationId = $this->getCurrentOrgId($request);
        
        $project = $this->findProjectByIdForCurrentOrg($projectId, $request);
        if (!$project) {
            throw new BusinessLogicException('Проект не найден в вашей организации.', 404);
        }

        $detachedCount = $project->users()->detach($userId);

        if ($detachedCount > 0) {
             Log::info('Foreman detached from project', ['project_id' => $projectId, 'user_id' => $userId, 'admin_id' => $request->user()->id]);
            return true;
        } else {
            Log::warning('Attempted to detach foreman not assigned to project', ['project_id' => $projectId, 'user_id' => $userId]);
            return false;
        }
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
             throw new BusinessLogicException('Пользователь не аутентифицирован.', 401);
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
            throw new BusinessLogicException('Проект не найден.', 404);
        }

        try {
            // Статистика по материалам
            $materialStats = DB::table('material_usage_logs as mul')
                ->where('mul.project_id', $id)
                ->selectRaw("\n                    COUNT(DISTINCT mul.material_id) as unique_materials_count,\n                    SUM(CASE WHEN mul.operation_type = 'receipt' THEN mul.quantity ELSE 0 END) as total_received,\n                    SUM(CASE WHEN mul.operation_type = 'write_off' THEN mul.quantity ELSE 0 END) as total_used,\n                    SUM(CASE WHEN mul.operation_type = 'receipt' THEN mul.total_price ELSE 0 END) as total_received_value,\n                    SUM(CASE WHEN mul.operation_type = 'write_off' THEN mul.total_price ELSE 0 END) as total_used_value\n                ")
                ->first();

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

            // Акты выполненных работ по проекту через контракты
            $acts = DB::table('contract_performance_acts as a')
                ->join('contracts as c', 'c.id', '=', 'a.contract_id')
                ->where('c.project_id', $id)
                ->select(['a.id', 'a.contract_id', 'a.act_document_number', 'a.act_date', 'a.amount', 'a.is_approved'])
                ->orderBy('a.act_date', 'desc')
                ->get();

            // Последние операции
            $lastMaterialOperation = DB::table('material_usage_logs')
                ->where('project_id', $id)
                ->orderBy('usage_date', 'desc')
                ->first(['usage_date', 'operation_type']);

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
                    'last_operation_date' => $lastMaterialOperation->usage_date ?? null,
                    'last_operation_type' => $lastMaterialOperation->operation_type ?? null
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
            throw new BusinessLogicException('Ошибка при получении статистики проекта.', 500);
        }
    }

    public function getProjectMaterials(int $id, int $perPage = 15, ?string $search = null, string $sortBy = 'created_at', string $sortDirection = 'desc'): array
    {
        $project = $this->projectRepository->find($id);
        if (!$project) {
            throw new BusinessLogicException('Проект не найден.', 404);
        }

        try {
            $query = DB::table('material_usage_logs as mul')
                ->join('materials as m', 'mul.material_id', '=', 'm.id')
                ->leftJoin('measurement_units as mu', 'm.measurement_unit_id', '=', 'mu.id')
                ->leftJoin('suppliers as s', 'mul.supplier_id', '=', 's.id')
                ->where('mul.project_id', $id)
                ->select([
                    'm.id as material_id',
                    'm.name as material_name',
                    'm.code as material_code',
                    'mu.short_name as unit',
                    's.name as supplier_name',
                    DB::raw("SUM(CASE WHEN mul.operation_type = 'receipt' THEN mul.quantity ELSE 0 END) as total_received"),
                    DB::raw("SUM(CASE WHEN mul.operation_type = 'write_off' THEN mul.quantity ELSE 0 END) as total_used"),
                    DB::raw("SUM(CASE WHEN mul.operation_type = 'receipt' THEN mul.quantity ELSE 0 END) - SUM(CASE WHEN mul.operation_type = 'write_off' THEN mul.quantity ELSE 0 END) as current_balance"),
                    DB::raw('AVG(mul.unit_price) as average_price'),
                    DB::raw('MAX(mul.usage_date) as last_operation_date')
                ])
                ->groupBy(['m.id', 'm.name', 'm.code', 'mu.short_name', 's.name']);

            if ($search) {
                $query->where(function($q) use ($search) {
                    $q->where('m.name', 'like', "%{$search}%")
                      ->orWhere('m.code', 'like', "%{$search}%");
                });
            }

            $allowedSortBy = ['material_name', 'material_code', 'total_received', 'total_used', 'current_balance', 'last_operation_date'];
            if (!in_array($sortBy, $allowedSortBy)) {
                $sortBy = 'last_operation_date';
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
            Log::error('Error getting project materials', [
                'project_id' => $id,
                'error' => $e->getMessage()
            ]);
            throw new BusinessLogicException('Ошибка при получении материалов проекта.', 500);
        }
    }

    public function getProjectWorkTypes(int $id, int $perPage = 15, ?string $search = null, string $sortBy = 'created_at', string $sortDirection = 'desc'): array
    {
        $project = $this->projectRepository->find($id);
        if (!$project) {
            throw new BusinessLogicException('Проект не найден.', 404);
        }

        try {
            $query = DB::table('completed_works as cw')
                ->join('work_types as wt', 'cw.work_type_id', '=', 'wt.id')
                ->leftJoin('measurement_units as mu', 'wt.measurement_unit_id', '=', 'mu.id')
                ->leftJoin('users as u', 'cw.user_id', '=', 'u.id')
                ->where('cw.project_id', $id)
                ->select([
                    'wt.id as work_type_id',
                    'wt.name as work_type_name',
                    'wt.description as work_type_description',
                    'mu.short_name as unit',
                    DB::raw('COUNT(cw.id) as works_count'),
                    DB::raw('SUM(cw.quantity) as total_quantity'),
                    DB::raw('SUM(cw.total_amount) as total_cost'),
                    DB::raw('AVG(cw.price) as average_unit_price'),
                    DB::raw('MAX(cw.completion_date) as last_completion_date'),
                    DB::raw('COUNT(DISTINCT cw.user_id) as workers_count')
                ])
                ->groupBy(['wt.id', 'wt.name', 'wt.description', 'mu.short_name']);

            if ($search) {
                $query->where(function($q) use ($search) {
                    $q->where('wt.name', 'like', "%{$search}%")
                      ->orWhere('wt.description', 'like', "%{$search}%");
                });
            }

            $allowedSortBy = ['work_type_name', 'works_count', 'total_quantity', 'total_cost', 'last_completion_date'];
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
            throw new BusinessLogicException('Ошибка при получении видов работ проекта.', 500);
        }
    }

    /**
     * Добавить дочернюю организацию к проекту.
     */
    public function addOrganizationToProject(int $projectId, int $organizationId, Request $request): void
    {
        $project = $this->findProjectByIdForCurrentOrg($projectId, $request);
        if (!$project) {
            throw new BusinessLogicException('Проект не найден в вашей организации.', 404);
        }

        if ($project->organizations()->where('organizations.id', $organizationId)->exists()) {
            throw new BusinessLogicException('Организация уже добавлена к проекту.', 409);
        }

        $project->organizations()->attach($organizationId, ['role' => 'collaborator']);
    }

    /**
     * Удалить организацию из проекта.
     */
    public function removeOrganizationFromProject(int $projectId, int $organizationId, Request $request): void
    {
        $project = $this->findProjectByIdForCurrentOrg($projectId, $request);
        if (!$project) {
            throw new BusinessLogicException('Проект не найден в вашей организации.', 404);
        }

        if ($organizationId === $project->organization_id) {
            throw new BusinessLogicException('Нельзя удалить головную организацию проекта.', 400);
        }

        $project->organizations()->detach($organizationId);
    }

    /**
     * Получить полную информацию по проекту: финансы, статистика, разбивка по организациям.
     */
    public function getFullProjectDetails(int $projectId, Request $request): array
    {
        $project = $this->findProjectByIdForCurrentOrg($projectId, $request);
        if (!$project) {
            throw new BusinessLogicException('Проект не найден или не принадлежит вашей организации.', 404);
        }

        // Загружаем организации и контракты с актами/платежами
        $project->load([
            'organizations:id,name',
            'contracts:id,project_id,total_amount,status',
            'contracts.performanceActs:id,contract_id,amount,is_approved',
            'contracts.payments:id,contract_id,amount',
        ]);

        // Общие суммы
        $totalContractsAmount = $project->contracts->sum('total_amount');
        $totalPerformanceActsAmount = $project->contracts->flatMap(fn($c) => $c->performanceActs)->where('is_approved', true)->sum('amount');
        $totalPaymentsAmount = $project->contracts->flatMap(fn($c) => $c->payments)->sum('amount');

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