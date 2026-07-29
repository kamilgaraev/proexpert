<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin\Reporting\Waves23;

final class InventoryRiskContractTest extends SupplyReportContractSourceTestCase
{
    public function test_api_rows_drill_down_and_export_share_the_inventory_snapshot_contract(): void
    {
        $this->assertContract(
            'app/BusinessModules/Features/BasicWarehouse/Reporting/InventoryRisk/Providers/'
            .'InventoryRiskReportProvider.php',
            'app/BusinessModules/Features/BasicWarehouse/Reporting/InventoryRisk/Queries/InventoryRiskRowQuery.php',
            'app/BusinessModules/Features/BasicWarehouse/Reporting/InventoryRisk/Services/'
            .'InventoryRiskSnapshotMaterializer.php',
            'warehouse',
            'requiresSensitive: true',
            drillDown: 'app/BusinessModules/Features/BasicWarehouse/Reporting/InventoryRisk/DrillDown/'
                .'InventoryRiskDrillDownProvider.php',
        );
    }
}
