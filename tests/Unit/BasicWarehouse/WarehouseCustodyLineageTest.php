<?php

declare(strict_types=1);

namespace Tests\Unit\BasicWarehouse;

use App\BusinessModules\Features\BasicWarehouse\Services\WarehouseCustodyLineage;
use PHPUnit\Framework\TestCase;

final class WarehouseCustodyLineageTest extends TestCase
{
    public function test_extracts_source_issue_keys_and_quantities_from_fifo_batches(): void
    {
        self::assertSame([
            ['idempotency_key' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'quantity' => 2.5],
            ['idempotency_key' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', 'quantity' => 1.0],
        ], WarehouseCustodyLineage::allocations([
            ['batch_number' => 'custody-issue:aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'quantity' => 2.5],
            ['batch_number' => 'legacy-batch', 'quantity' => 4],
            ['batch_number' => 'custody-issue:bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', 'quantity' => 1],
        ]));
    }
}
