<?php

namespace App\BusinessModules\Features\AdvancedWarehouse\Services;

use App\BusinessModules\Features\BasicWarehouse\Services\WarehouseService;
use App\BusinessModules\Features\BasicWarehouse\Contracts\WarehouseReportDataProvider;
use App\Services\Logging\LoggingService;

/**
 * Сервис продвинутого управления складом
 * Расширяет BasicWarehouseService дополнительными возможностями
 */
class AdvancedWarehouseService extends WarehouseService implements WarehouseReportDataProvider
{
    public function __construct(LoggingService $logging)
    {
        parent::__construct($logging);
    }

    /**
     * Получить данные аналитики оборачиваемости
     * 
     * @param int $organizationId ID организации
     * @param array $filters Фильтры
     * @return array Данные аналитики
     */
    public function getTurnoverAnalytics(int $organizationId, array $filters = []): array
    {
        $dateFrom = $filters['date_from'] ?? now()->subMonth();
        $dateTo = $filters['date_to'] ?? now();
        
        // Получаем движения за период
        $movements = \App\BusinessModules\Features\BasicWarehouse\Models\WarehouseMovement::where('organization_id', $organizationId)
            ->whereBetween('movement_date', [$dateFrom, $dateTo])
            ->with(['material'])
            ->get();
        
        // Группируем по материалам
        $assetAnalytics = [];
        $materialIds = $movements->pluck('material_id')->unique();
        
        foreach ($materialIds as $materialId) {
            $materialMovements = $movements->where('material_id', $materialId);
            $material = $materialMovements->first()->material;
            
            // Расход за период (write_off)
            $consumption = $materialMovements
                ->where('movement_type', 'write_off')
                ->sum('quantity');
            
            // Средний остаток (упрощенно - текущий остаток)
            $balance = \App\BusinessModules\Features\BasicWarehouse\Models\WarehouseBalance::where('organization_id', $organizationId)
                ->where('material_id', $materialId)
                ->first();
            
            $averageStock = $balance ? (float)$balance->available_quantity : 0;
            
            // Коэффициент оборачиваемости
            $turnoverRate = $averageStock > 0 ? $consumption / $averageStock : 0;
            
            // Период оборачиваемости в днях
            $days = $dateFrom->diffInDays($dateTo);
            $turnoverDays = $turnoverRate > 0 ? $days / $turnoverRate : 0;
            
            // ABC категория (упрощенно - по потреблению)
            $category = $turnoverRate > 2 ? 'A' : ($turnoverRate > 0.5 ? 'B' : 'C');
            
            $assetAnalytics[] = [
                'asset_id' => $materialId,
                'asset_name' => $material->name,
                'asset_code' => $material->code,
                'average_stock' => $averageStock,
                'consumption' => (float)$consumption,
                'turnover_rate' => round($turnoverRate, 2),
                'turnover_days' => round($turnoverDays, 0),
                'category' => $category,
            ];
        }
        
        // Сортируем по оборачиваемости
        usort($assetAnalytics, fn($a, $b) => $b['turnover_rate'] <=> $a['turnover_rate']);
        
        return [
            'period' => [
                'date_from' => $dateFrom->toDateString(),
                'date_to' => $dateTo->toDateString(),
                'days' => $dateFrom->diffInDays($dateTo),
            ],
            'assets' => $assetAnalytics,
            'summary' => [
                'total_assets_analyzed' => count($assetAnalytics),
                'average_turnover_rate' => count($assetAnalytics) > 0 
                    ? round(collect($assetAnalytics)->avg('turnover_rate'), 2) 
                    : 0,
                'slow_moving_count' => collect($assetAnalytics)->where('category', 'C')->count(),
                'fast_moving_count' => collect($assetAnalytics)->where('category', 'A')->count(),
            ],
        ];
    }

