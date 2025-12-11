<?php

namespace App\Services\EstimatePositionCatalog;

use App\Models\EstimatePositionCatalog;
use App\Models\EstimateItem;
use App\Models\Estimate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Pagination\LengthAwarePaginator;

class EstimatePositionCatalogService
{
    public function __construct(
        private readonly PriceHistoryService $priceHistoryService
    ) {}

    /**
     * Получить список позиций с пагинацией
     */
    public function getAllPositions(
        int $organizationId,
        int $perPage = 15,
        array $filters = [],
        string $sortBy = 'name',
        string $sortDirection = 'asc'
    ): LengthAwarePaginator {
        $query = EstimatePositionCatalog::where('organization_id', $organizationId);

        // Применить фильтры
        if (!empty($filters['category_id'])) {
            $query->where('category_id', $filters['category_id']);
        }

        if (!empty($filters['item_type'])) {
            $query->where('item_type', $filters['item_type']);
        }

        if (!empty($filters['is_active'])) {
            $query->where('is_active', $filters['is_active']);
        }

        if (!empty($filters['search'])) {
            $query->search($filters['search']);
        }

        // Сортировка
        $query->orderBy($sortBy, $sortDirection);

        return $query->with(['category', 'measurementUnit', 'workType', 'creator'])
            ->paginate($perPage);
    }

    /**
     * Получить позицию по ID
     */
    public function getPositionById(int $id, int $organizationId): ?EstimatePositionCatalog
    {
        return EstimatePositionCatalog::where('id', $id)
            ->where('organization_id', $organizationId)
            ->with(['category', 'measurementUnit', 'workType', 'creator', 'priceHistory'])
            ->first();
    }

    /**
     * Создать новую позицию
     */
    public function createPosition(int $organizationId, int $userId, array $data): EstimatePositionCatalog
    {
        try {
            $data['organization_id'] = $organizationId;
            $data['created_by_user_id'] = $userId;

            $position = EstimatePositionCatalog::create($data);

            Log::info('estimate_position_catalog.created', [
                'organization_id' => $organizationId,
                'position_id' => $position->id,
                'user_id' => $userId,
            ]);

            return $position->load(['category', 'measurementUnit', 'workType']);
        } catch (\Exception $e) {
            Log::error('estimate_position_catalog.create_failed', [
                'organization_id' => $organizationId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Обновить позицию
     */
    public function updatePosition(int $id, int $organizationId, array $data, int $userId): EstimatePositionCatalog
    {
        try {
            $position = $this->getPositionById($id, $organizationId);

            if (!$position) {
                throw new \RuntimeException('Position not found');
            }

            // Если изменилась цена, сохранить в истории
            if (isset($data['unit_price']) && $data['unit_price'] != $position->unit_price) {
                $this->priceHistoryService->trackPriceChange(
                    $position->id,
                    $position->unit_price,
                    $data['unit_price'],
                    $userId,
                    $data['price_change_reason'] ?? null
                );
            }

            $position->update($data);

            Log::info('estimate_position_catalog.updated', [
                'organization_id' => $organizationId,
                'position_id' => $position->id,
                'user_id' => $userId,
            ]);

            return $position->fresh(['category', 'measurementUnit', 'workType']);
        } catch (\Exception $e) {
            Log::error('estimate_position_catalog.update_failed', [
                'id' => $id,
                'organization_id' => $organizationId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Удалить позицию
     */
    public function deletePosition(int $id, int $organizationId): bool
    {
        try {
            $position = $this->getPositionById($id, $organizationId);

            if (!$position) {
                throw new \RuntimeException('Position not found');
            }

            // if (!$position->canBeDeleted()) {
            //     throw new \DomainException('Позиция используется в сметах и не может быть удалена');
            // }

            // Помечаем как неактивную перед удалением для надежности
            $position->update(['is_active' => false]);
            $position->delete();

            Log::info('estimate_position_catalog.deleted', [
                'organization_id' => $organizationId,
                'position_id' => $id,
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('estimate_position_catalog.delete_failed', [
                'id' => $id,
                'organization_id' => $organizationId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Поиск позиций
     */
    public function search(int $organizationId, string $query, array $filters = []): LengthAwarePaginator
    {
        $filters['search'] = $query;
        return $this->getAllPositions($organizationId, 15, $filters);
    }

    /**
     * Получить позиции по категории
     */
    public function getByCategory(int $organizationId, ?int $categoryId, int $perPage = 15): LengthAwarePaginator
    {
        return $this->getAllPositions($organizationId, $perPage, ['category_id' => $categoryId]);
    }

    /**
     * Применить позицию из справочника к смете
     */
    public function applyToEstimate(
        int $catalogItemId,
        int $estimateId,
        ?int $sectionId,
        float $quantity,
        int $userId
    ): EstimateItem {
        return DB::transaction(function () use ($catalogItemId, $estimateId, $sectionId, $quantity, $userId) {
            $catalogItem = EstimatePositionCatalog::findOrFail($catalogItemId);
            $estimate = Estimate::findOrFail($estimateId);

            // Создать позицию сметы из справочника
            $estimateItem = EstimateItem::create([
                'estimate_id' => $estimateId,
                'estimate_section_id' => $sectionId,
                'catalog_item_id' => $catalogItemId,
                'item_type' => $catalogItem->item_type,
                'name' => $catalogItem->name,
                'description' => $catalogItem->description,
                'measurement_unit_id' => $catalogItem->measurement_unit_id,
                'work_type_id' => $catalogItem->work_type_id,
                'quantity' => $quantity,
                'unit_price' => $catalogItem->unit_price,
                'direct_costs' => $catalogItem->direct_costs ?? $catalogItem->unit_price * $quantity,
                'total_amount' => $catalogItem->unit_price * $quantity,
                'is_manual' => true,
                'metadata' => [
                    'source' => 'catalog',
                    'catalog_item_id' => $catalogItemId,
                    'created_by' => $userId,
                ],
            ]);

            // Увеличить счетчик использований
            $catalogItem->incrementUsage();

            Log::info('estimate_position_catalog.applied_to_estimate', [
                'catalog_item_id' => $catalogItemId,
                'estimate_id' => $estimateId,
                'estimate_item_id' => $estimateItem->id,
                'user_id' => $userId,
            ]);

            return $estimateItem->load(['measurementUnit', 'workType', 'catalogItem']);
        });
    }
}

