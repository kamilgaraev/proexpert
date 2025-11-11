<?php

namespace App\Repositories\Landing;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\Project;
use App\Models\Contract;
use App\Models\CompletedWork;
use App\Models\ContractPerformanceAct;
use App\Models\BalanceTransaction;
use App\Models\Material;

class EloquentOrganizationDashboardRepository implements OrganizationDashboardRepositoryInterface
{
    public function getFinancialSummary(int $organizationId, int $projectId): array
    {
        // ОПТИМИЗАЦИЯ: Используем JOIN вместо whereHas для лучшей производительности
        $organizationBalanceId = DB::table('organization_balances')
            ->where('organization_id', $organizationId)
            ->value('id');
            
        if (!$organizationBalanceId) {
            return ['balance' => 0, 'credits_this_month' => 0, 'debits_this_month' => 0];
        }

        // Баланс — последнее значение balance_after из транзакций по проекту
        $lastTx = BalanceTransaction::where('organization_balance_id', $organizationBalanceId)
            ->where('project_id', $projectId)
            ->orderByDesc('id')
            ->first();
        $balance = $lastTx?->balance_after ?? 0;

        // Пополнения и списания за текущий месяц по проекту
        $startOfMonth = now()->startOfMonth();
        $monthlyData = BalanceTransaction::where('organization_balance_id', $organizationBalanceId)
            ->where('project_id', $projectId)
            ->whereDate('created_at', '>=', $startOfMonth)
            ->selectRaw("
                SUM(CASE WHEN type = ? THEN amount ELSE 0 END) as credits,
                SUM(CASE WHEN type = ? THEN amount ELSE 0 END) as debits
            ", [BalanceTransaction::TYPE_CREDIT, BalanceTransaction::TYPE_DEBIT])
            ->first();

        return [
            'balance' => $balance / 100,
            'credits_this_month' => ($monthlyData->credits ?? 0) / 100,
            'debits_this_month' => ($monthlyData->debits ?? 0) / 100,
        ];
    }

    public function getProjectSummary(int $organizationId, int $projectId): array
    {
        try {
            // Получаем данные конкретного проекта
            $project = Project::where('organization_id', $organizationId)
                ->where('id', $projectId)
                ->select('status')
                ->first();

            if (!$project) {
                return ['total' => 0, 'active' => 0, 'completed' => 0];
            }

            return [
                'total' => 1,
                'active' => in_array($project->status, ['active']) ? 1 : 0,
                'completed' => in_array($project->status, ['completed']) ? 1 : 0,
            ];
        } catch (\Exception $e) {
            Log::error('Error in getProjectSummary: ' . $e->getMessage());
            return ['total' => 0, 'active' => 0, 'completed' => 0];
        }
    }

    public function getContractSummary(int $organizationId, int $projectId): array
    {
        try {
            // Получаем контракты конкретного проекта
            $contracts = Contract::where('organization_id', $organizationId)
                ->where('project_id', $projectId)
                ->select('status', 'total_amount')
                ->get();

            $total = $contracts->count();
            $active = $contracts->filter(function ($contract) {
                return in_array($contract->status, ['active']);
            })->count();
            $draft = $contracts->filter(function ($contract) {
                return in_array($contract->status, ['draft']);
            })->count();
            $completed = $contracts->filter(function ($contract) {
                return in_array($contract->status, ['completed']);
            })->count();
            $totalAmount = $contracts->sum('total_amount') ?? 0;

            return [
                'total' => $total,
                'active' => $active,
                'draft' => $draft,
                'completed' => $completed,
                'total_amount' => (float)$totalAmount,
            ];
        } catch (\Exception $e) {
            Log::error('Error in getContractSummary: ' . $e->getMessage());
            return ['total' => 0, 'active' => 0, 'draft' => 0, 'completed' => 0, 'total_amount' => 0];
        }
    }

    public function getWorkMaterialSummary(int $organizationId, int $projectId): array
    {
        // CompletedWorks по проекту
        $worksQuery = CompletedWork::where('organization_id', $organizationId)
            ->where('project_id', $projectId);
        
        $worksTotal = (clone $worksQuery)->count();
        $worksConfirmed = (clone $worksQuery)->where('status', 'confirmed')->count();
        $worksAmount = (clone $worksQuery)->where('status', 'confirmed')->sum('total_amount');

        // Materials по проекту
        $materialsTotal = Material::where('organization_id', $organizationId)
            ->where('project_id', $projectId)
            ->count();

        return [
            'works' => [
                'total' => $worksTotal,
                'confirmed' => $worksConfirmed,
                'confirmed_amount' => (float)$worksAmount,
            ],
            'materials' => [
                'total' => $materialsTotal,
            ],
        ];
    }

    public function getActSummary(int $organizationId, int $projectId): array
    {
        // Акты контрактов по проекту
        $actsQuery = ContractPerformanceAct::whereHas('contract', function ($q) use ($organizationId, $projectId) {
            $q->where('organization_id', $organizationId)
              ->where('project_id', $projectId);
        });
        
        $total = $actsQuery->count();
        $approved = (clone $actsQuery)->where('is_approved', true)->count();
        $totalAmount = (clone $actsQuery)->sum('amount');
        
        return [
            'total' => $total,
            'approved' => $approved,
            'total_amount' => (float)$totalAmount,
        ];
    }

    public function getTeamSummary(int $organizationId, int $projectId): array
    {
        // Получаем участников проекта
        $projectUsers = DB::table('user_projects')
            ->where('project_id', $projectId)
            ->pluck('user_id')
            ->toArray();
            
        if (empty($projectUsers)) {
            return ['total' => 0, 'by_roles' => []];
        }
        
        // Используем новую систему авторизации с user_role_assignments
        $context = \App\Domain\Authorization\Models\AuthorizationContext::getOrganizationContext($organizationId);
        
        $usersQuery = DB::table('users')
            ->join('user_role_assignments', 'users.id', '=', 'user_role_assignments.user_id')
            ->where('user_role_assignments.context_id', $context->id)
            ->where('user_role_assignments.is_active', true)
            ->whereIn('users.id', $projectUsers)
            ->whereNull('users.deleted_at');

        $rolesCount = $usersQuery->select('user_role_assignments.role_slug', DB::raw('COUNT(*) as cnt'))
            ->groupBy('user_role_assignments.role_slug')
            ->pluck('cnt', 'user_role_assignments.role_slug')
            ->toArray();

        $total = array_sum($rolesCount);

        return [
            'total' => $total,
            'by_roles' => $rolesCount,
        ];
    }

    /**
     * Получить детальный список участников команды проекта с ролями
     */
    public function getTeamDetails(int $organizationId, int $projectId): array
    {
        // Кеш для конкретного проекта
        $cacheKey = "team_details_{$organizationId}_project_{$projectId}";
            
        // ОПТИМИЗАЦИЯ: Кэшируем результат детальной информации о команде на 10 минут
        return cache()->remember($cacheKey, 600, function() use ($organizationId, $projectId) {
            try {
                // ОПТИМИЗАЦИЯ: Используем JOIN вместо whereHas для лучшей производительности
                $contextId = DB::table('authorization_contexts')
                    ->where('type', 'organization')
                    ->where('resource_id', $organizationId)
                    ->value('id');
                    
                if (!$contextId) {
                    return [];
                }

                $users = DB::table('users')
                    ->join('organization_user', 'users.id', '=', 'organization_user.user_id')
                    ->join('user_projects', 'users.id', '=', 'user_projects.user_id')
                    ->leftJoin('user_role_assignments', function($join) use ($contextId) {
                        $join->on('users.id', '=', 'user_role_assignments.user_id')
                             ->where('user_role_assignments.context_id', '=', $contextId)
                             ->where('user_role_assignments.is_active', '=', true);
                    })
                    ->where('organization_user.organization_id', $organizationId)
                    ->where('organization_user.is_active', true)
                    ->where('user_projects.project_id', $projectId)
                    ->whereNull('users.deleted_at')
                    ->select([
                        'users.id',
                        'users.name', 
                        'users.email',
                        'users.avatar_path',
                        DB::raw('STRING_AGG(user_role_assignments.role_slug, \',\') as roles')
                    ])
                    ->groupBy('users.id', 'users.name', 'users.email', 'users.avatar_path')
                    ->get();

                return $users->map(function ($user) {
                    return [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                        'avatar_path' => $user->avatar_path,
                        'roles' => $user->roles ? explode(',', $user->roles) : [],
                    ];
                })->toArray();
            } catch (\Exception $e) {
                Log::error('Error in getTeamDetails: ' . $e->getMessage(), [
                    'organization_id' => $organizationId,
                    'project_id' => $projectId,
                    'trace' => $e->getTraceAsString()
                ]);
                return [];
            }
        });
    }

    public function getTimeseries(string $metric, string $period, int $organizationId, int $projectId): array
    {
        // Поддерживаем metric = projects/contracts/completed_works, period = month
        $model = match ($metric) {
            'projects' => Project::class,
            'contracts' => Contract::class,
            'completed_works' => CompletedWork::class,
            default => null,
        };
        if (!$model) {
            return ['labels' => [], 'values' => []];
        }
        
        $dateField = 'created_at';
        $query = $model::where('organization_id', $organizationId);
        
        // Фильтрация по проекту (обязательно)
        if ($metric === 'projects') {
            $query->where('id', $projectId);
        } else {
            $query->where('project_id', $projectId);
        }

        $months = collect(range(0, 5))->map(fn ($i) => now()->subMonths($i)->startOfMonth())->sort();
        $labels = [];
        $values = [];
        foreach ($months as $month) {
            $labels[] = $month->format('Y-m');
            $values[] = (clone $query)->whereBetween($dateField, [$month, (clone $month)->endOfMonth()])->count();
        }
        return compact('labels', 'values');
    }

    /**
     * Распределение статусов по проекту
     */
    public function getStatusDistribution(string $entity, int $organizationId, int $projectId): array
    {
        return match ($entity) {
            'projects' => $this->distribution(Project::class, $organizationId, 'status', $projectId),
            'contracts' => $this->distribution(Contract::class, $organizationId, 'status', $projectId),
            default => [],
        };
    }

    private function distribution(string $modelClass, int $orgId, string $field, int $projectId): array
    {
        // БЕЗОПАСНЫЙ ПОДХОД: Получаем данные по проекту в коде для избежания SQL ошибок
        $query = $modelClass::where('organization_id', $orgId);
        
        // Фильтрация по проекту (обязательно)
        if ($modelClass === Project::class) {
            $query->where('id', $projectId);
        } else {
            // Для контрактов и других сущностей
            $query->where('project_id', $projectId);
        }
        
        $items = $query->select($field)->get();
            
        return $items->groupBy($field)->map(function ($group) {
            return $group->count();
        })->toArray();
    }

    /**
     * Баланс проекта на конец каждого месяца
     */
    public function getMonthlyBalance(int $organizationId, int $months, int $projectId): array
    {
        $labels = [];
        $values = [];
        $dates = collect(range(0, $months - 1))->map(fn ($i) => now()->subMonths($i)->endOfMonth())->sort();

        foreach ($dates as $date) {
            $labels[] = $date->format('Y-m');
            $lastTx = BalanceTransaction::whereHas('organizationBalance', function ($q) use ($organizationId) {
                    $q->where('organization_id', $organizationId);
                })
                ->where('project_id', $projectId)
                ->whereDate('created_at', '<=', $date)
                ->orderByDesc('created_at')
                ->first();
            
            $values[] = $lastTx ? $lastTx->balance_after / 100 : 0;
        }

        return compact('labels', 'values');
    }
} 