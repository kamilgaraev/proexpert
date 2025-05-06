<?php

namespace App\Repositories;

use App\Models\Project;
use App\Repositories\Interfaces\ProjectRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;

class ProjectRepository extends BaseRepository implements ProjectRepositoryInterface
{
    /**
     * Конструктор репозитория проектов
     */
    public function __construct()
    {
        parent::__construct(Project::class); // Передаем имя класса
    }

    /**
     * Получить проекты для определенной организации
     *
     * @param int $organizationId
     * @param int $perPage
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public function getProjectsForOrganization(int $organizationId, int $perPage = 15): LengthAwarePaginator
    {
        return $this->model->where('organization_id', $organizationId)
            ->orderBy('is_archived')
            ->orderBy('status', 'desc')
            ->orderBy('name')
            ->paginate($perPage);
    }

    /**
     * Получить проекты, доступные конкретному пользователю
     *
     * @param int $userId
     * @param int|null $organizationId
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getProjectsForUser(int $userId, ?int $organizationId = null)
    {
        $query = $this->model->whereHas('users', function ($query) use ($userId) {
            $query->where('user_id', $userId);
        });

        if ($organizationId) {
            $query->where('organization_id', $organizationId);
        }

        return $query->orderBy('is_archived')
            ->orderBy('status', 'desc')
            ->orderBy('name')
            ->get();
    }

    /**
     * Получить проект со связанными данными
     *
     * @param int $id
     * @param array $relations Связи для загрузки
     * @return \App\Models\Project|null
     */
    public function findWithRelations(int $id, array $relations = [])
    {
        return $this->model->with($relations)->find($id);
    }

    /**
     * Получить активные проекты организации
     *
     * @param int $organizationId
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getActiveProjects(int $organizationId)
    {
        return $this->model->where('organization_id', $organizationId)
            ->where('status', 'active')
            ->where('is_archived', false)
            ->orderBy('name')
            ->get();
    }

    /**
     * Получить проекты для определенной организации с фильтрацией и пагинацией.
     *
     * @param int $organizationId
     * @param int $perPage
     * @param array $filters ['name' => string, 'status' => string, 'is_archived' => bool]
     * @param string $sortBy
     * @param string $sortDirection
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public function getProjectsForOrganizationPaginated(
        int $organizationId,
        int $perPage = 15,
        array $filters = [],
        string $sortBy = 'created_at',
        string $sortDirection = 'desc'
    ): LengthAwarePaginator
    {
        $query = $this->model->where('organization_id', $organizationId);

        // Применяем фильтры
        if (!empty($filters['name'])) {
            $query->where('name', 'ilike', '%' . $filters['name'] . '%');
        }
        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (isset($filters['is_archived'])) { // Проверяем именно isset, т.к. может быть false
            $query->where('is_archived', (bool)$filters['is_archived']);
        }

        // Eager load users relationship
        $query->with('users');

        // Применяем сортировку
        // Валидация sortBy нужна, чтобы предотвратить SQL-инъекции, если поле берется из запроса
        // Здесь предполагаем, что sortBy уже проверен
        $query->orderBy($sortBy, $sortDirection);

        return $query->paginate($perPage);
    }

    /**
     * Получить количество проектов по статусам для организации.
     *
     * @param int $organizationId
     * @param array $filters ['is_archived' => bool, 'status' => string]
     * @return Collection Ключ - статус, значение - количество.
     */
    public function getProjectCountsByStatus(int $organizationId, array $filters = []): Collection
    {
        $query = $this->model
            ->select('status', DB::raw('count(*) as count'))
            ->where('organization_id', $organizationId);

        // Фильтр по статусу (если передан)
        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        // Фильтр по архиву (если передан)
        if (isset($filters['is_archived'])) {
            $query->where('is_archived', (bool)$filters['is_archived']);
        }

        return $query->groupBy('status')
                    ->orderBy('status') // Опционально, для упорядоченного вывода
                    ->pluck('count', 'status'); // Возвращает коллекцию [status => count]
    }
} 