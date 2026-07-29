<?php

declare(strict_types=1);

namespace Tests\Architecture\Reporting;

use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDataProvider;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDrillDownProvider;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportRowQuery;
use App\BusinessModules\Features\Budgeting\Reporting\ManagementPnl\ManagementPnlProvider;
use App\BusinessModules\Features\Budgeting\Reporting\ManagementPnl\ManagementPnlQueryService;
use App\BusinessModules\Features\Budgeting\Reporting\ProjectFinance\BudgetPlanFactProvider;
use App\BusinessModules\Features\Budgeting\Reporting\ProjectFinance\ProjectFinanceQueryService;
use App\BusinessModules\Features\Budgeting\Reporting\ProjectFinance\ProjectMarginProvider;
use App\BusinessModules\Features\Budgeting\Reporting\ProjectFinance\WipCompletionForecastProvider;
use App\BusinessModules\Features\ChangeManagement\Reporting\ChangeClaim\DrillDown\ChangeClaimDrillDownProvider;
use App\BusinessModules\Features\ChangeManagement\Reporting\ChangeClaim\Providers\ChangeClaimContingencyReportProvider;
use App\BusinessModules\Features\ChangeManagement\Reporting\ChangeClaim\Queries\ChangeClaimRowQuery;
use App\BusinessModules\Features\ContractManagement\Reporting\ContractSettlementExposureProvider;
use App\BusinessModules\Features\ContractManagement\Reporting\ContractSettlementQueryService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class FinanceOwnerReportingContractTest extends TestCase
{
    private const PROVIDERS = [
        ProjectMarginProvider::class,
        BudgetPlanFactProvider::class,
        WipCompletionForecastProvider::class,
        ContractSettlementExposureProvider::class,
        ManagementPnlProvider::class,
        ChangeClaimContingencyReportProvider::class,
    ];

    private const OWNER_PATHS = [
        'app/BusinessModules/Core/Payments/Reporting',
        'app/BusinessModules/Core/Payments/Services/Reports/SettlementAgingPolicy.php',
        'app/BusinessModules/Features/Budgeting/Reporting/ProjectFinance',
        'app/BusinessModules/Features/Budgeting/Reporting/ManagementPnl',
        'app/BusinessModules/Features/ContractManagement/Reporting',
        'app/BusinessModules/Features/ChangeManagement/Reporting/ChangeClaim',
    ];

    private const MIGRATIONS = [
        'app/BusinessModules/Features/Budgeting/migrations/2026_07_26_000110_create_budgeting_project_finance_report_projections.php',
        'app/BusinessModules/Features/Budgeting/migrations/2026_07_26_000120_create_management_pnl_policies_and_projections.php',
        'app/BusinessModules/Features/ContractManagement/migrations/2026_07_26_000140_create_contract_settlement_report_projections.php',
        'app/BusinessModules/Features/ChangeManagement/migrations/2026_07_26_110000_create_change_claim_reporting_tables.php',
    ];

    #[Test]
    public function all_six_report_codes_use_the_canonical_owner_ports(): void
    {
        foreach (self::PROVIDERS as $provider) {
            self::assertTrue(
                is_subclass_of($provider, ReportDataProvider::class),
                $provider.' must implement '.ReportDataProvider::class,
            );
        }

        foreach ([
            ProjectFinanceQueryService::class,
            ContractSettlementQueryService::class,
            ManagementPnlQueryService::class,
        ] as $query) {
            self::assertTrue(is_subclass_of($query, ReportRowQuery::class));
            self::assertTrue(is_subclass_of($query, ReportDrillDownProvider::class));
        }

        self::assertTrue(is_subclass_of(ChangeClaimRowQuery::class, ReportRowQuery::class));
        self::assertTrue(is_subclass_of(ChangeClaimDrillDownProvider::class, ReportDrillDownProvider::class));
    }

    #[Test]
    public function migrations_define_immutable_snapshot_identity_without_data_backfill(): void
    {
        $contents = [];
        foreach (self::MIGRATIONS as $migration) {
            $path = $this->root().'/'.$migration;
            self::assertFileExists($path);
            $contents[$migration] = (string) file_get_contents($path);
            self::assertStringNotContainsString('DB::', $contents[$migration]);
            self::assertDoesNotMatchRegularExpression('/->(?:insert|update|delete|chunk|cursor|get)\\s*\\(/', $contents[$migration]);
        }

        self::assertStringContainsString(
            "['organization_id', 'snapshot_id', 'row_key']",
            $contents[self::MIGRATIONS[0]],
        );
        self::assertStringContainsString(
            "['organization_id', 'version']",
            $contents[self::MIGRATIONS[1]],
        );
        self::assertStringContainsString(
            "['organization_id', 'source_type', 'source_id', 'source_version', 'allocation_id', 'direction']",
            $contents[self::MIGRATIONS[2]],
        );
        self::assertStringContainsString(
            "['organization_id', 'change_claim_id', 'claim_version']",
            $contents[self::MIGRATIONS[3]],
        );
    }

    #[Test]
    public function owner_slice_does_not_publish_or_mutate_the_global_catalog(): void
    {
        $forbidden = [
            'PublishedReportDefinition',
            'ReportDefinitionBindingMap',
            'ReportCatalogActivationService',
            'ReportingCatalogServiceProvider',
            'management-catalog.v1.yaml',
        ];

        foreach ($this->phpFiles(self::OWNER_PATHS) as $file) {
            $contents = (string) file_get_contents($file);
            foreach ($forbidden as $symbol) {
                self::assertStringNotContainsString($symbol, $contents, $file.' imports '.$symbol);
            }
        }
    }

    #[Test]
    public function owner_models_pin_the_expected_snapshot_tables(): void
    {
        foreach ([
            'App\\BusinessModules\\Features\\Budgeting\\Reporting\\ProjectFinance\\Models\\ProjectFinanceSnapshot',
            'App\\BusinessModules\\Features\\Budgeting\\Reporting\\ManagementPnl\\Models\\ManagementPnlSnapshot',
            'App\\BusinessModules\\Features\\ContractManagement\\Reporting\\Models\\ContractSettlementExposureSnapshot',
            'App\\BusinessModules\\Features\\ChangeManagement\\Reporting\\ChangeClaim\\Models\\ChangeClaimSnapshot',
        ] as $model) {
            self::assertTrue((new ReflectionClass($model))->isSubclassOf('Illuminate\\Database\\Eloquent\\Model'));
        }
    }

    private function phpFiles(array $paths): array
    {
        $files = [];
        foreach ($paths as $path) {
            $absolute = $this->root().'/'.$path;
            if (is_file($absolute)) {
                $files[] = $absolute;

                continue;
            }
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($absolute));
            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }
        sort($files, SORT_STRING);

        return $files;
    }

    private function root(): string
    {
        return dirname(__DIR__, 3);
    }
}
