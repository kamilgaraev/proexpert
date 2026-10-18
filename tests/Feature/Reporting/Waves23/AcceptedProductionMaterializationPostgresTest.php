<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting\Waves23;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Application\Execution\CanonicalReportSourceHashBuilder;
use App\BusinessModules\Core\Reporting\Application\Rows\ReportRowChunkReader;
use App\BusinessModules\Core\Reporting\Domain\DTO\AuthorizationDecisionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportActor;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportFilterSet;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportProgress;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportWindowSort;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSortDirection;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\Exceptions\BusinessLogicException;
use App\Models\CompletedWork;
use App\Models\ContractPerformanceAct;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Services\Acting\ActingPriceService;
use App\Services\ActReport\ActReportNotificationService;
use App\Services\ActReport\ActReportWorkflowService;
use App\Services\CompletedWork\Reporting\AcceptedProduction\Models\ProductionAcceptanceEvent;
use App\Services\CompletedWork\Reporting\AcceptedProduction\Providers\AcceptedProductionReportProvider;
use App\Services\CompletedWork\Reporting\AcceptedProduction\Queries\AcceptedProductionRowQuery;
use App\Services\CompletedWork\Reporting\AcceptedProduction\Services\AcceptedProductionEventUniverse;
use App\Services\CompletedWork\Reporting\AcceptedProduction\Services\AcceptedProductionFormula;
use App\Services\CompletedWork\Reporting\AcceptedProduction\Services\AcceptedProductionHistoryBoundaryResolver;
use App\Services\CompletedWork\Reporting\AcceptedProduction\Services\AcceptedProductionLifecycleCompleteness;
use App\Services\CompletedWork\Reporting\AcceptedProduction\Services\AcceptedProductionLineageFilter;
use App\Services\CompletedWork\Reporting\AcceptedProduction\Services\AcceptedProductionSnapshotMaterializer;
use App\Services\CompletedWork\Reporting\AcceptedProduction\Services\ProductionAcceptanceEventRecorder;
use App\Services\CompletedWork\Reporting\AcceptedProduction\Services\ProductionAcceptanceRecognitionGrain;
use App\Services\Contract\ContractPerformanceActService;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use ReflectionMethod;
use Tests\Support\IsolatedPostgresTestDatabase;
use Tests\Support\Reporting\ReportDefinitionBuilder;
use Tests\Support\Reporting\ReportExecutionContextBuilder;
use Tests\TestCase;

#[Group('postgresql')]
final class AcceptedProductionMaterializationPostgresTest extends TestCase
{
    use RefreshDatabase;

    private ?array $previousDatabaseEnvironment = null;

    private bool $previousMigrationState = false;

    protected function setUp(): void
    {
        if (in_array($this->name(), [
            'test_frozen_as_of_replays_nonempty_result_after_later_reversal_and_reacceptance',
            'test_period_filter_keeps_acceptance_and_reversal_on_their_own_days_and_isolates_tenant',
            'test_history_checkpoint_captures_existing_source_sets_and_separates_late_backdated_rows',
            'test_signature_after_as_of_keeps_the_earlier_approval_in_runtime_gap_detection',
        ], true)) {
            $this->previousDatabaseEnvironment = [getenv('DB_DATABASE'), $_ENV['DB_DATABASE'] ?? null, $_SERVER['DB_DATABASE'] ?? null];
            $this->previousMigrationState = RefreshDatabaseState::$migrated;
            $configuration = IsolatedPostgresTestDatabase::databaseConfiguration();
            putenv('DB_DATABASE='.$configuration['database']);
            $_ENV['DB_DATABASE'] = $_SERVER['DB_DATABASE'] = $configuration['database'];
            RefreshDatabaseState::$migrated = false;
        }
        try {
            parent::setUp();
        } catch (\Throwable $exception) {
            $this->restoreDatabaseEnvironment();
            throw $exception;
        }
    }

    protected function tearDown(): void
    {
        try {
            parent::tearDown();
        } finally {
            $this->restoreDatabaseEnvironment();
        }
    }

    private function restoreDatabaseEnvironment(): void
    {
        if ($this->previousDatabaseEnvironment === null) {
            return;
        }
        [$value, $environment, $server] = $this->previousDatabaseEnvironment;
        $value === false ? putenv('DB_DATABASE') : putenv('DB_DATABASE='.$value);
        if ($environment === null) {
            unset($_ENV['DB_DATABASE']);
        } else {
            $_ENV['DB_DATABASE'] = $environment;
        }
        if ($server === null) {
            unset($_SERVER['DB_DATABASE']);
        } else {
            $_SERVER['DB_DATABASE'] = $server;
        }
        RefreshDatabaseState::$migrated = $this->previousMigrationState;
        $this->previousDatabaseEnvironment = null;
    }

    protected function beforeRefreshingDatabase(): void
    {
        self::assertSame(
            'pgsql',
            DB::connection()->getDriverName(),
            'Accepted production materialization requires isolated PostgreSQL.',
        );
        if ($this->previousDatabaseEnvironment !== null) {
            IsolatedPostgresTestDatabase::assertSafeConfiguration((array) config('database.connections.pgsql'));
            DB::statement('CREATE EXTENSION IF NOT EXISTS vector');
            DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        }
    }

    public function test_frozen_as_of_replays_nonempty_result_after_later_reversal_and_reacceptance(): void
    {
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $organizationId = (int) $organization->id;
        $projectId = (int) $project->id;
        $checkpoint = DB::table('production_acceptance_history_checkpoints')->where('organization_id', $organizationId)->firstOrFail();
        $day = (new DateTimeImmutable((string) $checkpoint->completed_at))->setTimezone(new DateTimeZone('UTC'))->setTime(0, 0)->modify('+1 day');
        $acceptedAt = $day->setTime(8, 0);
        $acceptedId = $this->insertEvent($organizationId, $projectId, 'accepted', '2.500', $acceptedAt->format(DATE_ATOM));
        $this->insertOwnerVersion($organizationId, $projectId, 'accepted', $acceptedAt->format(DATE_ATOM), 1);
        $scope = new ReportScope($organizationId, [$organizationId], [$projectId], [], new DateTimeZone('UTC'));
        $definition = (new ReportDefinitionBuilder)->code('accepted_production_progress')->formulaVersion('accepted_production.v1')->sourceSchemaVersion('production_acceptance_events_v2')->payload();
        $filters = new ReportFilterSet(['organization_id' => (string) $organizationId, 'project_id' => (string) $projectId, 'period_from' => $day->format('Y-m-d'), 'period_to' => $day->format('Y-m-d')]);
        $query = new ReportQuery($definition, $scope, $filters, [], $day->setTime(10, 0), 'ru-RU');
        $materializer = new AcceptedProductionSnapshotMaterializer(new AcceptedProductionFormula, new ProductionAcceptanceRecognitionGrain);
        self::assertSame(1, DB::transactionLevel());
        DB::commit();
        try {
            $baseline = $materializer->materialize($scope, $query, new ReportProgress(0));
            $rows = static fn (string $snapshotId): array => DB::table('accepted_production_rows')->where('organization_id', $organizationId)->where('snapshot_id', $snapshotId)->orderBy('row_key')->get()->map(static fn (object $row): array => (array) $row)->all();
            $baselineRows = $rows($baseline->id);
            self::assertCount(1, $baselineRows);
            self::assertSame(2.5, (float) $baselineRows[0]['accepted_quantity']);
            $actor = User::factory()->create(['current_organization_id' => $organizationId]);
            $context = (new ReportExecutionContextBuilder)
                ->actor(new ReportActor((int) $actor->id, 'active', ['reports.view', 'reports.run', 'reports.export']))
                ->scope($scope)
                ->authorization(new AuthorizationDecisionContext('queue', $organizationId, [$organizationId], [$projectId], [], new DateTimeZone('UTC'), 'nonempty-owner-test', null))
                ->build();
            $result = app(AcceptedProductionReportProvider::class)->result($context, $baseline);
            $canonicalHash = app(CanonicalReportSourceHashBuilder::class)->build($query, $baseline, $result);
            $canonical = new ReportSnapshotRef(
                $baseline->kind, $baseline->id, $baseline->scope, $baseline->definitionHash, $baseline->formulaVersion,
                $canonicalHash, $baseline->generatedAt, $baseline->staleAt, $baseline->watermarks, $baseline->classification,
                $baseline->seal, $baseline->materializedSourceHash,
            );
            $rowQuery = app(AcceptedProductionRowQuery::class);
            $sort = new ReportWindowSort('recognized_on', ReportSortDirection::DESC);
            $envelopes = iterator_to_array($rowQuery->cursor($context, $canonical, $sort, 10));
            self::assertCount(1, $envelopes);
            self::assertSame($canonicalHash->value, $envelopes[0]['source_hash']);
            self::assertNotSame($baseline->materializedSourceHash->value, $envelopes[0]['source_hash']);
            self::assertSame(2.5, (float) $envelopes[0]['values']['accepted_quantity']);
            $page = $rowQuery->page($context, $canonical, $sort, null, 10);
            self::assertCount(1, $page->rows);
            self::assertCount(1, iterator_to_array((new ReportRowChunkReader)->read(
                $context, $canonical, new Sha256Hash($envelopes[0]['query_hash']), $sort, 10, $rowQuery,
            )));
            $baselineTotals = DB::table('accepted_production_snapshots')->where('id', $baseline->id)->value('totals');
            $reversedAt = $day->setTime(12, 0);
            $reacceptedAt = $day->setTime(13, 0);
            $this->insertEvent($organizationId, $projectId, 'reversed', '-2.500', $reversedAt->format(DATE_ATOM), reversesEventId: $acceptedId, transitionVersion: 2);
            $this->insertOwnerVersion($organizationId, $projectId, 'reversed', $reversedAt->format(DATE_ATOM), 2);
            $latestEventId = $this->insertEvent($organizationId, $projectId, 'accepted', '4.500', $reacceptedAt->format(DATE_ATOM), transitionVersion: 3);
            $this->insertOwnerVersion($organizationId, $projectId, 'accepted', $reacceptedAt->format(DATE_ATOM), 3);
            $replay = $materializer->materialize($scope, $query, new ReportProgress(0));
            self::assertSame($baseline->materializedSourceHash->value, $replay->materializedSourceHash->value);
            self::assertSame($baseline->id, $replay->id);
            self::assertSame($baselineRows, $rows($replay->id));
            self::assertSame($baselineTotals, DB::table('accepted_production_snapshots')->where('id', $replay->id)->value('totals'));
            $laterQuery = new ReportQuery($definition, $scope, $filters, [], $day->setTime(14, 0), 'ru-RU');
            $later = $materializer->materialize($scope, $laterQuery, new ReportProgress(0));
            self::assertNotSame($baseline->materializedSourceHash->value, $later->materializedSourceHash->value);
            self::assertSame('event_'.$latestEventId, $later->watermarks['acceptance_events']);
            self::assertSame(4.5, (float) DB::table('accepted_production_rows')->where('organization_id', $organizationId)->where('snapshot_id', $later->id)->sum('accepted_quantity'));
        } finally {
            DB::beginTransaction();
        }
    }

