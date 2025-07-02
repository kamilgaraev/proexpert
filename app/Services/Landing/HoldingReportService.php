<?php

namespace App\Services\Landing;

use App\Models\Contract;
use App\Models\ContractPerformanceAct;
use App\Models\BalanceTransaction;
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
} 