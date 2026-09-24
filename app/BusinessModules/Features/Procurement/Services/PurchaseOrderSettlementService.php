<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Services;

use App\BusinessModules\Features\Procurement\Enums\PurchaseReceiptStatusEnum;
use App\BusinessModules\Features\Procurement\Models\PurchaseOrder;
use App\BusinessModules\Features\Procurement\Models\PurchaseReceiptLine;
use App\BusinessModules\Features\Procurement\Models\SupplierProposalVersion;
use App\BusinessModules\Features\Procurement\Support\SupplierPaymentSchedule;
use App\BusinessModules\Features\Procurement\Support\SupplierSettlementPlanner;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

final class PurchaseOrderSettlementService
{
    public function __construct(
        private readonly PurchaseOrderPaymentGateService $paymentGate,
        private readonly SupplierSettlementPlanner $planner
    ) {}

    public function plan(PurchaseOrder $order): ?array
    {
        if ($this->paymentGate->requiredAdvanceAmount($order) === null) {
            return null;
        }
        $version = $order->getRelation('acceptedSupplierProposalVersion');
        if (! $version instanceof SupplierProposalVersion) {
            return null;
        }
        $snapshot = $version->commercial_snapshot;
        $terms = SupplierPaymentSchedule::normalize($snapshot['payment_schedule'] ?? null);
        if ($terms === null) {
            return null;
        }

        $total = BigDecimal::of((string) $order->total_amount)->toScale(2);
        if ($total->isNegative() || ! isset($snapshot['total_amount'], $snapshot['subtotal_amount'])
            || ! $total->isEqualTo((string) $snapshot['total_amount'])
            || ($snapshot['currency'] ?? null) !== $order->currency) {
            throw new InvalidArgumentException('Settlement order does not match accepted commercial terms');
        }

        $order->loadMissing(['items', 'receipts.lines.returns']);
        $items = [];
        $subtotal = BigDecimal::zero();
        foreach ($order->items as $item) {
            $quantity = BigDecimal::of((string) $item->quantity);
            $amount = BigDecimal::of((string) $item->total_price)->toScale(2);
            $price = BigDecimal::of((string) $item->unit_price)->toScale(2);
            if ((int) $item->purchase_order_id !== (int) $order->id || ! $quantity->isPositive()
                || $amount->isNegative() || $price->isNegative() || isset($items[$item->id])) {
                throw new InvalidArgumentException('Invalid settlement order item');
            }
            $items[$item->id] = ['quantity' => $quantity, 'amount' => $amount, 'price' => $price,
                'received' => BigDecimal::zero(), 'received_amount' => BigDecimal::zero()];
            $subtotal = $subtotal->plus($amount);
        }
        if (! $subtotal->isEqualTo((string) $snapshot['subtotal_amount']) || ($subtotal->isZero() && ! $total->isZero())) {
            throw new InvalidArgumentException('Settlement items do not match accepted subtotal');
        }

        $facts = [];
        $receivedSubtotal = BigDecimal::zero();
        $previousGross = BigDecimal::zero();
        foreach ($order->receipts->sortBy('id') as $receipt) {
            if ((int) $receipt->organization_id !== (int) $order->organization_id
                || (int) $receipt->purchase_order_id !== (int) $order->id) {
                throw new InvalidArgumentException('Foreign settlement receipt');
            }
            if ($receipt->status !== PurchaseReceiptStatusEnum::POSTED) {
                continue;
            }
            foreach ($receipt->lines->sortBy('id') as $line) {
                if ((int) $line->purchase_receipt_id !== (int) $receipt->id || ! isset($items[$line->purchase_order_item_id])) {
                    throw new InvalidArgumentException('Foreign settlement receipt line');
                }
                $item = $items[$line->purchase_order_item_id];
                if (! $item['price']->isEqualTo((string) $line->price)) {
                    throw new InvalidArgumentException('Settlement receipt price differs from order');
                }
                $received = $item['received']->plus($this->effectiveQuantity($line, (int) $order->organization_id));
                if ($received->isGreaterThan($item['quantity'])) {
                    throw new InvalidArgumentException('Settlement received quantity exceeds order');
                }
                $receivedAmount = $item['amount']->multipliedBy($received)->dividedBy($item['quantity'], 2, RoundingMode::HalfUp);
                $receivedSubtotal = $receivedSubtotal->plus($receivedAmount->minus($item['received_amount']));
                $items[$line->purchase_order_item_id]['received'] = $received;
                $items[$line->purchase_order_item_id]['received_amount'] = $receivedAmount;
            }

            $gross = $subtotal->isZero()
                ? BigDecimal::zero()
                : $total->multipliedBy($receivedSubtotal)->dividedBy($subtotal, 2, RoundingMode::HalfUp);
            $facts[] = ['id' => (int) $receipt->id, 'date' => $receipt->receipt_date?->format('Y-m-d') ?? '',
                'amount_minor' => $gross->minus($previousGross)->multipliedBy(100)->toInt()];
            $previousGross = $gross;
        }

        return $this->planner->build($total->multipliedBy(100)->toInt(), $terms['advance_percent'], $terms['deferment_days'],
            $order->order_date?->format('Y-m-d') ?? '', $facts);
    }

    private function effectiveQuantity(PurchaseReceiptLine $line, int $organizationId): BigDecimal
    {
        $quantity = BigDecimal::of((string) $line->quantity_received);
        if ($quantity->isNegative()) {
            throw new InvalidArgumentException('Negative settlement receipt quantity');
        }
        foreach ($line->returns as $return) {
            $returned = BigDecimal::of((string) $return->quantity);
            if ((int) $return->organization_id !== $organizationId
                || (int) $return->purchase_receipt_line_id !== (int) $line->id || $returned->isNegative()) {
                throw new InvalidArgumentException('Invalid settlement receipt return');
            }
            $quantity = $quantity->minus($returned);
        }
        if ($quantity->isNegative()) {
            throw new InvalidArgumentException('Settlement returns exceed receipt quantity');
        }

        return $line->reversed_at !== null ? BigDecimal::zero() : $quantity;
    }
}
