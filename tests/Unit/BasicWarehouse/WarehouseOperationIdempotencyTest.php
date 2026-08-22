<?php

declare(strict_types=1);

namespace Tests\Unit\BasicWarehouse;

use App\BusinessModules\Features\BasicWarehouse\Services\WarehouseOperationIdempotency;
use PHPUnit\Framework\TestCase;

final class WarehouseOperationIdempotencyTest extends TestCase
{
    public function test_fingerprint_is_stable_for_equivalent_nested_payloads(): void
    {
        $first = WarehouseOperationIdempotency::fingerprint('receipt', [
            'warehouse_id' => 10,
            'quantity' => 1.250,
            'price' => 15.500,
            'reason' => ' Приход ',
            'metadata' => ['source' => 'manual', 'tags' => ['b', 'a']],
            'idempotency_key' => 'ignored',
        ]);
        $second = WarehouseOperationIdempotency::fingerprint('receipt', [
            'metadata' => ['tags' => ['b', 'a'], 'source' => 'manual'],
            'price' => '15.50',
            'quantity' => '1.25',
            'reason' => 'Приход',
            'warehouse_id' => 10,
        ]);

        self::assertSame($first, $second);
    }

    public function test_fingerprint_changes_for_operation_quantity_or_list_order(): void
    {
        $payload = ['warehouse_id' => 10, 'quantity' => 1, 'metadata' => ['tags' => ['a', 'b']]];

        self::assertNotSame(
            WarehouseOperationIdempotency::fingerprint('receipt', $payload),
            WarehouseOperationIdempotency::fingerprint('write_off', $payload),
        );
        self::assertNotSame(
            WarehouseOperationIdempotency::fingerprint('receipt', $payload),
            WarehouseOperationIdempotency::fingerprint('receipt', [...$payload, 'quantity' => 2]),
        );
        self::assertNotSame(
            WarehouseOperationIdempotency::fingerprint('receipt', $payload),
            WarehouseOperationIdempotency::fingerprint('receipt', [
                ...$payload,
                'metadata' => ['tags' => ['b', 'a']],
            ]),
        );
    }
}
