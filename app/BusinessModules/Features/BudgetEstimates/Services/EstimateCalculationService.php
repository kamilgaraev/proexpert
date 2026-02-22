<?php

namespace App\BusinessModules\Features\BudgetEstimates\Services;

use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\EstimateSection;
use App\Repositories\EstimateSectionRepository;
use App\Repositories\EstimateItemRepository;
use Illuminate\Support\Facades\Log;

class EstimateCalculationService
{
    public function __construct(
        protected EstimateSectionRepository $sectionRepository,
        protected EstimateItemRepository $itemRepository,
        protected EstimateCacheService $cacheService
    ) {}

    public function calculateItemTotal(EstimateItem $item, Estimate $estimate): float
    {
        // 0. Для дочерних ресурсов (винтики, труд и т.д.) просто считаем Q * P
        if ($item->parent_work_id !== null) {
            $totalAmount = round($item->quantity * $item->unit_price, 2);
            
            // ⭐ Если это импортированный ресурс и расчет Q*P дал 0 (например, для заголовков ЭМ, М),
            // но в файле был указан Итог — доверяем Итогу из файла.
            if ($item->current_total_amount > 0 && ($totalAmount <= 0 || $item->is_manual)) {
                $totalAmount = (float)$item->current_total_amount;
            }

            $item->update([
                'direct_costs' => $totalAmount,
                'overhead_amount' => 0,
                'profit_amount' => 0,
                'total_amount' => $totalAmount,
            ]);
            return $totalAmount;
        }

        // 1. Считаем фактические затраты из дочерних ресурсов (Прямые Затраты и Оборудование)
        $equipmentSum = 0;
        $resourcesSum = 0;
        
        $hasChildren = EstimateItem::where('parent_work_id', $item->id)->exists();

        if ($item->isWork() || $item->isMaterial()) {
             // ⭐ Находим сумму ПЗ из дочерних ресурсов. 
             // Чтобы избежать двойного счета (сумма заголовков + сумма деталей),
             // суммируем только "листовые" записи (у которых нет своих детей).
             $resourcesSum = (float) EstimateItem::where('parent_work_id', $item->id)
                 ->where('is_not_accounted', false)
                 ->where('item_type', '!=', \App\Enums\EstimatePositionItemType::EQUIPMENT->value)
                 ->whereDoesntHave('childItems')
                 ->sum('total_amount');
             
             // Если ресурсов много, а сумма 0 — пробуем все-таки суммировать всё (кроме оборудования), 
             // на случай если иерархия не проставилась.
             if ($resourcesSum <= 0 && $hasChildren) {
                 $resourcesSum = (float) EstimateItem::where('parent_work_id', $item->id)
                    ->where('item_type', '!=', \App\Enums\EstimatePositionItemType::EQUIPMENT->value)
                    ->sum('total_amount');
             }

             // Оборудование всегда считаем отдельно (оно не база для НР/СП)
             $equipmentSum = (float) EstimateItem::where('parent_work_id', $item->id)
                 ->where(function ($query) {
                     $query->where('item_type', \App\Enums\EstimatePositionItemType::EQUIPMENT->value)
                           ->orWhere(function ($q) {
                               $q->where('item_type', \App\Enums\EstimatePositionItemType::MATERIAL->value)
                                 ->where('unit_price', '>', 50000);
                           });
                 })
                 ->sum('total_amount');
             
             // Суммируем трудозатраты и маш-часы для красоты
             $laborHours = (float) EstimateItem::where('parent_work_id', $item->id)->sum('labor_hours');
             $machineryHours = (float) EstimateItem::where('parent_work_id', $item->id)->sum('machinery_hours');
             if ($laborHours != 0) $item->labor_hours = $laborHours;
             if ($machineryHours != 0) $item->machinery_hours = $machineryHours;

             // Самостоятельная детекция оборудования для корневой позиции
             if ($item->isMaterial() && $item->unit_price > 50000 && !$hasChildren) {
                 $equipmentSum = $item->quantity * $item->unit_price;
                 $resourcesSum = 0;
             }

             if ($item->isEquipment()) {
                 $equipmentSum = $resourcesSum != 0 ? $resourcesSum + $equipmentSum : ($item->quantity * $item->unit_price);
                 $resourcesSum = 0;
             }
        }

        // 2. Логика распределения маржи (Reverse Engineering)
        if ($item->is_manual) {
            // ⭐ Приоритет: current_total_amount или total_amount из БД
            $totalAmount = (float)($item->current_total_amount ?: $item->total_amount);
            if ($totalAmount <= 0) $totalAmount = round($item->quantity * $item->unit_price, 2);

            $directCosts = (float)$item->direct_costs;
            
            $equipmentSum = 0;

            // КРИТЕРИЙ ОБОРУДОВАНИЯ:
            // 1. Прямой тип оборудование
            // 2. Материал дороже 50к за единицу + отсутствие ресурсов
            $isEquipment = $item->isEquipment() || 
                ($item->unit_price > 50000 && !$hasChildren);

            $overheadAmount = (float)($item->overhead_amount ?? 0);
            $profitAmount = (float)($item->profit_amount ?? 0);

            // Если НР/СП не были спарсены при импорте, попробуем найти их в названии сейчас
            if (($overheadAmount + $profitAmount) <= 0.05) {
                $attrs = $this->parseAttributesFromName($item->name);
                if ($attrs['overhead'] > 0 || $attrs['profit'] > 0) {
                    $overheadAmount = $attrs['overhead'] * ($item->price_index ?? 1);
                    $profitAmount = $attrs['profit'] * ($item->price_index ?? 1);
                }
            }

            if ($isEquipment) {
                // ⭐ КРИТИЧЕСКИЙ ФИКС: Для оборудования Прямые Затраты — это только РЕСУРСЫ (труд/меш).
                // Если ресурсов нет ($hasChildren = false), то ПЗ должны быть 0, 
                // чтобы налоги не считались от стоимости самого оборудования.
                if (!$hasChildren && ($overheadAmount <= 0 && $profitAmount <= 0)) {
                    $directCosts = 0;
                }

                // Накладные и прибыль считаем ТОЛЬКО от ресурсов (если они есть)
                if ($directCosts > 0) {
                    $overheadAmount = round($directCosts * ($estimate->overhead_rate / 100), 2);
                    $profitAmount = round($directCosts * ($estimate->profit_rate / 100), 2);
                } else {
                    // Если налоги не пришли из файла и ПЗ нет — налоги 0
                    if ($overheadAmount <= 0) $overheadAmount = 0;
                    if ($profitAmount <= 0) $profitAmount = 0;
                }
                
                $equipmentSum = max(0, $totalAmount - $directCosts - $overheadAmount - $profitAmount);
            } else {
                // Стандартная позиция (Работа/Материал)
                if ($directCosts <= 0) {
                    $directCosts = $totalAmount;
                }
                
                // 3. Работа с остатком (маржой)
                $overheadAmount = (float)($item->overhead_amount ?? 0);
                $profitAmount = (float)($item->profit_amount ?? 0);
                
                // Если НР/СП заданы явно, используем их напрямую и НЕ вычисляем остаток
                if ($overheadAmount > 0 || $profitAmount > 0) {
                     // Мы просто доверяем марже из БД (она пришла из парсера)
                } else {
                     // НР и СП не были переданы из файла - пытаемся их восстановить из остатка
                     // Считаем остаток ПЕРЕД НР/СП
                     $remainingForMarkup = round(max(0, $totalAmount - $directCosts), 2);

                     if ($remainingForMarkup > 1) {
                         $totalRate = ($estimate->overhead_rate ?? 0) + ($estimate->profit_rate ?? 0);
                         if ($totalRate > 0) {
                             $overheadAmount = round($remainingForMarkup * ($estimate->overhead_rate / $totalRate), 2);
                             $profitAmount = $remainingForMarkup - $overheadAmount;
                         } else {
                             // Default 66/34 split
                             $overheadAmount = round($remainingForMarkup * 0.66, 2);
                             $profitAmount = $remainingForMarkup - $overheadAmount;
                         }
                     } else {
                         // Остатка нет (Сумма = ПЗ), смета без скрытых НР/СП
                         $overheadAmount = 0;
                         $profitAmount = 0;
                     }
                }

                // 4. Подгоняем ПЗ (базовые затраты), чтобы Итого сошлось идеально
                // Это гарантирует, что ПЗ + НР + СП = Итого (копейка в копейку)
                // Если ПЗ пришли равными итогу (баг импорта), они будут корректно уменьшены здесь.
                $directCosts = max(0, $totalAmount - $overheadAmount - $profitAmount);
            }
        } else {
            // АВТОМАТИЧЕСКАЯ КАЛЬКУЛЯЦИЯ (Снизу вверх, если позиция не пришла из Excel или изменена вручную)
            $isEquipment = $item->isEquipment() || ($item->unit_price > 50000);
            
            if ($isEquipment) {
                $directCosts = $resourcesSum;
                $overheadAmount = round($directCosts * ($estimate->overhead_rate / 100), 2);
                $profitAmount = round($directCosts * ($estimate->profit_rate / 100), 2);
                $equipmentSum = ($item->quantity * $item->unit_price) - $directCosts - $overheadAmount - $profitAmount;
                if ($equipmentSum < 0) $equipmentSum = $item->quantity * $item->unit_price; // Fallback
                $totalAmount = $directCosts + $overheadAmount + $profitAmount + $equipmentSum;
            } else {
                $directCosts = $resourcesSum > 0 ? $resourcesSum : $item->quantity * $item->unit_price;
                $overheadAmount = round($directCosts * ($estimate->overhead_rate / 100), 2);
                $profitAmount = round($directCosts * ($estimate->profit_rate / 100), 2);
                $totalAmount = $directCosts + $overheadAmount + $profitAmount;
            }
        }
        
        $item->update([
            'direct_costs' => round($directCosts, 2),
            'equipment_cost' => round($equipmentSum, 2),
            'overhead_amount' => round($overheadAmount, 2),
            'profit_amount' => round($profitAmount, 2),
            'total_amount' => round($totalAmount, 2),
        ]);
        
        return $totalAmount;
    }

