<?php

declare(strict_types=1);

namespace App\Services\Acting;

use App\BusinessModules\Features\ChangeManagement\Models\ChangeRequest;
use App\BusinessModules\Features\ChangeManagement\Models\VariationOrder;
use App\Exceptions\BusinessLogicException;
use App\Models\Contract;
use App\Models\PerformanceActLine;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;

use function trans_message;

final class ManualActLineBasisService
{
    /**
     * @param  array<string, mixed>  $line
     * @return array{amount: string, unit_price: string, snapshot: array<string, mixed>}
     */
    public function resolve(
        int $organizationId,
        int $projectId,
        Contract $contract,
        array $line,
    ): array {
        $variationOrderId = (int) ($line['variation_order_id'] ?? 0);
        if ($variationOrderId < 1) {
            throw new BusinessLogicException(trans_message('act_reports.variation_order_required'), 422);
        }

        $variation = VariationOrder::query()
            ->whereKey($variationOrderId)
            ->where('organization_id', $organizationId)
            ->lockForUpdate()
            ->first();
        if ($variation === null) {
            throw new BusinessLogicException(trans_message('act_reports.variation_order_not_available'), 422);
        }

        $change = ChangeRequest::query()
            ->whereKey($variation->change_request_id)
            ->where('organization_id', $organizationId)
            ->where('project_id', $projectId)
            ->lockForUpdate()
            ->first();
        if ($change === null || ! in_array($change->status, ['approved', 'implemented', 'closed'], true)) {
            throw new BusinessLogicException(trans_message('act_reports.variation_order_not_available'), 422);
        }

        $allocationMatches = $change->reporting_contract_project_allocation_id !== null
            && DB::table('contract_project_allocations')
                ->where('id', (int) $change->reporting_contract_project_allocation_id)
                ->where('contract_id', $contract->id)
                ->where('project_id', $projectId)
                ->where('is_active', true)
                ->whereNull('deleted_at')
                ->exists();
        if (! $allocationMatches) {
            throw new BusinessLogicException(trans_message('act_reports.variation_order_not_available'), 422);
        }

        $quantity = BigDecimal::of((string) ($line['quantity'] ?? '0'));
        if ($quantity->isLessThanOrEqualTo(0)) {
            throw new BusinessLogicException(trans_message('act_reports.manual_line_amount_invalid'), 422);
        }

        $providedAmount = array_key_exists('amount', $line) && $line['amount'] !== null
            ? BigDecimal::of((string) $line['amount'])->toScale(2, RoundingMode::HalfUp)
            : null;
        $providedUnitPrice = array_key_exists('unit_price', $line) && $line['unit_price'] !== null
            ? BigDecimal::of((string) $line['unit_price'])->toScale(2, RoundingMode::HalfUp)
            : null;
        if ($providedAmount === null && $providedUnitPrice === null) {
            throw new BusinessLogicException(trans_message('act_reports.manual_line_amount_invalid'), 422);
        }

        $calculatedAmount = $providedUnitPrice?->multipliedBy($quantity)->toScale(2, RoundingMode::HalfUp);
        if ($providedAmount !== null && $calculatedAmount !== null && ! $providedAmount->isEqualTo($calculatedAmount)) {
            throw new BusinessLogicException(trans_message('act_reports.manual_line_amount_invalid'), 422);
        }

        $amount = $providedAmount ?? $calculatedAmount;
        if ($amount === null || $amount->isLessThanOrEqualTo(0)) {
            throw new BusinessLogicException(trans_message('act_reports.manual_line_amount_invalid'), 422);
        }
        $unitPrice = $providedUnitPrice
            ?? $amount->dividedBy($quantity, 2, RoundingMode::HalfUp);

        $alreadyReserved = BigDecimal::of((string) PerformanceActLine::query()
            ->where('variation_order_id', $variation->id)
            ->whereHas('performanceAct', static function ($query): void {
                $query->whereNotIn('status', [
                    'rejected',
                    'annulled',
                    'cancelled',
                ]);
            })
            ->sum('amount'));
        $limit = BigDecimal::of((string) $variation->amount)->toScale(2, RoundingMode::HalfUp);
        if ($alreadyReserved->plus($amount)->isGreaterThan($limit)) {
            throw new BusinessLogicException(trans_message('act_reports.variation_order_limit_exceeded'), 422);
        }

        return [
            'amount' => (string) $amount,
            'unit_price' => (string) $unitPrice,
            'snapshot' => [
                'basis_type' => 'variation_order',
                'variation_order_id' => (int) $variation->id,
                'variation_number' => (string) $variation->variation_number,
                'variation_amount' => (string) $limit,
                'change_request_id' => (int) $change->id,
                'change_number' => (string) $change->change_number,
                'change_status' => (string) $change->status,
                'contract_id' => (int) $contract->id,
                'project_id' => $projectId,
                'base_unit_price' => (string) $unitPrice,
                'vat_rate' => '0.00',
            ],
        ];
    }
}
