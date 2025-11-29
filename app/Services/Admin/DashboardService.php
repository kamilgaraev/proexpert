<?php

namespace App\Services\Admin;

use App\Repositories\UserRepository;
use App\Repositories\ProjectRepository;
use App\Repositories\MaterialRepository;
use App\Repositories\SupplierRepository;
use App\Repositories\ContractRepository;
use App\Repositories\CompletedWork\CompletedWorkRepository;
use App\Services\Billing\SubscriptionLimitsService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use App\Models\User;
use App\Models\Project;
use App\Models\Material;
use App\Models\Supplier;
use App\Models\Contract;
use App\Models\CompletedWork;
use App\Models\Contractor;
use App\Enums\Contract\ContractStatusEnum;
use Carbon\Carbon;

class DashboardService
{
    protected UserRepository $userRepository;
    protected ProjectRepository $projectRepository;
    protected MaterialRepository $materialRepository;
    protected SupplierRepository $supplierRepository;
    protected ContractRepository $contractRepository;
    protected CompletedWorkRepository $completedWorkRepository;
    protected SubscriptionLimitsService $subscriptionLimitsService;

    private const CACHE_TTL_SHORT = 300; // 5 минут
    private const CACHE_TTL_MEDIUM = 600; // 10 минут
    private const CACHE_TTL_LONG = 900; // 15 минут

    public function __construct(
        UserRepository $userRepository,
        ProjectRepository $projectRepository,
        MaterialRepository $materialRepository,
        SupplierRepository $supplierRepository,
        ContractRepository $contractRepository,
        CompletedWorkRepository $completedWorkRepository,
        SubscriptionLimitsService $subscriptionLimitsService
    ) {
        $this->userRepository = $userRepository;
        $this->projectRepository = $projectRepository;
        $this->materialRepository = $materialRepository;
        $this->supplierRepository = $supplierRepository;
        $this->contractRepository = $contractRepository;
        $this->completedWorkRepository = $completedWorkRepository;
        $this->subscriptionLimitsService = $subscriptionLimitsService;
    }

    /**
     * Получить сводную информацию по проекту с сравнением периодов и финансовыми метриками
     */
    public function getSummary(int $organizationId, int $projectId): array
    {
        $cacheKey = "dashboard_summary_{$organizationId}_{$projectId}";
        $tags = $this->getCacheTags($organizationId, $projectId);

        return $this->remember($cacheKey, $tags, self::CACHE_TTL_SHORT, function () use ($organizationId, $projectId) {
            $currentPeriod = Carbon::now()->startOfMonth();
            $previousPeriod = $currentPeriod->copy()->subMonth();

            // Текущий период
            $current = $this->calculateSummaryForPeriod($organizationId, $projectId, $currentPeriod, Carbon::now());
            
            // Предыдущий период
            $previous = $this->calculateSummaryForPeriod($organizationId, $projectId, $previousPeriod, $previousPeriod->copy()->endOfMonth());

            // Финансовые метрики
            $financial = $this->calculateFinancialMetrics($organizationId, $projectId);

            return [
                'summary' => [
                    'contracts' => [
                        'total' => $current['contracts']['total'],
                        'active' => $current['contracts']['active'],
                        'completed' => $current['contracts']['completed'],
                        'draft' => $current['contracts']['draft'],
                        'total_amount' => $current['contracts']['total_amount'],
                        'completed_amount' => $current['contracts']['completed_amount'],
                        'completion_percentage' => $current['contracts']['completion_percentage'],
                        'change_from_previous' => [
                            'total' => $current['contracts']['total'] - $previous['contracts']['total'],
                            'percentage' => $this->calculatePercentageChange(
                                $previous['contracts']['total'],
                                $current['contracts']['total']
                            ),
                        ],
                    ],
                    'projects' => [
                        'total' => 1, // Один конкретный проект
                        'users_count' => $current['users_count'],
                        'completion_percentage' => $current['project_completion'],
                    ],
                    'materials' => [
                        'total' => $current['materials']['total'],
                        'change_from_previous' => [
                            'total' => $current['materials']['total'] - $previous['materials']['total'],
                        ],
                    ],
                    'suppliers' => [
                        'total' => $current['suppliers']['total'],
                    ],
                    'completed_works' => [
                        'total' => $current['completed_works']['total'],
                        'confirmed' => $current['completed_works']['confirmed'],
                        'total_amount' => $current['completed_works']['total_amount'],
                        'change_from_previous' => [
                            'total' => $current['completed_works']['total'] - $previous['completed_works']['total'],
                        ],
                    ],
                    'financial' => $financial,
                ],
                'period' => [
                    'current' => $currentPeriod->format('Y-m'),
                    'previous' => $previousPeriod->format('Y-m'),
                ],
            ];
        });
    }

    /**
     * Рассчитать сводку за период
     */
    private function calculateSummaryForPeriod(int $organizationId, int $projectId, Carbon $start, Carbon $end): array
    {
        // Контракты
        $contractsQuery = Contract::where('organization_id', $organizationId)
            ->where('project_id', $projectId)
            ->whereBetween('created_at', [$start, $end]);

        $contractsTotal = (clone $contractsQuery)->count();
        $contractsActive = (clone $contractsQuery)->where('status', ContractStatusEnum::ACTIVE->value)->count();
        $contractsCompleted = (clone $contractsQuery)->where('status', ContractStatusEnum::COMPLETED->value)->count();
        $contractsDraft = (clone $contractsQuery)->where('status', ContractStatusEnum::DRAFT->value)->count();

        // Суммы контрактов
        $contractsAmount = Contract::where('organization_id', $organizationId)
            ->where('project_id', $projectId)
            ->sum('total_amount');

        // Выполненные работы
        $completedWorksAmount = DB::table('completed_works')
            ->where('organization_id', $organizationId)
            ->where('project_id', $projectId)
            ->where('status', 'confirmed')
            ->whereBetween('created_at', [$start, $end])
            ->sum('total_amount');

        $completedWorksTotal = CompletedWork::where('organization_id', $organizationId)
            ->where('project_id', $projectId)
            ->whereBetween('created_at', [$start, $end])
            ->count();

        $completedWorksConfirmed = CompletedWork::where('organization_id', $organizationId)
            ->where('project_id', $projectId)
            ->where('status', 'confirmed')
            ->whereBetween('created_at', [$start, $end])
            ->count();

        // Процент выполнения контрактов
        $completionPercentage = $contractsAmount > 0 
            ? round(($completedWorksAmount / $contractsAmount) * 100, 2) 
            : 0.0;

        // Участники проекта
        $usersCount = DB::table('project_user')
            ->where('project_id', $projectId)
            ->distinct('user_id')
            ->count('user_id');

        // Материалы
        $materialsTotal = Material::where('organization_id', $organizationId)
            ->whereBetween('created_at', [$start, $end])
            ->count();

        // Поставщики
        $suppliersTotal = Supplier::where('organization_id', $organizationId)
            ->whereBetween('created_at', [$start, $end])
            ->count();

        // Процент выполнения проекта (упрощенный расчет)
        $projectCompletion = $this->calculateProjectCompletion($organizationId, $projectId);

        return [
            'contracts' => [
                'total' => $contractsTotal,
                'active' => $contractsActive,
                'completed' => $contractsCompleted,
                'draft' => $contractsDraft,
                'total_amount' => (float) $contractsAmount,
                'completed_amount' => (float) $completedWorksAmount,
                'completion_percentage' => $completionPercentage,
            ],
            'users_count' => $usersCount,
            'project_completion' => $projectCompletion,
            'materials' => [
                'total' => $materialsTotal,
            ],
            'suppliers' => [
                'total' => $suppliersTotal,
            ],
            'completed_works' => [
                'total' => $completedWorksTotal,
                'confirmed' => $completedWorksConfirmed,
                'total_amount' => (float) $completedWorksAmount,
            ],
        ];
    }

