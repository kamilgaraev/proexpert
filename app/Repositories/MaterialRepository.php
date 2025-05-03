<?php

namespace App\Repositories;

use App\Models\Material;
use App\Repositories\Interfaces\MaterialRepositoryInterface;

class MaterialRepository extends BaseRepository implements MaterialRepositoryInterface
{
    /**
     * Конструктор репозитория материалов
     */
    public function __construct()
    {
        parent::__construct(Material::class); // Передаем имя класса
    }

    /**
     * Получить материалы для определенной организации
     *
     * @param int $organizationId
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getMaterialsForOrganization(int $organizationId)
    {
        return $this->model->where('organization_id', $organizationId)
            ->with('measurementUnit')
            ->orderBy('name')
            ->get();
    }

    /**
     * Получить материал со связанными данными
     *
     * @param int $id
     * @param array $relations Связи для загрузки
     * @return \App\Models\Material|null
     */
    public function findWithRelations(int $id, array $relations = [])
    {
        return $this->model->with($relations)->find($id);
    }

    /**
     * Получить активные материалы организации
     *
     * @param int $organizationId
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getActiveMaterials(int $organizationId)
    {
        return $this->model->where('organization_id', $organizationId)
            ->where('is_active', true)
            ->with('measurementUnit')
            ->orderBy('name')
            ->get();
    }
    
    /**
     * Получить материалы по категории
     *
     * @param int $organizationId
     * @param string $category
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getMaterialsByCategory(int $organizationId, string $category)
    {
        return $this->model->where('organization_id', $organizationId)
            ->where('category', $category)
            ->where('is_active', true)
            ->with('measurementUnit')
            ->orderBy('name')
            ->get();
    }
} 