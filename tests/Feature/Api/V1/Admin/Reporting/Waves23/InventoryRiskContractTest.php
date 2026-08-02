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

    public function test_planning_only_drill_down_exposes_snapshot_and_policy_evidence_at_the_row_cutoff(): void
    {
        $source = file_get_contents(
            $this->root.'/app/BusinessModules/Features/BasicWarehouse/Reporting/InventoryRisk/'
            .'DrillDown/InventoryRiskDrillDownProvider.php',
        );

        self::assertIsString($source);
        foreach ([
            "'evidence_kind'",
            "'demand_snapshot'",
            "'reorder_policy'",
            "'source_version'",
            "'policy_version'",
            "'balance_date'",
            "'as_of'",
            "'unit_dimension'",
            "'unit_code'",
            "'conversion_version'",
        ] as $field) {
            self::assertStringContainsString($field, $source);
        }
    }
}
