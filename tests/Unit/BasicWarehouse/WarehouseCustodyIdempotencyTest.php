<?php

declare(strict_types=1);

namespace Tests\Unit\BasicWarehouse;

use App\BusinessModules\Features\BasicWarehouse\Services\WarehouseCustodyIdempotency;
use PHPUnit\Framework\TestCase;

final class WarehouseCustodyIdempotencyTest extends TestCase
{
    public function test_fingerprint_is_stable_for_same_logical_operation(): void
    {
        $first = WarehouseCustodyIdempotency::fingerprint('responsible_issue', [
            'project_id' => 10,
            'project_warehouse_id' => 20,
            'material_id' => 30,
            'responsible_user_id' => 40,
            'quantity' => 1.250,
            'reason' => ' Выдача ',
        ]);
        $second = WarehouseCustodyIdempotency::fingerprint('responsible_issue', [
            'quantity' => '1.25',
            'responsible_user_id' => 40,
            'material_id' => 30,
            'project_warehouse_id' => 20,
            'project_id' => 10,
            'reason' => 'Выдача',
        ]);

        self::assertSame($first, $second);
    }

    public function test_fingerprint_changes_for_quantity_or_operation_type(): void
    {
        $payload = [
            'custody_warehouse_id' => 20,
            'material_id' => 30,
            'quantity' => 1,
        ];

        self::assertNotSame(
            WarehouseCustodyIdempotency::fingerprint('responsible_return', $payload),
            WarehouseCustodyIdempotency::fingerprint('responsible_return', [...$payload, 'quantity' => 2]),
        );
        self::assertNotSame(
            WarehouseCustodyIdempotency::fingerprint('responsible_return', $payload),
            WarehouseCustodyIdempotency::fingerprint('responsible_issue', $payload),
        );
    }
}
