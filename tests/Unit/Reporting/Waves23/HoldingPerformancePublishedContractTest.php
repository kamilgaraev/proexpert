<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Waves23;

use App\BusinessModules\Core\MultiOrganization\Reporting\HoldingPerformanceBuiltinPublishedReport;
use App\BusinessModules\Core\MultiOrganization\Reporting\HoldingPerformanceCandidateContract;
use App\BusinessModules\Core\MultiOrganization\Reporting\HoldingPerformancePublishedRuntimeBindingRegistrar;
use App\BusinessModules\Core\MultiOrganization\Reporting\HoldingPerformanceReportBindingFactory;
use App\BusinessModules\Core\MultiOrganization\Reporting\Providers\HoldingPerformanceReportProvider;
use App\BusinessModules\Core\MultiOrganization\Reporting\Queries\HoldingPerformanceRowQuery;
use App\BusinessModules\Core\MultiOrganization\Reporting\Readiness\HoldingPerformanceReadinessProbe;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Application\Execution\CanonicalReportSourceHashBuilder;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionBindingAssembler;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionRegistry;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportFilterSet;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportProvenance;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuality;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportResult;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportResultMetadata;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSourceRef;
use App\BusinessModules\Core\Reporting\Domain\DTO\PublishedReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinitionBinding;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinitionBindingMap;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportFreshnessStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportQualityStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportReconciliationStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSnapshotClassification;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use DateTimeImmutable;
use DateTimeZone;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class HoldingPerformancePublishedContractTest extends TestCase
{
    #[Test]
    public function published_contract_keeps_holding_identity_server_owned(): void
    {
        $builtin = new HoldingPerformanceBuiltinPublishedReport(new HoldingPerformanceCandidateContract);
        $definition = $builtin->definition()->payload();
        $filterIds = array_column($definition->filters, 'id');

        self::assertSame([
            'organization_ids',
            'project_ids',
            'contractor_ids',
            'contract_statuses',
            'currencies',
            'period_from',
            'period_to',
        ], $filterIds);
        self::assertNotContains('organization_id', $filterIds);
        self::assertNotContains('holding_id', $filterIds);
        self::assertNotContains('project_id', $filterIds);
        self::assertNotContains('user_id', $filterIds);
        self::assertSame(HoldingPerformanceCandidateContract::FORMULA_VERSION, $definition->formulaVersion);
        self::assertSame(HoldingPerformanceCandidateContract::SOURCE_SCHEMA_VERSION, $definition->sourceSchemaVersion);
        self::assertSame(['multi-organization.reports.kpi'], $definition->permissionPolicy->viewPermissions);
        self::assertSame(['multi-organization.reports.export'], $definition->permissionPolicy->exportPermissions);
        self::assertSame([], $definition->permissionPolicy->sensitivePermissions);
        self::assertSame([], $definition->permissionPolicy->auditPermissions);
        self::assertSame(['csv', 'xlsx'], $definition->formats);
        self::assertSame(2, $builtin->metadata()->manifestOrdinal);
        self::assertSame('organization_project_currency_period_basis', $builtin->metadata()->grain);
        self::assertFalse($builtin->scheduling()->supportsSubscriptions);
        self::assertFalse($builtin->scheduling()->reproducibleScheduledSnapshot);
    }

    #[Test]
    public function published_runtime_binding_uses_complete_projection_and_scoped_drill_down(): void
    {
        $contract = new HoldingPerformanceCandidateContract;
        $definition = (new HoldingPerformanceBuiltinPublishedReport($contract))->definition();
        $provider = (new ReflectionClass(HoldingPerformanceReportProvider::class))
            ->newInstanceWithoutConstructor();
        $rows = (new ReflectionClass(HoldingPerformanceRowQuery::class))
            ->newInstanceWithoutConstructor();
        $readiness = (new ReflectionClass(HoldingPerformanceReadinessProbe::class))
            ->newInstanceWithoutConstructor();
        $assembler = new HoldingPerformanceCapturingBindingAssembler;
        $registrar = new HoldingPerformancePublishedRuntimeBindingRegistrar(
            new HoldingPerformancePublishedRegistry($definition),
            new HoldingPerformanceReportBindingFactory($provider, $rows, $readiness, $contract),
        );

        $registrar->register($assembler);

        $binding = $assembler->bindings[HoldingPerformanceCandidateContract::CODE];
        self::assertSame($provider, $binding->dataProvider);
        self::assertSame($rows, $binding->rowQuery);
        self::assertSame($rows, $binding->drillDownProvider);
        self::assertSame($readiness, $binding->readinessProbe);
    }

    #[Test]
    public function runtime_uses_fail_closed_scope_latest_identity_and_source_abac(): void
    {
        $materializer = file_get_contents(dirname(__DIR__, 4).'/app/BusinessModules/Core/MultiOrganization/Reporting/Services/HoldingPerformanceSnapshotMaterializer.php');
        $rows = file_get_contents(dirname(__DIR__, 4).'/app/BusinessModules/Core/MultiOrganization/Reporting/Queries/HoldingPerformanceRowQuery.php');
        $routes = file_get_contents(dirname(__DIR__, 4).'/app/BusinessModules/Core/Reporting/routes.php');
        $indexes = file_get_contents(dirname(__DIR__, 4).'/database/migrations/2026_08_05_030000_index_holding_performance_keyset_sorts.php');
        $coverage = file_get_contents(dirname(__DIR__, 4).'/app/BusinessModules/Core/MultiOrganization/Reporting/Services/HoldingPerformanceProjectionCoverageInspector.php');
        $events = file_get_contents(dirname(__DIR__, 4).'/app/BusinessModules/Core/MultiOrganization/Reporting/Services/HoldingPerformanceImmutableEventSource.php');
        $authorization = file_get_contents(dirname(__DIR__, 4).'/app/BusinessModules/Core/Reporting/Http/Admin/Middleware/AuthorizeReportDefinitionAccess.php');
        $assembler = file_get_contents(dirname(__DIR__, 4).'/app/BusinessModules/Core/MultiOrganization/Reporting/Services/HoldingAllocationCheckpointSourceAssembler.php');
        $accepted = file_get_contents(dirname(__DIR__, 4).'/app/BusinessModules/Core/MultiOrganization/Reporting/Services/AcceptedWorkHoldingFactProducer.php');
        $payments = file_get_contents(dirname(__DIR__, 4).'/app/BusinessModules/Core/MultiOrganization/Reporting/Services/HoldingPaymentEventFactProducer.php');

        self::assertIsString($materializer);
        self::assertIsString($rows);
        self::assertIsString($routes);
        self::assertIsString($indexes);
        self::assertIsString($coverage);
        self::assertIsString($events);
        self::assertIsString($authorization);
        self::assertIsString($assembler);
        self::assertIsString($accepted);
        self::assertIsString($payments);
        self::assertStringContainsString("->whereIn('project_id', \$context->scope->projectIds)", $materializer);
        self::assertStringNotContainsString("if (\$context->scope->projectIds !== [])", $materializer);
        self::assertStringContainsString("['contract_checkpoint', 'contract']", $materializer);
        self::assertStringContainsString("whereColumn('newer_fact.allocation_id'", $materializer);
        self::assertStringContainsString("where('newer_fact.source_schema_version'", $materializer);
        self::assertStringContainsString('CanonicalReportSourceHashBuilder', $materializer);
        self::assertStringContainsString('materializedSourceHash', $materializer);
        self::assertStringContainsString("->where('source_type', 'payment_transaction_event')", $materializer);
        self::assertGreaterThanOrEqual(
            2,
            substr_count($materializer, '$projectionCoverage->contributingPaymentVersionIds'),
        );
        self::assertGreaterThanOrEqual(
            2,
            substr_count($materializer, '$projectionCoverage->contributingActVersionIds'),
        );
        self::assertStringNotContainsString('$projectionCoverage->eligiblePaymentVersionIds', $materializer);
        self::assertStringNotContainsString('$projectionCoverage->eligibleActVersionIds', $materializer);
        self::assertGreaterThanOrEqual(3, substr_count($materializer, '$contractVersionIds'));
        self::assertStringNotContainsString('PaymentTransaction::query()', $coverage);
        self::assertStringNotContainsString('ContractPerformanceAct::query()', $coverage);
        self::assertStringContainsString('HoldingPaymentEventCoverageCheckpoint::query()', $events);
        self::assertStringContainsString("->whereNotExists(function (QueryBuilder \$newer)", $events);
        self::assertStringContainsString('sourceAccess->assertAccessible', $rows);
        self::assertStringContainsString("Route::post('/holding-performance/runs'", $routes);
        self::assertStringContainsString("'admin.reports.holding-performance.runs.store'", $authorization);
        self::assertStringContainsString("CurrencyCode::tryFrom(\$rawCurrency)?->value", $assembler);
        self::assertStringContainsString("'unknown_contract_dimension_checkpoint'", $assembler);
        self::assertStringContainsString('$this->sources->coverageStartedAt($query->asOf)', $materializer);
        self::assertStringContainsString("->where('holding_id', \$holdingId)", $coverage);
        self::assertStringContainsString("->whereIn('contributor_organization_id', \$organizationIds)", $coverage);
        self::assertStringContainsString('if ($metric->currency === null)', $materializer);
        self::assertStringContainsString("if (! \$active)", $accepted);
        self::assertStringContainsString("if (! \$event->active)", $payments);
        self::assertLessThan(
            strpos($routes, "Route::post('/{reportCode}/runs'"),
            strpos($routes, "Route::post('/holding-performance/runs'"),
        );
        foreach ([
            'period_start',
            'contributor_organization_id',
            'project_id',
            'currency',
            'monetary_basis',
            'contracted_minor',
            'accepted_accrual_minor',
            'cash_minor',
        ] as $column) {
            self::assertStringContainsString("'{$column}'", $indexes);
        }
        foreach ([
            'holding_accepted_event_scope_asof',
            'holding_accepted_event_latest_asof',
            'holding_payment_event_checkpoint_evidence',
            'holding_payment_event_latest_capture',
            'holding_fact_projection_coverage',
        ] as $index) {
            self::assertStringContainsString($index, $indexes);
        }
    }

    #[Test]
    public function event_fact_previews_reuse_projection_without_persistence_side_effects(): void
    {
        $accepted = file_get_contents(dirname(__DIR__, 4).'/app/BusinessModules/Core/MultiOrganization/Reporting/Services/AcceptedWorkHoldingFactProducer.php');
        $payments = file_get_contents(dirname(__DIR__, 4).'/app/BusinessModules/Core/MultiOrganization/Reporting/Services/HoldingPaymentEventFactProducer.php');

        self::assertIsString($accepted);
        self::assertIsString($payments);
        foreach ([$accepted, $payments] as $producer) {
            self::assertStringContainsString('public function previewEvent(', $producer);
            self::assertStringContainsString('?HoldingAllocationFact', $producer);
            self::assertSame(1, substr_count($producer, 'public function previewEvent('));
        }

        $acceptedPreview = substr(
            $accepted,
            strpos($accepted, 'public function previewEvent('),
            strpos($accepted, 'private function projectionForEvent(') - strpos($accepted, 'public function previewEvent('),
        );
        $paymentPreview = substr(
            $payments,
            strpos($payments, 'public function previewEvent('),
            strpos($payments, 'private function projection(') - strpos($payments, 'public function previewEvent('),
        );

        foreach ([$acceptedPreview, $paymentPreview] as $preview) {
            self::assertStringContainsString('$this->projector->project($source)', $preview);
            self::assertStringNotContainsString('persist(', $preview);
            self::assertStringNotContainsString('recordGap(', $preview);
        }
    }

    #[Test]
    public function canonical_execution_hash_keeps_raw_materialized_identity_separate(): void
    {
        $definition = (new HoldingPerformanceBuiltinPublishedReport(new HoldingPerformanceCandidateContract))
            ->definition()
            ->payload();
        $scope = new ReportScope(3, [3], [7], [], new DateTimeZone('Europe/Moscow'));
        $query = new ReportQuery(
            $definition,
            $scope,
            new ReportFilterSet([]),
            [],
            new DateTimeImmutable('2026-08-05T12:00:00+00:00'),
            'ru-RU',
        );
        $raw = new Sha256Hash(str_repeat('c', 64));
        $generatedAt = new DateTimeImmutable('2026-08-05T12:00:00+00:00');
        $source = new ReportSourceRef(
            'holding_allocations',
            'holding_facts',
            'snapshot_r02',
            HoldingPerformanceCandidateContract::SOURCE_SCHEMA_VERSION,
            'watermark_r02',
            2,
            $raw,
        );
        $provisional = new ReportSnapshotRef(
            HoldingPerformanceCandidateContract::CODE,
            'snapshot-r02',
            $scope,
            $definition->definitionHash,
            $definition->formulaVersion,
            $raw,
            $generatedAt,
            null,
            ['query_hash' => $query->queryHash->value],
            ReportSnapshotClassification::OPERATIONAL,
            null,
            $raw,
        );
        $provisionalResult = $this->result($provisional, $source, $raw);
        $canonical = (new CanonicalReportSourceHashBuilder)->build($query, $provisional, $provisionalResult);
        $snapshot = new ReportSnapshotRef(
            $provisional->kind,
            $provisional->id,
            $scope,
            $definition->definitionHash,
            $definition->formulaVersion,
            $canonical,
            $generatedAt,
            null,
            $provisional->watermarks,
            ReportSnapshotClassification::OPERATIONAL,
            null,
            $raw,
        );

        self::assertNotSame($raw->value, $canonical->value);
        self::assertSame($raw->value, $snapshot->materializedSourceHash->value);
        self::assertSame(
            $canonical->value,
            (new CanonicalReportSourceHashBuilder)->build(
                $query,
                $snapshot,
                $this->result($snapshot, $source, $canonical),
            )->value,
        );
    }

    private function result(ReportSnapshotRef $snapshot, ReportSourceRef $source, Sha256Hash $hash): ReportResult
    {
        return new ReportResult(
            new ReportResultMetadata($snapshot, 0, $snapshot->generatedAt, null),
            [],
            ReportFreshnessStatus::FRESH,
            new ReportQuality(
                ReportQualityStatus::COMPLETE,
                null,
                [],
                0,
                ReportReconciliationStatus::NOT_APPLICABLE,
                [],
                [],
            ),
            new ReportProvenance('holding_allocation_facts', [$source], $hash, null),
            [],
            ['formats' => ['csv', 'xlsx']],
        );
    }
}

final class HoldingPerformanceCapturingBindingAssembler implements ReportDefinitionBindingAssembler
{
    public array $bindings = [];

    public function register(ReportDefinitionBinding $binding): void
    {
        $this->bindings[$binding->code] = $binding;
    }

    public function assemble(ReportDefinitionRegistry $registry): ReportDefinitionBindingMap
    {
        throw new LogicException('not_used');
    }
}

final readonly class HoldingPerformancePublishedRegistry implements ReportDefinitionRegistry
{
    public function __construct(private PublishedReportDefinition $definition) {}

    public function published(string $code): PublishedReportDefinition
    {
        return $code === HoldingPerformanceCandidateContract::CODE
            ? $this->definition
            : throw ReportContractException::fromCode(ReportErrorCode::REPORT_NOT_FOUND);
    }

    public function publishedCodes(): array
    {
        return [HoldingPerformanceCandidateContract::CODE];
    }

    public function manifestSha256(): Sha256Hash
    {
        return new Sha256Hash(str_repeat('b', 64));
    }
}
