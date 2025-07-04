<?php

namespace App\Repositories;

use App\Repositories\Interfaces\BaseRepositoryInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

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

    public function getAllPaginated(array $filters = [], int $perPage = 15, string $sortBy = 'id', string $sortDirection = 'asc', array $relations = []): LengthAwarePaginator
    {
        $query = $this->model->query();

        // Простая обработка фильтров: [поле, оператор, значение] или [поле, значение]
        // или ассоциативный массив [поле => значение]
        foreach ($filters as $key => $filter) {
            // Формат: [['field','op','value'], ['field','value']] или ['field' => 'value']
            if (is_array($filter)) {
                $count = count($filter);
                if ($count === 3) {
                    $query->where($filter[0], $filter[1], $filter[2]);
                } elseif ($count === 2) {
                    $query->where($filter[0], $filter[1]);
                }
            } elseif (is_string($key)) {
                $query->where($key, $filter);
            }
        }
        
        $query->with($relations)->orderBy($sortBy, $sortDirection);
        return $query->paginate($perPage);
    }

    public function find(int $modelId, array $columns = ['*'], array $relations = [], array $appends = []): ?Model
    {
        return $this->model->with($relations)->find($modelId, $columns)?->append($appends);
    }

    public function firstByFilters(array $filters, array $columns = ['*'], array $relations = [], array $appends = []): ?Model
    {
        $query = $this->model->query();
        
        // Простая обработка фильтров: [поле, оператор, значение] или [поле, значение]
        // или ассоциативный массив [поле => значение]
        foreach ($filters as $key => $filter) {
            if (is_array($filter)) {
                $count = count($filter);
                if ($count === 3) {
                    $query->where($filter[0], $filter[1], $filter[2]);
                } elseif ($count === 2) {
                    $query->where($filter[0], $filter[1]);
                }
            } elseif (is_string($key)) {
                $query->where($key, $filter);
            }
        }
        return $query->with($relations)->first($columns)?->append($appends);
    }

    public function create(array $payload): ?Model
    {
        $model = $this->model->create($payload);
        return $model->fresh();
    }

    public function update(int $modelId, array $payload): bool
    {
        $model = $this->find($modelId);
        if (!$model) {
            return false;
        }
        return $model->update($payload);
    }

    public function delete(int $modelId): bool
    {
        return $this->find($modelId)?->delete() ?? false;
    }
} 