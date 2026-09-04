<?php

declare(strict_types=1);

namespace App\Services\Acting;

use App\BusinessModules\Features\BudgetEstimates\Services\Versioning\EstimateVersionItemSnapshotResolver;
use App\Exceptions\BusinessLogicException;
use App\Models\CompletedWork;
use App\Models\Contract;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DomainException;

use function trans_message;

final class PerformanceActFinancialBasisService
{
    public function __construct(
        private readonly EstimateVersionItemSnapshotResolver $snapshotItems,
        private readonly ActingPriceService $legacyPrices,
    ) {}

    /**
     * @return array{
     *     estimate_version_id: int|null,
     *     base_unit_price: string,
     *     unit_price: string,
     *     vat_rate: string,
     *     snapshot: array<string, mixed>
     * }
     */
    public function forCompletedWork(CompletedWork $work, Contract $contract, float $effectiveQuantity): array
    {
        $item = $work->estimateItem;
        $estimate = $item?->estimate;
        if ($item === null || $estimate === null) {
            $unitPrice = $this->money($this->legacyPrices->resolveCompletedWorkUnitPrice($work, $effectiveQuantity));

            return [
                'estimate_version_id' => null,
                'base_unit_price' => $unitPrice,
                'unit_price' => $unitPrice,
                'vat_rate' => '0.00',
                'snapshot' => [
                    'basis_type' => 'confirmed_completed_work',
                    'completed_work_id' => (int) $work->id,
                    'completed_work_price' => $unitPrice,
                    'completed_work_total' => $this->money($work->total_amount ?? 0),
                ],
            ];
        }

        $version = $estimate->currentVersion;
        if ($version === null
            || $version->status !== 'approved'
            || (int) $version->organization_id !== (int) $contract->organization_id
            || (int) $version->estimate_id !== (int) $estimate->id) {
            throw new BusinessLogicException(trans_message('act_reports.approved_estimate_version_required'), 422);
        }

        try {
            $snapshotItem = $this->snapshotItems->resolve($version, $item);
        } catch (DomainException) {
            throw new BusinessLogicException(trans_message('act_reports.approved_estimate_version_required'), 422);
        }

        $vatRate = $this->decimal($version->snapshot['rates']['vat_rate'] ?? 0, 2);
        [$baseAmount, $baseQuantity] = $this->snapshotBaseAmounts($snapshotItem, (int) $contract->id, $vatRate);
        $baseUnitPrice = (string) $baseAmount->dividedBy($baseQuantity, 2, RoundingMode::HalfUp);
        $unitPrice = $baseAmount
            ->multipliedBy(BigDecimal::one()->plus(BigDecimal::of($vatRate)->dividedBy(100, 8, RoundingMode::HalfUp)))
            ->dividedBy($baseQuantity, 2, RoundingMode::HalfUp);

        return [
            'estimate_version_id' => (int) $version->id,
            'base_unit_price' => $baseUnitPrice,
            'unit_price' => (string) $unitPrice,
            'vat_rate' => $vatRate,
            'snapshot' => [
                'basis_type' => 'estimate_version',
                'estimate_version_id' => (int) $version->id,
                'estimate_version_number' => (int) $version->version_number,
                'estimate_version_hash' => (string) $version->snapshot_hash,
                'estimate_item' => $snapshotItem,
                'contract_id' => (int) $contract->id,
                'base_unit_price' => $baseUnitPrice,
                'vat_rate' => $vatRate,
                'unit_price_with_vat' => (string) $unitPrice,
            ],
        ];
    }

    private function snapshotBaseAmounts(array $item, int $contractId, string $vatRate): array
    {
        foreach ($item['contract_links'] ?? [] as $link) {
            if ((int) ($link['contract_id'] ?? 0) !== $contractId) {
                continue;
            }

            $quantity = BigDecimal::of((string) ($link['quantity'] ?? '0'));
            if ($quantity->isGreaterThan(0)) {
                if (($link['amount_without_vat'] ?? null) !== null) {
                    return [BigDecimal::of((string) $link['amount_without_vat']), $quantity];
                }

                [$itemAmount, $itemQuantity] = $this->snapshotItemBaseAmounts($item);
                $baseAmount = $itemAmount->multipliedBy($quantity)
                    ->dividedBy($itemQuantity, 2, RoundingMode::HalfUp);
                $grossAmount = $baseAmount->multipliedBy(
                    BigDecimal::one()->plus(BigDecimal::of($vatRate)->dividedBy(100, 8, RoundingMode::HalfUp))
                );
                $amount = $this->money($link['amount'] ?? 0);
                if ($amount === $this->money($baseAmount) || $amount === $this->money($grossAmount)) {
                    return [$baseAmount, $quantity];
                }

                throw new BusinessLogicException(trans_message('act_reports.estimate_version_price_required'), 422);
            }
        }

        return $this->snapshotItemBaseAmounts($item);
    }

    private function snapshotItemBaseAmounts(array $item): array
    {
        $quantity = BigDecimal::of((string) ($item['quantity_total'] ?? $item['quantity'] ?? '0'));
        $amount = BigDecimal::of((string) ($item['current_total_amount'] ?? $item['total_amount'] ?? '0'));
        if ($quantity->isGreaterThan(0) && $amount->isGreaterThan(0)) {
            return [$amount, $quantity];
        }

        foreach (['actual_unit_price', 'current_unit_price', 'unit_price'] as $field) {
            if (($item[$field] ?? null) !== null && BigDecimal::of((string) $item[$field])->isGreaterThan(0)) {
                return [BigDecimal::of((string) $item[$field]), BigDecimal::one()];
            }
        }

        throw new BusinessLogicException(trans_message('act_reports.estimate_version_price_required'), 422);
    }

    private function money(mixed $value): string
    {
        return $this->decimal($value, 2);
    }

    private function decimal(mixed $value, int $scale): string
    {
        return (string) BigDecimal::of((string) ($value ?? 0))->toScale($scale, RoundingMode::HalfUp);
    }
}