    /**
     * Получить прогноз потребности в материалах
     * Использует простой линейный прогноз на основе исторических данных
     * 
     * TODO: Реализовать ML прогнозирование:
     * - ARIMA для временных рядов
     * - Prophet для сезонных паттернов  
     * - Нейронные сети (LSTM) для сложных зависимостей
     * 
     * @param int $organizationId ID организации
     * @param array $filters Фильтры (horizon_days, asset_ids)
     * @return array Данные прогноза
     */
    public function getForecastData(int $organizationId, array $filters = []): array
    {
        $horizonDays = $filters['horizon_days'] ?? 90;
        $historicalDays = 90; // Анализируем последние 90 дней
        
        $dateFrom = now()->subDays($historicalDays);
        $dateTo = now();
        
        // Получаем движения за исторический период
        $movements = \App\BusinessModules\Features\BasicWarehouse\Models\WarehouseMovement::where('organization_id', $organizationId)
            ->whereBetween('movement_date', [$dateFrom, $dateTo])
            ->where('movement_type', 'write_off')
            ->with(['material'])
            ->get();
        
        $forecasts = [];
        $materialIds = $movements->pluck('material_id')->unique();
        
        foreach ($materialIds as $materialId) {
            $materialMovements = $movements->where('material_id', $materialId);
            $material = $materialMovements->first()->material;
            
            // Простой линейный прогноз: средний расход в день * горизонт
            $totalConsumption = $materialMovements->sum('quantity');
            $averageDailyConsumption = $totalConsumption / $historicalDays;
            $predictedConsumption = $averageDailyConsumption * $horizonDays;
            
            // Текущий остаток
            $balance = \App\BusinessModules\Features\BasicWarehouse\Models\WarehouseBalance::where('organization_id', $organizationId)
                ->where('material_id', $materialId)
                ->first();
            
            $currentStock = $balance ? (float)$balance->available_quantity : 0;
            
            // Дата исчерпания запасов
            $daysUntilStockOut = $averageDailyConsumption > 0 
                ? $currentStock / $averageDailyConsumption 
                : 999999;
            
            // Рекомендуемое количество заказа (покрытие на 30 дней)
            $recommendedOrderQuantity = max(0, $averageDailyConsumption * 30 - $currentStock);
            
            // Уровень уверенности (упрощенно - на основе стабильности потребления)
            $consumptionVariance = $this->calculateVariance(
                $materialMovements->pluck('quantity')->toArray()
            );
            $confidence = max(50, min(95, 100 - ($consumptionVariance * 10)));
            
            $forecasts[] = [
                'asset_id' => $materialId,
                'asset_name' => $material->name,
                'asset_code' => $material->code,
                'current_stock' => $currentStock,
                'average_daily_consumption' => round($averageDailyConsumption, 2),
                'predicted_consumption' => round($predictedConsumption, 2),
                'recommended_order_quantity' => round($recommendedOrderQuantity, 2),
                'estimated_stock_out_date' => $daysUntilStockOut < $horizonDays 
                    ? now()->addDays((int)$daysUntilStockOut)->toDateString()
                    : null,
                'days_until_stock_out' => min((int)$daysUntilStockOut, $horizonDays),
                'confidence' => (int)$confidence,
                'forecast_method' => 'linear_average', // TODO: заменить на ML модель
            ];
        }
        
        // Сортируем по срочности
        usort($forecasts, fn($a, $b) => $a['days_until_stock_out'] <=> $b['days_until_stock_out']);
        
        // Разделяем по приоритетам
        $immediateOrders = collect($forecasts)->filter(fn($f) => $f['days_until_stock_out'] < 7)->values()->toArray();
        $plannedOrders = collect($forecasts)->filter(fn($f) => $f['days_until_stock_out'] >= 7 && $f['days_until_stock_out'] < 30)->values()->toArray();
        $excessiveStock = collect($forecasts)->filter(fn($f) => $f['days_until_stock_out'] > 180)->values()->toArray();
        
        return [
            'forecast_period' => [
                'start_date' => now()->toDateString(),
                'end_date' => now()->addDays($horizonDays)->toDateString(),
                'horizon_days' => $horizonDays,
                'historical_days' => $historicalDays,
            ],
            'forecasts' => $forecasts,
            'recommendations' => [
                'immediate_orders' => $immediateOrders,
                'planned_orders' => $plannedOrders,
                'excessive_stock' => $excessiveStock,
            ],
            'summary' => [
                'total_assets_forecasted' => count($forecasts),
                'immediate_attention_required' => count($immediateOrders),
                'planned_orders_required' => count($plannedOrders),
                'excessive_stock_count' => count($excessiveStock),
            ],
        ];
    }
    
    /**
     * Вспомогательный метод для расчета дисперсии
     */
    protected function calculateVariance(array $values): float
    {
        if (count($values) < 2) {
            return 0;
        }
        
        $mean = array_sum($values) / count($values);
        $variance = array_reduce($values, function($carry, $value) use ($mean) {
            return $carry + pow($value - $mean, 2);
        }, 0) / count($values);
        
        return sqrt($variance) / ($mean > 0 ? $mean : 1); // Коэффициент вариации
    }

