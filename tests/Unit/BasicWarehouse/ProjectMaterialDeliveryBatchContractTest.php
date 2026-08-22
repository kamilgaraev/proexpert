<?php

declare(strict_types=1);

namespace Tests\Unit\BasicWarehouse;

use PHPUnit\Framework\TestCase;

final class ProjectMaterialDeliveryBatchContractTest extends TestCase
{
    public function test_each_delivery_uses_its_own_transit_batch_for_ship_receive_and_cancel(): void
    {
        $projectWarehouseService = (string) file_get_contents(
            dirname(__DIR__, 3).'/app/BusinessModules/Features/BasicWarehouse/Services/ProjectWarehouseService.php'
        );
        $warehouseService = (string) file_get_contents(
            dirname(__DIR__, 3).'/app/BusinessModules/Features/BasicWarehouse/Services/WarehouseService.php'
        );

        self::assertSame(3, substr_count($projectWarehouseService, "'batch_number' => 'project-delivery:'.\$delivery->id"));
        self::assertSame(2, substr_count($projectWarehouseService, "'from_batch_number' => 'project-delivery:'.\$delivery->id"));
        self::assertStringContainsString(
            "\$sourceBatchesQuery->where('batch_number', \$metadata['from_batch_number']);",
            $warehouseService,
        );
    }
}
