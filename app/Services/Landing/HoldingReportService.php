<?php

namespace App\Services\Landing;

use App\Models\Contract;
use App\Models\ContractPerformanceAct;
use App\Models\BalanceTransaction;
use App\Models\Project;
use App\Models\Organization;
use App\Models\CompletedWork;
use Illuminate\Support\Collection;

/**
 * Сервис для построения сводных отчётов по холдингу (нескольким организациям).
 *
 * Методы сервиса получают массив идентификаторов организаций, к которым
 * у текущего пользователя уже проверен доступ.
 * В дальнейшем сюда можно вынести кеширование, материализованные представления и т.д.
 */
class HoldingReportService
{
    /**
     * Получить список договоров по набору организаций.
     */
    public function getConsolidatedContracts(array $organizationIds, array $filters = []): Collection
    {
        $query = Contract::query()
            ->whereIn('organization_id', $organizationIds)
            ->with(['organization', 'contractor']);

        // Фильтрация по дате заключения
        if (isset($filters['date_from'])) {
            $query->whereDate('date', '>=', $filters['date_from']);
        }
        if (isset($filters['date_to'])) {
            $query->whereDate('date', '<=', $filters['date_to']);
        }

        // Фильтр по статусу
        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return $query->get();
    }

    /**
     * Получить агрегированную статистику по договорам.
     */
    public function getContractsSummary(array $organizationIds, array $filters = []): array
    {
        $query = Contract::query()->whereIn('organization_id', $organizationIds);

        if (isset($filters['date_from'])) {
            $query->whereDate('date', '>=', $filters['date_from']);
        }
        if (isset($filters['date_to'])) {
            $query->whereDate('date', '<=', $filters['date_to']);
        }
        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return [
            'count' => $query->count(),
            'total_amount' => (float) $query->sum('total_amount'),
        ];
    }

    /**
     * Получить список актов выполненных работ по группам организаций.
     */
    public function getConsolidatedActs(array $organizationIds, array $filters = []): Collection
    {
        $query = ContractPerformanceAct::query()
            ->whereIn('organization_id', $organizationIds)
            ->with(['contract', 'organization']);

        if (isset($filters['date_from'])) {
            $query->whereDate('date', '>=', $filters['date_from']);
        }
        if (isset($filters['date_to'])) {
            $query->whereDate('date', '<=', $filters['date_to']);
        }
        if (isset($filters['is_approved'])) {
            $query->where('is_approved', $filters['is_approved']);
        }

        return $query->get();
    }

    /**
     * Получить движения денежных средств (BalanceTransaction) по нескольким организациям.
     * Возвращает коллекцию для унификации с ресурсами.
     */
    public function getMoneyMovements(array $organizationIds, array $filters = []): Collection
    {
        $query = BalanceTransaction::query()
            ->whereIn('organization_id', $organizationIds)
            ->with('organization');

        if (isset($filters['date_from'])) {
            $query->whereDate('created_at', '>=', $filters['date_from']);
        }
        if (isset($filters['date_to'])) {
            $query->whereDate('created_at', '<=', $filters['date_to']);
        }
        if (!empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        return $query->latest()->get();
    }

    /**
     * Получить список проектов по нескольким организациям.
     */
    public function getConsolidatedProjects(array $organizationIds, array $filters = []): Collection
    {
        $query = Project::query()
            ->whereIn('organization_id', $organizationIds)
            ->with(['organization']);

        if (isset($filters['date_from'])) {
            $query->whereDate('start_date', '>=', $filters['date_from']);
        }
        if (isset($filters['date_to'])) {
            $query->whereDate('end_date', '<=', $filters['date_to']);
        }
        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return $query->get();
    }

    /**
     * Информация по организациям холдинга.
     */
    public function getOrganizationsInfo(array $organizationIds): Collection
    {
        return Organization::whereIn('id', $organizationIds)
            ->withCount(['projects', 'contracts'])
            ->get()
            ->map(function (Organization $org) {
                return [
                    'id' => $org->id,
                    'name' => $org->name,
                    'is_active' => $org->is_active,
                    'projects_count' => $org->projects_count,
                    'contracts_count' => $org->contracts_count,
                ];
            });
    }

    /**
     * Общая статистика по организациям.
     */
    public function getGlobalStats(array $organizationIds, array $filters = []): array
    {
        $contracts = $this->getContractsSummary($organizationIds, $filters);

        $actsTotal = $this->getConsolidatedActs($organizationIds, $filters)->sum('amount');
        $actsCount = $this->getConsolidatedActs($organizationIds, $filters)->count();

        $projectsCount = Project::whereIn('organization_id', $organizationIds)->count();

        return [
            'contracts' => $contracts,
            'acts' => [
                'count' => $actsCount,
                'total_amount' => (float) $actsTotal,
            ],
            'projects' => [
                'count' => $projectsCount,
            ],
        ];
    }

    /**
     * Получить выполненные работы по организациям.
     */
    public function getConsolidatedCompletedWorks(array $organizationIds, array $filters = []): Collection
    {
        $query = CompletedWork::query()
            ->whereIn('organization_id', $organizationIds)
            ->with(['organization', 'project', 'contract', 'workType']);

        if (isset($filters['date_from'])) {
            $query->whereDate('completion_date', '>=', $filters['date_from']);
        }
        if (isset($filters['date_to'])) {
            $query->whereDate('completion_date', '<=', $filters['date_to']);
        }
        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return $query->get();
    }
} 