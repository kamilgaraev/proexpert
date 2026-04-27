<?php

declare(strict_types=1);

namespace App\Services\Acting;

use App\Models\CompletedWork;
use App\Models\ContractEstimateItem;
use App\Models\ContractPerformanceAct;
use App\Models\Estimate;
use App\Models\PerformanceActLine;

class ActingPriceService
{
    public function resolveCompletedWorkUnitPrice(CompletedWork $work, float $effectiveQuantity): float
    {
        $baseUnitPrice = $this->resolveCompletedWorkBaseUnitPrice($work, $effectiveQuantity);
        $estimate = $work->estimateItem?->estimate;

        return $this->applyVat($baseUnitPrice, $estimate);
    }

    public function resolveLineUnitPrice(ContractPerformanceAct $act, PerformanceActLine $line): float
    {
        $baseUnitPrice = $this->resolveLineBaseUnitPrice($act, $line);
        $estimate = $line->estimateItem?->estimate ?? $act->contract?->estimate;

        return $this->applyVat($baseUnitPrice, $estimate);
    }

    public function vatRate(?Estimate $estimate): float
    {
        if (!$estimate) {
            return 0.0;
        }

        $vatRate = (float) ($estimate->vat_rate ?? 0);
        if ($vatRate > 0) {
            return $vatRate;
        }

        $amount = (float) ($estimate->total_amount ?? 0);
        $amountWithVat = (float) ($estimate->total_amount_with_vat ?? 0);

        if ($amount > 0 && $amountWithVat > $amount) {
            return round((($amountWithVat / $amount) - 1) * 100, 2);
        }

        return 0.0;
    }

    public function vatAmountFromGross(float $grossAmount, ?Estimate $estimate): float
    {
        $vatRate = $this->vatRate($estimate);

        if ($grossAmount <= 0 || $vatRate <= 0) {
            return 0.0;
        }

        return round($grossAmount * $vatRate / (100 + $vatRate), 2);
    }

    private function resolveCompletedWorkBaseUnitPrice(CompletedWork $work, float $effectiveQuantity): float
    {
        if ($work->price !== null) {
            return round((float) $work->price, 2);
        }

        $contractLink = $work->estimateItem?->contractLinks
            ?->where('contract_id', $work->contract_id)
            ->sortBy('id')
            ->first();

        if ($contractLink && (float) $contractLink->quantity > 0) {
            return round((float) $contractLink->amount / (float) $contractLink->quantity, 2);
        }

        $estimateItem = $work->estimateItem;
        $estimatePrice = (float) (
            $estimateItem?->actual_unit_price
            ?? $estimateItem?->current_unit_price
            ?? $estimateItem?->unit_price
            ?? 0
        );

        if ($estimatePrice > 0) {
            return round($estimatePrice, 2);
        }

        $estimateQuantity = (float) ($estimateItem?->quantity_total ?? $estimateItem?->quantity ?? 0);
        $estimateAmount = (float) ($estimateItem?->current_total_amount ?? $estimateItem?->total_amount ?? 0);
        if ($estimateQuantity > 0 && $estimateAmount > 0) {
            return round($estimateAmount / $estimateQuantity, 2);
        }

        if ($effectiveQuantity <= 0) {
            return 0.0;
        }

        return round((float) ($work->total_amount ?? 0) / $effectiveQuantity, 2);
    }

    private function resolveLineBaseUnitPrice(ContractPerformanceAct $act, PerformanceActLine $line): float
    {
        $contractLink = $line->estimateItem?->contractLinks
            ?->where('contract_id', $act->contract_id)
            ->sortBy('id')
            ->first();

        if (!$contractLink && $line->estimate_item_id) {
            $contractLink = ContractEstimateItem::query()
                ->where('contract_id', $act->contract_id)
                ->where('estimate_item_id', $line->estimate_item_id)
                ->orderBy('id')
                ->first();
        }

        if ($contractLink && (float) $contractLink->quantity > 0) {
            return round((float) $contractLink->amount / (float) $contractLink->quantity, 2);
        }

        $estimateItem = $line->estimateItem;
        $estimatePrice = (float) (
            $estimateItem?->actual_unit_price
            ?? $estimateItem?->current_unit_price
            ?? $estimateItem?->unit_price
            ?? 0
        );

        if ($estimatePrice > 0) {
            return round($estimatePrice, 2);
        }

        $estimateQuantity = (float) ($estimateItem?->quantity_total ?? $estimateItem?->quantity ?? 0);
        $estimateAmount = (float) ($estimateItem?->current_total_amount ?? $estimateItem?->total_amount ?? 0);
        if ($estimateQuantity > 0 && $estimateAmount > 0) {
            return round($estimateAmount / $estimateQuantity, 2);
        }

        return 0.0;
    }

    private function applyVat(float $baseUnitPrice, ?Estimate $estimate): float
    {
        $vatRate = $this->vatRate($estimate);

        if ($baseUnitPrice <= 0 || $vatRate <= 0) {
            return round($baseUnitPrice, 2);
        }

        return round($baseUnitPrice * (1 + ($vatRate / 100)), 2);
    }
}