    /**
     * Рассчитать финансовые метрики
     */
    private function calculateFinancialMetrics(int $organizationId, int $projectId): array
    {
        $contractsAmount = Contract::where('organization_id', $organizationId)
            ->where('project_id', $projectId)
            ->sum('total_amount');

        $completedWorksAmount = DB::table('completed_works')
            ->where('organization_id', $organizationId)
            ->where('project_id', $projectId)
            ->where('status', 'confirmed')
            ->sum('total_amount');

        $contractsCount = Contract::where('organization_id', $organizationId)
            ->where('project_id', $projectId)
            ->count();

        return [
            'total_contracts_amount' => (float) $contractsAmount,
            'completed_works_amount' => (float) $completedWorksAmount,
            'remaining_amount' => max(0, (float) $contractsAmount - (float) $completedWorksAmount),
            'average_contract_amount' => $contractsCount > 0 
                ? round((float) $contractsAmount / $contractsCount, 2) 
                : 0.0,
        ];
    }

    /**
     * Рассчитать процент выполнения проекта
     */
    private function calculateProjectCompletion(int $organizationId, int $projectId): float
    {
        $contracts = Contract::where('organization_id', $organizationId)
            ->where('project_id', $projectId)
            ->where('is_fixed_amount', true)
            ->get();

        if ($contracts->isEmpty()) {
            return 0.0;
        }

        $totalCompletion = $contracts->sum(function ($contract) {
            return $contract->completion_percentage ?? 0.0;
        });

        return round($totalCompletion / $contracts->count(), 2);
    }

    /**
     * Рассчитать процент изменения
     */
    private function calculatePercentageChange(float $old, float $new): float
    {
        if ($old == 0) {
            return $new > 0 ? 100.0 : 0.0;
        }
        return round((($new - $old) / $old) * 100, 2);
    }

    /**
     * Получить временной ряд по выбранной метрике с правильной группировкой
     */
    public function getTimeseries(string $metric, string $period = 'month', ?int $organizationId = null, ?int $projectId = null): array
    {
        $cacheKey = "dashboard_timeseries_{$metric}_{$period}_{$organizationId}_{$projectId}";
        $tags = $this->getCacheTags($organizationId, $projectId);

        return $this->remember($cacheKey, $tags, self::CACHE_TTL_MEDIUM, function () use ($metric, $period, $organizationId, $projectId) {
            $start = $this->getStartDateForPeriod($period);
            $end = Carbon::now();

            $data = match ($metric) {
                'users' => $this->getUsersTimeseries($organizationId, $start, $end, $period),
                'projects' => $this->getProjectsTimeseries($organizationId, $start, $end, $period),
                'materials' => $this->getMaterialsTimeseries($organizationId, $start, $end, $period),
                'suppliers' => $this->getSuppliersTimeseries($organizationId, $start, $end, $period),
                'contracts' => $this->getContractsTimeseries($organizationId, $projectId, $start, $end, $period),
                'completed_works' => $this->getCompletedWorksTimeseries($organizationId, $projectId, $start, $end, $period),
                'financial' => $this->getFinancialTimeseries($organizationId, $projectId, $start, $end, $period),
                default => ['labels' => [], 'values' => [], 'previous_values' => []],
            };

            return [
                'labels' => $data['labels'],
                'values' => $data['values'],
                'previous_values' => $data['previous_values'] ?? [],
                'metric' => $metric,
                'period' => $period,
            ];
        });
    }

    /**
     * Получить дату начала для периода
     */
    private function getStartDateForPeriod(string $period): Carbon
    {
        return match ($period) {
            'day' => Carbon::now()->subDays(30),
            'week' => Carbon::now()->subWeeks(12),
            'month' => Carbon::now()->subMonths(6),
            'year' => Carbon::now()->subYears(2),
            default => Carbon::now()->subMonths(6),
        };
    }

    /**
     * Получить временной ряд для пользователей
     */
    private function getUsersTimeseries(?int $organizationId, Carbon $start, Carbon $end, string $period): array
    {
        $query = DB::table('project_user')
            ->whereBetween('created_at', [$start, $end]);

        return $this->groupByPeriod($query, 'created_at', $period, $start, $end);
    }

    /**
     * Получить временной ряд для проектов
     */
    private function getProjectsTimeseries(?int $organizationId, Carbon $start, Carbon $end, string $period): array
    {
        $query = Project::query()
            ->whereBetween('created_at', [$start, $end]);

        if ($organizationId) {
            $query->where('organization_id', $organizationId);
        }

        return $this->groupByPeriod($query, 'created_at', $period, $start, $end);
    }

    /**
     * Получить временной ряд для материалов
     */
    private function getMaterialsTimeseries(?int $organizationId, Carbon $start, Carbon $end, string $period): array
    {
        $query = Material::query()
            ->whereBetween('created_at', [$start, $end]);

        if ($organizationId) {
            $query->where('organization_id', $organizationId);
        }

        return $this->groupByPeriod($query, 'created_at', $period, $start, $end);
    }

    /**
     * Получить временной ряд для поставщиков
     */
    private function getSuppliersTimeseries(?int $organizationId, Carbon $start, Carbon $end, string $period): array
    {
        $query = Supplier::query()
            ->whereBetween('created_at', [$start, $end]);

        if ($organizationId) {
            $query->where('organization_id', $organizationId);
        }

        return $this->groupByPeriod($query, 'created_at', $period, $start, $end);
    }

    /**
     * Получить временной ряд для контрактов
     */
    private function getContractsTimeseries(?int $organizationId, ?int $projectId, Carbon $start, Carbon $end, string $period): array
    {
        $query = Contract::query()
            ->whereBetween('created_at', [$start, $end]);

        if ($organizationId) {
            $query->where('organization_id', $organizationId);
        }

        if ($projectId) {
            $query->where('project_id', $projectId);
        }

        return $this->groupByPeriod($query, 'created_at', $period, $start, $end);
    }

    /**
     * Получить временной ряд для выполненных работ
     */
    private function getCompletedWorksTimeseries(?int $organizationId, ?int $projectId, Carbon $start, Carbon $end, string $period): array
    {
        $query = CompletedWork::query()
            ->whereBetween('created_at', [$start, $end]);

        if ($organizationId) {
            $query->where('organization_id', $organizationId);
        }

        if ($projectId) {
            $query->where('project_id', $projectId);
        }

        return $this->groupByPeriod($query, 'created_at', $period, $start, $end);
    }

    /**
     * Получить временной ряд для финансовых метрик
     */
    private function getFinancialTimeseries(?int $organizationId, ?int $projectId, Carbon $start, Carbon $end, string $period): array
    {
        $labels = [];
        $values = [];
        $previousValues = [];

        $current = $start->copy();
        $previousStart = $start->copy()->sub($this->getPeriodDuration($period));

        while ($current <= $end) {
            $periodEnd = $this->getPeriodEnd($current, $period);
            
            // Текущий период
            $currentAmount = Contract::where('organization_id', $organizationId)
                ->when($projectId, fn($q) => $q->where('project_id', $projectId))
                ->whereBetween('created_at', [$current, $periodEnd])
                ->sum('total_amount');

            // Предыдущий период для сравнения
            $previousPeriodEnd = $this->getPeriodEnd($previousStart, $period);
            $previousAmount = Contract::where('organization_id', $organizationId)
                ->when($projectId, fn($q) => $q->where('project_id', $projectId))
                ->whereBetween('created_at', [$previousStart, $previousPeriodEnd])
                ->sum('total_amount');

            $labels[] = $this->formatPeriodLabel($current, $period);
            $values[] = (float) $currentAmount;
            $previousValues[] = (float) $previousAmount;

            $current = $this->getNextPeriod($current, $period);
            $previousStart = $previousStart->copy()->add($this->getPeriodDuration($period));
        }

        return [
            'labels' => $labels,
            'values' => $values,
            'previous_values' => $previousValues,
        ];
    }

