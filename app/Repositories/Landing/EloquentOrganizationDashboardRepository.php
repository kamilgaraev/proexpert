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
        // Баланс — последнее значение balance_after из транзакций
        $lastTx = BalanceTransaction::whereHas('organizationBalance', function ($q) use ($organizationId) {
            $q->where('organization_id', $organizationId);
        })->orderByDesc('id')->first();
        $balance = $lastTx?->balance_after ?? 0;

        // Пополнения и списания за текущий месяц
        $startOfMonth = now()->startOfMonth();
        $credits = BalanceTransaction::whereHas('organizationBalance', function ($q) use ($organizationId) {
            $q->where('organization_id', $organizationId);
        })->where('type', BalanceTransaction::TYPE_CREDIT)
          ->whereDate('created_at', '>=', $startOfMonth)
          ->sum('amount');
        $debits = BalanceTransaction::whereHas('organizationBalance', function ($q) use ($organizationId) {
            $q->where('organization_id', $organizationId);
        })->where('type', BalanceTransaction::TYPE_DEBIT)
          ->whereDate('created_at', '>=', $startOfMonth)
          ->sum('amount');

        return [
            'balance' => $balance / 100,
            'credits_this_month' => $credits / 100,
            'debits_this_month' => $debits / 100,
        ];
    }

    public function getProjectSummary(int $organizationId): array
    {
        $total = Project::where('organization_id', $organizationId)->count();
        $active = Project::where('organization_id', $organizationId)->where('status', 'active')->count();
        $completed = Project::where('organization_id', $organizationId)->where('status', 'completed')->count();

        return compact('total', 'active', 'completed');
    }

    public function getContractSummary(int $organizationId): array
    {
        $query = Contract::where('organization_id', $organizationId);
        $total = $query->count();
        $active = (clone $query)->where('status', 'active')->count();
        $draft = (clone $query)->where('status', 'draft')->count();
        $completed = (clone $query)->where('status', 'completed')->count();
        $totalAmount = (clone $query)->sum('total_amount');
        return [
            'total' => $total,
            'active' => $active,
            'draft' => $draft,
            'completed' => $completed,
            'total_amount' => (float)$totalAmount,
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
        // Считаем пользователей и распределяем по ролям через pivot role_user
        $usersQuery = DB::table('users')
            ->join('role_user', 'users.id', '=', 'role_user.user_id')
            ->join('roles', 'role_user.role_id', '=', 'roles.id')
            ->where('role_user.organization_id', $organizationId)
            ->whereNull('users.deleted_at');

        $rolesCount = $usersQuery->select('roles.slug', DB::raw('COUNT(*) as cnt'))
            ->groupBy('roles.slug')
            ->pluck('cnt', 'roles.slug')
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
        $users = \App\Models\User::whereHas('organizations', function ($q) use ($organizationId) {
                $q->where('organization_id', $organizationId);
            })
            ->with(['roles' => function ($q) use ($organizationId) {
                $q->where('role_user.organization_id', $organizationId);
            }])
            ->whereNull('deleted_at')
            ->get();

        return $users->map(function ($user) {
            return [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'avatar_url' => $user->avatar_url,
                'roles' => $user->roles->pluck('slug')->values()->all(),
            ];
        })->toArray();
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