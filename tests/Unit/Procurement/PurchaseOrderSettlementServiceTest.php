<?php

declare(strict_types=1);

namespace Tests\Unit\Procurement;

use App\BusinessModules\Features\Procurement\Models\PurchaseOrder;
use App\BusinessModules\Features\Procurement\Models\PurchaseOrderItem;
use App\BusinessModules\Features\Procurement\Models\PurchaseReceipt;
use App\BusinessModules\Features\Procurement\Models\PurchaseReceiptLine;
use App\BusinessModules\Features\Procurement\Models\PurchaseReceiptReturn;
use App\BusinessModules\Features\Procurement\Models\SupplierProposalVersion;
use App\BusinessModules\Features\Procurement\Services\PurchaseOrderPaymentGateService;
use App\BusinessModules\Features\Procurement\Services\PurchaseOrderSettlementService;
use App\BusinessModules\Features\Procurement\Support\SupplierSettlementPlanner;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class PurchaseOrderSettlementServiceTest extends TestCase
{
    public function test_receipt_includes_its_share_of_delivery_and_included_vat(): void
    {
        $order = $this->order();
        $rows = $this->service()->plan($order);

        $this->assertSame([
            ['source_key' => 'receipt:100', 'due_date' => '2026-09-15', 'amount_minor' => 105000],
            ['source_key' => 'unreceived', 'due_date' => null, 'amount_minor' => 105000],
        ], $rows);
    }

    public function test_excluded_vat_and_second_batch_preserve_the_order_total(): void
    {
        $order = $this->order('2520.00');
        $order->receipts->push($this->receipt(101, '50.000', '2026-09-08'));
        $rows = $this->service()->plan($order);

        $this->assertSame([126000, 126000], array_column($rows, 'amount_minor'));
        $this->assertSame(['2026-09-15', '2026-09-18'], array_column($rows, 'due_date'));
    }

    public function test_mixed_terms_use_the_gross_receipt_basis(): void
    {
        $order = $this->order();
        $snapshot = $order->acceptedSupplierProposalVersion->commercial_snapshot;
        $snapshot['payment_schedule'] = ['mode' => 'mixed', 'advance_percent' => 30, 'deferment_days' => 15];
        $order->acceptedSupplierProposalVersion->commercial_snapshot = $snapshot;

        $rows = $this->service()->plan($order);
        $this->assertSame([63000, 73500, 73500], array_column($rows, 'amount_minor'));
        $this->assertSame(['2026-09-01', '2026-09-20', null], array_column($rows, 'due_date'));
    }

    public function test_stale_loaded_version_is_not_used_for_new_accepted_version(): void
    {
        $order = $this->order();
        $order->accepted_supplier_proposal_version_id = 31;

        $this->assertNull($this->service()->plan($order));
    }

    public function test_foreign_receipt_and_mismatched_commercial_totals_are_rejected(): void
    {
        foreach (['organization', 'total', 'currency', 'subtotal'] as $conflict) {
            $order = $this->order();
            if ($conflict === 'organization') {
                $order->receipts->first()->organization_id = 2;
            } elseif ($conflict === 'subtotal') {
                $order->items->first()->total_price = '1700.00';
            } else {
                $order->{$conflict === 'total' ? 'total_amount' : 'currency'} = $conflict === 'total' ? '2200.00' : 'USD';
            }
            try {
                $this->service()->plan($order);
                $this->fail('Conflicting commercial facts accepted');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_returns_reduce_the_settlement_basis(): void
    {
        $order = $this->order();
        $line = $order->receipts->first()->lines->first();
        $return = new PurchaseReceiptReturn;
        $return->setRawAttributes(['id' => 200, 'organization_id' => 1, 'purchase_receipt_line_id' => $line->id, 'quantity' => '20.000000']);
        $line->returns->push($return);

        $rows = $this->service()->plan($order);
        $this->assertSame([63000, 147000], array_column($rows, 'amount_minor'));
    }

    public function test_reversed_line_and_cancelled_receipt_do_not_create_due_payments(): void
    {
        $order = $this->order();
        $line = $order->receipts->first()->lines->first();
        $line->setRawAttributes([...$line->getAttributes(), 'reversed_at' => '2026-09-06 12:00:00']);
        $this->assertSame([
            ['source_key' => 'unreceived', 'due_date' => null, 'amount_minor' => 210000],
        ], $this->service()->plan($order));

        $order = $this->order();
        $order->receipts->first()->status = 'cancelled';
        $this->assertSame('unreceived', $this->service()->plan($order)[0]['source_key']);
    }

    public function test_unverified_terms_keep_the_legacy_path(): void
    {
        $order = $this->order();
        $order->acceptedSupplierProposalVersion->integrity_status = 'invalid';
        $this->assertNull($this->service()->plan($order));
    }

    public function test_foreign_or_excess_receipts_and_price_changes_are_rejected(): void
    {
        foreach ([['purchase_order_item_id' => 999], ['quantity_received' => '100.001'], ['price' => '19.00']] as $attributes) {
            $order = $this->order();
            $line = $order->receipts->first()->lines->first();
            $line->setRawAttributes(array_replace($line->getAttributes(), $attributes));
            try {
                $this->service()->plan($order);
                $this->fail('Invalid receipt accepted');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    private function service(): PurchaseOrderSettlementService
    {
        return new PurchaseOrderSettlementService(new PurchaseOrderPaymentGateService, new SupplierSettlementPlanner);
    }

    private function order(string $total = '2100.00'): PurchaseOrder
    {
        $order = new PurchaseOrder;
        $order->setDateFormat('Y-m-d H:i:s');
        $order->setRawAttributes([
            'id' => 10, 'organization_id' => 1, 'accepted_supplier_proposal_id' => 20,
            'accepted_supplier_proposal_version_id' => 30, 'order_date' => '2026-09-01',
            'total_amount' => $total, 'currency' => 'RUB', 'status' => 'in_delivery',
        ]);
        $version = new SupplierProposalVersion;
        $version->setRawAttributes([
            'id' => 30, 'organization_id' => 1, 'supplier_proposal_id' => 20, 'integrity_status' => 'verified',
            'commercial_snapshot' => json_encode([
                'subtotal_amount' => '1800.00', 'delivery_amount' => '300.00', 'total_amount' => $total,
                'currency' => 'RUB', 'vat_mode' => $total === '2100.00' ? 'included' : 'excluded', 'vat_rate' => '20.00',
                'payment_schedule' => ['mode' => 'postpayment', 'advance_percent' => 0, 'deferment_days' => 10],
            ], JSON_THROW_ON_ERROR),
        ]);
        $order->setRelation('acceptedSupplierProposalVersion', $version);
        $item = new PurchaseOrderItem;
        $item->setRawAttributes(['id' => 11, 'purchase_order_id' => 10, 'quantity' => '100.000', 'unit_price' => '18.00', 'total_price' => '1800.00']);
        $order->setRelation('items', new Collection([$item]));
        $order->setRelation('receipts', new Collection([$this->receipt(100, '50.000', '2026-09-05')]));

        return $order;
    }

    private function receipt(int $id, string $quantity, string $date): PurchaseReceipt
    {
        $receipt = new PurchaseReceipt;
        $receipt->setDateFormat('Y-m-d H:i:s');
        $receipt->setRawAttributes(['id' => $id, 'organization_id' => 1, 'purchase_order_id' => 10, 'receipt_date' => $date, 'status' => 'posted']);
        $line = new PurchaseReceiptLine;
        $line->setDateFormat('Y-m-d H:i:s');
        $line->setRawAttributes(['id' => $id * 10, 'purchase_receipt_id' => $id, 'purchase_order_item_id' => 11, 'quantity_received' => $quantity, 'price' => '18.00']);
        $line->setRelation('returns', new Collection);
        $receipt->setRelation('lines', new Collection([$line]));

        return $receipt;
    }
}
