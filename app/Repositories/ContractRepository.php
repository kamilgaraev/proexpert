<?php

namespace App\Repositories;

use App\Models\Contract;
use App\Repositories\Interfaces\ContractRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;

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
            $query->where('contracts.contractor_id', $filters['contractor_id']);
        }
        if (!empty($filters['project_id'])) {
            $query->where('contracts.project_id', $filters['project_id']);
        }
        if (!empty($filters['status'])) {
            $query->where('contracts.status', $filters['status']);
        }
        if (!empty($filters['number'])) {
            $query->where('contracts.number', 'ilike', '%' . $filters['number'] . '%');
        }
        if (!empty($filters['date_from'])) {
            $query->whereDate('contracts.date', '>=', $filters['date_from']);
        }
        if (!empty($filters['date_to'])) {
            $query->whereDate('contracts.date', '<=', $filters['date_to']);
        }

        // Фильтры по суммам
        if (!empty($filters['amount_from'])) {
            $query->where('contracts.total_amount', '>=', $filters['amount_from']);
        }
        if (!empty($filters['amount_to'])) {
            $query->where('contracts.total_amount', '<=', $filters['amount_to']);
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
                    $qq->where('contracts.end_date', '<', now())
                       ->where('contracts.status', 'active');
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
            $query->where('contracts.end_date', '<', now())
                  ->where('contracts.status', 'active');
        }

        // Поиск по номеру или названию проекта
        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('contracts.number', 'ilike', '%' . $search . '%')
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

        $query->orderBy('contracts.' . $sortBy, $sortDirection);

        return $query->paginate($perPage);
    }

    public function findAccessible(int $contractId, int $organizationId): ?Contract
    {
        return $this->model
            ->where('contracts.id', $contractId)
            ->where(function($q) use ($organizationId) {
                $q->where('contracts.organization_id', $organizationId)
                  ->orWhereExists(function($sub) use ($organizationId) {
                      $sub->select(DB::raw(1))
                          ->from('projects as p')
                          ->join('project_organization as po', function($join) use ($organizationId) {
                              $join->on('po.project_id', '=', 'p.id')
                                   ->where('po.organization_id', $organizationId);
                          })
                          ->whereColumn('p.id', 'contracts.project_id');
                  });
            })
            ->first();
    }

    /**
     * Получить статистику по выполненным работам контракта
     */
    public function getContractWorksStatistics(int $contractId): array
    {
        $stats = DB::table('completed_works')
            ->where('contract_id', $contractId)
            ->selectRaw('
                status,
                COUNT(*) as count,
                SUM(total_amount) as total_amount,
                AVG(total_amount) as avg_amount
            ')
            ->groupBy('status')
            ->get()
            ->keyBy('status');

        return [
            'pending' => [
                'count' => $stats->get('pending')?->count ?? 0,
                'amount' => (float) ($stats->get('pending')?->total_amount ?? 0),
                'avg_amount' => (float) ($stats->get('pending')?->avg_amount ?? 0),
            ],
            'confirmed' => [
                'count' => $stats->get('confirmed')?->count ?? 0,
                'amount' => (float) ($stats->get('confirmed')?->total_amount ?? 0),
                'avg_amount' => (float) ($stats->get('confirmed')?->avg_amount ?? 0),
            ],
            'rejected' => [
                'count' => $stats->get('rejected')?->count ?? 0,
                'amount' => (float) ($stats->get('rejected')?->total_amount ?? 0),
                'avg_amount' => (float) ($stats->get('rejected')?->avg_amount ?? 0),
            ],
        ];
    }

    /**
     * Получить последние выполненные работы по контракту
     */
    public function getRecentCompletedWorks(int $contractId, int $limit = 10): Collection
    {
        return DB::table('completed_works')
            ->join('work_types', 'completed_works.work_type_id', '=', 'work_types.id')
            ->join('users', 'completed_works.user_id', '=', 'users.id')
            ->leftJoin('completed_work_materials', 'completed_works.id', '=', 'completed_work_materials.completed_work_id')
            ->where('completed_works.contract_id', $contractId)
            ->select([
                'completed_works.id',
                'work_types.name as work_type_name',
                'users.name as user_name',
                'completed_works.quantity',
                'completed_works.total_amount',
                'completed_works.status',
                'completed_works.completion_date',
                DB::raw('COUNT(completed_work_materials.id) as materials_count'),
                DB::raw('COALESCE(SUM(completed_work_materials.total_amount), 0) as materials_amount')
            ])
            ->groupBy([
                'completed_works.id',
                'work_types.name',
                'users.name',
                'completed_works.quantity',
                'completed_works.total_amount',
                'completed_works.status',
                'completed_works.completion_date'
            ])
            ->orderBy('completed_works.completion_date', 'desc')
            ->limit($limit)
            ->get();
    }

    /**
     * Получить все выполненные работы по контракту
     */
    public function getAllCompletedWorks(int $contractId): Collection
    {
        return DB::table('completed_works')
            ->join('work_types', 'completed_works.work_type_id', '=', 'work_types.id')
            ->join('users', 'completed_works.user_id', '=', 'users.id')
            ->leftJoin('completed_work_materials', 'completed_works.id', '=', 'completed_work_materials.completed_work_id')
            ->where('completed_works.contract_id', $contractId)
            ->select([
                'completed_works.id',
                'work_types.name as work_type_name',
                'users.name as user_name',
                'completed_works.quantity',
                'completed_works.total_amount',
                'completed_works.status',
                'completed_works.completion_date',
                DB::raw('COUNT(completed_work_materials.id) as materials_count'),
                DB::raw('COALESCE(SUM(completed_work_materials.total_amount), 0) as materials_amount')
            ])
            ->groupBy([
                'completed_works.id',
                'work_types.name',
                'users.name',
                'completed_works.quantity',
                'completed_works.total_amount',
                'completed_works.status',
                'completed_works.completion_date'
            ])
            ->orderBy('completed_works.completion_date', 'desc')
            ->get();
    }
} 