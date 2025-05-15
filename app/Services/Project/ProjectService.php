<?php

namespace App\Services\Project;

use App\Repositories\Interfaces\ProjectRepositoryInterface;
use App\Repositories\Interfaces\UserRepositoryInterface;
use App\Models\Project;
use App\Models\User;
use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Collection;
use App\Exceptions\BusinessLogicException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Log;
use App\DTOs\Project\ProjectDTO;

class ProjectService
{
    protected ProjectRepositoryInterface $projectRepository;
    protected UserRepositoryInterface $userRepository;

    public function __construct(
        ProjectRepositoryInterface $projectRepository,
        UserRepositoryInterface $userRepository
    ) {
        $this->projectRepository = $projectRepository;
        $this->userRepository = $userRepository;
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
        
        return $this->projectRepository->create($dataToCreate);
    }

    public function findProjectByIdForCurrentOrg(int $id, Request $request): ?Project
    {
        $organizationId = $this->getCurrentOrgId($request);
        $project = $this->projectRepository->find($id);

        if (!$project || $project->organization_id !== $organizationId) {
            return null;
        }
        return $project;
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
            || !$user->hasRole(Role::ROLE_FOREMAN, $organizationId) 
            || !$user->organizations()->where('organization_user.organization_id', $organizationId)->exists()
           ) { 
            throw new BusinessLogicException('Пользователь не найден, неактивен или не является прорабом в вашей организации.', 404);
        }

        try {
            $project->users()->attach($userId);
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
        // TODO: Реализовать получение статистики по проекту
        Log::warning("Method getProjectStatistics called but not fully implemented.", ['project_id' => $id]);
        return ['message' => 'Statistics not available yet.']; 
    }

    public function getProjectMaterials(int $id, int $perPage = 15, ?string $search = null, string $sortBy = 'created_at', string $sortDirection = 'desc'): array
    {
        // TODO: Реализовать получение материалов по проекту
        Log::warning("Method getProjectMaterials called but not fully implemented.", ['project_id' => $id]);
        // В идеале, если есть связь, можно было бы сделать $project->materials()->paginate(...)
        // Пока возвращаем пустой массив, имитируя пагинированный ответ
        return [
            'data' => [],
            'links' => [],
            'meta' => []
        ];
    }

    public function getProjectWorkTypes(int $id, int $perPage = 15, ?string $search = null, string $sortBy = 'created_at', string $sortDirection = 'desc'): array
    {
        // TODO: Реализовать получение видов работ по проекту
        Log::warning("Method getProjectWorkTypes called but not fully implemented.", ['project_id' => $id]);
        // Аналогично getProjectMaterials
        return [
            'data' => [],
            'links' => [],
            'meta' => []
        ];
    }
} 