<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services;

use App\Enums\EstimatePositionItemType;
use App\Models\Estimate;
use App\Models\EstimateItem;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

final class GeneratedEstimateResourceResize
{
    public function resize(EstimateItem $item, string $oldQuantity, string $newQuantity, Estimate $estimate): bool
    {
        $children = EstimateItem::query()->where('estimate_id', $estimate->id)->where('parent_work_id', $item->id)
            ->where('is_not_accounted', false)->whereDoesntHave('childItems')->lockForUpdate()->get();
        if ($children->isEmpty()) {
            return false;
        }
        $oldSum = $children->reduce(fn (BigDecimal $sum, EstimateItem $child): BigDecimal => $sum->plus((string) $child->total_amount), BigDecimal::zero());
        $residual = BigDecimal::of((string) $item->total_amount)->minus($oldSum);
        if ($residual->isLessThan(0)) {
            throw new \DomainException('generated_estimate_components_exceed_total');
        }
        $newSum = BigDecimal::zero();
        foreach ($children as $child) {
            $dose = $child->metadata['quantity_per_unit'] ?? null;
            if (! is_numeric($dose)) {
                if (! BigDecimal::of($oldQuantity)->isGreaterThan(0)) {
                    throw new \DomainException('generated_resource_quantity_basis_missing');
                }
                $dose = (string) BigDecimal::of((string) $child->quantity)->dividedBy($oldQuantity, 18, RoundingMode::HalfUp);
            }
            $quantity = BigDecimal::of((string) $dose)->multipliedBy($newQuantity);
            $price = is_numeric($child->metadata['precise_stored_unit_price'] ?? null)
                && is_numeric($child->metadata['precise_unit_price'] ?? null)
                && BigDecimal::of((string) $child->metadata['precise_stored_unit_price'])->isEqualTo((string) $child->unit_price)
                ? $child->metadata['precise_unit_price'] : (string) $child->unit_price;
            $amount = (string) $quantity->multipliedBy((string) $price)->toScale(2, RoundingMode::HalfUp);
            $child->update(['quantity' => (string) $quantity->toScale(8, RoundingMode::HalfUp), 'quantity_total' => (string) $quantity->toScale(8, RoundingMode::HalfUp),
                'total_amount' => $amount, 'current_total_amount' => $amount,
                'labor_hours' => $child->item_type === EstimatePositionItemType::LABOR ? (string) $quantity : '0',
                'machinery_hours' => $child->item_type === EstimatePositionItemType::MACHINERY ? (string) $quantity : '0']);
            (new GeneratedEstimateDecimalCalculation)->calculate($child, $estimate);
            $newSum = $newSum->plus($amount);
        }
        if (! $residual->isZero() && BigDecimal::of($oldQuantity)->isGreaterThan(0)) {
            $residual = $residual->multipliedBy($newQuantity)->dividedBy($oldQuantity, 2, RoundingMode::HalfUp);
        }
        $total = (string) $newSum->plus($residual)->toScale(2, RoundingMode::HalfUp);
        $metadata = $item->metadata ?? [];
        $metadata['quantity_evidence'] = null;
        $metadata['quantity_calculation'] = ['source' => 'ordinary_estimate_manual_edit', 'quantity' => $newQuantity, 'verification_status' => 'user_entered'];
        $item->update(['current_total_amount' => $total, 'total_amount' => $total, 'metadata' => $metadata]);
        foreach ($item->resources as $resource) {
            $quantity = BigDecimal::of((string) $resource->quantity_per_unit)->multipliedBy($newQuantity);
            $resource->update(['total_quantity' => (string) $quantity->toScale(18, RoundingMode::HalfUp),
                'total_amount' => (string) $quantity->multipliedBy((string) $resource->unit_price)->toScale(2, RoundingMode::HalfUp)]);
        }

        return true;
    }
}
