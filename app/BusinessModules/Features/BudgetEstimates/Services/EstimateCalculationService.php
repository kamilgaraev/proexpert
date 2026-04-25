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
                                 ->where('unit_price', '>', 5000000); // Порог поднят до 5 млн, чтобы не цеплять стройматериалы
                           });
                 })
                 ->sum('total_amount');
             
             // ⭐ УМНЫЙ СБОР ФОТ И ТРУДОЗАТРАТ ИЗ ДЕТЕЙ (MAX-BASED DEDUPLICATION)
             $fotOtAgg = 0; $fotOtDet = 0;
             $fotOtmAgg = 0; $fotOtmDet = 0;
             $hoursOtAgg = 0; $hoursOtDet = 0;
             $hoursOtmAgg = 0; $hoursOtmDet = 0;
             $machineryHours = 0;

             $allChildren = EstimateItem::where('parent_work_id', $item->id)->get();

             foreach ($allChildren as $child) {
                 $code = trim($child->normative_rate_code ?? '');
                 $name = mb_strtolower(trim($child->name));
                 $type = $child->item_type;

                 if ($type === \App\Enums\EstimatePositionItemType::LABOR) {
                     // Детальные разряды (1-100-XXX)
                     if (str_starts_with($code, '1-100-')) {
                         $fotOtDet += (float)$child->labor_cost;
                         $hoursOtDet += (float)$child->labor_hours;
                     } 
                     // ЗП машинистов детали (4-100-XXX)
                     elseif (str_starts_with($code, '4-100-')) {
                         $fotOtmDet += (float)$child->labor_cost;
                         $hoursOtmDet += (float)$child->labor_hours;
                     } 
                     // Агрегаторы ЗП Машинистов (ОТм, ЗТм...)
                     elseif (str_contains($name, 'отм') || str_contains($name, 'зтм')) {
                         $fotOtmAgg += (float)$child->labor_cost;
                         $hoursOtmAgg += (float)$child->labor_hours;
                     } 
                     // Основные агрегаторы (ОТ, ЗТ, ОТ(ЗТ)...)
                     else {
                         $fotOtAgg += (float)$child->labor_cost;
                         $hoursOtAgg += (float)$child->labor_hours;
                     }
                 } elseif ($type === \App\Enums\EstimatePositionItemType::MACHINERY) {
                     $machineryHours += (float)$child->machinery_hours;
                 }
             }

             // Расчет итогов с дедупликацией (защита от двойного счета ОТ и деталей разрядов)
             $finalFot = max($fotOtAgg, $fotOtDet) + max($fotOtmAgg, $fotOtmDet);
             $finalHours = max($hoursOtAgg, $hoursOtDet) + max($hoursOtmAgg, $hoursOtmDet);

             if ($finalFot > 0) $item->labor_cost = $finalFot;
             if ($finalHours > 0) $item->labor_hours = $finalHours;
             if ($machineryHours > 0) $item->machinery_hours = $machineryHours;

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
            // 2. Материал дороже 500к (поднят порог) + отсутствие ресурсов + отсутствие нормативного шифра (материалы по ГОСТ/ФССЦ не оборудование!)
            $hasNormativeCode = !empty($item->normative_rate_code) && 
                preg_match('/^(ФСБЦ|ФССЦ|ТССЦ|ТСЦ|01\.|ПРАЙС)/ui', $item->normative_rate_code);
                
            $isEquipment = $item->isEquipment() || 
                ($item->unit_price > 500000 && !$hasChildren && !$hasNormativeCode);

            $overheadAmount = (float)($item->overhead_amount ?? 0);
            $profitAmount = (float)($item->profit_amount ?? 0);

            if ($isEquipment) {
                // Прямые Затраты для оборудования — это только РЕСУРСЫ (труд/маш). 
                // Сумма самой "железки" - это оборудование.
                if (!$hasChildren && ($overheadAmount <= 0 && $profitAmount <= 0)) {
                    $directCosts = 0;
                }

                $laborBase = (float)($item->labor_cost ?? 0);
                if ($laborBase <= 0 && $hasChildren) {
                    $laborBase = (float) EstimateItem::where('parent_work_id', $item->id)
                        ->where('item_type', \App\Enums\EstimatePositionItemType::LABOR->value)
                        ->sum('labor_cost');
                }
                if ($laborBase > 0) {
                    $overheadAmount = round($laborBase * ($estimate->overhead_rate / 100), 2);
                    $profitAmount = round($laborBase * ($estimate->profit_rate / 100), 2);
                } else {
                    $overheadAmount = 0;
                    $profitAmount = 0;
                }
                
                $equipmentSum = max(0, $totalAmount - $directCosts - $overheadAmount - $profitAmount);
            } else {
                // Стандартная позиция (Работа/Материал)
                if ($directCosts <= 0) {
                    $directCosts = $totalAmount;
                }
                
                // ⭐ Если НР и СП уже есть (пришли из парсера), НЕ пересчитываем их по процентам
                if ($overheadAmount > 0 || $profitAmount > 0) {
                     // Доверяем данным в БД (они уже в рублях)
                } else {
                    // Только если рублёвых сумм НЕТ, пробуем считать от ФОТ (базовая логика)
                    if (($estimate->overhead_rate + $estimate->profit_rate) > 0) {
                         $fotBase = (float)($item->labor_cost ?? 0);
                         if ($fotBase > 0) {
                             $overheadAmount = round($fotBase * ($estimate->overhead_rate / 100), 2);
                             $profitAmount = round($fotBase * ($estimate->profit_rate / 100), 2);
                             
                             // Если сумма налогов вылезла за Итого, подрезаем их
                             if (($overheadAmount + $profitAmount) > $totalAmount && $totalAmount > 0) {
                                 $totalRate = ($estimate->overhead_rate + $estimate->profit_rate);
                                 $overheadAmount = round($totalAmount * ($estimate->overhead_rate / $totalRate), 2);
                                 $profitAmount = round($totalAmount - $overheadAmount, 2);
                             }
                         }
                    }
                }

                // КРИТИЧНО: ПЗ — это то, что осталось от общего итога после налогов.
                // Это гарантирует равенство: ПЗ + НР + СП = Итого (рубль в рубль)
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
        $this->cacheService->invalidateStructure($estimate);
        
        $this->dispatchAsyncUpdates($estimate);
        
        return $result;
    }

    public function dispatchAsyncUpdates(Estimate $estimate): void
    {
        \App\BusinessModules\Features\BudgetEstimates\Jobs\CalculateEstimateStatisticsJob::dispatch($estimate->id)->afterCommit();
        \App\BusinessModules\Features\BudgetEstimates\Jobs\GenerateEstimateSnapshotJob::dispatch($estimate->id)->afterCommit();
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

