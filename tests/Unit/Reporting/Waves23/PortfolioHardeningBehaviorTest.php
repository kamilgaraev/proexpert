<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Waves23;

use App\BusinessModules\Core\MultiOrganization\Reporting\Models\HoldingAcceptedWorkEventVersion;
use App\BusinessModules\Core\MultiOrganization\Reporting\Queries\HoldingPerformanceRowQuery;
use App\BusinessModules\Core\MultiOrganization\Reporting\Services\AcceptedWorkHoldingFactProducer;
use App\BusinessModules\Core\MultiOrganization\Reporting\Services\HoldingAllocationFactProjector;
use App\BusinessModules\Core\MultiOrganization\Reporting\Services\HoldingPerformanceSnapshotMaterializer;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportQualityStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportReconciliationStatus;
use App\BusinessModules\Features\Budgeting\DTOs\CashGapForecastContext;
use App\BusinessModules\Features\Budgeting\DTOs\CashGapForecastFilters;
use App\BusinessModules\Features\Budgeting\DTOs\CashGapForecastItem;
use App\BusinessModules\Features\Budgeting\DTOs\CashGapScenarioAdjustment;
use App\BusinessModules\Features\Budgeting\Reporting\Portfolio\BudgetingPortfolioProjectionService;
use App\BusinessModules\Features\Budgeting\Reporting\Portfolio\Models\BudgetingPortfolioSnapshot;
use App\BusinessModules\Features\Budgeting\Reporting\Portfolio\PortfolioLiquiditySourceVersionBackfill;
use App\BusinessModules\Features\Budgeting\Services\CashGapForecastService;
use DateTimeImmutable;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\FileLoader;
use Illuminate\Translation\Translator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class PortfolioHardeningBehaviorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $container = new Container;
        $loader = new FileLoader(
            new Filesystem,
            dirname(__DIR__, 4).DIRECTORY_SEPARATOR.'lang',
        );
        $container->instance('translator', new Translator($loader, 'ru'));
        $container->instance('config', new Repository([
            'app' => ['locale' => 'ru', 'fallback_locale' => 'ru'],
        ]));
        $container->instance('app', new class
        {
            public function getLocale(): string
            {
                return 'ru';
            }
        });
        Facade::setFacadeApplication($container);
        Container::setInstance($container);
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Container::setInstance(null);

        parent::tearDown();
    }

    #[Test]
    public function custom_probability_remains_decimal_through_scenario_calculation(): void
    {
        $adjustment = CashGapScenarioAdjustment::fromArray([
            'action' => CashGapScenarioAdjustment::ACTION_CHANGE_INFLOW_PROBABILITY,
            'cash_flow_key' => 'payment:10',
            'probability' => '0.123456785',
        ]);
        $result = (new CashGapForecastService)->forecast(
            new CashGapForecastContext(
                periodStart: '2026-07-30',
                periodEnd: '2026-07-30',
                openingBalance: '0',
                scenario: CashGapForecastContext::SCENARIO_CUSTOM,
                filters: new CashGapForecastFilters(organizationId: 1),
                scenarioAdjustments: [$adjustment],
            ),
            [
                new CashGapForecastItem(
                    date: '2026-07-30',
                    direction: CashGapForecastItem::DIRECTION_INFLOW,
                    bucket: CashGapForecastItem::BUCKET_PLANNED_INFLOW,
                    amount: '100.00',
                    probability: '1',
                    organizationId: 1,
                    cashFlowKey: 'payment:10',
                ),
            ],
        );

        self::assertSame('0.12345679', $adjustment->probability);
        self::assertSame('12.35', $result->days[0]->inflows);
        self::assertSame('0.12345679', $result->days[0]->drivers[0]['probability']);
    }

    #[Test]
    public function health_snapshot_exposes_incomplete_critical_source_coverage(): void
    {
        $snapshot = new BudgetingPortfolioSnapshot;
        $snapshot->setRawAttributes([
            'report_code' => BudgetingPortfolioProjectionService::HEALTH_CODE,
            'quality_status' => ReportQualityStatus::PARTIAL->value,
            'row_count' => 2,
            'watermarks' => json_encode([
                'quality_gaps' => ['margin_quality_attention'],
            ], JSON_THROW_ON_ERROR),
        ]);

        $quality = BudgetingPortfolioProjectionService::qualityFromRecord($snapshot);

        self::assertSame(ReportQualityStatus::PARTIAL, $quality->status);
        self::assertSame('2', $quality->coverage?->numerator);
        self::assertSame('3', $quality->coverage?->denominator);
        self::assertSame(1, $quality->unmatchedCount);
        self::assertSame(ReportReconciliationStatus::MISMATCH, $quality->reconciliation);
        self::assertSame('SOURCE_COVERAGE_PARTIAL', $quality->warnings[0]->code);
        self::assertSame(['margin_quality_attention'], $quality->excludedSources);
    }

    #[Test]
    public function allocation_drill_down_uses_contract_identity_for_contract_route(): void
    {
        $query = new HoldingPerformanceRowQuery(
            (new \ReflectionClass(HoldingPerformanceSnapshotMaterializer::class))->newInstanceWithoutConstructor(),
        );
        $method = new ReflectionMethod($query, 'routeParams');

        self::assertSame(
            ['id' => 44],
            $method->invoke($query, [
                'type' => 'contract_allocation',
                'id' => '991',
                'contract_id' => '44',
            ]),
        );
    }

    #[Test]
    public function allocation_drill_down_authorizes_the_routed_contract_target(): void
    {
        $query = new HoldingPerformanceRowQuery(
            (new \ReflectionClass(HoldingPerformanceSnapshotMaterializer::class))->newInstanceWithoutConstructor(),
        );
        $method = new ReflectionMethod($query, 'authorizationIdentity');

        self::assertSame(
            'contract:44',
            $method->invoke($query, [
                'type' => 'contract_allocation',
                'id' => '991',
                'contract_id' => '44',
            ]),
        );
    }

    #[Test]
    public function accepted_work_version_uses_immutable_event_id(): void
    {
        $producer = (new \ReflectionClass(AcceptedWorkHoldingFactProducer::class))
            ->newInstanceWithoutConstructor();
        $method = new ReflectionMethod($producer, 'sourceVersion');

        self::assertSame(875, $method->invoke($producer, 875));
    }

    #[Test]
    public function liquidity_backfill_exposes_resumable_source_slices(): void
    {
        $reflection = new \ReflectionClass(PortfolioLiquiditySourceVersionBackfill::class);
        $backfill = $reflection->newInstanceWithoutConstructor();

        self::assertTrue($reflection->hasMethod('projectSourceSlice'));
        self::assertSame([
            'payment_document',
            'payment_schedule',
            'payment_transaction',
            'budget_limit_reservation',
            'budget_amount',
            'opening_balance',
        ], $backfill->supportedSourceTypes());
    }

    #[Test]
    public function accepted_work_owner_event_identity_is_deterministic_and_transition_sensitive(): void
    {
        $approved = HoldingAcceptedWorkEventVersion::deterministicEventKey(
            91,
            44,
            12,
            7,
            true,
            '1500.00',
            'approved',
            '2026-07-01T10:00:00+00:00',
        );

        self::assertSame(
            $approved,
            HoldingAcceptedWorkEventVersion::deterministicEventKey(
                91,
                44,
                12,
                7,
                true,
                '1500.00',
                'approved',
                '2026-07-01T10:00:00+00:00',
            ),
        );
        self::assertNotSame(
            $approved,
            HoldingAcceptedWorkEventVersion::deterministicEventKey(
                91,
                44,
                12,
                7,
                false,
                '1500.00',
                'rejected',
                '2026-07-03T10:00:00+00:00',
            ),
        );
    }

    #[Test]
    public function liquidity_legacy_backfill_declares_history_unverifiable(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 4)
            .'/app/BusinessModules/Features/Budgeting/Reporting/Portfolio/PortfolioLiquiditySourceVersionBackfill.php',
        );

        self::assertIsString($source);
        self::assertStringContainsString('historyComplete: false', $source);
        self::assertStringContainsString('occurredAt: $ingestedAt', $source);
    }

    #[Test]
    public function accepted_work_lifecycle_records_inactive_creation_anchor(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 4)
            .'/app/BusinessModules/Core/MultiOrganization/Reporting/Services/'
            .'HoldingAcceptedWorkLifecycleRecorder.php',
        );

        self::assertIsString($source);
        self::assertStringContainsString(
            'historyComplete: true',
            $source,
        );
        self::assertStringContainsString('$this->facts->projectEvent($event)', $source);
    }

    #[Test]
    public function holding_gap_queries_apply_business_and_recorded_cutoffs(): void
    {
        foreach ([
            'HoldingPerformanceSnapshotMaterializer.php',
            'IntercompanyContractFlowSnapshotMaterializer.php',
        ] as $file) {
            $source = file_get_contents(
                dirname(__DIR__, 4)
                .'/app/BusinessModules/Core/MultiOrganization/Reporting/Services/'.$file,
            );

            self::assertIsString($source);
            self::assertStringContainsString("->where('business_effective_at', '<=', \$query->asOf)", $source);
            self::assertStringContainsString("->where('recorded_at', '<=', \$recordedCutoff)", $source);
        }
    }

    #[Test]
    public function liquidity_source_evidence_hash_includes_late_quality_gaps(): void
    {
        $base = [
            'payment_calendar' => [],
            'opening_balances' => [],
            'source_versions' => [['id' => 1, 'source_hash' => str_repeat('a', 64)]],
            'source_gaps' => [],
            'ingestion_watermark' => '2026-07-30T10:00:00+00:00',
        ];

        $complete = BudgetingPortfolioProjectionService::liquiditySourceEvidence($base, []);
        $partial = BudgetingPortfolioProjectionService::liquiditySourceEvidence(
            $base,
            [['code' => 'opening_balance_missing', 'currency' => 'RUB']],
        );

        self::assertNotSame($complete['hash'], $partial['hash']);
        self::assertSame(
            [['code' => 'opening_balance_missing', 'currency' => 'RUB']],
            $partial['payload']['source_gaps'],
        );
    }

    #[Test]
    public function unknown_history_gap_uses_stable_sentinel_closed_by_first_real_version(): void
    {
        self::assertSame([0, 73], HoldingAllocationFactProjector::resolvableGapSourceVersions(73));
        self::assertSame([0], HoldingAllocationFactProjector::resolvableGapSourceVersions(0));
    }

    #[Test]
    public function gap_business_time_uses_source_evidence_and_fails_closed_when_unknown(): void
    {
        self::assertSame(
            '2026-04-03T12:00:00+00:00',
            HoldingAllocationFactProjector::gapBusinessEffectiveAt([
                'business_effective_at' => '2026-04-03T12:00:00+00:00',
            ])->format(DateTimeImmutable::ATOM),
        );
        self::assertSame(
            '2026-04-04T00:00:00+00:00',
            HoldingAllocationFactProjector::gapBusinessEffectiveAt([
                'recognized_on' => '2026-04-04',
            ])->format(DateTimeImmutable::ATOM),
        );
        self::assertSame(
            '2026-04-05T10:00:00+00:00',
            HoldingAllocationFactProjector::gapBusinessEffectiveAt(
                [],
                new DateTimeImmutable('2026-04-05T10:00:00+00:00'),
            )->format(DateTimeImmutable::ATOM),
        );
        self::assertSame(
            '0001-01-01T00:00:00+00:00',
            HoldingAllocationFactProjector::gapBusinessEffectiveAt([])->format(DateTimeImmutable::ATOM),
        );
    }

    #[Test]
    public function historical_gap_queries_keep_resolved_gap_active_before_fact_effective_time(): void
    {
        foreach ([
            'HoldingPerformanceSnapshotMaterializer.php',
            'IntercompanyContractFlowSnapshotMaterializer.php',
        ] as $file) {
            $source = file_get_contents(
                dirname(__DIR__, 4)
                .'/app/BusinessModules/Core/MultiOrganization/Reporting/Services/'.$file,
            );

            self::assertIsString($source);
            self::assertStringContainsString(
                "->orWhere('resolved_business_effective_at', '>', \$query->asOf)",
                $source,
            );
        }
    }

    #[Test]
    public function malformed_string_currency_is_persisted_as_quality_gap(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 4)
            .'/app/BusinessModules/Core/MultiOrganization/Reporting/Services/HoldingPaymentEventFactProducer.php',
        );

        self::assertIsString($source);
        self::assertStringContainsString(
            'CurrencyCode::tryFrom($rawCurrency) === null',
            $source,
        );
        self::assertStringContainsString("\$missing[] = 'currency'", $source);
    }
}
