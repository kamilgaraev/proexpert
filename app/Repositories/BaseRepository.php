<?php

namespace App\Repositories;

use App\Repositories\Interfaces\BaseRepositoryInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class BaseRepository implements BaseRepositoryInterface
{
    protected Model $model;

    /**
     * BaseRepository constructor.
     *
     * @param string $modelClass The Eloquent model class name.
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     */
    public function __construct(string $modelClass)
    {
        $this->model = app()->make($modelClass); // Используем DI контейнер для создания модели
        if (!$this->model instanceof Model) { // Доп. проверка
             throw new \InvalidArgumentException("Class {$modelClass} must be an instance of Illuminate\Database\Eloquent\Model");
        }
    }

    public function getAll(array $columns = ['*'], array $relations = []): Collection
    {
        return $this->model->with($relations)->get($columns);
    }

    public function findById(int $modelId, array $columns = ['*'], array $relations = [], array $appends = []): ?Model
    {
        return $this->model->with($relations)->find($modelId, $columns)?->append($appends);
    }

    public function create(array $payload): ?Model
    {
        $model = $this->model->create($payload);
        return $model->fresh();
    }

    public function update(int $modelId, array $payload): bool
    {
        $model = $this->findById($modelId);
        if (!$model) {
            return false;
        }
        return $model->update($payload);
    }

    public function deleteById(int $modelId): bool
    {
        return $this->findById($modelId)?->delete() ?? false;
    }
} 