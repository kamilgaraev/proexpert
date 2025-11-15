<?php

namespace App\Services\EstimatePositionCatalog;

use App\Models\EstimatePositionCatalogCategory;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class CategoryService
{
    private const CACHE_TTL = 3600; // 1 час

    /**
     * Получить все категории для организации
     */
    public function getAllCategories(int $organizationId): Collection
    {
        return EstimatePositionCatalogCategory::where('organization_id', $organizationId)
            ->orderBy('sort_order')
            ->with(['parent', 'children'])
            ->get();
    }

    /**
     * Получить дерево категорий с кешированием
     */
    public function getCategoryTree(int $organizationId): array
    {
        $cacheKey = "estimate_position_catalog_categories_tree_{$organizationId}";

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($organizationId) {
            return $this->buildCategoryTree($organizationId);
        });
    }

    /**
     * Построить дерево категорий
     */
    private function buildCategoryTree(int $organizationId): array
    {
        $rootCategories = EstimatePositionCatalogCategory::where('organization_id', $organizationId)
            ->whereNull('parent_id')
            ->active()
            ->orderBy('sort_order')
            ->get();

        return $rootCategories->map(function ($category) {
            return $category->getTree();
        })->toArray();
    }

    /**
     * Получить категорию по ID
     */
    public function getCategoryById(int $id, int $organizationId): ?EstimatePositionCatalogCategory
    {
        return EstimatePositionCatalogCategory::where('id', $id)
            ->where('organization_id', $organizationId)
            ->with(['parent', 'children', 'positions'])
            ->first();
    }

    /**
     * Создать категорию
     */
    public function createCategory(int $organizationId, array $data): EstimatePositionCatalogCategory
    {
        try {
            $data['organization_id'] = $organizationId;

            // Если не указан sort_order, поставить в конец
            if (!isset($data['sort_order'])) {
                $maxOrder = EstimatePositionCatalogCategory::where('organization_id', $organizationId)
                    ->where('parent_id', $data['parent_id'] ?? null)
                    ->max('sort_order');
                $data['sort_order'] = ($maxOrder ?? 0) + 1;
            }

            $category = EstimatePositionCatalogCategory::create($data);

            $this->clearCache($organizationId);

            Log::info('estimate_position_catalog_category.created', [
                'organization_id' => $organizationId,
                'category_id' => $category->id,
            ]);

            return $category->load(['parent', 'children']);
        } catch (\Exception $e) {
            Log::error('estimate_position_catalog_category.create_failed', [
                'organization_id' => $organizationId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Обновить категорию
     */
    public function updateCategory(int $id, int $organizationId, array $data): EstimatePositionCatalogCategory
    {
        try {
            $category = $this->getCategoryById($id, $organizationId);

            if (!$category) {
                throw new \RuntimeException('Category not found');
            }

            $category->update($data);

            $this->clearCache($organizationId);

            Log::info('estimate_position_catalog_category.updated', [
                'organization_id' => $organizationId,
                'category_id' => $category->id,
            ]);

            return $category->fresh(['parent', 'children']);
        } catch (\Exception $e) {
            Log::error('estimate_position_catalog_category.update_failed', [
                'id' => $id,
                'organization_id' => $organizationId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Удалить категорию
     */
    public function deleteCategory(int $id, int $organizationId): bool
    {
        try {
            $category = $this->getCategoryById($id, $organizationId);

            if (!$category) {
                throw new \RuntimeException('Category not found');
            }

            if (!$category->canBeDeleted()) {
                throw new \DomainException('Категория содержит подкатегории или позиции и не может быть удалена');
            }

            $category->delete();

            $this->clearCache($organizationId);

            Log::info('estimate_position_catalog_category.deleted', [
                'organization_id' => $organizationId,
                'category_id' => $id,
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('estimate_position_catalog_category.delete_failed', [
                'id' => $id,
                'organization_id' => $organizationId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Изменить порядок категорий
     */
    public function reorderCategories(int $organizationId, array $orderData): bool
    {
        try {
            foreach ($orderData as $item) {
                EstimatePositionCatalogCategory::where('id', $item['id'])
                    ->where('organization_id', $organizationId)
                    ->update(['sort_order' => $item['sort_order']]);
            }

            $this->clearCache($organizationId);

            Log::info('estimate_position_catalog_categories.reordered', [
                'organization_id' => $organizationId,
                'count' => count($orderData),
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('estimate_position_catalog_categories.reorder_failed', [
                'organization_id' => $organizationId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Очистить кеш категорий для организации
     */
    private function clearCache(int $organizationId): void
    {
        $cacheKey = "estimate_position_catalog_categories_tree_{$organizationId}";
        Cache::forget($cacheKey);
    }
}

