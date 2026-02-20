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
        
        if ($item->isWork() || $item->isMaterial()) {
             // В GrandSmeta ресурсы часто дублируются (сводная строка "М" + детализация).
             // Ищем сводные строки компонентов.
             $summaryNames = ['М', 'ОТ(ЗТ)', 'ОТм', 'ЭМ', 'ОТ', 'ЗП', 'ЗПМ', 'МАТ'];
             $hasSummaries = EstimateItem::where('parent_work_id', $item->id)
                 ->whereIn('name', $summaryNames)
                 ->exists();

             if ($hasSummaries) {
                 // Если есть сводные строки, суммируем ТОЛЬКО их (это и есть ПЗ по позиции)
                 $resourcesSum = (float) EstimateItem::where('parent_work_id', $item->id)
                     ->whereIn('name', $summaryNames)
                     ->sum('total_amount');
             } else {
                 // Если сводных строк нет, суммируем все детальные ресурсы (кроме оборудования)
                 $resourcesSum = (float) EstimateItem::where('parent_work_id', $item->id)
                     ->where('item_type', '!=', \App\Enums\EstimatePositionItemType::EQUIPMENT->value)
                     ->where('is_not_accounted', false)
                     ->get()
                     ->filter(function ($resource) {
                         // Выделение Оборудования: если это material дороже 50 000 руб. без вложенных ресурсов
                         return !($resource->isMaterial() && $resource->unit_price > 50000);
                     })
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
             
             // Если сама позиция помечена как equipment — считаем всё её содержимое или её саму как оборудование
             if ($item->isEquipment()) {
                 $equipmentSum = $resourcesSum + $equipmentSum;
                 $resourcesSum = 0;
             }
        }

        // 2. Логика распределения маржи (Reverse Engineering)
        if ($item->is_manual && $item->current_total_amount !== null && $item->current_total_amount > 0) {
            $totalAmount = (float)$item->current_total_amount;
            
            // Если ресурсов нет (ручной ввод), подгоняем базу
            $directCosts = $resourcesSum > 0 ? $resourcesSum : ($item->isEquipment() ? 0 : $item->quantity * $item->unit_price);
            
            if ($item->isEquipment()) {
                $equipmentSum = $totalAmount;
                $directCosts = 0;
            }

            // Накладные и прибыль считаем от остатка за вычетом оборудования ПЕРЕД распределением
            $remainingForMarkup = max(0, $totalAmount - $directCosts - $equipmentSum);
            
            $overheadAmount = (float)($item->overhead_amount ?? 0);
            $profitAmount = (float)($item->profit_amount ?? 0);
            
            // Если в файле НР/СП не было (или они крайне малы), делим остаток 66/34
            if ($remainingForMarkup > 0 && ($overheadAmount + $profitAmount) <= 0.05) {
                 $overheadAmount = round($remainingForMarkup * 0.66, 2);
                 $profitAmount = round($remainingForMarkup * 0.34, 2);
            }
            
            // Если ресурсов нет (ручной ввод), подгоняем ПЗ под оставшуюся итоговую сумму
            if ($resourcesSum <= 0 && !$item->isEquipment()) {
                 $directCosts = max(0, $totalAmount - $overheadAmount - $profitAmount - $equipmentSum);
            }
        } else {
            // Стандартный расчет снизу вверх
            $directCosts = $resourcesSum > 0 ? $resourcesSum : ($item->quantity * $item->unit_price);
            $overheadAmount = $directCosts * ($estimate->overhead_rate / 100);
            $profitAmount = $directCosts * ($estimate->profit_rate / 100);
            $totalAmount = $directCosts + $overheadAmount + $profitAmount + $equipmentSum;
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
        // Используем агрегацию на уровне БД
        // 1. СМР часть: Прямые затраты + НР + СП для позиций, не являющихся ресурсами
        $cmrTotals = EstimateItem::where('estimate_id', $estimate->id)
            ->whereNull('parent_work_id')
            ->where('is_not_accounted', false)
            ->where('item_type', '!=', \App\Enums\EstimatePositionItemType::EQUIPMENT->value)
            ->selectRaw('
                COALESCE(SUM(direct_costs), 0) as total_direct,
                COALESCE(SUM(overhead_amount), 0) as total_overhead,
                COALESCE(SUM(profit_amount), 0) as total_profit
            ')
            ->get()
            ->first(); // Используем get()->first() для надежности с Eloquent

        // 2. Оборудование отдельно (оно входит в ИТОГО, но обычно не в ПЗ СМР)
        $totalEquipment = (float) EstimateItem::where('estimate_id', $estimate->id)
            ->whereNull('parent_work_id') // Считаем только корневые оборудование или позиции
            ->where('item_type', \App\Enums\EstimatePositionItemType::EQUIPMENT->value)
            ->sum('total_amount');
        
        // Дополнительно учитываем оборудование, которое было "спрятано" внутри материалов (дороже 50к)
        // Но так как calculateItemTotal уже обновил equipment_cost у корневых позиций, 
        // нам лучше суммировать поле equipment_cost у всех корневых позиций.
        
        $totalEquipmentFromItems = (float) EstimateItem::where('estimate_id', $estimate->id)
            ->whereNull('parent_work_id')
            ->sum('equipment_cost');
        
        $totalDirectCosts = (float) $cmrTotals->total_direct;
        $totalOverheadCosts = (float) $cmrTotals->total_overhead;
        $totalEstimatedProfit = (float) $cmrTotals->total_profit;
        
        $totalAmount = $totalDirectCosts + $totalOverheadCosts + $totalEstimatedProfit + $totalEquipmentFromItems;
        $totalAmountWithVat = $totalAmount * (1 + $estimate->vat_rate / 100);
        
        $result = [
            'total_direct_costs' => round($totalDirectCosts, 2),
            'total_overhead_costs' => round($totalOverheadCosts, 2),
            'total_estimated_profit' => round($totalEstimatedProfit, 2),
            'total_equipment_costs' => round($totalEquipmentFromItems, 2),
            'total_amount' => round($totalAmount, 2),
            'total_amount_with_vat' => round($totalAmountWithVat, 2),
        ];
        
        $estimate->update($result);
        
        // Инвалидировать кеш после обновления
        $this->cacheService->invalidateTotals($estimate);
        
        return $result;
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