    public function test_period_filter_keeps_acceptance_and_reversal_on_their_own_days_and_isolates_tenant(): void
    {
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $otherAccessibleProject = Project::factory()->create(['organization_id' => $organization->id]);
        $foreignOrganization = Organization::factory()->create();
        $foreignProject = Project::factory()->create(['organization_id' => $foreignOrganization->id]);
        $checkpoint = DB::table('production_acceptance_history_checkpoints')
            ->where('organization_id', $organization->id)
            ->firstOrFail();
        $coverageStart = (new DateTimeImmutable((string) $checkpoint->completed_at))
            ->setTimezone(new DateTimeZone('UTC'))
            ->setTime(0, 0)
            ->modify('+1 day');
        $acceptedAt = $coverageStart->setTime(8, 0);
        $reversedAt = $coverageStart->modify('+1 day')->setTime(6, 15);
        $foreignAt = $coverageStart->modify('+1 day')->setTime(7, 0);
        $otherProjectAt = $coverageStart->setTime(9, 0);
        $acceptedId = $this->insertEvent(
            organizationId: (int) $organization->id,
            projectId: (int) $project->id,
            eventType: 'accepted',
            quantity: '2.500',
            recognizedAt: $acceptedAt->format(DATE_ATOM),
        );
        $this->insertEvent(
            organizationId: (int) $organization->id,
            projectId: (int) $project->id,
            eventType: 'reversed',
            quantity: '-2.500',
            recognizedAt: $reversedAt->format(DATE_ATOM),
            reversesEventId: $acceptedId,
            transitionVersion: 2,
        );
        $this->insertEvent(
            organizationId: (int) $organization->id,
            projectId: (int) $otherAccessibleProject->id,
            eventType: 'accepted',
            quantity: '7.000',
            recognizedAt: $otherProjectAt->format(DATE_ATOM),
            performanceActId: 52,
            sourceLineId: 92,
        );
        $foreignAccepted = $this->insertEvent(
            organizationId: (int) $foreignOrganization->id,
            projectId: (int) $foreignProject->id,
            eventType: 'accepted',
            quantity: '9.000',
            recognizedAt: $foreignAt->format(DATE_ATOM),
        );
        $this->insertEvent(
            organizationId: (int) $foreignOrganization->id,
            projectId: (int) $foreignProject->id,
            eventType: 'reversed',
            quantity: '-9.000',
            recognizedAt: $foreignAt->modify('+1 minute')->format(DATE_ATOM),
            reversesEventId: $foreignAccepted,
            transitionVersion: 2,
        );
        $this->insertOwnerVersion((int) $organization->id, (int) $project->id, 'accepted', $acceptedAt->format(DATE_ATOM), 1);
        $this->insertOwnerVersion((int) $organization->id, (int) $project->id, 'reversed', $reversedAt->format(DATE_ATOM), 2);
        $this->insertOwnerVersion(
            (int) $organization->id,
            (int) $otherAccessibleProject->id,
            'accepted',
            $otherProjectAt->format(DATE_ATOM),
            1,
            52,
            92,
        );
        $this->insertOwnerVersion((int) $foreignOrganization->id, (int) $foreignProject->id, 'accepted', $foreignAt->format(DATE_ATOM), 1);
        $this->insertOwnerVersion((int) $foreignOrganization->id, (int) $foreignProject->id, 'reversed', $foreignAt->modify('+1 minute')->format(DATE_ATOM), 2);
        $scope = new ReportScope(
            (int) $organization->id,
            [(int) $organization->id],
            [(int) $project->id, (int) $otherAccessibleProject->id],
            [],
            new DateTimeZone('UTC'),
        );
        $definition = (new ReportDefinitionBuilder)
            ->code('accepted_production_progress')
            ->formulaVersion('accepted_production.v1')
            ->sourceSchemaVersion('production_acceptance_events_v2')
            ->payload();
        $query = new ReportQuery(
            $definition,
            $scope,
            new ReportFilterSet([
                'organization_id' => (string) $organization->id,
                'project_id' => (string) $project->id,
                'period_from' => $acceptedAt->format('Y-m-d'),
                'period_to' => $reversedAt->format('Y-m-d'),
            ]),
            [],
            $reversedAt->setTime(23, 59, 59),
            'ru-RU',
        );

        $snapshot = $this->outsideTestTransaction(fn () => (new AcceptedProductionSnapshotMaterializer(
            new AcceptedProductionFormula,
            new ProductionAcceptanceRecognitionGrain,
        ))->materialize($scope, $query, new ReportProgress(0)));

        $rows = DB::table('accepted_production_rows')
            ->where('organization_id', $organization->id)
            ->where('snapshot_id', $snapshot->id)
            ->get();
        self::assertCount(2, $rows);
        self::assertSame(
            [
                [$acceptedAt->format('Y-m-d'), '2.500', 'accepted'],
                [$reversedAt->format('Y-m-d'), '-2.500', 'reversed'],
            ],
            $rows
                ->sortBy('recognized_on')
                ->map(static fn ($row): array => [
                    (string) $row->recognized_on,
                    (string) $row->accepted_quantity,
                    (string) $row->event_status,
                ])
                ->values()
                ->all(),
        );
        self::assertSame(
            0,
            DB::table('accepted_production_rows')
                ->where('organization_id', $foreignOrganization->id)
                ->count(),
        );
    }

    public function test_keyset_index_covers_daily_recognition_order(): void
    {
        $definition = DB::table('pg_indexes')
            ->where('tablename', 'accepted_production_rows')
            ->where('indexname', 'accepted_production_row_keyset')
            ->value('indexdef');

        self::assertIsString($definition);
        self::assertStringContainsString(
            '(organization_id, snapshot_id, project_id, recognized_on, unit_dimension, unit_code, work_id, row_key)',
            $definition,
        );
    }

    public function test_missing_checkpoint_returns_the_standard_source_unavailable_error(): void
    {
        $scope = new ReportScope(999_999_999, [999_999_999], [999_999_999], [], new DateTimeZone('UTC'));
        $definition = (new ReportDefinitionBuilder)
            ->code('accepted_production_progress')
            ->formulaVersion('accepted_production.v1')
            ->sourceSchemaVersion('production_acceptance_events_v2')
            ->payload();
        $query = new ReportQuery(
            $definition,
            $scope,
            new ReportFilterSet([
                'period_from' => '2099-01-01',
                'period_to' => '2099-01-01',
            ]),
            [],
            new DateTimeImmutable('2099-01-01T23:59:59Z'),
            'ru-RU',
        );

        try {
            (new AcceptedProductionHistoryBoundaryResolver)->resolve($scope, $query);
            self::fail('Missing checkpoint must fail closed.');
        } catch (ReportContractException $exception) {
            self::assertSame(ReportErrorCode::REPORT_SOURCE_UNAVAILABLE, $exception->errorCode);
            self::assertSame([], $exception->safeFields);
        }
    }

