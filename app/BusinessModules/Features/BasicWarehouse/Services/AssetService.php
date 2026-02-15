<?php

namespace App\BusinessModules\Features\BasicWarehouse\Services;

use App\BusinessModules\Features\BasicWarehouse\Models\Asset;
use App\Models\Material;
use App\Services\Logging\LoggingService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Сервис управления активами
 * Расширяет функциональность MaterialService для работы с разными типами активов
 */
class AssetService
{
    protected LoggingService $logging;

    public function __construct(LoggingService $logging)
    {
        $this->logging = $logging;
    }

    /**
     * Создать актив
     */
    public function createAsset(int $organizationId, array $data): Asset
    {
        // Подготовка additional_properties с типом актива
        $additionalProperties = $data['additional_properties'] ?? [];
        $additionalProperties['asset_type'] = $data['asset_type'] ?? Asset::TYPE_MATERIAL;
        
        if (isset($data['asset_category'])) {
            $additionalProperties['asset_category'] = $data['asset_category'];
        }
        
        if (isset($data['asset_subcategory'])) {
            $additionalProperties['asset_subcategory'] = $data['asset_subcategory'];
        }
        
        if (isset($data['asset_attributes'])) {
            $additionalProperties['asset_attributes'] = $data['asset_attributes'];
        }

        $assetData = array_merge($data, [
            'organization_id' => $organizationId,
            'additional_properties' => $additionalProperties,
        ]);

        $asset = Asset::create($assetData);

        $this->logging->business('asset.created', [
            'organization_id' => $organizationId,
            'asset_id' => $asset->id,
            'asset_type' => $additionalProperties['asset_type'],
            'asset_name' => $asset->name,
        ]);

        $this->clearAssetCache($organizationId);

        return $asset;
    }

    /**
     * Обновить актив
     */
    public function updateAsset(int $assetId, array $data): Asset
    {
        $asset = Asset::findOrFail($assetId);

        // Обновляем additional_properties
        if (isset($data['asset_type']) || isset($data['asset_category']) || 
            isset($data['asset_subcategory']) || isset($data['asset_attributes'])) {
            
            $additionalProperties = $asset->additional_properties ?? [];
            
            if (isset($data['asset_type'])) {
                $additionalProperties['asset_type'] = $data['asset_type'];
            }
            if (isset($data['asset_category'])) {
                $additionalProperties['asset_category'] = $data['asset_category'];
            }
            if (isset($data['asset_subcategory'])) {
                $additionalProperties['asset_subcategory'] = $data['asset_subcategory'];
            }
            if (isset($data['asset_attributes'])) {
                $additionalProperties['asset_attributes'] = $data['asset_attributes'];
            }
            
            $data['additional_properties'] = $additionalProperties;
        }

        $asset->update($data);

        $this->logging->business('asset.updated', [
            'organization_id' => $asset->organization_id,
            'asset_id' => $asset->id,
            'asset_type' => $asset->asset_type,
        ]);

        $this->clearAssetCache($asset->organization_id);

        return $asset->fresh();
    }

    /**
     * Получить активы с пагинацией
     */
    public function getAssets(
        int $organizationId,
        array $filters = [],
        int $perPage = 15
    ): LengthAwarePaginator {
        $query = Asset::where('organization_id', $organizationId)
            ->where('is_active', true)
            ->with(['measurementUnit', 'warehouseBalances']);

        // Фильтр по типу актива
        if (isset($filters['asset_type'])) {
            $query->ofType($filters['asset_type']);
        }

        // Фильтр по категории
        if (isset($filters['category'])) {
            $query->where('category', $filters['category']);
        }

        // Фильтр по категории актива
        if (isset($filters['asset_category'])) {
            $query->ofCategory($filters['asset_category']);
        }

        // Поиск по имени или коду
        if (isset($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%");
            });
        }

        // Сортировка
        $sortBy = $filters['sort_by'] ?? 'name';
        $sortOrder = $filters['sort_order'] ?? 'asc';
        $query->orderBy($sortBy, $sortOrder);

