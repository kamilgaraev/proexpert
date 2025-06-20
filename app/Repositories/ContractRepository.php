<?php

namespace App\Repositories;

use App\Models\Contract;
use App\Repositories\Interfaces\ContractRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class ContractRepository extends BaseRepository implements ContractRepositoryInterface
{
    public function __construct()
    {
        parent::__construct(Contract::class);
    }

    public function getContractsForOrganizationPaginated(
        int $organizationId,
        int $perPage = 15,
        array $filters = [],
        string $sortBy = 'date',
        string $sortDirection = 'desc'
    ): LengthAwarePaginator
    {
        $query = $this->model->query()->where('organization_id', $organizationId);

        // Основные фильтры
        if (!empty($filters['contractor_id'])) {
            $query->where('contractor_id', $filters['contractor_id']);
        }
        if (!empty($filters['project_id'])) {
            $query->where('project_id', $filters['project_id']);
        }
        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (!empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }
        if (!empty($filters['number'])) {
            $query->where('number', 'ilike', '%' . $filters['number'] . '%');
        }
        if (!empty($filters['date_from'])) {
            $query->whereDate('date', '>=', $filters['date_from']);
        }
        if (!empty($filters['date_to'])) {
            $query->whereDate('date', '<=', $filters['date_to']);
        }

        // Фильтры по суммам
        if (!empty($filters['amount_from'])) {
            $query->where('total_amount', '>=', $filters['amount_from']);
        }
        if (!empty($filters['amount_to'])) {
            $query->where('total_amount', '<=', $filters['amount_to']);
        }

        // Фильтры по проценту выполнения (через подзапрос)
        if (!empty($filters['completion_from']) || !empty($filters['completion_to'])) {
            $query->whereHas('completedWorks', function ($subQuery) use ($filters) {
                // Подсчет процента выполнения через join с completed_works
            });
        }

        // Фильтр контрактов требующих внимания
        if (!empty($filters['requiring_attention'])) {
            $query->where(function ($q) {
                // Контракты близкие к завершению (90%+) или просроченные
                $q->whereRaw('
                    (
                        SELECT COALESCE(SUM(total_amount), 0) 
                        FROM completed_works 
                        WHERE contract_id = contracts.id 
                        AND status = ? 
                        AND deleted_at IS NULL
                    ) / NULLIF(total_amount, 0) * 100 >= 90
                ', ['confirmed'])
                ->orWhere(function ($qq) {
                    $qq->where('end_date', '<', now())
                       ->where('status', 'active');
                });
            });
        }

        // Фильтр приближающихся к лимиту
        if (!empty($filters['is_nearing_limit'])) {
            $query->whereRaw('
                (
                    SELECT COALESCE(SUM(total_amount), 0) 
                    FROM completed_works 
                    WHERE contract_id = contracts.id 
                    AND status = ? 
                    AND deleted_at IS NULL
                ) / NULLIF(total_amount, 0) * 100 >= 90
            ', ['confirmed']);
        }

        // Фильтр просроченных контрактов
        if (!empty($filters['is_overdue'])) {
            $query->where('end_date', '<', now())
                  ->where('status', 'active');
        }

        // Поиск по номеру или названию проекта
        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('number', 'ilike', '%' . $search . '%')
                  ->orWhereHas('project', function ($projectQuery) use ($search) {
                      $projectQuery->where('name', 'ilike', '%' . $search . '%');
                  })
                  ->orWhereHas('contractor', function ($contractorQuery) use ($search) {
                      $contractorQuery->where('name', 'ilike', '%' . $search . '%');
                  });
            });
        }

        // Eager load relationships
        $query->with(['contractor:id,name', 'project:id,name']);

        // Сортировка
        $allowedSortFields = ['created_at', 'date', 'total_amount', 'number', 'status'];
        if (!in_array($sortBy, $allowedSortFields)) {
            $sortBy = 'created_at';
        }

        $query->orderBy($sortBy, $sortDirection);

        return $query->paginate($perPage);
    }
} 