    private function parseAttributesFromName(?string $name): array
    {
        $res = ['overhead' => 0, 'profit' => 0];
        if (empty($name)) return $res;

        // Поиск конструкций типа "НР ( 123,45 руб )"
        if (preg_match('/(?:нр|накладные).*?\(\s*([\d\s]+[.,]?\d*)\s*(?:руб|р)/ui', $name, $m)) {
            $res['overhead'] = (float)str_replace([' ', ','], ['', '.'], $m[1]);
        }
        if (preg_match('/(?:сп|сметная).*?\(\s*([\d\s]+[.,]?\d*)\s*(?:руб|р)/ui', $name, $m)) {
            $res['profit'] = (float)str_replace([' ', ','], ['', '.'], $m[1]);
        }
        return $res;
    }

    public function calculateSectionTotal(EstimateSection $section): float
    {
        $total = 0;
        
        // ИСКЛЮЧАЕМ ресурсы из расчета (is_not_accounted = true) и вложенные позиции (parent_work_id != null)
        $items = $this->itemRepository->getBySection($section->id);
        foreach ($items as $item) {
            if (!$item->is_not_accounted && is_null($item->parent_work_id)) {
                $total += $item->total_amount;
            }
        }
        
        $childSections = $section->children;
        foreach ($childSections as $childSection) {
            $total += $this->calculateSectionTotal($childSection);
        }
        
        $section->update(['section_total_amount' => round($total, 2)]);
        
        return $total;
    }

