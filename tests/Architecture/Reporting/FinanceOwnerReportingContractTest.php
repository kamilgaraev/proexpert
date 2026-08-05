<?php

declare(strict_types=1);

namespace Tests\Architecture\Reporting;

use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDataProvider;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDrillDownProvider;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportRowQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDrillDownInput;
use App\BusinessModules\Features\Budgeting\Reporting\ManagementPnl\ManagementPnlProvider;
use App\BusinessModules\Features\Budgeting\Reporting\ManagementPnl\ManagementPnlQueryService;
use App\BusinessModules\Features\Budgeting\Reporting\ProjectFinance\BudgetPlanFactProvider;
use App\BusinessModules\Features\Budgeting\Reporting\ProjectFinance\ProjectFinanceProjectionService;
use App\BusinessModules\Features\Budgeting\Reporting\ProjectFinance\ProjectFinanceQueryService;
use App\BusinessModules\Features\Budgeting\Reporting\ProjectFinance\ProjectMarginProvider;
use App\BusinessModules\Features\Budgeting\Reporting\ProjectFinance\WipCompletionForecastProvider;
use App\BusinessModules\Features\ChangeManagement\Reporting\ChangeClaim\DrillDown\ChangeClaimDrillDownProvider;
use App\BusinessModules\Features\ChangeManagement\Reporting\ChangeClaim\Providers\ChangeClaimContingencyReportProvider;
use App\BusinessModules\Features\ChangeManagement\Reporting\ChangeClaim\Queries\ChangeClaimRowQuery;
use App\BusinessModules\Features\ChangeManagement\Reporting\ChangeClaim\Services\ChangeClaimSnapshotMaterializer;
use App\BusinessModules\Features\ContractManagement\Reporting\ContractSettlementExposureProvider;
use App\BusinessModules\Features\ContractManagement\Reporting\ContractSettlementOwnerSource;
use App\BusinessModules\Features\ContractManagement\Reporting\ContractSettlementOwnerTimestamp;
use App\BusinessModules\Features\ContractManagement\Reporting\ContractSettlementQueryService;
use DateTimeImmutable;
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
            self::assertDoesNotMatchRegularExpression('/DB::table\\s*\\(/', $contents[$migration]);
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
            "['organization_id', 'query_hash', 'contract_id', 'allocation_id', 'direction', 'currency']",
            $contents[self::MIGRATIONS[2]],
        );
        self::assertStringContainsString(
            "['organization_id', 'change_claim_id', 'claim_version']",
            $contents[self::MIGRATIONS[3]],
        );
    }

    #[Test]
    public function finance_owner_facts_are_database_append_only_and_writers_are_race_fenced(): void
    {
        $root = $this->root();
        $management = (string) file_get_contents($root.'/app/BusinessModules/Features/Budgeting/migrations/2026_07_26_000120_create_management_pnl_policies_and_projections.php');
        $settlement = (string) file_get_contents($root.'/app/BusinessModules/Features/ContractManagement/migrations/2026_07_26_000140_create_contract_settlement_report_projections.php');
        $change = (string) file_get_contents($root.'/app/BusinessModules/Features/ChangeManagement/migrations/2026_07_26_110000_create_change_claim_reporting_tables.php');
        foreach ([
            [$projectFinance = (string) file_get_contents($root.'/app/BusinessModules/Features/Budgeting/migrations/2026_07_26_000110_create_budgeting_project_finance_report_projections.php'), 'reports_project_finance_append_only', 'budgeting_project_finance_snapshots_append_only'],
            [$management, 'reports_management_pnl_append_only', 'management_pnl_snapshots_append_only'],
            [$settlement, 'reports_contract_settlement_append_only', 'contract_settlement_source_facts_append_only'],
            [$change, 'reports_change_claim_append_only', 'contingency_ledger_entries_append_only'],
        ] as [$migration, $function, $trigger]) {
            self::assertStringContainsString($function, $migration);
            self::assertStringContainsString($trigger, $migration);
            self::assertStringContainsString('BEFORE UPDATE OR DELETE', $migration);
        }

        $workflow = (string) file_get_contents($root.'/app/BusinessModules/Features/ChangeManagement/Reporting/ChangeClaim/Services/ChangeWorkflowEventRecorder.php');
        $ledger = (string) file_get_contents($root.'/app/BusinessModules/Features/ChangeManagement/Reporting/ChangeClaim/Services/ContingencyLedgerService.php');
        $settlementWriter = (string) file_get_contents($root.'/app/BusinessModules/Features/ContractManagement/Reporting/ContractSettlementProjectionService.php');
        self::assertStringContainsString('ChangeRequest::query()', $workflow);
        self::assertStringNotContainsString("DB::table('change_requests')", $workflow);
        self::assertStringContainsString('->lockForUpdate()', $workflow);
        self::assertStringContainsString('change_management_single_approved_change', (string) file_get_contents(
            $root.'/app/BusinessModules/Features/ChangeManagement/migrations/2026_07_30_000010_add_change_monetary_reporting_contract.php',
        ));
        self::assertStringContainsString('insertOrIgnore', $ledger);
        self::assertStringContainsString('contingency_ledger_replay_conflict', $ledger);
        self::assertStringContainsString('contract_settlement_source_fact_race_conflict', $settlementWriter);
        self::assertStringContainsString('contract_settlement_snapshot_race_conflict', $settlementWriter);
        self::assertStringContainsString('project_finance_snapshot_race_conflict', $projectFinanceWriter = (string) file_get_contents(
            $root.'/app/BusinessModules/Features/Budgeting/Reporting/ProjectFinance/ProjectFinanceProjectionService.php',
        ));
        self::assertStringContainsString('insertOrIgnore', $projectFinanceWriter);
        self::assertStringNotContainsString('(float)', $projectFinanceWriter);

        $managementWriter = (string) file_get_contents($root.'/app/BusinessModules/Features/Budgeting/Reporting/ManagementPnl/ManagementPnlProjectionService.php');
        $changeWriter = (string) file_get_contents($root.'/app/BusinessModules/Features/ChangeManagement/Reporting/ChangeClaim/Services/ChangeClaimSnapshotMaterializer.php');
        self::assertStringContainsString('management_pnl_snapshot_race_conflict', $managementWriter);
        self::assertStringContainsString('change_claim_snapshot_race_conflict', $changeWriter);
        self::assertStringContainsString("->where('effective_at', '<=', \$query->asOf)", $changeWriter);
        self::assertStringContainsString("'quality_status' => 'partial'", $changeWriter);
    }

    #[Test]
    public function standard_change_workflow_pins_approved_exposure_and_contingency_movements(): void
    {
        $root = $this->root();
        $recorder = (string) file_get_contents($root.'/app/BusinessModules/Features/ChangeManagement/Reporting/ChangeClaim/Services/ChangeWorkflowEventRecorder.php');
        $workflow = (string) file_get_contents($root.'/app/BusinessModules/Features/ChangeManagement/Services/ChangeManagementService.php');
        self::assertStringContainsString('approved_cost_minor', $recorder);
        self::assertStringContainsString('reporting_currency', $recorder);
        self::assertStringContainsString('reporting_contract_project_allocation_id', $recorder);
        self::assertStringNotContainsString("['contingency_opening_amount']", $recorder);
        self::assertStringNotContainsString('? $proposed', $recorder);
        self::assertStringContainsString("'approve' => 'consumption'", $recorder);
        self::assertStringContainsString('$this->contingencyLedger->append(', $recorder);
        self::assertStringContainsString("\$this->changeEvents->record(\$change, 'approve'", $workflow);
        self::assertStringNotContainsString("array_key_exists('approved_cost'", $recorder);
        self::assertStringContainsString('monetary_context', (string) file_get_contents(
            $root.'/app/BusinessModules/Features/ChangeManagement/Http/Requests/StoreChangeRequest.php',
        ));
        self::assertStringContainsString('approved_cost_amount', (string) file_get_contents(
            $root.'/app/BusinessModules/Features/ChangeManagement/Http/Requests/ApproveChangeRequest.php',
        ));
    }

    #[Test]
    public function every_financial_change_transition_rechecks_state_under_the_aggregate_lock(): void
    {
        $workflow = (string) file_get_contents(
            $this->root().'/app/BusinessModules/Features/ChangeManagement/Services/ChangeManagementService.php',
        );

        self::assertGreaterThanOrEqual(6, substr_count($workflow, '$this->lockedTransition('));
        self::assertStringContainsString('->lockForUpdate()', $workflow);
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

    #[Test]
    public function delivery_uses_typed_keysets_null_safe_ordering_and_typed_drill_down(): void
    {
        foreach ([
            ProjectFinanceQueryService::class,
            ContractSettlementQueryService::class,
            ManagementPnlQueryService::class,
            ChangeClaimRowQuery::class,
        ] as $query) {
            $contents = (string) file_get_contents((new ReflectionClass($query))->getFileName());
            self::assertStringContainsString('->keyset->lastSortValue', $contents, $query);
            self::assertStringContainsString('IS NULL ASC', $contents, $query);
            self::assertStringContainsString('->cursor()', $contents, $query);
            self::assertStringNotContainsString('->lazy(', $contents, $query);
            self::assertStringNotContainsString('tokenPayload(', $contents, $query);
        }

        foreach ([
            ProjectFinanceQueryService::class,
            ContractSettlementQueryService::class,
            ManagementPnlQueryService::class,
            ChangeClaimDrillDownProvider::class,
        ] as $provider) {
            $parameter = (new \ReflectionMethod($provider, 'drillDown'))->getParameters()[2];
            self::assertSame(ReportDrillDownInput::class, (string) $parameter->getType(), $provider);
        }
    }

    #[Test]
    public function production_sources_fail_closed_on_unsealed_filters_and_pin_exact_snapshots(): void
    {
        $contractSource = (string) file_get_contents((new ReflectionClass(ContractSettlementOwnerSource::class))->getFileName());
        $changeSource = (string) file_get_contents((new ReflectionClass(ChangeClaimSnapshotMaterializer::class))->getFileName());
        $projectSource = (string) file_get_contents((new ReflectionClass(ProjectFinanceProjectionService::class))->getFileName());

        self::assertStringContainsString('report_filter_not_sealed', $contractSource);
        self::assertStringContainsString('report_filter_not_sealed', $changeSource);
        self::assertStringContainsString('->forScope(', $projectSource);
        self::assertStringContainsString("->where('formula_version', EpmDataMartPayloadProjector::FORMULA_VERSION)", $projectSource);
        self::assertStringContainsString("'contract_performance_act'", $contractSource);
        self::assertStringContainsString("'payment_document'", $contractSource);
        self::assertStringContainsString("'payment_transaction'", $contractSource);
        self::assertStringContainsString("'version' => (int) \$version", $contractSource);
        self::assertStringContainsString("'hash' => \$hash", $contractSource);
        self::assertStringContainsString('contract_settlement_owner_history_checkpoint_missing', $contractSource);
        self::assertStringContainsString('management_pnl_component_tuple_ambiguous', (string) file_get_contents(
            $this->root().'/app/BusinessModules/Features/Budgeting/Reporting/ProjectFinance/ProjectFinanceManagementPnlComponentSource.php',
        ));
        self::assertStringContainsString('management_pnl_cost_classification_unsealed', (string) file_get_contents(
            $this->root().'/app/BusinessModules/Features/Budgeting/Reporting/ProjectFinance/ProjectFinanceManagementPnlComponentSource.php',
        ));

        $backfill = (string) file_get_contents(
            $this->root().'/app/BusinessModules/Features/ContractManagement/Reporting/ContractSettlementOwnerHistoryBackfillService.php',
        );
        self::assertStringContainsString("DB::table('organizations')", $backfill);
        self::assertStringContainsString('->lockForUpdate()', $backfill);
        self::assertStringContainsString('ContractSettlementOwnerHistoryCheckpoint::query()->create', $backfill);
    }

    #[Test]
    public function settlement_owner_foundation_pins_one_exact_boundary_and_covers_future_organizations(): void
    {
        $root = $this->root();
        $migration = (string) file_get_contents(
            $root.'/database/migrations/2026_08_05_040000_seed_contract_settlement_owner_history_foundation.php',
        );
        $backfill = (string) file_get_contents(
            $root.'/app/BusinessModules/Features/ContractManagement/Reporting/ContractSettlementOwnerHistoryBackfillService.php',
        );
        $recorder = (string) file_get_contents(
            $root.'/app/BusinessModules/Features/ContractManagement/Reporting/ContractSettlementOwnerVersionRecorder.php',
        );
        $source = (string) file_get_contents(
            $root.'/app/BusinessModules/Features/ContractManagement/Reporting/ContractSettlementOwnerSource.php',
        );
        $timestamp = (string) file_get_contents(
            $root.'/app/BusinessModules/Features/ContractManagement/Reporting/ContractSettlementOwnerTimestamp.php',
        );
        $versionModel = (string) file_get_contents(
            $root.'/app/BusinessModules/Features/ContractManagement/Reporting/Models/ContractSettlementOwnerVersion.php',
        );
        $checkpointModel = (string) file_get_contents(
            $root.'/app/BusinessModules/Features/ContractManagement/Reporting/Models/ContractSettlementOwnerHistoryCheckpoint.php',
        );

        self::assertStringContainsString(
            'LOCK TABLE contracts, contract_project_allocations, contract_performance_acts, '
            .'payment_documents, payment_transactions IN SHARE ROW EXCLUSIVE MODE',
            $migration,
        );
        self::assertStringContainsString('timestamptz(6)', $migration);
        self::assertStringContainsString('most_seed_contract_settlement_owner_checkpoint_v1', $migration);
        self::assertStringContainsString('AFTER INSERT ON organizations', $migration);
        self::assertStringContainsString('ContractSettlementOwnerHistoryBackfillService::class', $migration);
        self::assertStringContainsString(
            "\$this->recorder->record(\$owner, 'upsert', \$completedAt)",
            $backfill,
        );
        self::assertStringContainsString("'version' => (int) \$version->version", $backfill);
        self::assertStringContainsString("'hash' => (string) \$version->owner_hash", $backfill);
        self::assertStringContainsString('?DateTimeInterface $occurredAt = null', $recorder);
        self::assertStringContainsString(': ContractSettlementOwnerVersion', $recorder);
        self::assertStringContainsString("'Y-m-d\\TH:i:s.uP'", $timestamp);
        self::assertStringContainsString("'Y-m-d H:i:s.uP'", $timestamp);
        self::assertStringContainsString('ContractSettlementOwnerTimestamp::canonical($occurredAt)', $recorder);
        self::assertStringContainsString('ContractSettlementOwnerTimestamp::canonical($completedAt)', $backfill);
        self::assertStringContainsString('ContractSettlementOwnerTimestamp::database($query->asOf)', $source);
        self::assertStringContainsString('ContractSettlementOwnerTimestamp::MODEL_FORMAT', $versionModel);
        self::assertStringContainsString('ContractSettlementOwnerTimestamp::MODEL_FORMAT', $checkpointModel);
        self::assertStringNotContainsString('DATE_ATOM', $recorder);
        self::assertStringNotContainsString('DATE_ATOM', $backfill);
        $nonUtc = new DateTimeImmutable('2026-08-05T12:34:56.123456+03:00');
        self::assertSame('2026-08-05T09:34:56.123456+00:00', ContractSettlementOwnerTimestamp::canonical($nonUtc));
        self::assertSame('2026-08-05 09:34:56.123456+00:00', ContractSettlementOwnerTimestamp::database($nonUtc));
    }

    #[Test]
    public function settlement_projection_reads_owner_contract_act_and_payment_sources(): void
    {
        $root = $this->root();
        $source = (string) file_get_contents(
            $root.'/app/BusinessModules/Features/ContractManagement/Reporting/ContractSettlementOwnerSource.php',
        );
        $projection = (string) file_get_contents(
            $root.'/app/BusinessModules/Features/ContractManagement/Reporting/ContractSettlementProjectionService.php',
        );

        foreach ([
            'ContractSettlementOwnerVersion::query()',
            "'contract_performance_act' => ContractPerformanceAct::class",
            "'payment_document' => PaymentDocument::class",
            'PaymentTransactionStatus::COMPLETED',
            'ContractSettlementAllocationConserver',
        ] as $ownerContract) {
            self::assertStringContainsString($ownerContract, $source);
        }
        self::assertStringContainsString('private ContractSettlementOwnerSource $ownerSource', $projection);
        self::assertStringContainsString('ContractSettlementSourceFact::query()->insertOrIgnore', $projection);
        self::assertStringContainsString("->where('scope_hash', \$scopeHash)", $projection);
        self::assertStringContainsString("->where('query_hash', \$query->queryHash->value)", $projection);
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