    /**
     * Группировать данные по периоду
     */
    private function groupByPeriod($query, string $dateField, string $period, Carbon $start, Carbon $end): array
    {
        $labels = [];
        $values = [];
        $previousValues = [];

        $current = $start->copy();
        $previousStart = $start->copy()->sub($this->getPeriodDuration($period));

        while ($current <= $end) {
            $periodEnd = $this->getPeriodEnd($current, $period);
            
            // Текущий период
            $count = (clone $query)
                ->whereBetween($dateField, [$current, $periodEnd])
                ->count();

            // Предыдущий период для сравнения
            $previousPeriodEnd = $this->getPeriodEnd($previousStart, $period);
            $previousCount = (clone $query)
                ->whereBetween($dateField, [$previousStart, $previousPeriodEnd])
                ->count();

            $labels[] = $this->formatPeriodLabel($current, $period);
            $values[] = $count;
            $previousValues[] = $previousCount;

            $current = $this->getNextPeriod($current, $period);
            $previousStart = $previousStart->copy()->add($this->getPeriodDuration($period));
        }

        return [
            'labels' => $labels,
            'values' => $values,
            'previous_values' => $previousValues,
        ];
    }

    /**
     * Получить конец периода
     */
    private function getPeriodEnd(Carbon $date, string $period): Carbon
    {
        return match ($period) {
            'day' => $date->copy()->endOfDay(),
            'week' => $date->copy()->endOfWeek(),
            'month' => $date->copy()->endOfMonth(),
            'year' => $date->copy()->endOfYear(),
            default => $date->copy()->endOfMonth(),
        };
    }

    /**
     * Получить следующий период
     */
    private function getNextPeriod(Carbon $date, string $period): Carbon
    {
        return match ($period) {
            'day' => $date->copy()->addDay()->startOfDay(),
            'week' => $date->copy()->addWeek()->startOfWeek(),
            'month' => $date->copy()->addMonth()->startOfMonth(),
            'year' => $date->copy()->addYear()->startOfYear(),
            default => $date->copy()->addMonth()->startOfMonth(),
        };
    }

    /**
     * Получить длительность периода
     */
    private function getPeriodDuration(string $period): \DateInterval
    {
        return match ($period) {
            'day' => new \DateInterval('P1D'),
            'week' => new \DateInterval('P1W'),
            'month' => new \DateInterval('P1M'),
            'year' => new \DateInterval('P1Y'),
            default => new \DateInterval('P1M'),
        };
    }

    /**
     * Форматировать метку периода
     */
    private function formatPeriodLabel(Carbon $date, string $period): string
    {
        return match ($period) {
            'day' => $date->format('Y-m-d'),
            'week' => $date->format('Y-W'),
            'month' => $date->format('Y-m'),
            'year' => $date->format('Y'),
            default => $date->format('Y-m'),
        };
    }

    /**
     * Получить топ сущностей по активности/объёму
     */
    public function getTopEntities(string $entity, string $period = 'month', ?int $organizationId = null, ?int $projectId = null, int $limit = 5, string $sortBy = 'amount'): array
    {
        $cacheKey = "dashboard_top_{$entity}_{$period}_{$organizationId}_{$projectId}_{$limit}_{$sortBy}";
        $tags = $this->getCacheTags($organizationId, $projectId);

        return $this->remember($cacheKey, $tags, self::CACHE_TTL_MEDIUM, function () use ($entity, $period, $organizationId, $projectId, $limit, $sortBy) {
            return match ($entity) {
                'projects' => $this->getTopProjects($organizationId, $limit, $sortBy),
                'contracts' => $this->getTopContracts($organizationId, $projectId, $limit, $sortBy),
                'materials' => $this->getTopMaterials($organizationId, $limit, $sortBy),
                'suppliers' => $this->getTopSuppliers($organizationId, $limit, $sortBy),
                'contractors' => $this->getTopContractors($organizationId, $limit, $sortBy),
                default => [],
            };
        });
    }

