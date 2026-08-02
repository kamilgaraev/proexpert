<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin\Reporting\Waves23;

final class ProcurementCycleContractTest extends SupplyReportContractSourceTestCase
{
    public function test_api_rows_drill_down_and_export_share_the_cycle_snapshot_contract(): void
    {
        $this->assertContract(
            'app/BusinessModules/Features/Procurement/Reporting/Cycle/Providers/ProcurementCycleReportProvider.php',
            'app/BusinessModules/Features/Procurement/Reporting/Cycle/Queries/ProcurementCycleRowQuery.php',
            'app/BusinessModules/Features/Procurement/Reporting/Cycle/Services/ProcurementCycleSnapshotMaterializer.php',
            'purchase_request_line',
            'requiresAudit: true',
        );
    }
}