    public function test_malformed_filter_is_rejected_before_checkpoint_lookup(): void
    {
        $scope = new ReportScope(999_999_999, [999_999_999], [999_999_999], [], new DateTimeZone('UTC'));
        $query = new ReportQuery(
            (new ReportDefinitionBuilder)
                ->code('accepted_production_progress')
                ->formulaVersion('accepted_production.v1')
                ->sourceSchemaVersion('production_acceptance_events_v2')
                ->payload(),
            $scope,
            new ReportFilterSet([
                'organization_id' => '999999999',
                'project_id' => '999999999',
                'period_from' => '2099-01-01',
                'period_to' => '2099-01-01',
                'contractor_ids' => 'broken',
            ]),
            [],
            new DateTimeImmutable('2099-01-01T23:59:59Z'),
            'ru-RU',
        );

        try {
            (new AcceptedProductionEventUniverse)->stream($scope, $query);
            self::fail('Malformed public filter must fail before the source lookup.');
        } catch (ReportContractException $exception) {
            self::assertSame(ReportErrorCode::REPORT_REQUEST_INVALID, $exception->errorCode);
            self::assertSame([], $exception->safeFields);
        }
    }

    public function test_invalid_calendar_period_returns_the_standard_range_error_before_sql(): void
    {
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $scope = new ReportScope(
            (int) $organization->id,
            [(int) $organization->id],
            [(int) $project->id],
            [],
            new DateTimeZone('UTC'),
        );
        $definition = (new ReportDefinitionBuilder)
            ->code('accepted_production_progress')
            ->formulaVersion('accepted_production.v1')
            ->sourceSchemaVersion('production_acceptance_events_v2')
            ->payload();
        $query = new ReportQuery(
            $definition,
            $scope,
            new ReportFilterSet([
                'period_from' => '2099-02-31',
                'period_to' => '2099-02-31',
            ]),
            [],
            new DateTimeImmutable('2099-03-01T23:59:59Z'),
            'ru-RU',
        );

        try {
            (new AcceptedProductionHistoryBoundaryResolver)->resolve($scope, $query);
            self::fail('Invalid calendar date must be rejected before source queries.');
        } catch (ReportContractException $exception) {
            self::assertSame(ReportErrorCode::REPORT_FILTER_RANGE_INVALID, $exception->errorCode);
            self::assertSame([], $exception->safeFields);
        }
    }

    public function test_drill_lineage_period_uses_the_snapshot_timezone_near_midnight(): void
    {
        $eventId = $this->insertEvent(
            organizationId: 3,
            projectId: 7,
            eventType: 'accepted',
            quantity: '2.500',
            recognizedAt: '2026-08-01T21:30:00Z',
        );
        $filter = AcceptedProductionLineageFilter::fromArray([
            'as_of' => '2026-08-02T23:59:59.000000+03:00',
            'contractor_ids' => [],
            'period_from' => '2026-08-02',
            'period_to' => '2026-08-02',
            'statuses' => [],
            'timezone' => 'Europe/Moscow',
            'unit_codes' => [],
            'zones' => [],
        ]);
        $query = ProductionAcceptanceEvent::query()->whereKey($eventId);

        $filter->applyTo($query);

        self::assertSame([$eventId], $query->pluck('id')->map(static fn ($id): int => (int) $id)->all());
    }

    public function test_acceptance_events_are_append_only_in_postgresql(): void
    {
        $eventId = $this->insertEvent(
            organizationId: 3,
            projectId: 7,
            eventType: 'accepted',
            quantity: '2.500',
            recognizedAt: '2026-07-30T06:15:00Z',
        );

        $this->expectException(QueryException::class);
        DB::transaction(
            static fn (): int => DB::table('production_acceptance_events')
                ->where('id', $eventId)
                ->update(['accepted_quantity_delta' => '3.000']),
        );
    }

    public function test_owner_versions_and_members_are_append_only_in_postgresql(): void
    {
        [$ownerId, $memberId] = $this->insertOwnerVersion(
            3,
            7,
            'accepted',
            '2026-07-30T06:15:00Z',
            1,
        );

        $this->assertMutationRejected(
            static fn (): int => DB::table('production_acceptance_owner_versions')
                ->where('id', $ownerId)
                ->update(['event_type' => 'reversed']),
        );
        $this->assertMutationRejected(
            static fn (): int => DB::table('production_acceptance_owner_members')
                ->where('id', $memberId)
                ->delete(),
        );
    }

    public function test_history_checkpoint_is_created_for_new_organization_and_is_append_only(): void
    {
        $organization = Organization::factory()->create();
        $checkpoint = DB::table('production_acceptance_history_checkpoints')
            ->where('organization_id', $organization->getKey())
            ->first();

        self::assertNotNull($checkpoint);
        self::assertSame(0, (int) $checkpoint->excluded_legacy_act_count);
        self::assertSame(0, (int) $checkpoint->performance_act_watermark_id);
        self::assertSame(0, (int) $checkpoint->owner_version_count);
        self::assertSame(0, (int) $checkpoint->owner_version_watermark_id);
        self::assertSame(0, (int) $checkpoint->owner_member_count);
        self::assertSame(0, (int) $checkpoint->owner_member_watermark_id);
        self::assertSame(0, (int) $checkpoint->event_count);
        self::assertSame(0, (int) $checkpoint->event_watermark_id);
        self::assertSame(0, (int) $checkpoint->backfill_ledger_watermark_id);
        $emptySetHash = hash('sha256', '');
        self::assertSame($emptySetHash, (string) $checkpoint->legacy_act_set_hash);
        self::assertSame($emptySetHash, (string) $checkpoint->owner_version_set_hash);
        self::assertSame($emptySetHash, (string) $checkpoint->owner_member_set_hash);
        self::assertSame($emptySetHash, (string) $checkpoint->event_set_hash);
        self::assertSame($emptySetHash, (string) $checkpoint->backfill_ledger_set_hash);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', (string) $checkpoint->source_hash);

        $this->assertMutationRejected(
            static fn (): int => DB::table('production_acceptance_history_checkpoints')
                ->where('organization_id', $organization->getKey())
                ->update(['excluded_legacy_act_count' => 1]),
        );
        $this->assertMutationRejected(
            static fn (): int => DB::table('production_acceptance_history_checkpoints')
                ->where('organization_id', $organization->getKey())
                ->delete(),
        );
    }

    public static function acceptanceUnitSources(): array
    {
        return ['work_type_unit' => ['work_type'], 'scoped_estimate_unit' => ['estimate'],
            'scoped_schedule_unit' => ['schedule'], 'scoped_schedule_estimate_unit' => ['schedule_estimate'],
            'matching_estimate_and_schedule_units' => ['estimate_and_schedule'],
            'soft_deleted_schedule_unit' => ['schedule', 'task'],
            'soft_deleted_schedule_and_work_estimate_unit' => ['estimate_and_schedule', 'task'],
            'soft_deleted_schedule_parent' => ['schedule', 'schedule'],
            'soft_deleted_work_estimate_item' => ['estimate', 'estimate_item'],
            'soft_deleted_task_estimate_item' => ['schedule_estimate', 'estimate_item'],
            'soft_deleted_work_estimate' => ['estimate', 'estimate'],
            'soft_deleted_task_estimate' => ['schedule_estimate', 'estimate'],
            'soft_deleted_estimate_unit' => ['estimate', 'unit'],
            'soft_deleted_task_unit' => ['schedule', 'unit'],
            'soft_deleted_work_type' => ['work_type', 'work_type'],
            'soft_deleted_work_type_unit' => ['work_type', 'unit']];
    }

    #[DataProvider('acceptanceUnitSources')]
    public function test_real_act_approval_records_acceptance_without_invalidating_the_checkpoint(string $unitSource, ?string $deletedSource = null): void
    {
        [$scope, $actId, $lineId, $projectId, $organizationId] = $this->insertLifecycleOwner(
            approvalDate: null,
            rejectedAt: null,
            currentStatus: ContractPerformanceAct::STATUS_PENDING_APPROVAL,
        );
        $user = User::factory()->create(['current_organization_id' => $organizationId]);
        $source = $this->makeLifecycleLineProjectable($actId, $lineId, $projectId, $organizationId, (int) $user->id, $unitSource);
        if ($deletedSource !== null) {
            $this->deleteAcceptanceUnitSource($source['work_id'], $deletedSource);
        }

        $this->rerunHistoryCheckpointMigration();
        $checkpoint = DB::table('production_acceptance_history_checkpoints')
            ->where('organization_id', $organizationId)
            ->firstOrFail();
        self::assertSame(0, (int) $checkpoint->excluded_legacy_act_count);
        self::assertGreaterThanOrEqual($actId, (int) $checkpoint->performance_act_watermark_id);

        $this->mock(ActingPriceService::class)->shouldNotReceive('resolveLineUnitPrice');
        $this->mock(ActReportNotificationService::class)
            ->shouldReceive('notifyStatusChanged')
            ->once();
        CarbonImmutable::setTestNow(new DateTimeImmutable((string) $checkpoint->completed_at));
        try {
            $approved = app(ActReportWorkflowService::class)->approve(
                ContractPerformanceAct::query()->findOrFail($actId),
                (int) $user->id,
            );
        } finally {
            CarbonImmutable::setTestNow();
        }

        self::assertSame(ContractPerformanceAct::STATUS_APPROVED, (string) $approved->status);
        self::assertSame(1, ProductionAcceptanceEvent::query()
            ->where('organization_id', $organizationId)
            ->where('project_id', $projectId)
            ->where('performance_act_id', $actId)
            ->where('event_type', 'accepted')
            ->count());
        self::assertSame(1, DB::table('production_acceptance_owner_versions')
            ->where('organization_id', $organizationId)
            ->where('performance_act_id', $actId)
            ->where('event_type', 'accepted')
            ->count());
        $event = ProductionAcceptanceEvent::query()->where('performance_act_id', $actId)->sole();
        self::assertSame($source['unit_code'], $event->unit_code);
        self::assertSame('unit_'.$source['unit_id'], $event->conversion_version);
        self::assertSame($source['unit_code'], DB::table('production_acceptance_owner_members')
            ->where('performance_act_id', $actId)->value('unit_code'));

        $coverageDay = (new DateTimeImmutable((string) $checkpoint->completed_at))
            ->setTimezone(new DateTimeZone('UTC'))
            ->setTime(0, 0)
            ->modify('+1 day');
        $query = new ReportQuery(
            (new ReportDefinitionBuilder)
                ->code('accepted_production_progress')
                ->formulaVersion('accepted_production.v1')
                ->sourceSchemaVersion('production_acceptance_events_v2')
                ->payload(),
            $scope,
            new ReportFilterSet([
                'organization_id' => (string) $organizationId,
                'project_id' => (string) $projectId,
                'period_from' => $coverageDay->format('Y-m-d'),
                'period_to' => $coverageDay->format('Y-m-d'),
            ]),
            [],
            $coverageDay->setTime(23, 59, 59),
            'ru-RU',
        );

        $resolved = (new AcceptedProductionHistoryBoundaryResolver)->resolve($scope, $query);

        self::assertSame((string) $checkpoint->source_hash, $resolved->sourceHash);
    }

