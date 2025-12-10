<?php

namespace App\BusinessModules\Features\Procurement\Services;

use App\Models\Material;
use App\Modules\Core\AccessController;

/**
 * Сервис для интеграции с модулем управления каталогом
 * 
 * Помогает автоматически заполнять данные о материалах
 * при создании заказов поставщикам
 */
class CatalogIntegrationService
{
    public function __construct(
        private readonly AccessController $accessController
    ) {}

    /**
     * Получить данные о материале из каталога
     * 
     * @param int $organizationId
     * @param int $materialId
     * @return array|null
     */
    public function getMaterialData(int $organizationId, int $materialId): ?array
    {
        // Проверяем активацию модуля catalog-management
        if (!$this->accessController->hasModuleAccess($organizationId, 'catalog-management')) {
            return null;
        }

        try {
            $material = Material::where('organization_id', $organizationId)
                ->where('id', $materialId)
                ->with(['measurementUnit'])
                ->first();

            if (!$material) {
                return null;
            }

            return [
                'id' => $material->id,
                'name' => $material->name,
                'code' => $material->code,
                'category' => $material->category,
                'unit' => $material->measurementUnit?->name ?? 'шт',
                'unit_code' => $material->measurementUnit?->code ?? 'pcs',
                'price' => $material->price ?? 0,
                'description' => $material->description,
                'manufacturer' => $material->manufacturer,
                'article' => $material->article,
                // Рекомендуемые поставщики (если есть в metadata)
                'recommended_suppliers' => $material->metadata['recommended_suppliers'] ?? [],
                // Средняя цена закупки (если есть)
                'average_purchase_price' => $material->metadata['average_purchase_price'] ?? null,
            ];
        } catch (\Exception $e) {
            \Log::error('procurement.catalog_integration.error', [
                'organization_id' => $organizationId,
                'material_id' => $materialId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Получить рекомендуемых поставщиков для материала
     * 
     * @param int $organizationId
     * @param int $materialId
     * @return array
     */
    public function getRecommendedSuppliers(int $organizationId, int $materialId): array
    {
        $materialData = $this->getMaterialData($organizationId, $materialId);

        if (!$materialData) {
            return [];
        }

        return $materialData['recommended_suppliers'] ?? [];
    }

    /**
     * Обновить среднюю цену закупки материала
     * 
     * @param int $organizationId
     * @param int $materialId
     * @param float $price
     */
    public function updateAveragePurchasePrice(int $organizationId, int $materialId, float $price): void
    {
        if (!$this->accessController->hasModuleAccess($organizationId, 'catalog-management')) {
            return;
        }

        try {
            $material = Material::where('organization_id', $organizationId)
                ->where('id', $materialId)
                ->first();

            if (!$material) {
                return;
            }

            $metadata = $material->metadata ?? [];
            $currentAverage = $metadata['average_purchase_price'] ?? null;
            $purchaseCount = $metadata['purchase_count'] ?? 0;

            // Рассчитываем новую среднюю цену
            if ($currentAverage === null) {
                $newAverage = $price;
            } else {
                $newAverage = (($currentAverage * $purchaseCount) + $price) / ($purchaseCount + 1);
            }

            $material->update([
                'metadata' => array_merge($metadata, [
                    'average_purchase_price' => $newAverage,
                    'purchase_count' => $purchaseCount + 1,
                    'last_purchase_price' => $price,
                    'last_purchase_at' => now()->toDateTimeString(),
                ]),
            ]);

            \Log::info('procurement.catalog.price_updated', [
                'material_id' => $materialId,
                'old_average' => $currentAverage,
                'new_average' => $newAverage,
            ]);
        } catch (\Exception $e) {
            \Log::error('procurement.catalog.price_update_failed', [
                'material_id' => $materialId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}

