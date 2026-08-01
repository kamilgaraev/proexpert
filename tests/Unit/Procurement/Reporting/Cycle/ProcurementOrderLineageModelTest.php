<?php

declare(strict_types=1);

namespace Tests\Unit\Procurement\Reporting\Cycle;

use App\BusinessModules\Features\Procurement\Models\PurchaseOrder;
use App\BusinessModules\Features\Procurement\Models\PurchaseOrderItem;
use PHPUnit\Framework\TestCase;

final class ProcurementOrderLineageModelTest extends TestCase
{
    public function test_order_item_accepts_direct_relational_lineage(): void
    {
        $item = new PurchaseOrderItem();
        $item->fill([
            'purchase_request_line_id' => 11,
            'supplier_request_line_id' => 12,
            'supplier_proposal_line_id' => 13,
        ]);

        self::assertSame(11, $item->purchase_request_line_id);
        self::assertSame(12, $item->supplier_request_line_id);
        self::assertSame(13, $item->supplier_proposal_line_id);
        self::assertContains('purchase_request_line_id', $item->getFillable());
        self::assertContains('supplier_request_line_id', $item->getFillable());
        self::assertContains('supplier_proposal_line_id', $item->getFillable());
    }

    public function test_order_keeps_exact_sent_timestamp_separate_from_legacy_date(): void
    {
        $order = new PurchaseOrder();

        self::assertSame('date', $order->getCasts()['sent_at']);
        self::assertSame('immutable_datetime', $order->getCasts()['sent_at_exact']);
        self::assertContains('sent_at_exact', $order->getFillable());
    }
}