    #[DataProvider('acceptanceUnitSources')]
    public function test_pivot_only_work_records_the_same_master_unit_without_duplicates(string $unitSource, ?string $deletedSource = null): void
    {
        [, $actId, $lineId, $projectId, $organizationId] = $this->insertLifecycleOwner(
            approvalDate: null, rejectedAt: null, currentStatus: ContractPerformanceAct::STATUS_PENDING_APPROVAL,
        );
        $user = User::factory()->create(['current_organization_id' => $organizationId]);
        $source = $this->makeLifecycleLineProjectable($actId, $lineId, $projectId, $organizationId, (int) $user->id, $unitSource);
        if ($deletedSource !== null) {
            $this->deleteAcceptanceUnitSource($source['work_id'], $deletedSource);
        }
        DB::table('performance_act_lines')->where('id', $lineId)->delete();
        DB::table('performance_act_completed_works')->insert([
            'performance_act_id' => $actId, 'completed_work_id' => $source['work_id'],
            'included_quantity' => '1.000', 'included_amount' => '1000.00', 'currency' => 'RUB',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $recorder = app(ProductionAcceptanceEventRecorder::class);
        $act = ContractPerformanceAct::query()->findOrFail($actId);
        $at = CarbonImmutable::parse('2026-08-01T12:00:00Z');
        $recorder->recordTransition($act, 'pending', 'approved', $at, (int) $user->id);
        $recorder->recordTransition($act, 'pending', 'approved', $at, (int) $user->id);

        $event = ProductionAcceptanceEvent::query()->where('performance_act_id', $actId)->sole();
        self::assertSame('completed_work', $event->source_line_type);
        self::assertSame($source['work_id'], $event->work_id);
        self::assertSame($source['unit_code'], $event->unit_code);
        self::assertSame('unit_'.$source['unit_id'], $event->conversion_version);
        self::assertSame(1, DB::table('production_acceptance_owner_members')->where('performance_act_id', $actId)->count());
        self::assertSame($source['unit_code'], DB::table('production_acceptance_owner_members')
            ->where('performance_act_id', $actId)->value('unit_code'));
    }

    public static function unprovenAcceptanceUnits(): array
    {
        return [
            'text_without_master_identity' => ['missing_unit', 'unit_unavailable'],
            'foreign_estimate_project' => ['foreign_estimate_project', 'scope_unavailable'],
            'foreign_estimate_organization' => ['foreign_estimate_organization', 'scope_unavailable'],
            'foreign_deleted_estimate_organization' => ['foreign_deleted_estimate_organization', 'scope_unavailable'],
            'foreign_schedule_project' => ['foreign_schedule_project', 'scope_unavailable'],
            'foreign_soft_deleted_schedule_project' => ['foreign_deleted_schedule_project', 'scope_unavailable'],
            'foreign_unit_organization' => ['foreign_unit_organization', 'scope_unavailable'],
            'foreign_deleted_unit_organization' => ['foreign_deleted_unit_organization', 'scope_unavailable'],
            'conflicting_schedule_and_estimate_units' => ['conflicting_units', 'unit_unavailable'],
            'conflicting_captured_code' => ['captured_mismatch', 'unit_unavailable'],
        ];
    }

    #[DataProvider('unprovenAcceptanceUnits')]
    public function test_unproven_unit_links_record_a_gap_without_fabricating_acceptance(string $fault, string $reason): void
    {
        [, $actId, $lineId, $projectId, $organizationId] = $this->insertLifecycleOwner(
            approvalDate: null, rejectedAt: null, currentStatus: ContractPerformanceAct::STATUS_PENDING_APPROVAL,
        );
        $user = User::factory()->create(['current_organization_id' => $organizationId]);
        $source = $this->makeLifecycleLineProjectable($actId, $lineId, $projectId, $organizationId, (int) $user->id,
            $fault === 'missing_unit' ? 'work_type' : 'estimate_and_schedule');
        $work = CompletedWork::query()->findOrFail($source['work_id']);
        if ($fault === 'missing_unit') {
            DB::table('work_types')->where('id', $work->work_type_id)->update(['measurement_unit_id' => null]);
        } elseif ($fault === 'captured_mismatch') {
            DB::table('performance_act_lines')->where('id', $lineId)->update(['unit' => 'kg']);
        } elseif ($fault === 'conflicting_units') {
            $otherUnitId = DB::table('measurement_units')->insertGetId(['organization_id' => $organizationId,
                'name' => 'Другая единица', 'short_name' => 'unit-'.$work->id, 'type' => 'work', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('schedule_tasks')->where('id', $work->schedule_task_id)->update(['measurement_unit_id' => $otherUnitId]);
        } elseif (in_array($fault, ['foreign_estimate_project', 'foreign_schedule_project', 'foreign_deleted_schedule_project'], true)) {
            $other = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $organizationId]));
            if ($fault === 'foreign_estimate_project') {
                DB::table('estimates')->where('id', $work->estimateItem->estimate_id)->update(['project_id' => $other->id]);
            } else {
                DB::table('project_schedules')->where('id', $work->scheduleTask->schedule_id)->update(['project_id' => $other->id]);
                if ($fault === 'foreign_deleted_schedule_project') {
                    DB::table('schedule_tasks')->where('id', $work->schedule_task_id)->update(['deleted_at' => now()]);
                }
            }
        } else {
            $foreign = Organization::withoutEvents(fn () => Organization::factory()->create());
            if (in_array($fault, ['foreign_estimate_organization', 'foreign_deleted_estimate_organization'], true)) {
                DB::table('estimates')->where('id', $work->estimateItem->estimate_id)->update(['organization_id' => $foreign->id]);
                if ($fault === 'foreign_deleted_estimate_organization') {
                    DB::table('estimates')->where('id', $work->estimateItem->estimate_id)->update(['deleted_at' => now()]);
                }
            } else {
                DB::table('measurement_units')->where('id', $source['unit_id'])->update(['organization_id' => $foreign->id, 'is_system' => false]);
                if ($fault === 'foreign_deleted_unit_organization') {
                    DB::table('measurement_units')->where('id', $source['unit_id'])->update(['deleted_at' => now()]);
                }
            }
        }
        $this->mock(ActingPriceService::class)->shouldNotReceive('resolveLineUnitPrice');
        $this->mock(ActReportNotificationService::class)->shouldReceive('notifyStatusChanged')->once();
        $approved = app(ActReportWorkflowService::class)->approve(ContractPerformanceAct::query()->findOrFail($actId), (int) $user->id);

        self::assertSame(ContractPerformanceAct::STATUS_APPROVED, (string) $approved->status);
        self::assertSame(0, ProductionAcceptanceEvent::query()->where('performance_act_id', $actId)->count());
        self::assertSame(0, DB::table('production_acceptance_owner_versions')->where('performance_act_id', $actId)->count());
        self::assertSame('runtime_acceptance_'.$reason, DB::table('production_acceptance_backfill_ledger')
            ->where('performance_act_id', $actId)->value('reason'));
    }

    public function test_manual_act_approval_records_a_runtime_gap_without_blocking_the_workflow(): void
    {
        [, $actId, , , $organizationId] = $this->insertLifecycleOwner(
            approvalDate: null,
            rejectedAt: null,
            currentStatus: ContractPerformanceAct::STATUS_PENDING_APPROVAL,
        );
        $user = User::factory()->create(['current_organization_id' => $organizationId]);
        $this->rerunHistoryCheckpointMigration();
        $checkpoint = DB::table('production_acceptance_history_checkpoints')
            ->where('organization_id', $organizationId)
            ->firstOrFail();

        $this->mock(ActingPriceService::class)->shouldNotReceive('resolveLineUnitPrice');
        $this->mock(ActReportNotificationService::class)
            ->shouldReceive('notifyStatusChanged')
            ->once();
        CarbonImmutable::setTestNow(
            (new DateTimeImmutable((string) $checkpoint->completed_at))->modify('+1 day'),
        );
        try {
            $approved = app(ActReportWorkflowService::class)->approve(
                ContractPerformanceAct::query()->findOrFail($actId),
                (int) $user->id,
            );
        } finally {
            CarbonImmutable::setTestNow();
        }

        self::assertSame(ContractPerformanceAct::STATUS_APPROVED, (string) $approved->status);
        self::assertSame(0, ProductionAcceptanceEvent::query()
            ->where('performance_act_id', $actId)
            ->count());
        self::assertSame(0, DB::table('production_acceptance_owner_versions')
            ->where('performance_act_id', $actId)
            ->count());
        self::assertSame('runtime_acceptance_source_identity_unavailable', DB::table(
            'production_acceptance_backfill_ledger',
        )->where('performance_act_id', $actId)->value('reason'));
    }

    public function test_legacy_cross_project_act_scope_gap_does_not_block_approval(): void
    {
        [, $actId, $lineId, $projectId, $organizationId] = $this->insertLifecycleOwner(
            approvalDate: null,
            rejectedAt: null,
            currentStatus: ContractPerformanceAct::STATUS_PENDING_APPROVAL,
        );
        $user = User::factory()->create(['current_organization_id' => $organizationId]);
        $this->makeLifecycleLineProjectable($actId, $lineId, $projectId, $organizationId, (int) $user->id);
        $otherProject = Project::factory()->create(['organization_id' => $organizationId]);
        $workId = (int) DB::table('performance_act_lines')->where('id', $lineId)->value('completed_work_id');
        DB::table('completed_works')->where('id', $workId)->update([
            'project_id' => (int) $otherProject->id,
        ]);

        $this->mock(ActingPriceService::class)->shouldNotReceive('resolveLineUnitPrice');
        $this->mock(ActReportNotificationService::class)
            ->shouldReceive('notifyStatusChanged')
            ->once();

        $approved = app(ActReportWorkflowService::class)->approve(
            ContractPerformanceAct::query()->findOrFail($actId),
            (int) $user->id,
        );

        self::assertSame(ContractPerformanceAct::STATUS_APPROVED, (string) $approved->status);
        self::assertSame(0, ProductionAcceptanceEvent::query()
            ->where('performance_act_id', $actId)
            ->count());
        self::assertSame(0, DB::table('production_acceptance_owner_versions')
            ->where('performance_act_id', $actId)
            ->count());
        self::assertSame('runtime_acceptance_scope_unavailable', DB::table(
            'production_acceptance_backfill_ledger',
        )->where('performance_act_id', $actId)->value('reason'));
    }

    public function test_act_service_rejects_completed_work_from_another_project(): void
    {
        [, $actId, $lineId, $projectId, $organizationId] = $this->insertLifecycleOwner(
            approvalDate: null,
            rejectedAt: null,
            currentStatus: ContractPerformanceAct::STATUS_DRAFT,
        );
        $user = User::factory()->create(['current_organization_id' => $organizationId]);
        $this->makeLifecycleLineProjectable($actId, $lineId, $projectId, $organizationId, (int) $user->id);
        $otherProject = Project::factory()->create(['organization_id' => $organizationId]);
        $workId = (int) DB::table('performance_act_lines')->where('id', $lineId)->value('completed_work_id');
        DB::table('completed_works')->where('id', $workId)->update([
            'project_id' => (int) $otherProject->id,
        ]);

        $act = ContractPerformanceAct::query()->findOrFail($actId);
        $service = app(ContractPerformanceActService::class);
        $availableWorkIds = array_column(
            $service->getAvailableWorksForAct(
                (int) $act->contract_id,
                $organizationId,
                $projectId,
            ),
            'id',
        );

        self::assertNotContains($workId, $availableWorkIds);

        $sync = new ReflectionMethod($service, 'syncCompletedWorks');
        $sync->setAccessible(true);
        try {
            $sync->invoke($service, $act, [
                $workId => [
                    'included_quantity' => '1.0000',
                    'included_amount' => '1000.00',
                    'currency' => 'RUB',
                ],
            ]);
            self::fail('Cross-project completed work must be rejected.');
        } catch (BusinessLogicException $exception) {
            self::assertSame(422, $exception->getCode());
        }

        self::assertSame(0, DB::table('performance_act_completed_works')
            ->where('performance_act_id', $actId)
            ->count());
    }

    public function test_approval_preserves_four_decimal_source_quantity(): void
    {
        [, $actId, $lineId, $projectId, $organizationId] = $this->insertLifecycleOwner(
            approvalDate: null,
            rejectedAt: null,
            currentStatus: ContractPerformanceAct::STATUS_PENDING_APPROVAL,
        );
        $user = User::factory()->create(['current_organization_id' => $organizationId]);
        $this->makeLifecycleLineProjectable($actId, $lineId, $projectId, $organizationId, (int) $user->id);
        $workId = (int) DB::table('performance_act_lines')->where('id', $lineId)->value('completed_work_id');
        DB::table('completed_works')->where('id', $workId)->update([
            'quantity' => '1.2345',
            'completed_quantity' => '1.2345',
        ]);
        DB::table('performance_act_lines')->where('id', $lineId)->update([
            'quantity' => '1.2345',
            'unit_price' => '200.00',
            'amount' => '246.90',
        ]);
        DB::table('contract_performance_acts')->where('id', $actId)->update([
            'amount' => '246.90',
        ]);

        $this->mock(ActingPriceService::class)->shouldNotReceive('resolveLineUnitPrice');
        $this->mock(ActReportNotificationService::class)
            ->shouldReceive('notifyStatusChanged')
            ->once();

        app(ActReportWorkflowService::class)->approve(
            ContractPerformanceAct::query()->findOrFail($actId),
            (int) $user->id,
        );

        $event = ProductionAcceptanceEvent::query()
            ->where('performance_act_id', $actId)
            ->firstOrFail();
        self::assertSame('1.2345', (string) $event->accepted_quantity_delta);
        self::assertSame(20_000, (int) $event->approved_rate_minor);
        self::assertSame(0, DB::table('production_acceptance_backfill_ledger')
            ->where('performance_act_id', $actId)
            ->count());
    }

    public function test_legacy_approval_rejection_records_an_unprovable_reversal_without_blocking_the_workflow(): void
    {
        [, $actId, $lineId, $projectId, $organizationId] = $this->insertLifecycleOwner(
            approvalDate: '2026-07-20',
            rejectedAt: null,
            currentStatus: ContractPerformanceAct::STATUS_PENDING_APPROVAL,
            isApproved: true,
        );
        $user = User::factory()->create(['current_organization_id' => $organizationId]);
        $this->makeLifecycleLineProjectable($actId, $lineId, $projectId, $organizationId, (int) $user->id);
        $this->rerunHistoryCheckpointMigration();
        $checkpoint = DB::table('production_acceptance_history_checkpoints')
            ->where('organization_id', $organizationId)
            ->firstOrFail();

        $this->mock(ActReportNotificationService::class)
            ->shouldReceive('notifyStatusChanged')
            ->once();
        CarbonImmutable::setTestNow(
            (new DateTimeImmutable((string) $checkpoint->completed_at))->modify('+1 day'),
        );
        try {
            $rejected = app(ActReportWorkflowService::class)->reject(
                ContractPerformanceAct::query()->findOrFail($actId),
                (int) $user->id,
                'Не принято',
            );
        } finally {
            CarbonImmutable::setTestNow();
        }

        self::assertSame(ContractPerformanceAct::STATUS_REJECTED, (string) $rejected->status);
        self::assertFalse((bool) $rejected->is_approved);
        self::assertSame(0, ProductionAcceptanceEvent::query()
            ->where('performance_act_id', $actId)
            ->count());
        self::assertSame(0, DB::table('production_acceptance_owner_versions')
            ->where('performance_act_id', $actId)
            ->count());
        self::assertSame('runtime_reversal_legacy_history_unavailable', DB::table(
            'production_acceptance_backfill_ledger',
        )->where('performance_act_id', $actId)->value('reason'));
    }

    public function test_history_checkpoint_captures_existing_source_sets_and_separates_late_backdated_rows(): void
    {
        [$scope, $actId, $lineId, $projectId, $organizationId] = $this->insertLifecycleOwner(
            approvalDate: '2026-07-20',
            rejectedAt: null,
            signedAt: '2099-08-02T12:00:00Z',
        );
        [$ownerId, $ownerMemberId] = $this->insertOwnerVersion(
            $organizationId,
            $projectId,
            'accepted',
            '2026-07-20T00:00:00Z',
            1,
            $actId,
            $lineId,
        );
        $eventId = $this->insertEvent(
            organizationId: $organizationId,
            projectId: $projectId,
            eventType: 'accepted',
            quantity: '1.000',
            recognizedAt: '2026-07-20T00:00:00Z',
            performanceActId: $actId,
            sourceLineId: $lineId,
        );
        $ledgerId = (int) DB::table('production_acceptance_backfill_ledger')->insertGetId([
            'organization_id' => $organizationId,
            'project_id' => $projectId,
            'performance_act_id' => $actId,
            'recognized_at' => '2026-07-20T00:00:00Z',
            'status' => 'unprovable',
            'reason' => 'historical_membership_unprovable',
            'source_hash' => hash('sha256', 'checkpoint-ledger-'.$organizationId),
            'recorded_at' => '2026-08-01T00:00:00Z',
        ]);

        $this->rerunHistoryCheckpointMigration();

        $checkpoint = DB::table('production_acceptance_history_checkpoints')
            ->where('organization_id', $organizationId)
            ->first();

        self::assertNotNull($checkpoint);
        self::assertSame(1, (int) $checkpoint->excluded_legacy_act_count);
        self::assertSame($actId, (int) $checkpoint->performance_act_watermark_id);
        self::assertSame(1, (int) $checkpoint->owner_version_count);
        self::assertSame($ownerId, (int) $checkpoint->owner_version_watermark_id);
        self::assertSame(1, (int) $checkpoint->owner_member_count);
        self::assertSame($ownerMemberId, (int) $checkpoint->owner_member_watermark_id);
        self::assertSame(1, (int) $checkpoint->event_count);
        self::assertSame($eventId, (int) $checkpoint->event_watermark_id);
        self::assertSame(1, (int) $checkpoint->unprovable_legacy_count);
        self::assertSame($ledgerId, (int) $checkpoint->backfill_ledger_watermark_id);
        self::assertSame(
            (string) $checkpoint->event_set_hash,
            $this->acceptanceEventSetHash($organizationId),
        );
        self::assertSame(
            (string) $checkpoint->owner_member_set_hash,
            $this->acceptanceOwnerMemberSetHash($organizationId),
        );
        self::assertSame(
            (string) $checkpoint->source_hash,
            (string) DB::table('production_acceptance_history_checkpoints')
                ->where('organization_id', $organizationId)
                ->selectRaw(<<<'SQL'
encode(sha256(convert_to(jsonb_build_object(
    'organization_id', organization_id,
    'completed_at', to_char(completed_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS.US"Z"'),
    'excluded_legacy_act_count', excluded_legacy_act_count,
    'performance_act_watermark_id', performance_act_watermark_id,
    'legacy_act_set_hash', legacy_act_set_hash,
    'owner_version_count', owner_version_count,
    'owner_version_watermark_id', owner_version_watermark_id,
    'owner_version_set_hash', owner_version_set_hash,
    'owner_member_count', owner_member_count,
    'owner_member_watermark_id', owner_member_watermark_id,
    'owner_member_set_hash', owner_member_set_hash,
    'event_count', event_count,
    'event_watermark_id', event_watermark_id,
    'event_set_hash', event_set_hash,
    'unprovable_legacy_count', unprovable_legacy_count,
    'backfill_ledger_watermark_id', backfill_ledger_watermark_id,
    'backfill_ledger_set_hash', backfill_ledger_set_hash
)::text, 'UTF8')), 'hex') AS calculated_hash
SQL)
                ->value('calculated_hash'),
        );

        $lateEventId = $this->insertEvent(
            organizationId: $organizationId,
            projectId: $projectId,
            eventType: 'accepted',
            quantity: '2.000',
            recognizedAt: '2026-07-01T00:00:00Z',
            performanceActId: $actId,
            sourceLineId: $lineId + 1,
        );
        self::assertGreaterThan((int) $checkpoint->event_watermark_id, $lateEventId);
        self::assertLessThan(
            new DateTimeImmutable((string) $checkpoint->completed_at),
            new DateTimeImmutable('2026-07-01T00:00:00Z'),
        );
        self::assertNotSame(
            (string) $checkpoint->event_set_hash,
            $this->acceptanceEventSetHash($organizationId),
        );
        $lateOwnerMemberId = (int) DB::table('production_acceptance_owner_members')->insertGetId([
            'owner_version_id' => $ownerId,
            'organization_id' => $organizationId,
            'project_id' => $projectId,
            'performance_act_id' => $actId,
            'source_line_type' => 'performance_act_line',
            'source_line_id' => $lineId + 1,
            'work_id' => 78,
            'contractor_id' => 19,
            'unit_code' => 'm3',
            'zone' => 'B',
        ]);
        self::assertGreaterThan((int) $checkpoint->owner_member_watermark_id, $lateOwnerMemberId);
        self::assertNotSame(
            (string) $checkpoint->owner_member_set_hash,
            $this->acceptanceOwnerMemberSetHash($organizationId),
        );
        $coverageDay = (new DateTimeImmutable((string) $checkpoint->completed_at))
            ->setTimezone(new DateTimeZone('UTC'))
            ->setTime(0, 0)
            ->modify('+1 day');
        $definition = (new ReportDefinitionBuilder)
            ->code('accepted_production_progress')
            ->formulaVersion('accepted_production.v1')
            ->sourceSchemaVersion('production_acceptance_events_v2')
            ->payload();
        $query = new ReportQuery(
            $definition,
            $scope,
            new ReportFilterSet([
                'organization_id' => (string) $organizationId,
                'project_id' => (string) $projectId,
                'period_from' => $coverageDay->format('Y-m-d'),
                'period_to' => $coverageDay->format('Y-m-d'),
            ]),
            [],
            $coverageDay->setTime(23, 59, 59),
            'ru-RU',
        );
        $stream = $this->outsideTestTransaction(fn () => (new AcceptedProductionEventUniverse)->stream($scope, $query));

        self::assertSame(0, $stream->eligibleCount());
        self::assertSame(0, $stream->gapCount());
        $foreignOrganization = Organization::factory()->create();
        $this->assertQueryRejected(
            static fn (): int => DB::table('production_acceptance_owner_members')->insertGetId([
                'owner_version_id' => $ownerId,
                'organization_id' => $foreignOrganization->getKey(),
                'project_id' => $projectId,
                'performance_act_id' => $actId,
                'source_line_type' => 'performance_act_line',
                'source_line_id' => $lineId + 2,
                'work_id' => 79,
                'contractor_id' => 19,
                'unit_code' => 'm3',
                'zone' => 'C',
            ]),
            '23514',
        );
        $this->assertMutationRejected(
            static fn (): int => DB::table('production_acceptance_history_checkpoints')
                ->where('organization_id', $organizationId)
                ->delete(),
        );
    }

    public function test_signature_after_as_of_keeps_the_earlier_approval_in_runtime_gap_detection(): void
    {
        [$scope, $actId, , $projectId, $organizationId] = $this->insertLifecycleOwner(
            approvalDate: '2099-01-01',
            rejectedAt: null,
            signedAt: '2099-01-02T12:00:00Z',
        );
        $checkpoint = DB::table('production_acceptance_history_checkpoints')
            ->where('organization_id', $organizationId)
            ->firstOrFail();
        $approvalDay = (new DateTimeImmutable((string) $checkpoint->completed_at))
            ->setTimezone(new DateTimeZone('UTC'))
            ->setTime(0, 0)
            ->modify('+1 day');
        $asOf = $approvalDay->setTime(23, 59, 59);
        DB::table('contract_performance_acts')->where('id', $actId)->update([
            'approval_date' => $approvalDay->format('Y-m-d'),
            'signed_at' => $approvalDay->modify('+1 day')->setTime(12, 0),
        ]);
        $query = new ReportQuery(
            (new ReportDefinitionBuilder)
                ->code('accepted_production_progress')
                ->formulaVersion('accepted_production.v1')
                ->sourceSchemaVersion('production_acceptance_events_v2')
                ->payload(),
            $scope,
            new ReportFilterSet([
                'organization_id' => (string) $organizationId,
                'project_id' => (string) $projectId,
                'period_from' => $approvalDay->format('Y-m-d'),
                'period_to' => $approvalDay->format('Y-m-d'),
            ]),
            [],
            $asOf,
            'ru-RU',
        );

        $gaps = $this->outsideTestTransaction(fn () => iterator_to_array((new AcceptedProductionEventUniverse)->stream($scope, $query)->gaps()));

        self::assertCount(1, $gaps);
        self::assertSame('legacy_owner_unprovable', $gaps[0]['kind']);
        self::assertSame($actId, $gaps[0]['performance_act_id']);
    }

    public function test_history_checkpoint_rejects_preexisting_owner_member_scope_drift(): void
    {
        [, $actId, $lineId, $projectId, $organizationId] = $this->insertLifecycleOwner(
            approvalDate: '2026-07-20',
            rejectedAt: null,
        );
        [$ownerId] = $this->insertOwnerVersion(
            $organizationId,
            $projectId,
            'accepted',
            '2026-07-20T00:00:00Z',
            1,
            $actId,
            $lineId,
        );
        $foreignOrganization = Organization::factory()->create();
        DB::unprepared(
            'DROP TRIGGER IF EXISTS production_acceptance_owner_members_scope_guard '
            .'ON production_acceptance_owner_members',
        );
        DB::unprepared('DROP FUNCTION IF EXISTS production_acceptance_owner_member_scope_guard()');
        DB::table('production_acceptance_owner_members')->insert([
            'owner_version_id' => $ownerId,
            'organization_id' => $foreignOrganization->getKey(),
            'project_id' => $projectId,
            'performance_act_id' => $actId,
            'source_line_type' => 'performance_act_line',
            'source_line_id' => $lineId + 1,
            'work_id' => 78,
            'contractor_id' => 19,
            'unit_code' => 'm3',
            'zone' => 'B',
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('owner member scope drift detected');
        $this->rerunHistoryCheckpointMigration();
    }

    public function test_approval_after_as_of_is_not_part_of_candidate_universe(): void
    {
        [$scope] = $this->insertLifecycleOwner(
            approvalDate: '2026-08-02',
            rejectedAt: null,
        );

        $gaps = (new AcceptedProductionLifecycleCompleteness)->inspect(
            $scope,
            new DateTimeImmutable('2026-07-30T23:59:59Z'),
            collect(),
            ['candidates' => [], 'orphan_events' => [], 'legacy_gaps' => []],
        );

        self::assertSame([], $gaps);
    }

    public function test_rejection_before_as_of_without_reversal_event_is_an_explicit_gap(): void
    {
        [$scope, $actId, $lineId, $projectId, $organizationId] = $this->insertLifecycleOwner(
            approvalDate: '2026-07-20',
            rejectedAt: '2026-07-29T12:00:00Z',
        );
        $this->insertEvent(
            organizationId: $organizationId,
            projectId: $projectId,
            eventType: 'accepted',
            quantity: '2.500',
            recognizedAt: '2026-07-20T00:00:00Z',
            performanceActId: $actId,
            sourceLineId: $lineId,
        );

        $gaps = (new AcceptedProductionLifecycleCompleteness)->inspect(
            $scope,
            new DateTimeImmutable('2026-07-30T23:59:59Z'),
            ProductionAcceptanceEvent::query()->orderBy('transition_version')->get(),
            $this->ownerUniverse($actId, $lineId, 'reversed'),
        );

        self::assertCount(1, $gaps);
        self::assertSame('accepted_production_owner_history_unproven', $gaps[0]['reason']);
    }

    public function test_mutable_current_status_does_not_remove_legacy_candidate_from_gap_detection(): void
    {
        [$scope, $actId, $lineId] = $this->insertLifecycleOwner(
            approvalDate: '2026-07-20',
            rejectedAt: null,
            currentStatus: 'draft',
        );

        $gaps = (new AcceptedProductionLifecycleCompleteness)->inspect(
            $scope,
            new DateTimeImmutable('2026-07-30T23:59:59Z'),
            collect(),
            $this->ownerUniverse($actId, $lineId, 'accepted'),
        );

        self::assertCount(1, $gaps);
        self::assertSame($actId, $gaps[0]['performance_act_id']);
        self::assertSame('accepted_production_owner_history_unproven', $gaps[0]['reason']);
    }

    public function test_signature_after_as_of_does_not_remove_the_earlier_approval_candidate(): void
    {
        [$scope, $actId, $lineId] = $this->insertLifecycleOwner(
            approvalDate: '2026-07-20',
            rejectedAt: null,
            signedAt: '2026-08-02T12:00:00Z',
        );

        $gaps = (new AcceptedProductionLifecycleCompleteness)->inspect(
            $scope,
            new DateTimeImmutable('2026-07-30T23:59:59Z'),
            collect(),
            $this->ownerUniverse($actId, $lineId, 'accepted'),
        );

        self::assertCount(1, $gaps);
        self::assertSame($actId, $gaps[0]['performance_act_id']);
        self::assertSame('accepted_production_owner_history_unproven', $gaps[0]['reason']);
    }

    private function outsideTestTransaction(callable $capture): mixed
    {
        self::assertSame(1, DB::transactionLevel());
        DB::commit();
        try {
            return $capture();
        } finally {
            DB::beginTransaction();
        }
    }

    private function insertEvent(
        int $organizationId,
        int $projectId,
        string $eventType,
        string $quantity,
        string $recognizedAt,
        ?int $reversesEventId = null,
        int $transitionVersion = 1,
        int $performanceActId = 51,
        int $sourceLineId = 91,
    ): int {
        return (int) DB::table('production_acceptance_events')->insertGetId([
            'organization_id' => $organizationId,
            'project_id' => $projectId,
            'contract_id' => 21,
            'performance_act_id' => $performanceActId,
            'source_line_type' => 'performance_act_line',
            'source_line_id' => $sourceLineId,
            'work_id' => 77,
            'task_id' => 31,
            'wbs_code' => '1.2',
            'zone' => 'A',
            'contractor_id' => 19,
            'transition_version' => $transitionVersion,
            'event_type' => $eventType,
            'reverses_event_id' => $reversesEventId,
            'accepted_quantity_delta' => $quantity,
            'planned_quantity' => '10.000',
            'reported_quantity' => '7.500',
            'unit_dimension' => 'volume',
            'unit_code' => 'm3',
            'conversion_version' => 'unit_4',
            'approved_rate_minor' => 125_045,
            'currency' => 'RUB',
            'currency_source' => 'performance_act_line.unit_price@contract_performance_act.currency',
            'recognized_at' => $recognizedAt,
            'actor_id' => 5,
            'source_hash' => hash('sha256', implode(':', [
                $organizationId,
                $projectId,
                $eventType,
                $recognizedAt,
            ])),
            'evidence_refs' => json_encode([
                ['type' => 'performance_act', 'id' => 51, 'project_id' => $projectId],
                ['type' => 'performance_act_line', 'id' => 91, 'project_id' => $projectId],
                ['type' => 'completed_work', 'id' => 77, 'project_id' => $projectId],
            ], JSON_THROW_ON_ERROR),
        ]);
    }

    private function insertLifecycleOwner(
        ?string $approvalDate,
        ?string $rejectedAt,
        ?string $currentStatus = null,
        ?string $signedAt = null,
        ?bool $isApproved = null,
    ): array {
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $contractorId = (int) DB::table('contractors')->insertGetId([
            'organization_id' => $organization->id,
            'name' => 'Lifecycle contractor',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $contractId = (int) DB::table('contracts')->insertGetId([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'contractor_id' => $contractorId,
            'number' => 'LIFECYCLE-'.$project->id,
            'date' => '2026-07-01',
            'total_amount' => 1000,
            'status' => 'active',
            'created_at' => '2026-07-01T00:00:00Z',
            'updated_at' => '2026-07-01T00:00:00Z',
        ]);
        $actId = (int) DB::table('contract_performance_acts')->insertGetId([
            'contract_id' => $contractId,
            'project_id' => $project->id,
            'act_document_number' => 'ACT-'.$project->id,
            'act_date' => '2026-07-20',
            'amount' => 1000,
            'status' => $currentStatus ?? ($rejectedAt === null ? 'approved' : 'rejected'),
            'is_approved' => $isApproved ?? ($currentStatus === null && $rejectedAt === null),
            'approval_date' => $approvalDate,
            'rejected_at' => $rejectedAt,
            'signed_at' => $signedAt,
            'created_at' => '2026-07-10T00:00:00Z',
            'updated_at' => '2026-07-10T00:00:00Z',
        ]);
        $lineId = (int) DB::table('performance_act_lines')->insertGetId([
            'performance_act_id' => $actId,
            'line_type' => 'manual',
            'title' => 'Lifecycle line',
            'quantity' => 1,
            'unit_price' => 1000,
            'amount' => 1000,
            'created_at' => '2026-07-10T00:00:00Z',
            'updated_at' => '2026-07-10T00:00:00Z',
        ]);

        return [
            new ReportScope(
                (int) $organization->id,
                [(int) $organization->id],
                [(int) $project->id],
                [],
                new DateTimeZone('UTC'),
            ),
            $actId,
            $lineId,
            (int) $project->id,
            (int) $organization->id,
        ];
    }

    private function deleteAcceptanceUnitSource(int $workId, string $source): void
    {
        $work = CompletedWork::query()->findOrFail($workId);
        $target = match ($source) {
            'task' => $work->scheduleTask,
            'schedule' => $work->scheduleTask?->schedule,
            'estimate_item' => $work->estimateItem ?? $work->scheduleTask?->estimateItem,
            'estimate' => $work->estimateItem?->estimate ?? $work->scheduleTask?->estimateItem?->estimate,
            'unit' => $work->workType?->measurementUnit ?? $work->estimateItem?->measurementUnit
                ?? $work->scheduleTask?->measurementUnit ?? $work->scheduleTask?->estimateItem?->measurementUnit,
            'work_type' => $work->workType,
        };
        self::assertInstanceOf(\Illuminate\Database\Eloquent\Model::class, $target);
        DB::table($target->getTable())->where('id', $target->getKey())->update(['deleted_at' => now()]);
    }

    private function makeLifecycleLineProjectable(
        int $actId,
        int $lineId,
        int $projectId,
        int $organizationId,
        int $userId,
        string $unitSource = 'work_type',
    ): array {
        $unitCode = $unitSource === 'work_type' ? 'm3' : 'м²';
        $unitId = DB::table('measurement_units')->where('organization_id', $organizationId)
            ->where('short_name', $unitCode)->whereNull('deleted_at')->value('id');
        $unitId = $unitId === null ? (int) DB::table('measurement_units')->insertGetId([
            'organization_id' => $organizationId,
            'name' => $unitSource === 'work_type' ? 'Cubic meter' : 'Квадратный метр',
            'short_name' => $unitCode,
            'type' => $unitSource === 'work_type' ? 'work' : 'material',
            'created_at' => now(),
            'updated_at' => now(),
        ]) : (int) $unitId;
        $workTypeId = (int) DB::table('work_types')->insertGetId([
            'organization_id' => $organizationId,
            'name' => 'Concrete',
            'code' => 'CONCRETE-'.$projectId,
            'measurement_unit_id' => $unitSource === 'work_type' ? $unitId : null,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $work = CompletedWork::query()->create([
            'organization_id' => $organizationId,
            'project_id' => $projectId,
            'contract_id' => DB::table('contract_performance_acts')->where('id', $actId)->value('contract_id'),
            'work_type_id' => $workTypeId,
            'user_id' => $userId,
            'quantity' => '1.000',
            'completed_quantity' => '1.000',
            'price' => '1000.00',
            'total_amount' => '1000.00',
            'completion_date' => '2026-08-01',
            'status' => 'confirmed',
        ]);
        $estimateItem = null;
        if (in_array($unitSource, ['estimate', 'schedule_estimate', 'estimate_and_schedule'], true)) {
            $estimate = \App\Models\Estimate::withoutEvents(fn () => \App\Models\Estimate::query()->create([
                'organization_id' => $organizationId, 'project_id' => $projectId, 'number' => 'ACCEPTANCE-UNIT-'.$work->id,
                'name' => 'Смета работы', 'type' => 'local', 'status' => 'draft', 'estimate_date' => '2026-08-01',
                'total_amount' => 1000, 'total_amount_with_vat' => 1000, 'vat_rate' => 0,
            ]));
            $section = \App\Models\EstimateSection::withoutEvents(fn () => \App\Models\EstimateSection::query()->create([
                'estimate_id' => $estimate->id, 'section_number' => '1', 'full_section_number' => '1',
                'name' => 'Работы', 'sort_order' => 1, 'section_total_amount' => 1000,
            ]));
            $estimateItem = \App\Models\EstimateItem::withoutEvents(fn () => \App\Models\EstimateItem::query()->create([
                'estimate_id' => $estimate->id, 'estimate_section_id' => $section->id, 'position_number' => '1.1',
                'name' => 'Работа по смете', 'item_type' => 'work', 'measurement_unit_id' => $unitId,
                'quantity' => 1, 'quantity_total' => 1, 'unit_price' => 1000, 'total_amount' => 1000,
            ]));
            if ($unitSource !== 'schedule_estimate') {
                DB::table('completed_works')->where('id', $work->id)->update(['estimate_item_id' => $estimateItem->id]);
            }
        }
        if (in_array($unitSource, ['schedule', 'schedule_estimate', 'estimate_and_schedule'], true)) {
            $schedule = \App\Models\ProjectSchedule::withoutEvents(fn () => \App\Models\ProjectSchedule::query()->create([
                'organization_id' => $organizationId, 'project_id' => $projectId, 'created_by_user_id' => $userId,
                'name' => 'График работы', 'planned_start_date' => '2026-08-01', 'planned_end_date' => '2026-08-02',
            ]));
            $task = \App\Models\ScheduleTask::withoutEvents(fn () => \App\Models\ScheduleTask::query()->create([
                'schedule_id' => $schedule->id, 'organization_id' => $organizationId, 'created_by_user_id' => $userId,
                'estimate_item_id' => $estimateItem?->id, 'measurement_unit_id' => $unitSource === 'schedule_estimate' ? null : $unitId,
                'name' => 'Работа графика', 'task_type' => 'task', 'planned_start_date' => '2026-08-01',
                'planned_end_date' => '2026-08-02', 'planned_duration_days' => 1, 'status' => 'completed', 'progress_percent' => 100,
            ]));
            DB::table('completed_works')->where('id', $work->id)->update(['schedule_task_id' => $task->id]);
        }
        DB::table('performance_act_lines')->where('id', $lineId)->update([
            'completed_work_id' => (int) $work->id,
            'line_type' => 'completed_work',
            'unit' => $unitCode,
            'unit_price' => '1000.00',
            'amount' => '1000.00',
            'currency' => 'RUB',
        ]);
        DB::table('contract_performance_acts')->where('id', $actId)->update([
            'amount' => '1000.00',
            'currency' => 'RUB',
        ]);

        return ['work_id' => (int) $work->id, 'unit_id' => $unitId, 'unit_code' => $unitCode];
    }

    private function ownerUniverse(int $actId, int $lineId, string $eventType): array
    {
        return [
            'candidates' => [[
                'event_type' => $eventType,
                'owner_version_id' => 1,
                'performance_act_id' => $actId,
                'source_line_id' => $lineId,
                'source_line_type' => 'performance_act_line',
            ]],
            'legacy_gaps' => [],
            'orphan_events' => [],
        ];
    }

    private function insertOwnerVersion(
        int $organizationId,
        int $projectId,
        string $eventType,
        string $effectiveAt,
        int $version,
        int $performanceActId = 51,
        int $sourceLineId = 91,
    ): array {
        $member = [
            'contractor_id' => 19,
            'source_line_id' => $sourceLineId,
            'source_line_type' => 'performance_act_line',
            'unit_code' => 'm3',
            'work_id' => 77,
            'zone' => 'A',
        ];
        $ownerId = (int) DB::table('production_acceptance_owner_versions')->insertGetId([
            'organization_id' => $organizationId,
            'project_id' => $projectId,
            'contract_id' => 21,
            'performance_act_id' => $performanceActId,
            'version' => $version,
            'event_type' => $eventType,
            'effective_at' => $effectiveAt,
            'source_event_id' => implode(':', [
                'test-owner',
                $organizationId,
                $projectId,
                $version,
            ]),
            'source_hash' => hash('sha256', implode(':', [
                $organizationId,
                $projectId,
                $eventType,
                $effectiveAt,
                $version,
            ])),
            'members' => json_encode([$member], JSON_THROW_ON_ERROR),
        ]);
        $memberId = (int) DB::table('production_acceptance_owner_members')->insertGetId([
            'owner_version_id' => $ownerId,
            'organization_id' => $organizationId,
            'project_id' => $projectId,
            'performance_act_id' => $performanceActId,
            'source_line_type' => $member['source_line_type'],
            'source_line_id' => $member['source_line_id'],
            'work_id' => $member['work_id'],
            'contractor_id' => $member['contractor_id'],
            'unit_code' => $member['unit_code'],
            'zone' => $member['zone'],
        ]);

        return [$ownerId, $memberId];
    }

    private function assertMutationRejected(callable $mutation): void
    {
        $this->assertQueryRejected($mutation, '55000');
    }

    private function assertQueryRejected(callable $mutation, string $expectedCode): void
    {
        try {
            DB::transaction($mutation);
            self::fail('Expected production acceptance source mutation to be rejected.');
        } catch (QueryException $exception) {
            self::assertSame($expectedCode, $exception->getCode());
        }
    }

    private function rerunHistoryCheckpointMigration(): void
    {
        DB::unprepared(
            'DROP TRIGGER IF EXISTS most_seed_production_acceptance_history_checkpoint_v1 ON organizations',
        );
        DB::unprepared('DROP FUNCTION IF EXISTS most_seed_production_acceptance_history_checkpoint_v1()');
        DB::unprepared(
            'DROP TRIGGER IF EXISTS production_acceptance_owner_members_scope_guard '
            .'ON production_acceptance_owner_members',
        );
        DB::unprepared('DROP FUNCTION IF EXISTS production_acceptance_owner_member_scope_guard()');
        DB::unprepared('DROP TABLE production_acceptance_history_checkpoints');

        $migration = require dirname(__DIR__, 4)
            .'/database/migrations/2026_08_06_000150_create_production_acceptance_history_checkpoints.php';
        $migration->up();
    }

    private function acceptanceEventSetHash(int $organizationId): string
    {
        return (string) DB::table('production_acceptance_events')
            ->where('organization_id', $organizationId)
            ->selectRaw(<<<'SQL'
encode(sha256(convert_to(COALESCE(string_agg(
    encode(sha256(convert_to(jsonb_build_array(id, source_hash)::text, 'UTF8')), 'hex'),
    '' ORDER BY id
), ''), 'UTF8')), 'hex') AS set_hash
SQL)
            ->value('set_hash');
    }

    private function acceptanceOwnerMemberSetHash(int $organizationId): string
    {
        return (string) DB::table('production_acceptance_owner_members as member')
            ->join(
                'production_acceptance_owner_versions as owner',
                'owner.id',
                '=',
                'member.owner_version_id',
            )
            ->where('owner.organization_id', $organizationId)
            ->selectRaw(<<<'SQL'
encode(sha256(convert_to(COALESCE(string_agg(
    encode(sha256(convert_to(jsonb_build_object(
        'id', member.id,
        'owner_version_id', member.owner_version_id,
        'owner_organization_id', owner.organization_id,
        'owner_project_id', owner.project_id,
        'owner_performance_act_id', owner.performance_act_id,
        'member_organization_id', member.organization_id,
        'member_project_id', member.project_id,
        'member_performance_act_id', member.performance_act_id,
        'source_line_type', member.source_line_type,
        'source_line_id', member.source_line_id,
        'work_id', member.work_id,
        'contractor_id', member.contractor_id,
        'unit_code', member.unit_code,
        'zone', member.zone
    )::text, 'UTF8')), 'hex'),
    '' ORDER BY member.id
), ''), 'UTF8')), 'hex') AS set_hash
SQL)
            ->value('set_hash');
    }
}
