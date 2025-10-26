<?php

namespace App\Repositories;

use App\Repositories\Interfaces\BaseRepositoryInterface;
use App\Services\Logging\LoggingService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Pagination\LengthAwarePaginator;

class BaseRepository implements BaseRepositoryInterface
{
    protected Model $model;
    protected LoggingService $logging;

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
        
        $this->logging = app(LoggingService::class);
    }

    public function getAll(array $columns = ['*'], array $relations = []): Collection
    {
        return $this->model->with($relations)->get($columns);
    }

    public function getAllPaginated(array $filters = [], int $perPage = 15, string $sortBy = 'id', string $sortDirection = 'asc', array $relations = []): LengthAwarePaginator
    {
        $startTime = microtime(true);
        $modelClass = get_class($this->model);
        
        $this->logging->technical('repository.paginated.started', [
            'model' => $modelClass,
            'filters_count' => count($filters),
            'per_page' => $perPage,
            'sort_by' => $sortBy,
            'relations_count' => count($relations)
        ]);
        
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
        
        $queryStart = microtime(true);
        $result = $query->paginate($perPage);
        $queryDuration = (microtime(true) - $queryStart) * 1000;
        
        $totalDuration = (microtime(true) - $startTime) * 1000;
        
        $this->logging->technical('repository.paginated.completed', [
            'model' => $modelClass,
            'total_records' => $result->total(),
            'query_duration_ms' => $queryDuration,
            'total_duration_ms' => $totalDuration,
            'filters_count' => count($filters),
            'relations_count' => count($relations)
        ]);
        
        if ($totalDuration > 1000) {
            $this->logging->technical('repository.paginated.slow', [
                'model' => $modelClass,
                'total_duration_ms' => $totalDuration,
                'total_records' => $result->total(),
                'filters_count' => count($filters)
            ], 'warning');
        }
        
        return $result;
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
        $startTime = microtime(true);
        $modelClass = get_class($this->model);
        
        $this->logging->business('repository.create.started', [
            'model' => $modelClass,
            'payload_fields' => array_keys($payload)
        ]);
        
        try {
            $model = $this->model->create($payload);
            $result = $model->fresh();
            
            $duration = (microtime(true) - $startTime) * 1000;
            
            $this->logging->business('repository.create.completed', [
                'model' => $modelClass,
                'record_id' => $result?->id,
                'duration_ms' => $duration
            ]);
            
            if ($duration > 500) {
                $this->logging->technical('repository.create.slow', [
                    'model' => $modelClass,
                    'duration_ms' => $duration,
                    'payload_size' => count($payload)
                ], 'warning');
            }
            
            return $result;
            
        } catch (\Exception $e) {
            $duration = (microtime(true) - $startTime) * 1000;
            
            $this->logging->technical('repository.create.failed', [
                'model' => $modelClass,
                'error' => $e->getMessage(),
                'duration_ms' => $duration
            ], 'error');
            
            throw $e;
        }
    }

    public function update(int $modelId, array $payload): bool
    {
        $startTime = microtime(true);
        $modelClass = get_class($this->model);
        
        Log::info('BaseRepository::update - START', [
            'model' => $modelClass,
            'record_id' => $modelId,
            'payload_keys' => array_keys($payload),
            'payload_total_amount' => $payload['total_amount'] ?? 'NOT SET'
        ]);
        
        $this->logging->business('repository.update.started', [
            'model' => $modelClass,
            'record_id' => $modelId,
            'payload_fields' => array_keys($payload)
        ]);
        
        try {
            $model = $this->find($modelId);
            if (!$model) {
                $this->logging->business('repository.update.not_found', [
                    'model' => $modelClass,
                    'record_id' => $modelId
                ], 'warning');
                return false;
            }
            
            Log::info('BaseRepository::update - BEFORE UPDATE', [
                'model' => $modelClass,
                'record_id' => $modelId,
                'current_total_amount' => $model->total_amount ?? 'NOT SET',
                'payload_total_amount' => $payload['total_amount'] ?? 'NOT SET'
            ]);
            
            $result = $model->update($payload);
            
            Log::info('BaseRepository::update - AFTER UPDATE', [
                'model' => $modelClass,
                'record_id' => $modelId,
                'result' => $result,
                'new_total_amount' => $model->fresh()->total_amount ?? 'NOT SET'
            ]);
            
            $duration = (microtime(true) - $startTime) * 1000;
            
            $this->logging->business('repository.update.completed', [
                'model' => $modelClass,
                'record_id' => $modelId,
                'success' => $result,
                'duration_ms' => $duration
            ]);
            
            if ($duration > 500) {
                $this->logging->technical('repository.update.slow', [
                    'model' => $modelClass,
                    'record_id' => $modelId,
                    'duration_ms' => $duration
                ], 'warning');
            }
            
            return $result;
            
        } catch (\Exception $e) {
            $duration = (microtime(true) - $startTime) * 1000;
            
            $this->logging->technical('repository.update.failed', [
                'model' => $modelClass,
                'record_id' => $modelId,
                'error' => $e->getMessage(),
                'duration_ms' => $duration
            ], 'error');
            
            throw $e;
        }
    }

    public function delete(int $modelId): bool
    {
        $startTime = microtime(true);
        $modelClass = get_class($this->model);
        
        $this->logging->business('repository.delete.started', [
            'model' => $modelClass,
            'record_id' => $modelId
        ]);
        
        try {
            $result = $this->find($modelId)?->delete() ?? false;
            $duration = (microtime(true) - $startTime) * 1000;
            
            $this->logging->business('repository.delete.completed', [
                'model' => $modelClass,
                'record_id' => $modelId,
                'success' => $result,
                'duration_ms' => $duration
            ]);
            
            if (!$result) {
                $this->logging->business('repository.delete.not_found', [
                    'model' => $modelClass,
                    'record_id' => $modelId
                ], 'warning');
            }
            
            return $result;
            
        } catch (\Exception $e) {
            $duration = (microtime(true) - $startTime) * 1000;
            
            $this->logging->technical('repository.delete.failed', [
                'model' => $modelClass,
                'record_id' => $modelId,
                'error' => $e->getMessage(),
                'duration_ms' => $duration
            ], 'error');
            
            throw $e;
        }
    }
} 