    public function calculateEstimateTotal(Estimate $estimate): array
    {
        // Для утвержденных смет используем кеш
        if ($estimate->isApproved()) {
            return $this->cacheService->rememberTotals($estimate, function () use ($estimate) {
                return $this->performCalculation($estimate);
            });
        }
        
        // Для черновиков считаем без кеша
        return $this->performCalculation($estimate);
    }
    
    private function performCalculation(Estimate $estimate): array
    {
        // ⭐ ЕДИНЫЙ ЗАПРОС: Суммируем всё одним махом без фильтрации по типам.
        // Это гарантирует, что каждая строка будет посчитана ровно один раз.
        $totals = EstimateItem::where('estimate_id', $estimate->id)
            ->whereNull('parent_work_id')
            ->where('is_not_accounted', false)
            ->selectRaw('
                COALESCE(SUM(total_amount), 0) as grand_total,
                COALESCE(SUM(equipment_cost), 0) as total_equipment,
                COALESCE(SUM(overhead_amount), 0) as total_overhead,
                COALESCE(SUM(profit_amount), 0) as total_profit
            ')
            ->first();

        $totalAmount = (float)$totals->grand_total;
        $totalEquipment = (float)$totals->total_equipment;
        $totalOverheadCosts = (float)$totals->total_overhead;
        $totalEstimatedProfit = (float)$totals->total_profit;
        
        // Прямые затраты в шапке — это то, что осталось от общего Итога за вычетом ОБ, НР и СП.
        // Такая формула физически исключает расхождение шапки с разделами.
        $totalDirectCosts = max(0, $totalAmount - $totalEquipment - $totalOverheadCosts - $totalEstimatedProfit);
        
        $totalAmountWithVat = $totalAmount * (1 + $estimate->vat_rate / 100);
        
        $result = [
            'total_direct_costs' => round($totalDirectCosts, 2),
            'total_overhead_costs' => round($totalOverheadCosts, 2),
            'total_estimated_profit' => round($totalEstimatedProfit, 2),
            'total_equipment_costs' => round($totalEquipment, 2),
            'total_amount' => round($totalAmount, 2),
            'total_amount_with_vat' => round($totalAmountWithVat, 2),
        ];
        
        $estimate->update($result);
        
        // Инвалидировать кеш после обновления
        $this->cacheService->invalidateTotals($estimate);
        
        $this->dispatchAsyncUpdates($estimate);
        
        return $result;
    }

    public function dispatchAsyncUpdates(Estimate $estimate): void
    {
        \App\BusinessModules\Features\BudgetEstimates\Jobs\CalculateEstimateStatisticsJob::dispatch($estimate->id);
        \App\BusinessModules\Features\BudgetEstimates\Jobs\GenerateEstimateSnapshotJob::dispatch($estimate->id);
    }

    public function recalculateAll(Estimate $estimate): array
    {
        $startTime = microtime(true);
        
        $items = $this->itemRepository->getAllByEstimate($estimate->id);
        
        foreach ($items as $item) {
            $this->calculateItemTotal($item, $estimate);
        }
        
        $rootSections = $this->sectionRepository->getRootSections($estimate->id);
        foreach ($rootSections as $section) {
            $this->calculateSectionTotal($section);
        }
        
        $result = $this->calculateEstimateTotal($estimate);
        
        $duration = round((microtime(true) - $startTime) * 1000, 2);
        
        // Логирование
        Log::info('estimate.recalculated', [
            'estimate_id' => $estimate->id,
            'items_count' => $items->count(),
            'total_amount' => $result['total_amount'],
            'duration_ms' => $duration,
        ]);
        
        return $result;
    }

    public function applyCoefficients(Estimate $estimate, array $coefficients): void
    {
        $metadata = $estimate->metadata ?? [];
        $metadata['coefficients'] = array_merge($metadata['coefficients'] ?? [], $coefficients);
        
        $estimate->update(['metadata' => $metadata]);
        
        $this->recalculateAll($estimate);
    }

    public function applyVAT(Estimate $estimate, float $vatRate): void
    {
        $estimate->update(['vat_rate' => $vatRate]);
        
        $totalAmountWithVat = $estimate->total_amount * (1 + $vatRate / 100);
        $estimate->update(['total_amount_with_vat' => round($totalAmountWithVat, 2)]);
    }

    public function updateRates(Estimate $estimate, ?float $overheadRate = null, ?float $profitRate = null, ?float $vatRate = null): void
    {
        $updates = [];
        
        if ($overheadRate !== null) {
            $updates['overhead_rate'] = $overheadRate;
        }
        
        if ($profitRate !== null) {
            $updates['profit_rate'] = $profitRate;
        }
        
        if ($vatRate !== null) {
            $updates['vat_rate'] = $vatRate;
        }
        
        if (!empty($updates)) {
            $estimate->update($updates);
            $this->recalculateAll($estimate);
        }
    }

    public function getEstimateStructure(Estimate $estimate): array
    {
        $totalDirectCosts = $estimate->total_direct_costs;
        $totalOverheadCosts = $estimate->total_overhead_costs;
        $totalEstimatedProfit = $estimate->total_estimated_profit;
        $totalAmount = $estimate->total_amount;
        
        if ($totalAmount == 0) {
            return [
                'direct_costs_percentage' => 0,
                'overhead_costs_percentage' => 0,
                'profit_percentage' => 0,
            ];
        }
        
        return [
            'direct_costs_percentage' => round(($totalDirectCosts / $totalAmount) * 100, 2),
            'overhead_costs_percentage' => round(($totalOverheadCosts / $totalAmount) * 100, 2),
            'profit_percentage' => round(($totalEstimatedProfit / $totalAmount) * 100, 2),
        ];
    }
}

