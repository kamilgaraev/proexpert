<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services;

use App\Enums\EstimatePositionItemType;
use App\Models\Estimate;
use App\Models\EstimateItem;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/** Generated positions already contain accepted, resource-rounded money. No implicit markup is introduced. */
final class GeneratedEstimateDecimalCalculation
{
    public function calculate(EstimateItem $item, Estimate $estimate): float
    {
        if ((int) $item->estimate_id !== (int) $estimate->id) {
            throw new \DomainException('estimate_item_calculation_scope_mismatch');
        }
        $money = static fn (mixed $value): string => (string) BigDecimal::of((string) ($value ?? '0'))->toScale(2, RoundingMode::HalfUp);
        $total = BigDecimal::of((string) ($item->current_total_amount ?? $item->total_amount ?? '0'));
        $sourceMoney = $item->metadata['generated_source_money'] ?? null;
        if (is_array($sourceMoney)) {
            if (($estimate->metadata['vat_calculation_method'] ?? null) !== EstimateSourceVatCalculator::METHOD) {
                throw new \DomainException('source_money_parent_policy_missing');
            }
            $accepted = (new SourceMoneyVatBasis)->accepted($sourceMoney, [
                'organization_id' => (int) $estimate->organization_id, 'project_id' => (int) $estimate->project_id,
                'session_id' => (int) ($estimate->metadata['generation_session_id'] ?? 0),
                'work_item_key' => $item->metadata['source_work_item_key'] ?? null,
                'measurement_unit_id' => (int) $item->measurement_unit_id, 'quantity' => (string) $item->quantity,
                'stored_unit_price' => (string) $item->unit_price, 'net_amount' => (string) $total->toScale(2),
            ]);
            $total = BigDecimal::of($accepted['net_amount']);
        }
        if ($total->isZero()) {
            $total = BigDecimal::of((string) $item->quantity)->multipliedBy((string) $item->unit_price)->toScale(2, RoundingMode::HalfUp);
        }
        $type = $item->item_type;
        if ($item->parent_work_id !== null) {
            $item->update(['direct_costs' => $money($total), 'total_amount' => $money($total), 'current_total_amount' => $money($total),
                'materials_cost' => $type === EstimatePositionItemType::MATERIAL ? $money($total) : '0.00',
                'machinery_cost' => $type === EstimatePositionItemType::MACHINERY ? $money($total) : '0.00',
                'labor_cost' => $type === EstimatePositionItemType::LABOR ? $money($total) : '0.00',
                'other_cost' => $type === EstimatePositionItemType::OTHER ? $money($total) : '0.00',
                'equipment_cost' => $type === EstimatePositionItemType::EQUIPMENT ? $money($total) : '0.00',
                'overhead_amount' => '0.00', 'profit_amount' => '0.00']);

            return $total->toFloat();
        }
        $children = EstimateItem::query()->where('estimate_id', $estimate->id)->where('parent_work_id', $item->id)
            ->where('is_not_accounted', false)->whereDoesntHave('childItems')->get();
        $components = ['materials_cost' => BigDecimal::zero(), 'machinery_cost' => BigDecimal::zero(),
            'labor_cost' => BigDecimal::zero(), 'other_cost' => BigDecimal::zero(), 'equipment_cost' => BigDecimal::zero()];
        foreach ($children as $child) {
            $field = match ($child->item_type) {
                EstimatePositionItemType::MATERIAL => 'materials_cost', EstimatePositionItemType::MACHINERY => 'machinery_cost',
                EstimatePositionItemType::LABOR => 'labor_cost', EstimatePositionItemType::OTHER => 'other_cost',
                EstimatePositionItemType::EQUIPMENT => 'equipment_cost', default => null,
            };
            if ($field !== null) {
                $components[$field] = $components[$field]->plus((string) $child->total_amount);
            }
        }
        $values = [];
        if ($children->isNotEmpty()) {
            $resourceTotal = $children->reduce(fn (BigDecimal $sum, EstimateItem $child): BigDecimal => $sum->plus((string) $child->total_amount), BigDecimal::zero());
            if ($resourceTotal->isGreaterThan($total)) {
                throw new \DomainException('generated_estimate_components_exceed_total');
            }
            foreach ($components as $field => $amount) {
                $values[$field] = $money($amount);
            }
            $values['labor_hours'] = (string) $children->filter(fn (EstimateItem $child): bool => $child->item_type === EstimatePositionItemType::LABOR)
                ->reduce(fn (BigDecimal $sum, EstimateItem $child): BigDecimal => $sum->plus((string) $child->labor_hours), BigDecimal::zero());
            $values['machinery_hours'] = (string) $children->filter(fn (EstimateItem $child): bool => $child->item_type === EstimatePositionItemType::MACHINERY)
                ->reduce(fn (BigDecimal $sum, EstimateItem $child): BigDecimal => $sum->plus((string) $child->machinery_hours), BigDecimal::zero());
        } elseif ($type === EstimatePositionItemType::OTHER) {
            $values['other_cost'] = $money($total);
        }
        $equipment = $type === EstimatePositionItemType::EQUIPMENT && $children->isEmpty() ? $total
            : BigDecimal::of($values['equipment_cost'] ?? (string) ($item->equipment_cost ?? '0'));
        $overhead = BigDecimal::of((string) ($item->overhead_amount ?? '0'));
        $profit = BigDecimal::of((string) ($item->profit_amount ?? '0'));
        $direct = $total->minus($equipment)->minus($overhead)->minus($profit);
        if ($direct->isLessThan(0)) {
            throw new \DomainException('generated_estimate_components_exceed_total');
        }
        $item->update([...$values, 'direct_costs' => $money($direct), 'equipment_cost' => $money($equipment),
            'overhead_amount' => $money($overhead), 'profit_amount' => $money($profit), 'total_amount' => $money($total)]);

        return $total->toFloat();
    }
}