    /**
     * Получить ABC/XYZ анализ запасов
     * ABC: классификация по стоимости потребления
     * XYZ: классификация по стабильности потребления
     * 
     * @param int $organizationId ID организации
     * @param array $filters Фильтры
     * @return array Данные ABC/XYZ анализа
     */
    public function getAbcXyzAnalysis(int $organizationId, array $filters = []): array
    {
        $dateFrom = $filters['date_from'] ?? now()->subYear();
        $dateTo = $filters['date_to'] ?? now();
        
        // Получаем движения за период
        $movements = \App\BusinessModules\Features\BasicWarehouse\Models\WarehouseMovement::where('organization_id', $organizationId)
            ->whereBetween('movement_date', [$dateFrom, $dateTo])
            ->where('movement_type', 'write_off')
            ->with(['material'])
            ->get();
        
        $assetAnalysis = [];
        $materialIds = $movements->pluck('material_id')->unique();
        $totalValue = 0;
        
        // Первый проход: рассчитываем стоимость потребления для каждого актива
        foreach ($materialIds as $materialId) {
            $materialMovements = $movements->where('material_id', $materialId);
            $material = $materialMovements->first()->material;
            
            // Стоимость потребления за период
            $consumptionValue = $materialMovements->sum(function($m) {
                return (float)$m->quantity * (float)$m->price;
            });
            
            // Коэффициент вариации для XYZ
            $quantities = $materialMovements->pluck('quantity')->toArray();
            $variance = $this->calculateVariance($quantities);
            
            $assetAnalysis[] = [
                'asset_id' => $materialId,
                'asset_name' => $material->name,
                'asset_code' => $material->code,
                'total_value' => $consumptionValue,
                'consumption_variance' => $variance,
            ];
            
            $totalValue += $consumptionValue;
        }
        
        // Сортируем по стоимости для ABC анализа
        usort($assetAnalysis, fn($a, $b) => $b['total_value'] <=> $a['total_value']);
        
        // Второй проход: присваиваем ABC категории (правило Парето)
        $cumulativePercent = 0;
        foreach ($assetAnalysis as &$asset) {
            $asset['value_percent'] = $totalValue > 0 ? ($asset['total_value'] / $totalValue) * 100 : 0;
            $cumulativePercent += $asset['value_percent'];
            
            // ABC категории: A=80%, B=15%, C=5%
            if ($cumulativePercent <= 80) {
                $asset['abc_category'] = 'A';
            } elseif ($cumulativePercent <= 95) {
                $asset['abc_category'] = 'B';
            } else {
                $asset['abc_category'] = 'C';
            }
            
            // XYZ категории по коэффициенту вариации
            // X: < 0.1 (стабильное потребление)
            // Y: 0.1-0.25 (среднее)
            // Z: > 0.25 (нестабильное)
            if ($asset['consumption_variance'] < 0.1) {
                $asset['xyz_category'] = 'X';
            } elseif ($asset['consumption_variance'] < 0.25) {
                $asset['xyz_category'] = 'Y';
            } else {
                $asset['xyz_category'] = 'Z';
            }
            
            $asset['combined_category'] = $asset['abc_category'] . $asset['xyz_category'];
            
            // Рекомендации по категориям
            $asset['recommendation'] = $this->getAbcXyzRecommendation($asset['combined_category']);
        }
        
        // Подсчет распределения
        $abcDistribution = [
            'A' => ['count' => collect($assetAnalysis)->where('abc_category', 'A')->count(), 'value_percent' => 80],
            'B' => ['count' => collect($assetAnalysis)->where('abc_category', 'B')->count(), 'value_percent' => 15],
            'C' => ['count' => collect($assetAnalysis)->where('abc_category', 'C')->count(), 'value_percent' => 5],
        ];
        
        $xyzDistribution = [
            'X' => ['count' => collect($assetAnalysis)->where('xyz_category', 'X')->count(), 'stability' => 'high'],
            'Y' => ['count' => collect($assetAnalysis)->where('xyz_category', 'Y')->count(), 'stability' => 'medium'],
            'Z' => ['count' => collect($assetAnalysis)->where('xyz_category', 'Z')->count(), 'stability' => 'low'],
        ];
        
        return [
            'analysis_period' => [
                'date_from' => $dateFrom->toDateString(),
                'date_to' => $dateTo->toDateString(),
            ],
            'abc_distribution' => $abcDistribution,
            'xyz_distribution' => $xyzDistribution,
            'assets' => $assetAnalysis,
            'recommendations' => [
                'AX' => 'Критические товары со стабильным спросом - строгий контроль, минимальные запасы, частые поставки',
                'AY' => 'Критические товары со средней стабильностью - повышенные страховые запасы',
                'AZ' => 'Критические товары с нестабильным спросом - максимальные страховые запасы, анализ причин',
                'BX' => 'Важные товары со стабильным спросом - стандартный контроль, средние запасы',
                'BY' => 'Важные товары со средней стабильностью - средние страховые запасы',
                'BZ' => 'Важные товары с нестабильным спросом - повышенные страховые запасы',
                'CX' => 'Малоценные товары со стабильным спросом - упрощенный контроль, закупка большими партиями',
                'CY' => 'Малоценные товары со средней стабильностью - стандартные запасы',
                'CZ' => 'Малоценные товары с нестабильным спросом - минимальный контроль, закупка по мере необходимости',
            ],
            'summary' => [
                'total_assets_analyzed' => count($assetAnalysis),
                'total_consumption_value' => round($totalValue, 2),
                'critical_assets_count' => $abcDistribution['A']['count'],
                'stable_assets_count' => $xyzDistribution['X']['count'],
            ],
        ];
    }
    
