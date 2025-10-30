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
        $query = $this->model->query();
        
        // Для project-based маршрутов: если указан список организаций проекта,
        // показываем контракты всех организаций-участников проекта
        // Это должно применяться ПЕРЕД проверкой contractor_context
        if (!empty($filters['project_organization_ids']) && is_array($filters['project_organization_ids'])) {
            $query->whereIn('organization_id', $filters['project_organization_ids']);
            \Illuminate\Support\Facades\Log::debug('ContractRepository: Applied project_organization_ids filter', [
                'project_organization_ids' => $filters['project_organization_ids']
            ]);
        } elseif (empty($filters['contractor_context'])) {
            // Если указан contractor_context - фильтруем только по contractor_id, без organization_id
            // Это нужно для подрядчиков, которые зарегистрировались и видят свои контракты
            // Обычная фильтрация по организации пользователя
            $query->where('organization_id', $organizationId);
            \Illuminate\Support\Facades\Log::debug('ContractRepository: Applied organization_id filter', [
                'organization_id' => $organizationId
            ]);
        } else {
            \Illuminate\Support\Facades\Log::debug('ContractRepository: No organization filter (contractor_context mode)');
        }

        // Основные фильтры
        if (!empty($filters['contractor_id'])) {
            $query->where('contracts.contractor_id', $filters['contractor_id']);
        }
        
        if (!empty($filters['project_id'])) {
            $query->where('contracts.project_id', $filters['project_id']);
        }
        
        if (!empty($filters['status'])) {
            if (is_array($filters['status'])) {
                $query->whereIn('contracts.status', $filters['status']);
            } else {
                $query->where('contracts.status', $filters['status']);
            }
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

        // Фильтры по датам начала/окончания работ
        if (!empty($filters['start_date_from'])) {
            $query->whereDate('contracts.start_date', '>=', $filters['start_date_from']);
        }
        if (!empty($filters['start_date_to'])) {
            $query->whereDate('contracts.start_date', '<=', $filters['start_date_to']);
        }
        if (!empty($filters['end_date_from'])) {
            $query->whereDate('contracts.end_date', '>=', $filters['end_date_from']);
        }
        if (!empty($filters['end_date_to'])) {
            $query->whereDate('contracts.end_date', '<=', $filters['end_date_to']);
        }

        // Фильтры по суммам
        if (!empty($filters['amount_from'])) {
            $query->where('contracts.total_amount', '>=', $filters['amount_from']);
        }
        if (!empty($filters['amount_to'])) {
            $query->where('contracts.total_amount', '<=', $filters['amount_to']);
        }
        
        // Фильтр по проценту ГП
        if (isset($filters['gp_percentage_from'])) {
            $query->where('contracts.gp_percentage', '>=', $filters['gp_percentage_from']);
        }
        if (isset($filters['gp_percentage_to'])) {
            $query->where('contracts.gp_percentage', '<=', $filters['gp_percentage_to']);
        }
        
        // Фильтр по категории работ
        if (!empty($filters['work_type_category'])) {
            if (is_array($filters['work_type_category'])) {
                $query->whereIn('contracts.work_type_category', $filters['work_type_category']);
            } else {
                $query->where('contracts.work_type_category', $filters['work_type_category']);
            }
        }
        
        // Фильтр по наличию аванса
        if (isset($filters['has_advance'])) {
            if ($filters['has_advance']) {
                $query->where('contracts.planned_advance_amount', '>', 0);
            } else {
                $query->where(function($q) {
                    $q->whereNull('contracts.planned_advance_amount')
                      ->orWhere('contracts.planned_advance_amount', '=', 0);
                });
            }
        }
        
        // Фильтр по статусу выплаты аванса
        if (isset($filters['advance_paid_status'])) {
            if ($filters['advance_paid_status'] === 'paid') {
                $query->whereRaw('contracts.actual_advance_amount >= contracts.planned_advance_amount')
                      ->where('contracts.planned_advance_amount', '>', 0);
            } elseif ($filters['advance_paid_status'] === 'partial') {
                $query->whereRaw('contracts.actual_advance_amount > 0 AND contracts.actual_advance_amount < contracts.planned_advance_amount')
                      ->where('contracts.planned_advance_amount', '>', 0);
            } elseif ($filters['advance_paid_status'] === 'not_paid') {
                $query->where(function($q) {
                    $q->where('contracts.actual_advance_amount', '=', 0)
                      ->orWhereNull('contracts.actual_advance_amount');
                })->where('contracts.planned_advance_amount', '>', 0);
            }
        }
        
        // Фильтр по наличию родительского контракта
        if (isset($filters['has_parent'])) {
            if ($filters['has_parent']) {
                $query->whereNotNull('contracts.parent_contract_id');
            } else {
                $query->whereNull('contracts.parent_contract_id');
            }
        }
        
        // Фильтр по наличию дочерних контрактов
        if (isset($filters['has_children'])) {
            if ($filters['has_children']) {
                $query->whereExists(function($subQuery) use ($organizationId) {
                    $subQuery->select(DB::raw(1))
                        ->from('contracts as child')
                        ->whereColumn('child.parent_contract_id', 'contracts.id')
                        ->where('child.organization_id', $organizationId)
                        ->whereNull('child.deleted_at');
                });
            } else {
                $query->whereNotExists(function($subQuery) use ($organizationId) {
                    $subQuery->select(DB::raw(1))
                        ->from('contracts as child')
                        ->whereColumn('child.parent_contract_id', 'contracts.id')
                        ->where('child.organization_id', $organizationId)
                        ->whereNull('child.deleted_at');
                });
            }
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

        // Расширенный поиск по подрядчику
        if (!empty($filters['contractor_search'])) {
            $search = $filters['contractor_search'];
            $query->whereHas('contractor', function ($contractorQuery) use ($search) {
                $contractorQuery->where(function($q) use ($search) {
                    $q->where('name', 'ilike', '%' . $search . '%')
                      ->orWhere('inn', 'like', '%' . $search . '%')
                      ->orWhere('kpp', 'like', '%' . $search . '%')
                      ->orWhere('email', 'ilike', '%' . $search . '%')
                      ->orWhere('phone', 'like', '%' . $search . '%');
                });
            });
        }
        
        // Поиск по проекту
        if (!empty($filters['project_search'])) {
            $search = $filters['project_search'];
            $query->whereHas('project', function ($projectQuery) use ($search) {
                $projectQuery->where(function($q) use ($search) {
                    $q->where('name', 'ilike', '%' . $search . '%')
                      ->orWhere('address', 'ilike', '%' . $search . '%')
                      ->orWhere('code', 'ilike', '%' . $search . '%');
                });
            });
        }
        
        // Общий поиск по номеру контракта, названию проекта и подрядчику
        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('contracts.number', 'ilike', '%' . $search . '%')
                  ->orWhere('contracts.subject', 'ilike', '%' . $search . '%')
                  ->orWhereHas('project', function ($projectQuery) use ($search) {
                      $projectQuery->where(function($pq) use ($search) {
                          $pq->where('name', 'ilike', '%' . $search . '%')
                             ->orWhere('code', 'ilike', '%' . $search . '%');
                      });
                  })
                  ->orWhereHas('contractor', function ($contractorQuery) use ($search) {
                      $contractorQuery->where(function($cq) use ($search) {
                          $cq->where('name', 'ilike', '%' . $search . '%')
                             ->orWhere('inn', 'like', '%' . $search . '%');
                      });
                  });
            });
        }

        // Eager load relationships
        $query->with([
            'contractor:id,name', 
            'project:id,name',
            'agreements:id,contract_id,change_amount' // Для расчета эффективной суммы контракта
        ]);

        // Сортировка
        $allowedSortFields = ['created_at', 'date', 'total_amount', 'number', 'status'];
        if (!in_array($sortBy, $allowedSortFields)) {
            $sortBy = 'created_at';
        }

        $query->orderBy('contracts.' . $sortBy, $sortDirection);

        // Логируем финальный запрос перед пагинацией
        \Illuminate\Support\Facades\Log::debug('ContractRepository: Before pagination', [
            'total_before_pagination' => $query->count(),
            'filters_applied' => [
                'project_organization_ids' => $filters['project_organization_ids'] ?? null,
                'project_id' => $filters['project_id'] ?? null,
                'contractor_id' => $filters['contractor_id'] ?? null,
                'status' => $filters['status'] ?? null,
            ],
            'per_page' => $perPage
        ]);

        $result = $query->paginate($perPage);
        
        \Illuminate\Support\Facades\Log::debug('ContractRepository: After pagination', [
            'total' => $result->total(),
            'count' => $result->count(),
            'current_page' => $result->currentPage(),
            'contract_ids' => $result->pluck('id')->toArray(),
        ]);

        return $result;
    }

    public function findAccessible(int $contractId, int $organizationId): ?Contract
    {
        return $this->model
            ->where('contracts.id', $contractId)
            ->where(function($q) use ($organizationId) {
                // 1. Доступ как заказчик (organization_id)
                $q->where('contracts.organization_id', $organizationId)
                  // 2. Доступ через участие в проекте
                  ->orWhereExists(function($sub) use ($organizationId) {
                      $sub->select(DB::raw(1))
                          ->from('projects as p')
                          ->join('project_organization as po', function($join) use ($organizationId) {
                              $join->on('po.project_id', '=', 'p.id')
                                   ->where('po.organization_id', $organizationId);
                          })
                          ->whereColumn('p.id', 'contracts.project_id');
                  })
                  // 3. Доступ как подрядчик (через source_organization_id)
                  ->orWhereHas('contractor', function($contractorQuery) use ($organizationId) {
                      $contractorQuery->where('source_organization_id', $organizationId);
                  });
            })
            ->with('contractor') // Нужно для проверки source_organization_id
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

    public function getContractsForOrganizations(array $orgIds, array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = $this->model->query()->whereIn('organization_id', $orgIds);

        if (!empty($filters['status'])) {
            if (is_array($filters['status'])) {
                $query->whereIn('contracts.status', $filters['status']);
            } else {
                $query->where('contracts.status', $filters['status']);
            }
        }

        if (!empty($filters['date_from'])) {
            $query->whereDate('contracts.date', '>=', $filters['date_from']);
        }

        if (!empty($filters['date_to'])) {
            $query->whereDate('contracts.date', '<=', $filters['date_to']);
        }

        if (!empty($filters['contractor_search'])) {
            $search = $filters['contractor_search'];
            $query->whereHas('contractor', function ($contractorQuery) use ($search) {
                $contractorQuery->where(function($q) use ($search) {
                    $q->where('name', 'ilike', '%' . $search . '%')
                      ->orWhere('inn', 'like', '%' . $search . '%');
                });
            });
        }

        if (!empty($filters['project_id'])) {
            $query->where('contracts.project_id', $filters['project_id']);
        }

        if (count($orgIds) > 1) {
            $query->with(['organization:id,name,is_holding', 'contractor:id,name', 'project:id,name']);
        } else {
            $query->with(['contractor:id,name', 'project:id,name']);
        }

        return $query->orderBy('contracts.date', 'desc')->paginate($perPage);
    }
} 