<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin\Reporting\Waves23;

final class SupplyReliabilityContractTest extends SupplyReportContractSourceTestCase
{
    public function test_api_rows_drill_down_and_export_share_the_supply_snapshot_contract(): void
    {
        $this->assertContract(
            'app/BusinessModules/Features/Procurement/Reporting/Supply/Providers/SupplyReliabilityReportProvider.php',
            'app/BusinessModules/Features/Procurement/Reporting/Supply/Queries/SupplyReliabilityRowQuery.php',
            'app/BusinessModules/Features/Procurement/Reporting/Supply/Services/SupplyReliabilitySnapshotMaterializer.php',
            'purchase_order_item',
            'requiresSensitive: true',
            drillDown: 'app/BusinessModules/Features/Procurement/Reporting/Supply/DrillDown/'
                .'SupplyReliabilityDrillDownProvider.php',
        );
    }
}