    /**
     * Получить топ проектов
     */
    private function getTopProjects(?int $organizationId, int $limit, string $sortBy): array
    {
        $query = Project::query();

        if ($organizationId) {
            $query->where('organization_id', $organizationId);
        }

        // Подсчет контрактов
        $query->withCount('contracts');

        // Подсчет материалов через подзапрос
        $query->selectRaw('projects.*, 
            (SELECT COUNT(DISTINCT completed_work_materials.material_id) 
             FROM completed_works 
             JOIN completed_work_materials ON completed_work_materials.completed_work_id = completed_works.id 
             WHERE completed_works.project_id = projects.id) as materials_count');

        $query->orderByDesc(match ($sortBy) {
            'materials' => 'materials_count',
            'contracts' => 'contracts_count',
            'budget' => 'budget_amount',
            default => 'budget_amount',
        })
        ->limit($limit);

        return $query->get(['id', 'name', 'budget_amount', 'materials_count', 'contracts_count'])
            ->map(function ($project) {
                return [
                    'id' => $project->id,
                    'name' => $project->name,
                    'budget_amount' => (float) ($project->budget_amount ?? 0),
                    'materials_count' => (int) ($project->materials_count ?? 0),
                    'contracts_count' => $project->contracts_count ?? 0,
                ];
            })
            ->toArray();
    }

    /**
     * Получить топ контрактов
     */
    private function getTopContracts(?int $organizationId, ?int $projectId, int $limit, string $sortBy): array
    {
        $query = Contract::query()
            ->with(['project:id,name', 'contractor:id,name']);

        if ($organizationId) {
            $query->where('organization_id', $organizationId);
        }

        if ($projectId) {
            $query->where('project_id', $projectId);
        }

        // Оптимизация: используем подзапрос для сортировки по completion только если нужно
        if ($sortBy === 'completion') {
            $query->addSelect(DB::raw('(SELECT COALESCE(SUM(total_amount), 0) FROM completed_works WHERE contract_id = contracts.id AND status = \'confirmed\') as completed_amount_calc'))
                ->orderByDesc('completed_amount_calc');
        } else {
            $query->orderByDesc('total_amount');
        }

        $query->limit($limit);

        return $query->get(['id', 'number', 'total_amount', 'status', 'project_id', 'contractor_id'])
            ->map(function ($contract) {
                return [
                    'id' => $contract->id,
                    'number' => $contract->number,
                    'project_name' => $contract->project?->name,
                    'contractor_name' => $contract->contractor?->name,
                    'total_amount' => (float) $contract->total_amount,
                    'completed_works_amount' => $contract->completed_works_amount,
                    'completion_percentage' => $contract->completion_percentage,
                    'status' => $contract->status->value,
                ];
            })
            ->toArray();
    }

    /**
     * Получить топ материалов
     */
    private function getTopMaterials(?int $organizationId, int $limit, string $sortBy): array
    {
        $query = Material::query();

        if ($organizationId) {
            $query->where('organization_id', $organizationId);
        }

        $query->orderByDesc('created_at')
            ->limit($limit);

        return $query->get(['id', 'name', 'unit', 'created_at'])
            ->map(function ($material) {
                return [
                    'id' => $material->id,
                    'name' => $material->name,
                    'unit' => $material->unit ?? '',
                    'created_at' => $material->created_at->format('Y-m-d H:i:s'),
                ];
            })
            ->toArray();
    }

    /**
     * Получить топ поставщиков
     */
    private function getTopSuppliers(?int $organizationId, int $limit, string $sortBy): array
    {
        $query = Supplier::query();

        if ($organizationId) {
            $query->where('organization_id', $organizationId);
        }

        $query->orderByDesc('created_at')
            ->limit($limit);

        return $query->get(['id', 'name', 'created_at'])
            ->map(function ($supplier) {
                return [
                    'id' => $supplier->id,
                    'name' => $supplier->name,
                    'created_at' => $supplier->created_at->format('Y-m-d H:i:s'),
                ];
            })
            ->toArray();
    }

    /**
     * Получить топ подрядчиков
     */
    private function getTopContractors(?int $organizationId, int $limit, string $sortBy): array
    {
        $query = Contractor::query()
            ->withCount('contracts');

        if ($organizationId) {
            $query->where('organization_id', $organizationId);
        }

        $query->orderByDesc('contracts_count')
            ->limit($limit);

        return $query->get(['id', 'name', 'contracts_count'])
            ->map(function ($contractor) {
                return [
                    'id' => $contractor->id,
                    'name' => $contractor->name,
                    'contracts_count' => $contractor->contracts_count ?? 0,
                ];
            })
            ->toArray();
    }

    /**
     * Получить историю последних действий/операций
     */
    public function getHistory(string $type = 'materials', int $limit = 10, ?int $organizationId = null, ?int $projectId = null, ?string $status = null): array
    {
        $cacheKey = "dashboard_history_{$type}_{$limit}_{$organizationId}_{$projectId}_{$status}";
        $tags = $this->getCacheTags($organizationId, $projectId);

        return $this->remember($cacheKey, $tags, self::CACHE_TTL_SHORT, function () use ($type, $limit, $organizationId, $projectId, $status) {
            return match ($type) {
                'materials' => $this->getMaterialsHistory($organizationId, $limit),
                'contracts' => $this->getContractsHistory($organizationId, $projectId, $limit, $status),
                'projects' => $this->getProjectsHistory($organizationId, $limit),
                'completed_works' => $this->getCompletedWorksHistory($organizationId, $projectId, $limit, $status),
                default => [],
            };
        });
    }

    /**
     * Получить историю материалов
     */
    private function getMaterialsHistory(?int $organizationId, int $limit): array
    {
        $query = Material::query();

        if ($organizationId) {
            $query->where('organization_id', $organizationId);
        }

        return $query->orderByDesc('created_at')
            ->limit($limit)
            ->get(['id', 'name', 'unit', 'created_at'])
            ->map(function ($material) {
                return [
                    'id' => $material->id,
                    'name' => $material->name,
                    'unit' => $material->unit ?? '',
                    'type' => 'material',
                    'created_at' => $material->created_at->format('Y-m-d H:i:s'),
                ];
            })
            ->toArray();
    }

    /**
     * Получить историю контрактов
     */
    private function getContractsHistory(?int $organizationId, ?int $projectId, int $limit, ?string $status): array
    {
        $query = Contract::query()
            ->with(['project:id,name', 'contractor:id,name']);

        if ($organizationId) {
            $query->where('organization_id', $organizationId);
        }

        if ($projectId) {
            $query->where('project_id', $projectId);
        }

        if ($status) {
            $query->where('status', $status);
        }

        return $query->orderByDesc('created_at')
            ->limit($limit)
            ->get(['id', 'number', 'total_amount', 'status', 'created_at', 'project_id', 'contractor_id'])
            ->map(function ($contract) {
                return [
                    'id' => $contract->id,
                    'number' => $contract->number,
                    'project_name' => $contract->project?->name,
                    'contractor_name' => $contract->contractor?->name,
                    'total_amount' => (float) $contract->total_amount,
                    'status' => $contract->status->value,
                    'type' => 'contract',
                    'created_at' => $contract->created_at->format('Y-m-d H:i:s'),
                ];
            })
            ->toArray();
    }

    /**
     * Получить историю проектов
     */
    private function getProjectsHistory(?int $organizationId, int $limit): array
    {
        $query = Project::query();

        if ($organizationId) {
            $query->where('organization_id', $organizationId);
        }

        return $query->orderByDesc('created_at')
            ->limit($limit)
            ->get(['id', 'name', 'status', 'created_at'])
            ->map(function ($project) {
                return [
                    'id' => $project->id,
                    'name' => $project->name,
                    'status' => $project->status ?? '',
                    'type' => 'project',
                    'created_at' => $project->created_at->format('Y-m-d H:i:s'),
                ];
            })
            ->toArray();
    }

    /**
     * Получить историю выполненных работ
     */
    private function getCompletedWorksHistory(?int $organizationId, ?int $projectId, int $limit, ?string $status): array
    {
        $query = CompletedWork::query()
            ->with([
                'project:id,name',
                'contract:id,number',
                'workType:id,name',
                'user:id,name'
            ]);

        if ($organizationId) {
            $query->where('organization_id', $organizationId);
        }

        if ($projectId) {
            $query->where('project_id', $projectId);
        }

        if ($status) {
            $query->where('status', $status);
        }

        return $query->orderByDesc('created_at')
            ->limit($limit)
            ->get(['id', 'quantity', 'total_amount', 'status', 'completion_date', 'created_at', 'project_id', 'contract_id', 'work_type_id', 'user_id'])
            ->map(function ($work) {
                return [
                    'id' => $work->id,
                    'project_name' => $work->project?->name,
                    'contract_number' => $work->contract?->number,
                    'work_type_name' => $work->workType?->name,
                    'user_name' => $work->user?->name,
                    'quantity' => (float) $work->quantity,
                    'total_amount' => (float) $work->total_amount,
                    'status' => $work->status,
                    'completion_date' => $work->completion_date?->format('Y-m-d'),
                    'type' => 'completed_work',
                    'created_at' => $work->created_at->format('Y-m-d H:i:s'),
                ];
            })
            ->toArray();
    }

    /**
     * Получить лимиты и их заполнение
     */
    public function getLimits(?int $organizationId = null): array
    {
        $user = Auth::user();
        if (!$user) {
            return [];
        }

        return $this->subscriptionLimitsService->getUserLimitsData($user);
    }

    /**
     * Получить базовые финансовые показатели
     */
    public function getFinancialMetrics(int $organizationId, ?int $projectId = null): array
    {
        $cacheKey = "dashboard_financial_metrics_{$organizationId}_{$projectId}";
        $tags = $this->getCacheTags($organizationId, $projectId);

        return $this->remember($cacheKey, $tags, self::CACHE_TTL_MEDIUM, function () use ($organizationId, $projectId) {
            $contractsQuery = Contract::where('organization_id', $organizationId);
            if ($projectId) {
                $contractsQuery->where('project_id', $projectId);
            }

            $totalContractsAmount = $contractsQuery->sum('total_amount');
            $activeContractsAmount = (clone $contractsQuery)->where('status', ContractStatusEnum::ACTIVE->value)->sum('total_amount');
            $completedContractsAmount = (clone $contractsQuery)->where('status', ContractStatusEnum::COMPLETED->value)->sum('total_amount');

            $worksQuery = DB::table('completed_works')
                ->where('organization_id', $organizationId)
                ->where('status', 'confirmed');
            
            if ($projectId) {
                $worksQuery->where('project_id', $projectId);
            }

            $completedWorksAmount = $worksQuery->sum('total_amount');

            return [
                'total_contracts_amount' => (float) $totalContractsAmount,
                'active_contracts_amount' => (float) $activeContractsAmount,
                'completed_contracts_amount' => (float) $completedContractsAmount,
                'completed_works_amount' => (float) $completedWorksAmount,
                'remaining_amount' => max(0, (float) $totalContractsAmount - (float) $completedWorksAmount),
                'completion_percentage' => $totalContractsAmount > 0 
                    ? round(($completedWorksAmount / $totalContractsAmount) * 100, 2) 
                    : 0.0,
            ];
        });
    }

    /**
     * Получить детальную аналитику контрактов
     */
    public function getContractsAnalytics(int $organizationId, ?int $projectId = null, array $filters = []): array
    {
        $cacheKey = "dashboard_contracts_analytics_{$organizationId}_{$projectId}_" . md5(serialize($filters));
        $tags = $this->getCacheTags($organizationId, $projectId);

        return $this->remember($cacheKey, $tags, self::CACHE_TTL_MEDIUM, function () use ($organizationId, $projectId, $filters) {
            $query = Contract::where('organization_id', $organizationId);
            
            if ($projectId) {
                $query->where('project_id', $projectId);
            }

            // Применяем фильтры
            if (isset($filters['status'])) {
                $query->where('status', $filters['status']);
            }

            if (isset($filters['contractor_id'])) {
                $query->where('contractor_id', $filters['contractor_id']);
            }

            if (isset($filters['date_from'])) {
                $query->where('created_at', '>=', $filters['date_from']);
            }

            if (isset($filters['date_to'])) {
                $query->where('created_at', '<=', $filters['date_to']);
            }

            $total = $query->count();
            $byStatus = (clone $query)->select('status', DB::raw('COUNT(*) as count'))
                ->groupBy('status')
                ->get()
                ->mapWithKeys(function ($item) {
                    $statusValue = $item->status instanceof ContractStatusEnum 
                        ? $item->status->value 
                        : (string)$item->status;
                    return [$statusValue => $item->count];
                });

            $totalAmount = (clone $query)->sum('total_amount');
            $avgAmount = $total > 0 ? $totalAmount / $total : 0;

            $completedWorksAmount = DB::table('completed_works')
                ->where('organization_id', $organizationId)
                ->where('status', 'confirmed')
                ->when($projectId, fn($q) => $q->where('project_id', $projectId))
                ->sum('total_amount');

            return [
                'total' => $total,
                'by_status' => [
                    'draft' => $byStatus->get(ContractStatusEnum::DRAFT->value, 0),
                    'active' => $byStatus->get(ContractStatusEnum::ACTIVE->value, 0),
                    'completed' => $byStatus->get(ContractStatusEnum::COMPLETED->value, 0),
                    'on_hold' => $byStatus->get(ContractStatusEnum::ON_HOLD->value, 0),
                    'terminated' => $byStatus->get(ContractStatusEnum::TERMINATED->value, 0),
                ],
                'total_amount' => (float) $totalAmount,
                'average_amount' => round((float) $avgAmount, 2),
                'completed_works_amount' => (float) $completedWorksAmount,
                'completion_percentage' => $totalAmount > 0 
                    ? round(($completedWorksAmount / $totalAmount) * 100, 2) 
                    : 0.0,
            ];
        });
    }

    /**
     * Получить аналитику проектов
     */
    public function getProjectsAnalytics(int $organizationId, array $filters = []): array
    {
        $cacheKey = "dashboard_projects_analytics_{$organizationId}_" . md5(serialize($filters));
        $tags = $this->getCacheTags($organizationId);

        return $this->remember($cacheKey, $tags, self::CACHE_TTL_MEDIUM, function () use ($organizationId, $filters) {
            $query = Project::where('organization_id', $organizationId);

            // Применяем фильтры
            if (isset($filters['status'])) {
                $query->where('status', $filters['status']);
            }

            if (isset($filters['is_archived'])) {
                $query->where('is_archived', $filters['is_archived']);
            }

            $projects = $query->withCount('contracts')->get();

            $total = $projects->count();
            $totalBudget = $projects->sum('budget_amount');
            $avgBudget = $total > 0 ? $totalBudget / $total : 0;

            // Подсчет материалов через подзапрос
            $totalMaterials = DB::table('completed_works')
                ->join('completed_work_materials', 'completed_work_materials.completed_work_id', '=', 'completed_works.id')
                ->join('projects', 'completed_works.project_id', '=', 'projects.id')
                ->where('projects.organization_id', $organizationId)
                ->distinct('completed_work_materials.material_id')
                ->count('completed_work_materials.material_id');

            return [
                'total' => $total,
                'total_budget' => (float) $totalBudget,
                'average_budget' => round((float) $avgBudget, 2),
                'total_contracts' => $projects->sum('contracts_count'),
                'total_materials' => $totalMaterials,
            ];
        });
    }

    /**
     * Получить аналитику материалов
     */
    public function getMaterialsAnalytics(int $organizationId, array $filters = []): array
    {
        $cacheKey = "dashboard_materials_analytics_{$organizationId}_" . md5(serialize($filters));
        $tags = $this->getCacheTags($organizationId);

        return $this->remember($cacheKey, $tags, self::CACHE_TTL_MEDIUM, function () use ($organizationId, $filters) {
            $query = Material::where('organization_id', $organizationId);

            // Применяем фильтры
            if (isset($filters['category'])) {
                $query->where('category', $filters['category']);
            }

            if (isset($filters['is_active'])) {
                $query->where('is_active', $filters['is_active']);
            }

            $total = $query->count();
            $byCategory = (clone $query)->select('category', DB::raw('COUNT(*) as count'))
                ->groupBy('category')
                ->get()
                ->mapWithKeys(function ($item) {
                    $categoryKey = $item->category ?? 'Без категории';
                    return [$categoryKey => $item->count];
                });

            return [
                'total' => $total,
                'by_category' => $byCategory->toArray(),
            ];
        });
    }

    /**
     * Получить аналитику выполненных работ
     */
    public function getCompletedWorksAnalytics(int $organizationId, ?int $projectId = null): array
    {
        $cacheKey = "dashboard_completed_works_analytics_{$organizationId}_{$projectId}";
        $tags = $this->getCacheTags($organizationId, $projectId);

        return $this->remember($cacheKey, $tags, self::CACHE_TTL_MEDIUM, function () use ($organizationId, $projectId) {
            $query = CompletedWork::where('organization_id', $organizationId);
            if ($projectId) {
                $query->where('project_id', $projectId);
            }

            $total = $query->count();
            $byStatus = $query->select('status', DB::raw('COUNT(*) as count'), DB::raw('SUM(total_amount) as total_amount'))
                ->groupBy('status')
                ->get()
                ->mapWithKeys(function ($item) {
                    return [(string)$item->status => $item];
                });

            $confirmedAmount = $byStatus->get('confirmed')?->total_amount ?? 0;
            $pendingAmount = $byStatus->get('pending')?->total_amount ?? 0;

            return [
                'total' => $total,
                'by_status' => [
                    'confirmed' => [
                        'count' => $byStatus->get('confirmed')?->count ?? 0,
                        'amount' => (float) $confirmedAmount,
                    ],
                    'pending' => [
                        'count' => $byStatus->get('pending')?->count ?? 0,
                        'amount' => (float) $pendingAmount,
                    ],
                    'rejected' => [
                        'count' => $byStatus->get('rejected')?->count ?? 0,
                        'amount' => (float) ($byStatus->get('rejected')?->total_amount ?? 0),
                    ],
                ],
                'total_amount' => (float) ($confirmedAmount + $pendingAmount),
            ];
        });
    }

    /**
     * Получить аналитику по подрядчикам
     */
    public function getContractorsAnalytics(int $organizationId): array
    {
        $cacheKey = "dashboard_contractors_analytics_{$organizationId}";
        $tags = $this->getCacheTags($organizationId);

        return $this->remember($cacheKey, $tags, self::CACHE_TTL_MEDIUM, function () use ($organizationId) {
            $contractors = Contractor::where('organization_id', $organizationId)
                ->withCount('contracts')
                ->get();

            $total = $contractors->count();
            $totalContracts = $contractors->sum('contracts_count');

            return [
                'total' => $total,
                'total_contracts' => $totalContracts,
                'average_contracts_per_contractor' => $total > 0 ? round($totalContracts / $total, 2) : 0,
            ];
        });
    }

    /**
     * Получить аналитику по поставщикам
     */
    public function getSuppliersAnalytics(int $organizationId): array
    {
        $cacheKey = "dashboard_suppliers_analytics_{$organizationId}";
        $tags = $this->getCacheTags($organizationId);

        return $this->remember($cacheKey, $tags, self::CACHE_TTL_MEDIUM, function () use ($organizationId) {
            $total = Supplier::where('organization_id', $organizationId)->count();

            return [
                'total' => $total,
            ];
        });
    }

    /**
     * Получить сравнение периодов
     */
    public function getComparisonData(int $organizationId, ?int $projectId = null, string $period = 'month'): array
    {
        $cacheKey = "dashboard_comparison_{$organizationId}_{$projectId}_{$period}";
        $tags = $this->getCacheTags($organizationId, $projectId);

        return $this->remember($cacheKey, $tags, self::CACHE_TTL_MEDIUM, function () use ($organizationId, $projectId, $period) {
            $currentStart = Carbon::now()->startOfMonth();
            $currentEnd = Carbon::now();
            $previousStart = $currentStart->copy()->subMonth();
            $previousEnd = $previousStart->copy()->endOfMonth();

            $current = $this->calculateSummaryForPeriod($organizationId, $projectId ?? 0, $currentStart, $currentEnd);
            $previous = $this->calculateSummaryForPeriod($organizationId, $projectId ?? 0, $previousStart, $previousEnd);

            return [
                'current' => $current,
                'previous' => $previous,
                'changes' => [
                    'contracts' => [
                        'total' => $current['contracts']['total'] - $previous['contracts']['total'],
                        'percentage' => $this->calculatePercentageChange($previous['contracts']['total'], $current['contracts']['total']),
                    ],
                    'completed_works' => [
                        'total' => $current['completed_works']['total'] - $previous['completed_works']['total'],
                        'percentage' => $this->calculatePercentageChange($previous['completed_works']['total'], $current['completed_works']['total']),
                    ],
                ],
            ];
        });
    }

    /**
     * Получить распределение контрактов по статусам (для pie chart)
     */
    public function getContractsByStatus(int $organizationId, ?int $projectId = null): array
    {
        $cacheKey = "dashboard_contracts_by_status_{$organizationId}_{$projectId}";
        $tags = $this->getCacheTags($organizationId, $projectId);

        return $this->remember($cacheKey, $tags, self::CACHE_TTL_MEDIUM, function () use ($organizationId, $projectId) {
            $query = Contract::where('organization_id', $organizationId);
            if ($projectId) {
                $query->where('project_id', $projectId);
            }

            $byStatus = $query->select('status', DB::raw('COUNT(*) as count'))
                ->groupBy('status')
                ->get();

            $labels = [];
            $data = [];
            $colors = [
                ContractStatusEnum::DRAFT->value => '#f59e0b',
                ContractStatusEnum::ACTIVE->value => '#3b82f6',
                ContractStatusEnum::COMPLETED->value => '#10b981',
                ContractStatusEnum::ON_HOLD->value => '#6b7280',
                ContractStatusEnum::TERMINATED->value => '#ef4444',
            ];

            $statusValues = [];
            foreach ($byStatus as $item) {
                $statusValue = $item->status instanceof ContractStatusEnum 
                    ? $item->status->value 
                    : (string)$item->status;
                $statusValues[] = $statusValue;
                
                $statusLabel = match ($statusValue) {
                    ContractStatusEnum::DRAFT->value => 'Черновики',
                    ContractStatusEnum::ACTIVE->value => 'Активные',
                    ContractStatusEnum::COMPLETED->value => 'Завершенные',
                    ContractStatusEnum::ON_HOLD->value => 'Приостановленные',
                    ContractStatusEnum::TERMINATED->value => 'Расторгнутые',
                    default => $statusValue,
                };
                $labels[] = $statusLabel;
                $data[] = $item->count;
            }

            return [
                'type' => 'pie',
                'labels' => $labels,
                'datasets' => [
                    [
                        'label' => 'Контракты',
                        'data' => $data,
                        'backgroundColor' => array_map(fn($status) => $colors[$status] ?? '#9ca3af', $statusValues),
                    ],
                ],
            ];
        });
    }

    /**
     * Получить распределение проектов по статусам (для pie chart)
     */
    public function getProjectsByStatus(int $organizationId): array
    {
        $cacheKey = "dashboard_projects_by_status_{$organizationId}";
        $tags = $this->getCacheTags($organizationId);

        return $this->remember($cacheKey, $tags, self::CACHE_TTL_MEDIUM, function () use ($organizationId) {
            $byStatus = Project::where('organization_id', $organizationId)
                ->select('status', DB::raw('COUNT(*) as count'))
                ->groupBy('status')
                ->get();

            $labels = [];
            $data = [];

            foreach ($byStatus as $item) {
                $labels[] = $item->status ?? 'Без статуса';
                $data[] = $item->count;
            }

            return [
                'type' => 'pie',
                'labels' => $labels,
                'datasets' => [
                    [
                        'label' => 'Проекты',
                        'data' => $data,
                    ],
                ],
            ];
        });
    }

    /**
     * Получить движение финансов (для line chart)
     */
    public function getFinancialFlow(int $organizationId, ?int $projectId = null, string $period = 'month'): array
    {
        return $this->getFinancialTimeseries($organizationId, $projectId, 
            $this->getStartDateForPeriod($period), 
            Carbon::now(), 
            $period
        );
    }

    /**
     * Получить контракты по подрядчикам (для bar chart)
     */
    public function getContractsByContractor(int $organizationId, ?int $projectId = null, int $limit = 10): array
    {
        $cacheKey = "dashboard_contracts_by_contractor_{$organizationId}_{$projectId}_{$limit}";
        $tags = $this->getCacheTags($organizationId, $projectId);

        return $this->remember($cacheKey, $tags, self::CACHE_TTL_MEDIUM, function () use ($organizationId, $projectId, $limit) {
            $query = Contract::where('organization_id', $organizationId)
                ->with('contractor:id,name');

            if ($projectId) {
                $query->where('project_id', $projectId);
            }

            $byContractor = $query->select('contractor_id', DB::raw('COUNT(*) as count'), DB::raw('SUM(total_amount) as total_amount'))
                ->groupBy('contractor_id')
                ->orderByDesc('count')
                ->limit($limit)
                ->get();

            $labels = [];
            $counts = [];
            $amounts = [];

            foreach ($byContractor as $item) {
                $contractor = Contractor::find($item->contractor_id);
                $labels[] = $contractor?->name ?? 'Неизвестно';
                $counts[] = $item->count;
                $amounts[] = (float) $item->total_amount;
            }

            return [
                'type' => 'bar',
                'labels' => $labels,
                'datasets' => [
                    [
                        'label' => 'Количество контрактов',
                        'data' => $counts,
                        'backgroundColor' => '#3b82f6',
                    ],
                    [
                        'label' => 'Сумма контрактов',
                        'data' => $amounts,
                        'backgroundColor' => '#10b981',
                    ],
                ],
            ];
        });
    }

    /**
     * Получить материалы по проектам (для bar chart)
     */
    public function getMaterialsByProject(int $organizationId, int $limit = 10): array
    {
        $cacheKey = "dashboard_materials_by_project_{$organizationId}_{$limit}";
        $tags = $this->getCacheTags($organizationId);

        return $this->remember($cacheKey, $tags, self::CACHE_TTL_MEDIUM, function () use ($organizationId, $limit) {
            // Материалы связаны с проектами через completed_work_materials -> completed_works -> project_id
            if (!DB::getSchemaBuilder()->hasTable('completed_work_materials')) {
                return [
                    'type' => 'bar',
                    'labels' => [],
                    'datasets' => [
                        [
                            'label' => 'Материалы',
                            'data' => [],
                            'backgroundColor' => '#8b5cf6',
                        ],
                    ],
                ];
            }

            $byProject = DB::table('completed_work_materials')
                ->join('completed_works', 'completed_work_materials.completed_work_id', '=', 'completed_works.id')
                ->join('projects', 'completed_works.project_id', '=', 'projects.id')
                ->where('projects.organization_id', $organizationId)
                ->select('projects.id', 'projects.name', DB::raw('COUNT(DISTINCT completed_work_materials.material_id) as count'))
                ->groupBy('projects.id', 'projects.name')
                ->orderByDesc('count')
                ->limit($limit)
                ->get();

            $labels = [];
            $data = [];

            foreach ($byProject as $item) {
                $labels[] = $item->name;
                $data[] = $item->count;
            }

            return [
                'type' => 'bar',
                'labels' => $labels,
                'datasets' => [
                    [
                        'label' => 'Материалы',
                        'data' => $data,
                        'backgroundColor' => '#8b5cf6',
                    ],
                ],
            ];
        });
    }

    /**
     * Получить топ контрактов по сумме
     */
    public function getTopContractsByAmount(int $organizationId, ?int $projectId = null, int $limit = 5): array
    {
        return $this->getTopEntities('contracts', 'month', $organizationId, $projectId, $limit, 'amount');
    }

    /**
     * Получить топ проектов по бюджету
     */
    public function getTopProjectsByBudget(int $organizationId, int $limit = 5): array
    {
        return $this->getTopEntities('projects', 'month', $organizationId, null, $limit, 'budget');
    }

    /**
     * Получить топ материалов по использованию
     */
    public function getTopMaterialsByUsage(int $organizationId, int $limit = 5): array
    {
        return $this->getTopEntities('materials', 'month', $organizationId, null, $limit, 'usage');
    }

    /**
     * Получить топ подрядчиков по объему работ
     */
    public function getTopContractorsByVolume(int $organizationId, int $limit = 5): array
    {
        return $this->getTopEntities('contractors', 'month', $organizationId, null, $limit, 'volume');
    }

    /**
     * Получить топ поставщиков по сумме
     */
    public function getTopSuppliersByAmount(int $organizationId, int $limit = 5): array
    {
        return $this->getTopEntities('suppliers', 'month', $organizationId, null, $limit, 'amount');
    }

    /**
     * Получить месячные тренды
     */
    public function getMonthlyTrends(int $organizationId, ?int $projectId = null): array
    {
        $cacheKey = "dashboard_monthly_trends_{$organizationId}_{$projectId}";
        $tags = $this->getCacheTags($organizationId, $projectId);

        return $this->remember($cacheKey, $tags, self::CACHE_TTL_LONG, function () use ($organizationId, $projectId) {
            $start = Carbon::now()->subMonths(6)->startOfMonth();
            $end = Carbon::now();

            return [
                'contracts' => $this->getContractsTimeseries($organizationId, $projectId, $start, $end, 'month'),
                'completed_works' => $this->getCompletedWorksTimeseries($organizationId, $projectId, $start, $end, 'month'),
                'financial' => $this->getFinancialTimeseries($organizationId, $projectId, $start, $end, 'month'),
            ];
        });
    }

    /**
     * Получить производительность контрактов
     */
    public function getContractPerformance(int $organizationId, ?int $projectId = null): array
    {
        $cacheKey = "dashboard_contract_performance_{$organizationId}_{$projectId}";
        $tags = $this->getCacheTags($organizationId, $projectId);

        return $this->remember($cacheKey, $tags, self::CACHE_TTL_MEDIUM, function () use ($organizationId, $projectId) {
            $query = Contract::where('organization_id', $organizationId)
                ->where('is_fixed_amount', true);

            if ($projectId) {
                $query->where('project_id', $projectId);
            }

            $contracts = $query->get();

            $totalContracts = $contracts->count();
            $avgCompletion = $contracts->avg(fn($c) => $c->completion_percentage ?? 0);
            $onTime = $contracts->filter(fn($c) => !$c->is_overdue)->count();
            $overdue = $contracts->filter(fn($c) => $c->is_overdue)->count();
            $nearingLimit = $contracts->filter(fn($c) => $c->isNearingLimit())->count();

            return [
                'total_contracts' => $totalContracts,
                'average_completion' => round((float) $avgCompletion, 2),
                'on_time' => $onTime,
                'overdue' => $overdue,
                'nearing_limit' => $nearingLimit,
                'on_time_percentage' => $totalContracts > 0 ? round(($onTime / $totalContracts) * 100, 2) : 0,
            ];
        });
    }

    /**
     * Получить прогресс проектов с детализацией
     */
    public function getProjectProgress(int $organizationId, ?int $projectId = null): array
    {
        $cacheKey = "dashboard_project_progress_{$organizationId}_{$projectId}";
        $tags = $this->getCacheTags($organizationId, $projectId);

        return $this->remember($cacheKey, $tags, self::CACHE_TTL_MEDIUM, function () use ($organizationId, $projectId) {
            $query = Project::where('organization_id', $organizationId);
            if ($projectId) {
                $query->where('id', $projectId);
            }

            $projects = $query->withCount('contracts')->get();

            $totalProjects = $projects->count();
            $totalBudget = $projects->sum('budget_amount');
            $totalContracts = $projects->sum('contracts_count');

            return [
                'total_projects' => $totalProjects,
                'total_budget' => (float) $totalBudget,
                'total_contracts' => $totalContracts,
                'average_budget' => $totalProjects > 0 ? round((float) $totalBudget / $totalProjects, 2) : 0,
                'average_contracts_per_project' => $totalProjects > 0 ? round($totalContracts / $totalProjects, 2) : 0,
            ];
        });
    }

    /**
     * Получить расход материалов с трендами
     */
    public function getMaterialConsumption(int $organizationId, ?int $projectId = null, string $period = 'month'): array
    {
        $cacheKey = "dashboard_material_consumption_{$organizationId}_{$projectId}_{$period}";
        $tags = $this->getCacheTags($organizationId, $projectId);

        return $this->remember($cacheKey, $tags, self::CACHE_TTL_MEDIUM, function () use ($organizationId, $projectId, $period) {
            $start = $this->getStartDateForPeriod($period);
            $end = Carbon::now();

            $query = Material::where('organization_id', $organizationId)
                ->whereBetween('created_at', [$start, $end]);

            if ($projectId) {
                $query->where('project_id', $projectId);
            }

            $total = $query->count();
            $trend = $this->groupByPeriod($query, 'created_at', $period, $start, $end);

            return [
                'total' => $total,
                'trend' => $trend,
            ];
        });
    }

    /**
     * Получить эффективность выполненных работ
     */
    public function getWorksEfficiency(int $organizationId, ?int $projectId = null): array
    {
        $cacheKey = "dashboard_works_efficiency_{$organizationId}_{$projectId}";
        $tags = $this->getCacheTags($organizationId, $projectId);

        return $this->remember($cacheKey, $tags, self::CACHE_TTL_MEDIUM, function () use ($organizationId, $projectId) {
            $query = CompletedWork::where('organization_id', $organizationId);
            if ($projectId) {
                $query->where('project_id', $projectId);
            }

            $total = $query->count();
            $confirmed = (clone $query)->where('status', 'confirmed')->count();
            $pending = (clone $query)->where('status', 'pending')->count();
            $rejected = (clone $query)->where('status', 'rejected')->count();

            $confirmedAmount = (clone $query)->where('status', 'confirmed')->sum('total_amount');
            $totalAmount = $query->sum('total_amount');

            return [
                'total' => $total,
                'confirmed' => $confirmed,
                'pending' => $pending,
                'rejected' => $rejected,
                'confirmed_percentage' => $total > 0 ? round(($confirmed / $total) * 100, 2) : 0,
                'confirmed_amount' => (float) $confirmedAmount,
                'total_amount' => (float) $totalAmount,
                'efficiency_rate' => $totalAmount > 0 ? round(($confirmedAmount / $totalAmount) * 100, 2) : 0,
            ];
        });
    }

    /**
     * Получить распределение материалов по категориям
     */
    public function getMaterialsByCategory(int $organizationId): array
    {
        $cacheKey = "dashboard_materials_by_category_{$organizationId}";
        $tags = $this->getCacheTags($organizationId);

        return $this->remember($cacheKey, $tags, self::CACHE_TTL_MEDIUM, function () use ($organizationId) {
            // Если у материалов есть категории, используем их
            // Иначе группируем по project_id
            $byProject = Material::where('organization_id', $organizationId)
                ->select('project_id', DB::raw('COUNT(*) as count'))
                ->groupBy('project_id')
                ->get();

            $labels = [];
            $data = [];

            foreach ($byProject as $item) {
                $project = Project::find($item->project_id);
                $labels[] = $project?->name ?? 'Без проекта';
                $data[] = $item->count;
            }

            return [
                'type' => 'pie',
                'labels' => $labels,
                'datasets' => [
                    [
                        'label' => 'Материалы',
                        'data' => $data,
                    ],
                ],
            ];
        });
    }

    /**
     * Получить выполненные работы по типам
     */
    public function getWorksByType(int $organizationId, ?int $projectId = null): array
    {
        $cacheKey = "dashboard_works_by_type_{$organizationId}_{$projectId}";
        $tags = $this->getCacheTags($organizationId, $projectId);

        return $this->remember($cacheKey, $tags, self::CACHE_TTL_MEDIUM, function () use ($organizationId, $projectId) {
            $query = CompletedWork::where('organization_id', $organizationId);
            if ($projectId) {
                $query->where('project_id', $projectId);
            }

            $byType = $query->select('work_type_id', DB::raw('COUNT(*) as count'), DB::raw('SUM(total_amount) as total_amount'))
                ->groupBy('work_type_id')
                ->orderByDesc('count')
                ->limit(10)
                ->get();

            $labels = [];
            $counts = [];
            $amounts = [];

            foreach ($byType as $item) {
                $workType = \App\Models\WorkType::find($item->work_type_id);
                $labels[] = $workType?->name ?? 'Неизвестный тип';
                $counts[] = $item->count;
                $amounts[] = (float) $item->total_amount;
            }

            return [
                'type' => 'bar',
                'labels' => $labels,
                'datasets' => [
                    [
                        'label' => 'Количество работ',
                        'data' => $counts,
                        'backgroundColor' => '#8b5cf6',
                    ],
                    [
                        'label' => 'Сумма работ',
                        'data' => $amounts,
                        'backgroundColor' => '#ec4899',
                    ],
                ],
            ];
        });
    }

    /**
     * Очистить кеш дашборда
     */
    public function clearDashboardCache(int $organizationId, ?int $projectId = null): void
    {
        $tags = $this->getCacheTags($organizationId, $projectId);
        
        if ($this->supportsTaggedCache()) {
            Cache::tags($tags)->flush();
        } else {
            // Fallback: удаляем ключи по паттерну
            $patterns = [
                "dashboard_summary_{$organizationId}_{$projectId}",
                "dashboard_timeseries_*_{$organizationId}_{$projectId}",
                "dashboard_top_*_{$organizationId}_{$projectId}",
                "dashboard_history_*_{$organizationId}_{$projectId}",
                "dashboard_financial_metrics_{$organizationId}_{$projectId}",
            ];
            
            foreach ($patterns as $pattern) {
                // В реальном приложении здесь нужна более сложная логика для удаления по паттерну
                // Для простоты просто очищаем весь кеш
                Cache::flush();
                break;
            }
        }
    }

    /**
     * Получить теги кеша
     */
    private function getCacheTags(int $organizationId, ?int $projectId = null): array
    {
        $tags = ['dashboard', "org_{$organizationId}"];
        if ($projectId) {
            $tags[] = "project_{$projectId}";
        }
        return $tags;
    }

    /**
     * Проверить поддержку tagged cache
     */
    private function supportsTaggedCache(): bool
    {
        $driver = config('cache.default');
        return in_array($driver, ['redis', 'memcached']);
    }

    /**
     * Вспомогательный метод для кеширования
     */
    private function remember(string $key, array $tags, int $ttl, callable $callback)
    {
        if ($this->supportsTaggedCache()) {
            return Cache::tags($tags)->remember($key, $ttl, $callback);
        }
        return Cache::remember($key, $ttl, $callback);
    }

    /**
     * Получить полную структуру дашборда (упрощенный подход без виджетов)
     * Возвращает готовую структуру со всеми данными для отображения
     */
    public function getFullDashboard(int $organizationId, int $projectId): array
    {
        $cacheKey = "dashboard_full_{$organizationId}_{$projectId}";
        $tags = $this->getCacheTags($organizationId, $projectId);

        return $this->remember($cacheKey, $tags, self::CACHE_TTL_SHORT, function () use ($organizationId, $projectId) {
            // Основная сводка
            $summary = $this->getSummary($organizationId, $projectId);

            // Финансовые метрики
            $financial = $this->getFinancialMetrics($organizationId, $projectId);

            // Аналитика контрактов
            $contractsAnalytics = $this->getContractsAnalytics($organizationId, $projectId);

            // Графики
            $contractsByStatus = $this->getContractsByStatus($organizationId, $projectId);
            $contractsByContractor = $this->getContractsByContractor($organizationId, $projectId, 5);
            $financialFlow = $this->getFinancialFlow($organizationId, $projectId, 'month');

            // Топ-листы
            $topContracts = $this->getTopContractsByAmount($organizationId, $projectId, 5);
            $topContractors = $this->getTopContractorsByVolume($organizationId, 5);

            // Детальная аналитика
            $contractPerformance = $this->getContractPerformance($organizationId, $projectId);
            $worksEfficiency = $this->getWorksEfficiency($organizationId, $projectId);
            $completedWorksAnalytics = $this->getCompletedWorksAnalytics($organizationId, $projectId);

            // Временные ряды
            $timeseriesContracts = $this->getTimeseries('contracts', 'month', $organizationId, $projectId);
            $timeseriesWorks = $this->getTimeseries('completed_works', 'month', $organizationId, $projectId);

            // История
            $recentActivity = $this->getHistory('completed_works', 10, $organizationId, $projectId);

            // Сравнение периодов
            $comparison = $this->getComparisonData($organizationId, $projectId, 'month');

            return [
                'summary' => $summary['summary'],
                'period' => $summary['period'],
                'financial' => $financial,
                'contracts' => [
                    'analytics' => $contractsAnalytics,
                    'performance' => $contractPerformance,
                    'by_status' => $contractsByStatus,
                    'by_contractor' => $contractsByContractor,
                    'top' => $topContracts,
                ],
                'works' => [
                    'analytics' => $completedWorksAnalytics,
                    'efficiency' => $worksEfficiency,
                    'recent' => $recentActivity,
                ],
                'contractors' => [
                    'top' => $topContractors,
                ],
                'charts' => [
                    'contracts_timeseries' => $timeseriesContracts,
                    'works_timeseries' => $timeseriesWorks,
                    'financial_flow' => $financialFlow,
                ],
                'comparison' => $comparison,
            ];
        });
    }
} 