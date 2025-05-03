<?php

namespace App\Repositories;

use App\Models\WorkType;
use App\Repositories\Interfaces\WorkTypeRepositoryInterface;

class WorkTypeRepository extends BaseRepository implements WorkTypeRepositoryInterface
{
    /**
     * Конструктор репозитория видов работ
     */
    public function __construct()
    {
        parent::__construct(WorkType::class); // Передаем имя класса
    }

    /**
     * Получить активные виды работ для организации
     *
     * @param int $organizationId
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getActiveWorkTypes(int $organizationId)
    {
        return $this->model
            ->where('organization_id', $organizationId)
            ->where('is_active', true)
            ->with('measurementUnit') // Добавим ед. изм.
            ->orderBy('name')
            ->get();
    }
} 