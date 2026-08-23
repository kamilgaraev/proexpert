<?php

declare(strict_types=1);

namespace Tests\Unit\BasicWarehouse;

use App\BusinessModules\Features\BasicWarehouse\Services\WarehouseCustodyIdempotency;
use PHPUnit\Framework\TestCase;

final class WarehouseCustodyIdempotencyTest extends TestCase
{
    public function test_issue_and_return_use_the_same_project_fence_and_canonical_warehouse_lock_order(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 3).'/app/BusinessModules/Features/BasicWarehouse/Services/WarehouseCustodyService.php',
        );

        self::assertIsString($source);
        self::assertStringContainsString('private function lockProject(', $source);
        self::assertStringContainsString('private function lockWarehouses(', $source);
        self::assertStringContainsString("->orderBy('id')", $source);

        $issueStart = strpos($source, 'public function issueToResponsible(');
        $returnStart = strpos($source, 'public function returnFromResponsible(');
        $getOrCreateStart = strpos($source, 'public function getOrCreateCustodyWarehouse(');

        self::assertIsInt($issueStart);
        self::assertIsInt($returnStart);
        self::assertIsInt($getOrCreateStart);

        $issue = substr($source, $issueStart, $returnStart - $issueStart);
        $return = substr($source, $returnStart, $getOrCreateStart - $returnStart);

        self::assertStringContainsString('$this->lockProject($organizationId, $projectId);', $issue);
        self::assertStringContainsString(
            '$this->lockWarehouses($organizationId, [(int) $projectWarehouse->id, (int) $custodyWarehouse->id]);',
            $issue,
        );
        self::assertStringContainsString('$this->lockProject($organizationId, $projectId);', $return);
        self::assertStringContainsString(
            '$this->lockWarehouses($organizationId, [(int) $projectWarehouse->id, (int) $custodyWarehouse->id]);',
            $return,
        );
    }

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
