<?php

namespace App\Repositories\CompletedWork;

use App\Models\CompletedWork;
use App\Repositories\BaseRepository;
use App\Repositories\Interfaces\CompletedWorkRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class CompletedWorkRepository extends BaseRepository implements CompletedWorkRepositoryInterface
{
    public function __construct()
    {
        parent::__construct(CompletedWork::class);
    }

    public function getAllPaginated(array $filters = [], int $perPage = 15, string $sortBy = 'id', string $sortDirection = 'asc', array $relations = []): \Illuminate\Pagination\LengthAwarePaginator
    {
        $query = $this->model->query();

        foreach ($filters as $key => $value) {
            if ($value === null || $value === '' || $value === '0' || $value === 0) {
                continue;
            }

            switch ($key) {
                case 'search':
                    $query->where(function ($q) use ($value) {
                        $q->where('notes', 'ILIKE', "%{$value}%")
                          ->orWhereHas('project', function ($projectQuery) use ($value) {
                              $projectQuery->where('name', 'ILIKE', "%{$value}%");
                          })
                          ->orWhereHas('workType', function ($workTypeQuery) use ($value) {
                              $workTypeQuery->where('name', 'ILIKE', "%{$value}%");
                          })
                          ->orWhereHas('user', function ($userQuery) use ($value) {
                              $userQuery->where('name', 'ILIKE', "%{$value}%");
                          });
                    });
                    break;
                
                case 'organization_id':
                    $query->where('organization_id', $value);
                    break;
                
                case 'project_id':
                    $query->where('project_id', $value);
                    break;
                
                case 'contract_id':
                    $query->where('contract_id', $value);
                    break;
                
                case 'work_type_id':
                    $query->where('work_type_id', $value);
                    break;
                
                case 'user_id':
                    $query->where('user_id', $value);
                    break;
                
                case 'status':
                    $query->where('status', $value);
                    break;
                
                case 'completion_date_from':
                    $query->where('completion_date', '>=', $value);
                    break;
                
                case 'completion_date_to':
                    $query->where('completion_date', '<=', $value);
                    break;
                
                default:
                    if (in_array($key, ['quantity', 'price', 'total_amount'])) {
                        $query->where($key, $value);
                    }
                    break;
            }
        }

        $query->with($relations)->orderBy($sortBy, $sortDirection);
        return $query->paginate($perPage);
    }

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