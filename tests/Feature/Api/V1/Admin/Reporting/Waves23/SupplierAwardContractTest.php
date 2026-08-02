<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin\Reporting\Waves23;

final class SupplierAwardContractTest extends SupplyReportContractSourceTestCase
{
    public function test_api_rows_drill_down_and_export_share_the_award_snapshot_contract(): void
    {
        $this->assertContract(
            'app/BusinessModules/Features/Procurement/Reporting/Award/Providers/'
            .'SupplierAwardCompetitivenessReportProvider.php',
            'app/BusinessModules/Features/Procurement/Reporting/Award/Queries/SupplierAwardRowQuery.php',
            'app/BusinessModules/Features/Procurement/Reporting/Award/Services/SupplierAwardSnapshotMaterializer.php',
            'supplier_award_decision',
            'requiresSensitive: true',
        );
    }
}
