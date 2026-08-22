<?php

declare(strict_types=1);

namespace Tests\Unit\Procurement;

use App\BusinessModules\Features\Procurement\Services\PurchaseReceiptIdempotency;
use PHPUnit\Framework\TestCase;

final class PurchaseReceiptIdempotencyTest extends TestCase
{
    public function test_fingerprint_is_stable_for_the_same_logical_receipt(): void
    {
        $first = PurchaseReceiptIdempotency::fingerprint(
            44,
            [[
                'item_id' => 701,
                'quantity_received' => 1.25,
                'price' => 80000,
            ]],
            ['receipt_date' => '2026-05-22', 'notes' => 'Первая часть'],
        );
        $second = PurchaseReceiptIdempotency::fingerprint(
            44,
            [[
                'price' => 80000.0,
                'quantity_received' => 1.250,
                'item_id' => 701,
            ]],
            ['notes' => 'Первая часть', 'receipt_date' => '2026-05-22'],
        );

        self::assertSame($first, $second);
    }

    public function test_fingerprint_changes_when_receipt_quantity_changes(): void
    {
        $first = PurchaseReceiptIdempotency::fingerprint(
            44,
            [['item_id' => 701, 'quantity_received' => 1, 'price' => 80000]],
            ['receipt_date' => '2026-05-22'],
        );
        $second = PurchaseReceiptIdempotency::fingerprint(
            44,
            [['item_id' => 701, 'quantity_received' => 2, 'price' => 80000]],
            ['receipt_date' => '2026-05-22'],
        );

        self::assertNotSame($first, $second);
    }
}
