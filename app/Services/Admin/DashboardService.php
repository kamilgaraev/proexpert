<?php

namespace App\Services\Admin;

use App\Repositories\UserRepository;
use App\Repositories\ProjectRepository;
use App\Repositories\MaterialRepository;
use App\Repositories\SupplierRepository;
use App\Repositories\ContractRepository;
use App\Repositories\CompletedWork\CompletedWorkRepository;
use Illuminate\Http\Request;
use App\Models\User;
use App\Models\Project;
use App\Models\Material;
use App\Models\Supplier;
use App\Models\Contract;
use App\Models\CompletedWork;

class DashboardService
{
    protected UserRepository $userRepository;
    protected ProjectRepository $projectRepository;
    protected MaterialRepository $materialRepository;
    protected SupplierRepository $supplierRepository;
    protected ContractRepository $contractRepository;
    protected CompletedWorkRepository $completedWorkRepository;

    public function __construct(
        UserRepository $userRepository,
        ProjectRepository $projectRepository,
        MaterialRepository $materialRepository,
        SupplierRepository $supplierRepository,
        ContractRepository $contractRepository,
        CompletedWorkRepository $completedWorkRepository
    ) {
        $this->userRepository = $userRepository;
        $this->projectRepository = $projectRepository;
        $this->materialRepository = $materialRepository;
        $this->supplierRepository = $supplierRepository;
        $this->contractRepository = $contractRepository;
        $this->completedWorkRepository = $completedWorkRepository;
    }

    /**
     * Получить сводную информацию по всей системе
     */
    public function getSummary(Request $request): array
    {
        return [
            'users_count' => User::count(),
            'projects_count' => Project::count(),
            'materials_count' => Material::count(),
            'suppliers_count' => Supplier::count(),
            'contracts_count' => Contract::count(),
            'completed_works_count' => CompletedWork::count(),
            // Можно добавить другие метрики по необходимости
        ];
    }

    /**
     * Получить временной ряд по выбранной метрике
     */
    public function getTimeseries(string $metric, string $period = 'month', ?int $organizationId = null): array
    {
        // Пример: динамика новых пользователей, проектов, материалов по дням/неделям/месяцам
        $model = match ($metric) {
            'users' => User::class,
            'projects' => Project::class,
            'materials' => Material::class,
            'suppliers' => Supplier::class,
            'contracts' => Contract::class,
            'completed_works' => CompletedWork::class,
            default => null,
        };
        if (!$model) {
            return ['labels' => [], 'values' => [], 'metric' => $metric];
        }
        $dateField = 'created_at';
        $query = $model::query();
        if ($organizationId && in_array('organization_id', (new $model)->getFillable())) {
            $query->where('organization_id', $organizationId);
        }
        $start = now()->subMonths(6)->startOfMonth();
        $end = now();
        $labels = [];
        $values = [];
        $periods = [];
        $current = $start->copy();
        while ($current <= $end) {
            $periods[] = $current->format('Y-m');
            $current->addMonth();
        }
        foreach ($periods as $period) {
            $count = (clone $query)
                ->whereBetween($dateField, [
                    $period . '-01 00:00:00',
                    now()->parse($period . '-01')->endOfMonth()->format('Y-m-d 23:59:59')
                ])->count();
            $labels[] = $period;
            $values[] = $count;
        }
        return [
            'labels' => $labels,
            'values' => $values,
            'metric' => $metric
        ];
    }

    /**
     * Получить топ-5 сущностей по активности/объёму
     */
    public function getTopEntities(string $entity, string $period = 'month', ?int $organizationId = null): array
    {
        // Пример: топ-5 проектов по количеству материалов
        if ($entity === 'projects') {
            $query = Project::query();
            if ($organizationId) {
                $query->where('organization_id', $organizationId);
            }
            $top = $query->withCount('materials')
                ->orderByDesc('materials_count')
                ->limit(5)
                ->get(['id', 'name', 'materials_count']);
            return $top->toArray();
        }
        // Можно добавить другие сущности
        return [];
    }

    /**
     * Получить историю последних действий/операций
     */
    public function getHistory(string $type = 'materials', int $limit = 10, ?int $organizationId = null): array
    {
        // Пример: история добавления материалов
        if ($type === 'materials') {
            $query = Material::query();
            if ($organizationId) {
                $query->where('organization_id', $organizationId);
            }
            $history = $query->orderByDesc('created_at')->limit($limit)->get(['id', 'name', 'created_at']);
            return $history->toArray();
        }
        // Можно добавить другие типы истории
        return [];
    }

    /**
     * Получить лимиты и их заполнение (пример)
     */
    public function getLimits(?int $organizationId = null): array
    {
        // Пример: лимиты по материалам (заглушка)
        return [
            'monthly_limit' => 50000,
            'monthly_used' => 45790,
            'yearly_limit' => 100000,
            'yearly_used' => 52780,
        ];
    }
} 