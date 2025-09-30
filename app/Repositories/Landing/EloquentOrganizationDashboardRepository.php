<?php

namespace App\Repositories\Landing;

use Illuminate\Support\Facades\DB;
use App\Models\Project;
use App\Models\Contract;
use App\Models\CompletedWork;
use App\Models\ContractPerformanceAct;
use App\Models\BalanceTransaction;
use App\Models\Material;

class EloquentOrganizationDashboardRepository implements OrganizationDashboardRepositoryInterface
{
    public function getFinancialSummary(int $organizationId): array
    {
        // ОПТИМИЗАЦИЯ: Используем JOIN вместо whereHas для лучшей производительности
        $organizationBalanceId = DB::table('organization_balances')
            ->where('organization_id', $organizationId)
            ->value('id');
            
        if (!$organizationBalanceId) {
            return ['balance' => 0, 'credits_this_month' => 0, 'debits_this_month' => 0];
        }

        // Баланс — последнее значение balance_after из транзакций
        $lastTx = BalanceTransaction::where('organization_balance_id', $organizationBalanceId)
            ->orderByDesc('id')
            ->first();
        $balance = $lastTx?->balance_after ?? 0;

        // Пополнения и списания за текущий месяц в одном запросе
        $startOfMonth = now()->startOfMonth();
        $monthlyData = BalanceTransaction::where('organization_balance_id', $organizationBalanceId)
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

    public function getProjectSummary(int $organizationId): array
    {
        // ОПТИМИЗАЦИЯ: Один запрос вместо трех
        $data = Project::where('organization_id', $organizationId)
            ->selectRaw('
                COUNT(*) as total,
                COUNT(CASE WHEN status = "active" THEN 1 END) as active,
                COUNT(CASE WHEN status = "completed" THEN 1 END) as completed
            ')
            ->first();

        return [
            'total' => (int)$data->total,
            'active' => (int)$data->active,
            'completed' => (int)$data->completed,
        ];
    }

    public function getContractSummary(int $organizationId): array
    {
        // ОПТИМИЗАЦИЯ: Один запрос вместо пяти
        $data = Contract::where('organization_id', $organizationId)
            ->selectRaw('
                COUNT(*) as total,
                COUNT(CASE WHEN status = "active" THEN 1 END) as active,
                COUNT(CASE WHEN status = "draft" THEN 1 END) as draft,
                COUNT(CASE WHEN status = "completed" THEN 1 END) as completed,
                SUM(total_amount) as total_amount
            ')
            ->first();

        return [
            'total' => (int)$data->total,
            'active' => (int)$data->active,
            'draft' => (int)$data->draft,
            'completed' => (int)$data->completed,
            'total_amount' => (float)($data->total_amount ?? 0),
        ];
    }

    public function getWorkMaterialSummary(int $organizationId): array
    {
        // CompletedWorks
        $worksTotal = CompletedWork::where('organization_id', $organizationId)->count();
        $worksConfirmed = CompletedWork::where('organization_id', $organizationId)->where('status', 'confirmed')->count();
        $worksAmount = CompletedWork::where('organization_id', $organizationId)->where('status', 'confirmed')->sum('total_amount');

        // Materials (расход)
        $materialsTotal = Material::where('organization_id', $organizationId)->count();

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

    public function getActSummary(int $organizationId): array
    {
        // Акт относится к контракту, поэтому связываем через контракт
        $actsQuery = ContractPerformanceAct::whereHas('contract', function ($q) use ($organizationId) {
            $q->where('organization_id', $organizationId);
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

    public function getTeamSummary(int $organizationId): array
    {
        // Используем новую систему авторизации с user_role_assignments
        $context = \App\Domain\Authorization\Models\AuthorizationContext::getOrganizationContext($organizationId);
        
        $usersQuery = DB::table('users')
            ->join('user_role_assignments', 'users.id', '=', 'user_role_assignments.user_id')
            ->where('user_role_assignments.context_id', $context->id)
            ->where('user_role_assignments.is_active', true)
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
     * Получить детальный список участников команды с ролями.
     */
    public function getTeamDetails(int $organizationId): array
    {
        // ОПТИМИЗАЦИЯ: Кэшируем результат детальной информации о команде на 10 минут
        return cache()->remember("team_details_{$organizationId}", 600, function() use ($organizationId) {
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
                ->leftJoin('user_role_assignments', function($join) use ($contextId) {
                    $join->on('users.id', '=', 'user_role_assignments.user_id')
                         ->where('user_role_assignments.context_id', '=', $contextId)
                         ->where('user_role_assignments.is_active', '=', true);
                })
                ->where('organization_user.organization_id', $organizationId)
                ->where('organization_user.is_active', true)
                ->whereNull('users.deleted_at')
                ->select([
                    'users.id',
                    'users.name', 
                    'users.email',
                    'users.avatar_url',
                    DB::raw('GROUP_CONCAT(user_role_assignments.role_slug) as roles')
                ])
                ->groupBy('users.id', 'users.name', 'users.email', 'users.avatar_url')
                ->get();

            return $users->map(function ($user) {
                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'avatar_url' => $user->avatar_url,
                    'roles' => $user->roles ? explode(',', $user->roles) : [],
                ];
            })->toArray();
        });
    }

    public function getTimeseries(string $metric, string $period, int $organizationId): array
    {
        // На данный момент поддерживаем metric = projects/contracts, period = month
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
     * Распределение статусов.
     */
    public function getStatusDistribution(string $entity, int $organizationId): array
    {
        return match ($entity) {
            'projects' => $this->distribution(Project::class, $organizationId, 'status'),
            'contracts' => $this->distribution(Contract::class, $organizationId, 'status'),
            default => [],
        };
    }

    private function distribution(string $modelClass, int $orgId, string $field): array
    {
        return $modelClass::where('organization_id', $orgId)
            ->select($field, DB::raw('COUNT(*) as cnt'))
            ->groupBy($field)
            ->pluck('cnt', $field)
            ->toArray();
    }

    /**
     * Баланс на конец каждого месяца.
     */
    public function getMonthlyBalance(int $organizationId, int $months = 6): array
    {
        $labels = [];
        $values = [];
        $dates = collect(range(0, $months - 1))->map(fn ($i) => now()->subMonths($i)->endOfMonth())->sort();

        foreach ($dates as $date) {
            $labels[] = $date->format('Y-m');
            $lastTx = BalanceTransaction::whereHas('organizationBalance', function ($q) use ($organizationId) {
                    $q->where('organization_id', $organizationId);
                })
                ->whereDate('created_at', '<=', $date)
                ->orderByDesc('created_at')
                ->first();
            $values[] = $lastTx ? $lastTx->balance_after / 100 : 0;
        }

        return compact('labels', 'values');
    }
} 