    /**
     * Получить рекомендацию по ABC/XYZ категории
     */
    protected function getAbcXyzRecommendation(string $category): string
    {
        $recommendations = [
            'AX' => 'Критический товар - строгий контроль запасов',
            'AY' => 'Критический товар - повышенные страховые запасы',
            'AZ' => 'Критический товар - максимальные страховые запасы',
            'BX' => 'Важный товар - стандартный контроль',
            'BY' => 'Важный товар - средние страховые запасы',
            'BZ' => 'Важный товар - повышенные страховые запасы',
            'CX' => 'Малоценный товар - упрощенный контроль',
            'CY' => 'Малоценный товар - стандартные запасы',
            'CZ' => 'Малоценный товар - минимальный контроль',
        ];
        
        return $recommendations[$category] ?? 'Требуется анализ';
    }

    /**
     * Зарезервировать активы для проекта
     * Только для AdvancedWarehouse
     */
    public function reserveAssets(
        int $organizationId,
        int $warehouseId,
        int $materialId,
        float $quantity,
        array $metadata = []
    ): array {
        \Illuminate\Support\Facades\DB::beginTransaction();
        
        try {
            // Проверяем доступность активов
            $balance = \App\BusinessModules\Features\BasicWarehouse\Models\WarehouseBalance::where('organization_id', $organizationId)
                ->where('warehouse_id', $warehouseId)
                ->where('material_id', $materialId)
                ->lockForUpdate()
                ->first();
            
            if (!$balance || $balance->available_quantity < $quantity) {
                throw new \InvalidArgumentException(
                    "Недостаточно активов для резервирования. Доступно: " . ($balance ? $balance->available_quantity : 0)
                );
            }
            
            // Создаем резервацию
            $expiresAt = isset($metadata['expires_hours']) 
                ? now()->addHours($metadata['expires_hours'])
                : now()->addHours(24);
            
            $reservation = \App\BusinessModules\Features\AdvancedWarehouse\Models\AssetReservation::create([
                'organization_id' => $organizationId,
                'warehouse_id' => $warehouseId,
                'material_id' => $materialId,
                'quantity' => $quantity,
                'project_id' => $metadata['project_id'] ?? null,
                'reserved_by' => $metadata['user_id'] ?? request()->user()?->id ?? 1,
                'status' => 'active',
                'expires_at' => $expiresAt,
                'reason' => $metadata['reason'] ?? null,
                'metadata' => $metadata,
            ]);
            
            // Резервируем в балансе
            $balance->reserve($quantity);
            
            $this->logging->business('warehouse.asset.reserved', [
                'organization_id' => $organizationId,
                'warehouse_id' => $warehouseId,
                'material_id' => $materialId,
                'quantity' => $quantity,
                'reservation_id' => $reservation->id,
            ]);
            
            \Illuminate\Support\Facades\DB::commit();
            
            return [
                'reserved' => true,
                'reservation_id' => $reservation->id,
                'quantity' => (float)$quantity,
                'expires_at' => $expiresAt->toDateTimeString(),
                'remaining_available' => (float)$balance->available_quantity,
            ];
            
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\DB::rollBack();
            throw $e;
        }
    }

