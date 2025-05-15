<?php

namespace App\Repositories\CompletedWork;

use App\Models\CompletedWork;
use App\Repositories\BaseRepository;
use App\Repositories\Interfaces\CompletedWorkRepositoryInterface;
// LengthAwarePaginator не нужен, если метод getAllPaginated унаследован

class CompletedWorkRepository extends BaseRepository implements CompletedWorkRepositoryInterface
{
    public function __construct()
    {
        parent::__construct(CompletedWork::class);
    }

    // Метод getAllPaginated будет унаследован из BaseRepository.
    // Его сигнатура: getAllPaginated(array $filters = [], int $perPage = 15, string $sortBy = 'id', string $sortDirection = 'asc', array $relations = [])

    public function findById(int $id, int $organizationId): ?CompletedWork
    {
        // Важно: $this->model->where(...) вернет Builder.
        // first() вернет ?Model. Нужно убедиться, что возвращается именно ?CompletedWork.
        // В данном случае это будет так, т.к. $this->model это экземпляр CompletedWork.
        /** @var ?CompletedWork */
        return $this->model->where('id', $id)
                           ->where('organization_id', $organizationId)
                           ->first();
    }

    // Методы create, update, delete наследуются из BaseRepository.
    // Их сигнатуры:
    // create(array $payload): ?Model
    // update(int $modelId, array $payload): bool
    // delete(int $modelId): bool
} 