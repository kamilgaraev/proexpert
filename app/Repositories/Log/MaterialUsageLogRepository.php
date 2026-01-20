<?php

namespace App\Repositories\Log;

use App\Repositories\Interfaces\Log\MaterialUsageLogRepositoryInterface;
use Illuminate\Support\Collection;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;

/**
 * Заглушка-репозиторий для MaterialUsageLog
 * 
 * ВНИМАНИЕ: Эта функциональность устарела и больше не используется.
 * Используйте модуль складского учета (OrganizationWarehouse + WarehouseBalance)
 * для управления материалами и их движением.
 */
class MaterialUsageLogRepository implements MaterialUsageLogRepositoryInterface
{
    /**
     * Получить агрегированные данные по расходу материалов
     * 
     * @deprecated Используйте модуль складского учета
     * @return Collection
     */
    public function getAggregatedUsage(int $organizationId, array $filters = []): Collection
    {
        // Возвращаем пустую коллекцию - данные теперь в warehouse_balances
        return collect([]);
    }

    /**
     * Получить пагинированный список логов использования материалов
     * 
     * @deprecated Используйте модуль складского учета
     * @return LengthAwarePaginator
     */
    public function getPaginatedLogs(
        int $organizationId,
        int $perPage = 15,
        array $filters = [],
        string $sortBy = 'usage_date',
        string $sortDirection = 'desc'
    ): LengthAwarePaginator {
        // Возвращаем пустой пагинатор - данные теперь в warehouse_movements
        return new Paginator(
            collect([]),
            0,
            $perPage,
            1,
            ['path' => request()->url(), 'query' => request()->query()]
        );
    }

    /**
     * Все остальные методы из BaseRepositoryInterface (не используются)
     */
    public function find(int $id)
    {
        return null;
    }

    public function findOrFail(int $id)
    {
        throw new \Exception('MaterialUsageLog больше не используется. Используйте модуль складского учета.');
    }

    public function all(): Collection
    {
        return collect([]);
    }

    public function paginate(int $perPage = 15, array $filters = [], string $sortBy = 'id', string $sortDirection = 'asc'): LengthAwarePaginator
    {
        return new Paginator(collect([]), 0, $perPage, 1);
    }

    public function create(array $data)
    {
        throw new \Exception('MaterialUsageLog больше не используется. Используйте модуль складского учета.');
    }

    public function update(int $id, array $data): bool
    {
        throw new \Exception('MaterialUsageLog больше не используется. Используйте модуль складского учета.');
    }

    public function delete(int $id): bool
    {
        throw new \Exception('MaterialUsageLog больше не используется. Используйте модуль складского учета.');
    }
}