        return $query->paginate($perPage);
    }

    /**
     * Получить актив по ID
     */
    public function getAssetById(int $assetId): Asset
    {
        return Asset::with([
            'measurementUnit',
            'warehouseBalances.warehouse',
            'receipts',
            'writeOffs'
        ])->findOrFail($assetId);
    }

    /**
     * Получить активы по типу
     */
    public function getAssetsByType(int $organizationId, string $type): \Illuminate\Database\Eloquent\Collection
    {
        return Cache::remember(
            "assets_by_type_{$organizationId}_{$type}",
            3600,
            fn() => Asset::where('organization_id', $organizationId)
                ->where('is_active', true)
                ->ofType($type)
                ->orderBy('name')
                ->get()
        );
    }

    /**
     * Получить статистику по типам активов
     */
    public function getAssetTypeStatistics(int $organizationId): array
    {
        return Cache::remember(
            "asset_type_stats_{$organizationId}",
            600,
            function () use ($organizationId) {
                $stats = [];
                
                foreach (Asset::getAssetTypes() as $type => $label) {
                    $count = Asset::where('organization_id', $organizationId)
                        ->where('is_active', true)
                        ->ofType($type)
                        ->count();
                    
                    $totalValue = Asset::where('materials.organization_id', $organizationId)
                        ->where('materials.is_active', true)
                        ->ofType($type)
                        ->join('warehouse_balances', 'materials.id', '=', 'warehouse_balances.material_id')
                        ->selectRaw('SUM(warehouse_balances.available_quantity * warehouse_balances.unit_price) as total')
                        ->value('total') ?? 0;
                    
                    $stats[$type] = [
                        'label' => $label,
                        'count' => $count,
                        'total_value' => (float)$totalValue,
                    ];
                }
                
                return $stats;
            }
        );
    }

    /**
     * Получить активы с низкими остатками
     */
    public function getLowStockAssets(int $organizationId, int $warehouseId = null): \Illuminate\Database\Eloquent\Collection
    {
        $query = Asset::where('organization_id', $organizationId)
            ->where('is_active', true)
            ->whereHas('warehouseBalances', function ($q) use ($warehouseId) {
                $q->lowStock();
                if ($warehouseId) {
                    $q->where('warehouse_id', $warehouseId);
                }
            })
            ->with(['warehouseBalances' => function ($q) use ($warehouseId) {
                $q->lowStock();
                if ($warehouseId) {
                    $q->where('warehouse_id', $warehouseId);
                }
            }]);

        return $query->get();
    }

    /**
     * Деактивировать актив
     */
    public function deactivateAsset(int $assetId): Asset
    {
        $asset = Asset::findOrFail($assetId);
        $asset->update(['is_active' => false]);

        $this->logging->business('asset.deactivated', [
            'organization_id' => $asset->organization_id,
            'asset_id' => $asset->id,
            'asset_type' => $asset->asset_type,
        ]);

        $this->clearAssetCache($asset->organization_id);

        return $asset;
    }

    /**
     * Активировать актив
     */
    public function activateAsset(int $assetId): Asset
    {
        $asset = Asset::findOrFail($assetId);
        $asset->update(['is_active' => true]);

        $this->logging->business('asset.activated', [
            'organization_id' => $asset->organization_id,
            'asset_id' => $asset->id,
            'asset_type' => $asset->asset_type,
        ]);

        $this->clearAssetCache($asset->organization_id);

        return $asset;
    }

    /**
     * Импортировать активы из массива
     */
    public function importAssets(int $organizationId, array $assetsData): array
    {
        $created = 0;
        $updated = 0;
        $errors = [];

        foreach ($assetsData as $index => $data) {
            try {
                // Проверяем существует ли актив по коду
                $asset = null;
                if (isset($data['code'])) {
                    $asset = Asset::where('organization_id', $organizationId)
                        ->where('code', $data['code'])
                        ->first();
                }

                if ($asset) {
                    $this->updateAsset($asset->id, $data);
                    $updated++;
                } else {
                    $this->createAsset($organizationId, $data);
                    $created++;
                }
            } catch (\Exception $e) {
                $errors[] = [
                    'row' => $index + 1,
                    'data' => $data,
                    'error' => $e->getMessage(),
                ];
            }
        }

        $this->logging->business('assets.imported', [
            'organization_id' => $organizationId,
            'created' => $created,
            'updated' => $updated,
            'errors_count' => count($errors),
        ]);

        return [
            'created' => $created,
            'updated' => $updated,
            'errors' => $errors,
        ];
    }

    /**
     * Очистить кэш активов
     */
    protected function clearAssetCache(int $organizationId): void
    {
        Cache::forget("assets_by_type_{$organizationId}_*");
        Cache::forget("asset_type_stats_{$organizationId}");
    }
}

