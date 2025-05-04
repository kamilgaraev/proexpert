<?php

namespace App\Repositories\Interfaces;

use App\Repositories\RepositoryInterface;
use Illuminate\Support\Collection;

interface ProjectRepositoryInterface extends RepositoryInterface
{
    /**
     * Получить проекты для определенной организации
     *
     * @param int $organizationId
     * @param int $perPage Количество элементов на странице
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public function getProjectsForOrganization(int $organizationId, int $perPage = 15);

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
    );

    /**
     * Получить проекты, доступные конкретному пользователю
     *
     * @param int $userId
     * @param int|null $organizationId
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getProjectsForUser(int $userId, ?int $organizationId = null);
    
    /**
     * Получить проект со связанными данными
     *
     * @param int $id
     * @param array $relations Связи для загрузки
     * @return \App\Models\Project|null
     */
    public function findWithRelations(int $id, array $relations = []);
    
    /**
     * Получить активные проекты организации
     *
     * @param int $organizationId
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getActiveProjects(int $organizationId);

    /**
     * Получить количество проектов по статусам для организации.
     *
     * @param int $organizationId
     * @param array $filters ['is_archived' => bool, 'status' => string]
     * @return Collection Ключ - статус, значение - количество.
     */
    public function getProjectCountsByStatus(int $organizationId, array $filters = []): Collection;
} 