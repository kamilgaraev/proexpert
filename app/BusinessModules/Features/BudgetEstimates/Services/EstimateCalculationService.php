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
        // 1. Считаем фактические прямые затраты из дочерних ресурсов (для расценок)
        $resourcesSum = 0;
        if ($item->item_type === 'work' || $item->item_type === 'material') {
             $resourcesSum = EstimateItem::where('parent_work_id', $item->id)
                 ->where('is_not_accounted', false)
                 ->sum('total_amount');
        }

        // 2. Для ручных позиций из свернутой сметы (GrandSmeta) current_total_amount - это "Итого", а не ПЗ
        if ($item->is_manual && $item->current_total_amount !== null && $item->current_total_amount > 0) {
            $totalAmount = $item->current_total_amount;
            
            // Если есть ресурсы, прямые затраты = стоимости ресурсов. Иначе берем из базы или считаем Q*P
            $directCosts = $resourcesSum > 0 ? $resourcesSum : ($item->quantity * $item->unit_price);
            
            // Вся оставшаяся сумма (Total - ПЗ) - это Накладные и Прибыль
            $remainingForOverheadAndProfit = max(0, $totalAmount - $directCosts);
            
            // Если мы спарсили НР и СП явно из файла (например, в BaseItemStrategy), используем их
            $overheadAmount = $item->overhead_amount ?? 0;
            $profitAmount = $item->profit_amount ?? 0;
            
            // Если в файле НР/СП не было или они нули, но остаток есть, делим его пропорционально 66/34 (стандартно НР больше СП)
            if ($remainingForOverheadAndProfit > 0 && ($overheadAmount + $profitAmount) == 0) {
                 $overheadAmount = $remainingForOverheadAndProfit * 0.66;
                 $profitAmount = $remainingForOverheadAndProfit * 0.34;
            }
            
        } else {
            // Стандартный расчет (снизу вверх)
            $directCosts = $resourcesSum > 0 ? $resourcesSum : ($item->quantity * $item->unit_price);
            $overheadAmount = $directCosts * ($estimate->overhead_rate / 100);
            $profitAmount = $directCosts * ($estimate->profit_rate / 100);
            $totalAmount = $directCosts + $overheadAmount + $profitAmount;
        }
        
        $item->update([
            'direct_costs' => round($directCosts, 2),
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
    
    /**
     * Выполнить расчет итоговых сумм (с оптимизацией через БД)
     */
    private function performCalculation(Estimate $estimate): array
    {
        // Используем агрегацию на уровне БД вместо цикла
        // ИСКЛЮЧАЕМ ресурсы из расчета (is_not_accounted = true) и подчиненные позиции
        $totals = EstimateItem::where('estimate_id', $estimate->id)
            ->where('is_not_accounted', false)
            ->whereNull('parent_work_id')
            ->selectRaw('
                COALESCE(SUM(direct_costs), 0) as total_direct_costs,
                COALESCE(SUM(overhead_amount), 0) as total_overhead_costs,
                COALESCE(SUM(profit_amount), 0) as total_estimated_profit
            ')
            ->first();
        
        $totalDirectCosts = (float) $totals->total_direct_costs;
        $totalOverheadCosts = (float) $totals->total_overhead_costs;
        $totalEstimatedProfit = (float) $totals->total_estimated_profit;
        
        $totalAmount = $totalDirectCosts + $totalOverheadCosts + $totalEstimatedProfit;
        $totalAmountWithVat = $totalAmount * (1 + $estimate->vat_rate / 100);
        
        $result = [
            'total_direct_costs' => round($totalDirectCosts, 2),
            'total_overhead_costs' => round($totalOverheadCosts, 2),
            'total_estimated_profit' => round($totalEstimatedProfit, 2),
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

