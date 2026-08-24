<?php

declare(strict_types=1);

namespace App\Services\Acting;

use App\Exceptions\BusinessLogicException;
use App\Models\ContractPerformanceAct;
use App\Models\PerformanceActLine;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

use function trans_message;

final class PerformanceActFinancialTotalsService
{
    public function recalculateFromStoredBasis(ContractPerformanceAct $act): ContractPerformanceAct
    {
        if ($act->is_approved) {
            return $act->fresh(['contract.project', 'contract.contractor', 'estimateVersion', 'lines', 'files']);
        }

        $act->loadMissing(['lines', 'completedWorks']);

        foreach ($act->lines as $line) {
            $unitPrice = $this->storedUnitPrice($line);
            if ($unitPrice->isLessThanOrEqualTo(0)) {
                throw new BusinessLogicException(trans_message('act_reports.financial_basis_required'), 422);
            }

            $amount = BigDecimal::of((string) $line->quantity)
                ->multipliedBy($unitPrice)
                ->toScale(2, RoundingMode::HalfUp);
            if (! BigDecimal::of((string) $line->amount)->isEqualTo($amount)
                || ! BigDecimal::of((string) ($line->unit_price ?? 0))->isEqualTo($unitPrice)) {
                $line->forceFill([
                    'unit_price' => (string) $unitPrice,
                    'amount' => (string) $amount,
                ])->save();
            }

            if ($line->completed_work_id !== null) {
                $act->completedWorks()->updateExistingPivot($line->completed_work_id, [
                    'included_amount' => (string) $amount,
                ]);
            }
        }

        return $this->synchronize($act);
    }

    public function synchronize(ContractPerformanceAct $act): ContractPerformanceAct
    {
        $lines = $act->lines()->get();
        $versionIds = $lines
            ->pluck('estimate_version_id')
            ->filter()
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->values();
        if ($versionIds->count() > 1) {
            throw new BusinessLogicException(trans_message('act_reports.single_estimate_version_required'), 422);
        }

        $vatRates = $lines
            ->map(static fn (PerformanceActLine $line): string => number_format(
                (float) data_get($line->basis_snapshot, 'vat_rate', 0),
                2,
                '.',
                '',
            ))
            ->unique()
            ->values();
        if ($vatRates->count() > 1) {
            throw new BusinessLogicException(trans_message('act_reports.single_vat_rate_required'), 422);
        }

        $gross = BigDecimal::zero();
        $net = BigDecimal::zero();
        foreach ($lines as $line) {
            $gross = $gross->plus(BigDecimal::of((string) $line->amount));
            $baseUnitPrice = data_get($line->basis_snapshot, 'base_unit_price');
            $net = $net->plus($baseUnitPrice === null
                ? BigDecimal::of((string) $line->amount)
                : BigDecimal::of((string) $line->quantity)->multipliedBy(BigDecimal::of((string) $baseUnitPrice)));
        }

        $gross = $gross->toScale(2, RoundingMode::HalfUp);
        $net = $net->toScale(2, RoundingMode::HalfUp);
        $act->forceFill([
            'estimate_version_id' => $versionIds->first(),
            'amount' => (string) $gross,
            'vat_rate' => $vatRates->first() ?? '0.00',
            'vat_amount' => (string) $gross->minus($net)->toScale(2, RoundingMode::HalfUp),
            'amount_without_vat' => (string) $net,
        ])->save();

        return $act->fresh(['contract.project', 'contract.contractor', 'estimateVersion', 'lines', 'files']);
    }

    private function storedUnitPrice(PerformanceActLine $line): BigDecimal
    {
        $snapshot = $line->basis_snapshot;
        $stored = data_get($snapshot, 'unit_price_with_vat')
            ?? data_get($snapshot, 'completed_work_price')
            ?? data_get($snapshot, 'base_unit_price')
            ?? $line->unit_price;

        return BigDecimal::of((string) ($stored ?? 0))->toScale(2, RoundingMode::HalfUp);
    }
}
