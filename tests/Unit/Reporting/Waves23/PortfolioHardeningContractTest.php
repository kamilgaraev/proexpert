<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Waves23;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PortfolioHardeningContractTest extends TestCase
{
    #[Test]
    public function liquidity_history_has_ingestion_time_and_database_append_only_guard(): void
    {
        $migration = $this->source(
            'app/BusinessModules/Features/Budgeting/migrations/'
            .'2026_07_26_000100_create_budgeting_portfolio_report_projections.php',
        );
        $reader = $this->source(
            'app/BusinessModules/Features/Budgeting/Reporting/Portfolio/PortfolioLiquidityAsOfSource.php',
        );

        self::assertStringContainsString("dateTimeTz('recorded_at')", $migration);
        self::assertStringContainsString('budgeting_liquidity_source_versions_append_only', $migration);
        self::assertStringContainsString("where('recorded_at', '<=', \$asOf)", $reader);
    }

    #[Test]
    public function accepted_work_delete_emits_reversal_without_synthetic_provenance(): void
    {
        $observer = $this->source('app/Observers/ContractPerformanceActObserver.php');
        $producer = $this->source(
            'app/BusinessModules/Core/MultiOrganization/Reporting/Services/'
            .'AcceptedWorkHoldingFactProducer.php',
        );

        self::assertMatchesRegularExpression(
            '/function deleted\\([^)]*\\).*acceptedWorkFacts->project\\(\\$act, now\\(\\), false\\)/s',
            $observer,
        );
        self::assertStringNotContainsString('performance_act_state', $producer);
        self::assertStringContainsString("'id' => (int) \$act->getKey()", $producer);
    }

    #[Test]
    public function four_reports_use_platform_signed_typed_cursor_contract(): void
    {
        $handler = $this->source(
            'app/BusinessModules/Core/Reporting/Application/Actions/Handlers/GetReportRowsHandler.php',
        );
        self::assertStringContainsString('$this->cursors->decode(', $handler);
        self::assertStringContainsString('$this->cursors->encode(', $handler);

        foreach ([
            'app/BusinessModules/Core/MultiOrganization/Reporting/Queries/HoldingPerformanceRowQuery.php',
            'app/BusinessModules/Core/MultiOrganization/Reporting/Queries/IntercompanyContractFlowRowQuery.php',
            'app/BusinessModules/Features/Budgeting/Reporting/Portfolio/BudgetingPortfolioQueryService.php',
        ] as $path) {
            $query = $this->source($path);
            self::assertStringContainsString('$cursor->keyset->lastSortValue', $query);
            self::assertStringContainsString('$cursor->keyset->lastStableRowKey', $query);
            self::assertStringContainsString('limit($limit + 1)', $query);
        }
    }

    #[Test]
    public function every_snapshot_read_rechecks_full_pinned_identity(): void
    {
        foreach ([
            'app/BusinessModules/Core/MultiOrganization/Reporting/Services/HoldingPerformanceSnapshotMaterializer.php',
            'app/BusinessModules/Core/MultiOrganization/Reporting/Services/IntercompanyContractFlowSnapshotMaterializer.php',
            'app/BusinessModules/Features/Budgeting/Reporting/Portfolio/BudgetingPortfolioQueryService.php',
        ] as $path) {
            $source = $this->source($path);
            self::assertStringContainsString('record->definition_hash', $source);
            self::assertStringContainsString('snapshot->definitionHash->value', $source);
            self::assertStringContainsString('record->formula_version', $source);
            self::assertStringContainsString("snapshot->watermarks['query_hash']", $source);
        }
    }

    #[Test]
    public function liquidity_probability_is_decimal_at_owner_boundaries(): void
    {
        foreach ([
            'app/BusinessModules/Core/Payments/DTOs/PaymentCalendarItem.php',
            'app/BusinessModules/Features/Budgeting/DTOs/CashGapForecastItem.php',
            'app/BusinessModules/Features/Budgeting/DTOs/CashGapForecastContext.php',
        ] as $path) {
            $source = $this->source($path);
            self::assertStringContainsString('PortfolioDecimal::ratio(', $source);
        }
        self::assertStringContainsString(
            'public string $probability',
            $this->source('app/BusinessModules/Core/Payments/DTOs/PaymentCalendarItem.php'),
        );
        self::assertStringContainsString(
            'public string $stressInflowProbabilityFactor',
            $this->source('app/BusinessModules/Features/Budgeting/DTOs/CashGapForecastContext.php'),
        );

        $service = $this->source(
            'app/BusinessModules/Features/Budgeting/Services/CashGapForecastService.php',
        );
        self::assertStringContainsString('private function multiply(string $amount, string $factor)', $service);
        self::assertStringNotContainsString('private function multiply(string $amount, float $factor)', $service);
    }

    private function source(string $path): string
    {
        $source = file_get_contents(dirname(__DIR__, 4).DIRECTORY_SEPARATOR.$path);
        self::assertIsString($source);

        return $source;
    }
}
