<?php

declare(strict_types=1);

namespace Tests\Integration\Reporting\WaveOne;

use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDataProvider;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDrillDownProvider;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportRowQuery;
use App\BusinessModules\Features\Budgeting\Reporting\ManagementPnl\ManagementPnlProvider;
use App\BusinessModules\Features\Budgeting\Reporting\ManagementPnl\ManagementPnlQueryService;
use App\BusinessModules\Features\ChangeManagement\Reporting\ChangeClaim\DrillDown\ChangeClaimDrillDownProvider;
use App\BusinessModules\Features\ChangeManagement\Reporting\ChangeClaim\Providers\ChangeClaimContingencyReportProvider;
use App\BusinessModules\Features\ChangeManagement\Reporting\ChangeClaim\Queries\ChangeClaimRowQuery;
use App\BusinessModules\Features\ContractManagement\Reporting\ContractSettlementExposureProvider;
use App\BusinessModules\Features\ContractManagement\Reporting\ContractSettlementQueryService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class FinanceOwnerProviderContractTest extends TestCase
{
    #[Test]
    public function owner_providers_and_delivery_ports_resolve_from_the_real_container(): void
    {
        foreach ([
            ManagementPnlProvider::class,
            ContractSettlementExposureProvider::class,
            ChangeClaimContingencyReportProvider::class,
        ] as $provider) {
            self::assertInstanceOf(ReportDataProvider::class, $this->app->make($provider));
        }

        foreach ([
            ManagementPnlQueryService::class,
            ContractSettlementQueryService::class,
            ChangeClaimRowQuery::class,
        ] as $query) {
            self::assertInstanceOf(ReportRowQuery::class, $this->app->make($query));
        }

        foreach ([
            ManagementPnlQueryService::class,
            ContractSettlementQueryService::class,
            ChangeClaimDrillDownProvider::class,
        ] as $drillDown) {
            self::assertInstanceOf(ReportDrillDownProvider::class, $this->app->make($drillDown));
        }
    }
}