    /**
     * Снять резервирование
     */
    public function unreserveAssets(int $reservationId): bool
    {
        \Illuminate\Support\Facades\DB::beginTransaction();
        
        try {
            $reservation = \App\BusinessModules\Features\AdvancedWarehouse\Models\AssetReservation::where('id', $reservationId)
                ->where('status', 'active')
                ->lockForUpdate()
                ->firstOrFail();
            
            // Возвращаем количество в доступные
            $balance = \App\BusinessModules\Features\BasicWarehouse\Models\WarehouseBalance::where('organization_id', $reservation->organization_id)
                ->where('warehouse_id', $reservation->warehouse_id)
                ->where('material_id', $reservation->material_id)
                ->lockForUpdate()
                ->firstOrFail();
            
            $balance->unreserve($reservation->quantity);
            
            // Обновляем статус резервации
            $reservation->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
            ]);
            
            $this->logging->business('warehouse.asset.unreserved', [
                'reservation_id' => $reservationId,
                'organization_id' => $reservation->organization_id,
                'quantity' => $reservation->quantity,
            ]);
            
            \Illuminate\Support\Facades\DB::commit();
            
            return true;
            
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\DB::rollBack();
            throw $e;
        }
    }

    /**
     * Создать правило автоматического пополнения
     */
    public function createAutoReorderRule(
        int $organizationId,
        int $materialId,
        array $ruleData
    ): array {
        $warehouseId = $ruleData['warehouse_id'];
        
        // Проверяем существование правила
        $existingRule = \App\BusinessModules\Features\AdvancedWarehouse\Models\AutoReorderRule::where('warehouse_id', $warehouseId)
            ->where('material_id', $materialId)
            ->first();
        
        if ($existingRule) {
            // Обновляем существующее правило
            $existingRule->update([
                'min_stock' => $ruleData['min_stock'],
                'max_stock' => $ruleData['max_stock'],
                'reorder_point' => $ruleData['reorder_point'],
                'reorder_quantity' => $ruleData['reorder_quantity'],
                'default_supplier_id' => $ruleData['default_supplier_id'] ?? null,
                'is_active' => $ruleData['is_active'] ?? true,
                'notes' => $ruleData['notes'] ?? null,
            ]);
            
            $rule = $existingRule;
            $action = 'updated';
        } else {
            // Создаем новое правило
            $rule = \App\BusinessModules\Features\AdvancedWarehouse\Models\AutoReorderRule::create([
                'organization_id' => $organizationId,
                'warehouse_id' => $warehouseId,
                'material_id' => $materialId,
                'min_stock' => $ruleData['min_stock'],
                'max_stock' => $ruleData['max_stock'],
                'reorder_point' => $ruleData['reorder_point'],
                'reorder_quantity' => $ruleData['reorder_quantity'],
                'default_supplier_id' => $ruleData['default_supplier_id'] ?? null,
                'is_active' => $ruleData['is_active'] ?? true,
                'notes' => $ruleData['notes'] ?? null,
            ]);
            
            $action = 'created';
        }
        
        $this->logging->business('warehouse.auto_reorder_rule.' . $action, [
            'organization_id' => $organizationId,
            'warehouse_id' => $warehouseId,
            'material_id' => $materialId,
            'rule_id' => $rule->id,
        ]);
        
        return [
            'rule_id' => $rule->id,
            'action' => $action,
            'material_id' => $materialId,
            'warehouse_id' => $warehouseId,
            'min_stock' => (float)$rule->min_stock,
            'max_stock' => (float)$rule->max_stock,
            'reorder_point' => (float)$rule->reorder_point,
            'reorder_quantity' => (float)$rule->reorder_quantity,
            'is_active' => $rule->is_active,
        ];
    }

    /**
     * Проверить необходимость автопополнения
     * Проверяет все активные правила и возвращает рекомендации по заказам
     */
    public function checkAutoReorder(int $organizationId): array
    {
        $rules = \App\BusinessModules\Features\AdvancedWarehouse\Models\AutoReorderRule::where('organization_id', $organizationId)
            ->where('is_active', true)
            ->with(['material', 'warehouse', 'defaultSupplier'])
            ->get();
        
        $ordersToGenerate = [];
        $rulesChecked = 0;
        
        foreach ($rules as $rule) {
            $rulesChecked++;
            
            // Получаем текущий остаток
            $balance = \App\BusinessModules\Features\BasicWarehouse\Models\WarehouseBalance::where('organization_id', $organizationId)
                ->where('warehouse_id', $rule->warehouse_id)
                ->where('material_id', $rule->material_id)
                ->first();
            
            $currentStock = $balance ? (float)$balance->available_quantity : 0;
            
            // Проверяем нужно ли пополнение
            if ($rule->needsReorder($currentStock)) {
                $orderQuantity = $rule->calculateOrderQuantity($currentStock);
                
                $ordersToGenerate[] = [
                    'rule_id' => $rule->id,
                    'material_id' => $rule->material_id,
                    'material_name' => $rule->material->name,
                    'material_code' => $rule->material->code,
                    'warehouse_id' => $rule->warehouse_id,
                    'warehouse_name' => $rule->warehouse->name,
                    'current_stock' => $currentStock,
                    'reorder_point' => (float)$rule->reorder_point,
                    'min_stock' => (float)$rule->min_stock,
                    'max_stock' => (float)$rule->max_stock,
                    'recommended_order_quantity' => $orderQuantity,
                    'supplier_id' => $rule->default_supplier_id,
                    'supplier_name' => $rule->defaultSupplier->name ?? null,
                    'priority' => $this->calculateOrderPriority($currentStock, $rule->reorder_point, $rule->min_stock),
                    'estimated_stock_out_days' => $this->estimateStockOutDays($organizationId, $rule->material_id, $currentStock),
                ];
                
                // Обновляем время последней проверки
                $rule->update(['last_checked_at' => now()]);
            } else {
                // Просто обновляем время проверки
                $rule->update(['last_checked_at' => now()]);
            }
        }
        
        // Сортируем по приоритету
        usort($ordersToGenerate, fn($a, $b) => $b['priority'] <=> $a['priority']);
        
        $this->logging->business('warehouse.auto_reorder.checked', [
            'organization_id' => $organizationId,
            'rules_checked' => $rulesChecked,
            'orders_to_generate' => count($ordersToGenerate),
        ]);
        
        return [
            'checked_at' => now()->toDateTimeString(),
            'rules_checked' => $rulesChecked,
            'orders_to_generate' => count($ordersToGenerate),
            'orders' => $ordersToGenerate,
            'summary' => [
                'critical_orders' => collect($ordersToGenerate)->where('priority', '>=', 8)->count(),
                'high_priority_orders' => collect($ordersToGenerate)->whereBetween('priority', [5, 7])->count(),
                'normal_orders' => collect($ordersToGenerate)->where('priority', '<', 5)->count(),
            ],
        ];
    }
    
    /**
     * Рассчитать приоритет заказа (1-10)
     */
    protected function calculateOrderPriority(float $currentStock, float $reorderPoint, float $minStock): int
    {
        if ($currentStock <= 0) {
            return 10; // Критический - запасы исчерпаны
        }
        
        if ($currentStock < $minStock) {
            return 9; // Очень высокий - ниже минимума
        }
        
        if ($currentStock < $reorderPoint) {
            // Пропорционально расстоянию до минимума
            $ratio = ($reorderPoint - $currentStock) / ($reorderPoint - $minStock);
            return max(5, min(8, (int)(5 + $ratio * 3)));
        }
        
        return 3; // Обычный приоритет
    }
    
    /**
     * Оценить количество дней до исчерпания запасов
     */
    protected function estimateStockOutDays(int $organizationId, int $materialId, float $currentStock): ?int
    {
        // Получаем среднее потребление за последние 30 дней
        $movements = \App\BusinessModules\Features\BasicWarehouse\Models\WarehouseMovement::where('organization_id', $organizationId)
            ->where('material_id', $materialId)
            ->where('movement_type', 'write_off')
            ->where('movement_date', '>=', now()->subDays(30))
            ->get();
        
        if ($movements->isEmpty()) {
            return null; // Нет данных для оценки
        }
        
        $totalConsumption = $movements->sum('quantity');
        $averageDailyConsumption = $totalConsumption / 30;
        
        if ($averageDailyConsumption <= 0) {
            return null;
        }
        
        return (int)($currentStock / $averageDailyConsumption);
    }
}

