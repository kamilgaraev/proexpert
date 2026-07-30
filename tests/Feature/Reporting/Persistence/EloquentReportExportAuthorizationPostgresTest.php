<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting\Persistence;

use App\BusinessModules\Core\Reporting\Application\Access\ReportAuthorizationFence;
use App\BusinessModules\Core\Reporting\Application\Access\ReportAuthorizationSubject;
use App\BusinessModules\Core\Reporting\Application\Access\ReportCatalogAuthorization;
use App\BusinessModules\Core\Reporting\Application\Access\ReportExecutionContextFactory;
use App\BusinessModules\Core\Reporting\Application\Audit\ReportTransitionAudit;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\CurrentReportScopeAuthorizer;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportDispatchIntentStore;
use App\BusinessModules\Core\Reporting\Application\Dispatch\ReportDispatchAggregate;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Application\Execution\CurrentReportAuthorization;
use App\BusinessModules\Core\Reporting\Application\Execution\CurrentReportAuthorizationTarget;
use App\BusinessModules\Core\Reporting\Application\Execution\ReportRunExportSource;
use App\BusinessModules\Core\Reporting\Application\Input\CreateReportExportData;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDownloadLink;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportFilterSet;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportOutputClassification;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportProvenance;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuality;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportResult;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportResultMetadata;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSourceRef;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportVisibility;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportWindowSort;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportDataClassification;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportFreshnessStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportOperation;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportQualityStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportReconciliationStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSnapshotClassification;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSortDirection;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\IdempotencyKey;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\EloquentReportExportStore;
use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\Models\ReportExportRecord;
use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\Models\ReportRunRecord;
use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\ReportExportAuthorizationIdentity;
use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\ReportExportHydrator;
use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\ReportRunAuthorizationIdentity;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use App\Models\Organization;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use ReflectionClass;
use RuntimeException;
use Tests\Support\Reporting\FakeReportExecutionClock;
use Tests\Support\Reporting\ReportDefinitionBuilder;
use Tests\Support\Reporting\ReportExecutionContextBuilder;
use Tests\Support\Reporting\ReportRunBuilder;
use Tests\TestCase;

#[Group('postgresql')]
final class EloquentReportExportAuthorizationPostgresTest extends TestCase
{
    use RefreshDatabase;

    private DateTimeImmutable $now;

    private ReportExecutionContext $context;

    private ReportRunExportSource $source;

    private FakeReportExecutionClock $clock;

    protected function beforeRefreshingDatabase(): void
    {
        self::assertSame(
            'pgsql',
            DB::connection()->getDriverName(),
            'Export authorization transaction tests require isolated PostgreSQL.',
        );
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->now = new DateTimeImmutable('2026-07-29T10:00:00.000000Z');
        $this->clock = new FakeReportExecutionClock($this->now);
        $this->source = $this->source();
        $scope = $this->source->query->scope;
        $baseContext = (new ReportExecutionContextBuilder)->build();
        $this->context = (new ReportExecutionContextBuilder)
            ->scope($scope)
            ->authorization(new \App\BusinessModules\Core\Reporting\Domain\DTO\AuthorizationDecisionContext(
                'http',
                $scope->organizationId,
                $scope->holdingOrganizationIds,
                $scope->projectIds,
                $scope->resources,
                $scope->timezone,
                'report-test',
                null,
            ))
            ->actor($baseContext->actor)
            ->build();
        Organization::factory()->create(['id' => 1]);
        $this->insertReadyRun();
    }

    public function test_create_locks_ready_parent_and_persists_only_after_complete_fence(): void
    {
        $subject = new ReportAuthorizationSubject(
            ReportDispatchAggregate::RUN,
            $this->source->run->id,
            $this->source->query->definition,
            $this->context->scope,
            $this->source->snapshot,
            null,
            null,
            ReportRunAuthorizationIdentity::fromRecord(
                ReportRunRecord::query()->findOrFail($this->source->run->id),
            ),
        );
        $data = new CreateReportExportData(
            'csv',
            ['audit', 'secret'],
            new ReportWindowSort('audit', ReportSortDirection::ASC),
            'ru-RU',
            new DateTimeZone('UTC'),
        );
        $fence = $this->fence($subject, [
            ReportOperation::EXPORT,
            ReportOperation::VIEW_SENSITIVE,
            ReportOperation::VIEW_AUDIT,
        ]);

        $created = $this->store()->createOrReuse(
            $this->context,
            $this->source,
            $data,
            new IdempotencyKey('postgres-create-export'),
            $fence,
        );

        self::assertSame('queued', $created->status->value);
        self::assertSame(
            1,
            DB::table('report_exports')
                ->where('id', $created->id)
                ->where('run_id', $this->source->run->id)
                ->where('status', 'queued')
                ->count(),
        );
    }

    #[DataProvider('runImmutableMutationProvider')]
    public function test_create_rejects_each_locked_immutable_run_identity_mutation(
        string $attribute,
        mixed $mutatedValue,
    ): void {
        $run = ReportRunRecord::query()->findOrFail($this->source->run->id);
        $subject = new ReportAuthorizationSubject(
            ReportDispatchAggregate::RUN,
            $this->source->run->id,
            $this->source->query->definition,
            $this->context->scope,
            $this->source->snapshot,
            null,
            null,
            ReportRunAuthorizationIdentity::fromRecord($run),
        );
        $fence = $this->fence($subject, [
            ReportOperation::EXPORT,
            ReportOperation::VIEW_SENSITIVE,
            ReportOperation::VIEW_AUDIT,
        ]);
        DB::table('report_runs')
            ->where('id', $this->source->run->id)
            ->update([$attribute => $mutatedValue]);

        try {
            $this->store()->createOrReuse(
                $this->context,
                $this->source,
                new CreateReportExportData(
                    'csv',
                    ['audit', 'secret'],
                    new ReportWindowSort('audit', ReportSortDirection::ASC),
                    'ru-RU',
                    new DateTimeZone('UTC'),
                ),
                new IdempotencyKey("postgres-mutated-run-{$attribute}"),
                $fence,
            );
            self::fail("Immutable run identity mutation {$attribute} was accepted.");
        } catch (\App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException $exception) {
            self::assertSame(
                \App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode::REPORT_NOT_FOUND,
                $exception->errorCode,
            );
        }

        self::assertSame(0, DB::table('report_exports')->count());
    }

    public static function runImmutableMutationProvider(): iterable
    {
        yield 'definition snapshot hash' => ['definition_snapshot_hash', str_repeat('4', 64)];
        yield 'input fingerprint' => ['input_fingerprint', str_repeat('5', 64)];
        yield 'saved view id' => ['saved_view_id', '01J00000000000000000000011'];
        yield 'saved view revision' => ['saved_view_revision', 2];
        yield 'saved view hash' => ['saved_view_hash', str_repeat('6', 64)];
    }

    public function test_cancel_requires_complete_locked_record_fence_and_rolls_back_on_missing_operation(): void
    {
        $exportId = '01J00000000000000000000001';
        $this->insertExport($exportId, 'queued', $this->now->modify('+10 minutes'));
        $record = ReportExportRecord::query()->findOrFail($exportId);
        $subject = $this->subject($record);
        $incomplete = $this->fence($subject, [ReportOperation::EXPORT]);

        try {
            $this->store()->cancel($this->context, $exportId, $this->now, $incomplete);
            self::fail('Expected missing sensitive and audit operations to reject cancellation.');
        } catch (\App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException $exception) {
            self::assertSame(
                \App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode::REPORT_SCOPE_FORBIDDEN,
                $exception->errorCode,
            );
        }
        self::assertSame('queued', DB::table('report_exports')->where('id', $exportId)->value('status'));

        DB::table('report_exports')->where('id', $exportId)->update([
            'status' => 'running',
            'execution_lease_token' => '00000000-0000-4000-8000-000000000001',
            'execution_heartbeat_at' => $this->now,
            'execution_lease_expires_at' => $this->now->modify('+960 seconds'),
            'updated_at' => $this->now,
        ]);
        $complete = $this->fence($subject, [
            ReportOperation::EXPORT,
            ReportOperation::VIEW_SENSITIVE,
            ReportOperation::VIEW_AUDIT,
        ]);
        $cancelled = $this->store()->cancel($this->context, $exportId, $this->now, $complete);

        self::assertSame('cancelled', $cancelled->status->value);
        self::assertSame('cancelled', DB::table('report_exports')->where('id', $exportId)->value('status'));
    }

    public function test_download_uses_post_lock_clock_bounded_ttl_and_rolls_back_callback_writes(): void
    {
        $exportId = '01J00000000000000000000002';
        $this->insertExport($exportId, 'ready', $this->now->modify('+90 seconds'));
        $record = ReportExportRecord::query()->findOrFail($exportId);
        $fence = $this->fence($this->subject($record), [
            ReportOperation::DOWNLOAD,
            ReportOperation::VIEW_SENSITIVE,
            ReportOperation::VIEW_AUDIT,
        ], function (): void {
            $this->clock->advance(new DateInterval('PT31S'));
        });
        $ttl = null;
        $link = $this->store()->withReadyDownload(
            $this->context,
            $exportId,
            300,
            $fence,
            function ($export, int $boundedTtl) use (&$ttl): ReportDownloadLink {
                $ttl = $boundedTtl;
                $issuedAt = $this->clock->now();

                return new ReportDownloadLink(
                    'https://storage.example.test/report.csv',
                    (string) $export->versionId,
                    $issuedAt,
                    $issuedAt->modify("+{$boundedTtl} seconds"),
                );
            },
        );

        self::assertSame(59, $ttl);
        self::assertSame($this->now->modify('+90 seconds'), $link->expiresAt);

        try {
            $this->store()->withReadyDownload(
                $this->context,
                $exportId,
                300,
                $fence,
                function () use ($exportId): never {
                    DB::table('report_exports')->where('id', $exportId)->update(['artifact_etag' => 'mutated']);

                    throw new RuntimeException('presign failed');
                },
            );
            self::fail('Expected callback failure.');
        } catch (RuntimeException) {
        }

        self::assertSame(
            'etag-1',
            DB::table('report_exports')->where('id', $exportId)->value('artifact_etag'),
        );
    }

    #[DataProvider('exportImmutableMutationProvider')]
    public function test_cancel_rejects_each_locked_immutable_export_identity_mutation(
        string $attribute,
        mixed $mutatedValue,
    ): void {
        $exportId = '01J00000000000000000000003';
        $this->insertExport($exportId, 'queued', $this->now->modify('+10 minutes'));
        $record = ReportExportRecord::query()->findOrFail($exportId);
        $fence = $this->fence($this->subject($record), [
            ReportOperation::EXPORT,
            ReportOperation::VIEW_SENSITIVE,
            ReportOperation::VIEW_AUDIT,
        ]);
        DB::table('report_exports')->where('id', $exportId)->update([
            $attribute => $mutatedValue,
            'updated_at' => $this->now,
        ]);

        try {
            $this->store()->cancel($this->context, $exportId, $this->now, $fence);
            self::fail("Immutable export identity mutation {$attribute} was accepted.");
        } catch (\App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException $exception) {
            self::assertSame(
                \App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode::REPORT_NOT_FOUND,
                $exception->errorCode,
            );
        }

        self::assertSame('queued', DB::table('report_exports')->where('id', $exportId)->value('status'));
    }

    public static function exportImmutableMutationProvider(): iterable
    {
        yield 'export hash' => ['export_hash', str_repeat('1', 64)];
        yield 'input fingerprint' => ['input_fingerprint', str_repeat('2', 64)];
        yield 'render locale' => ['locale', 'en-US'];
    }

    private function store(): EloquentReportExportStore
    {
        return new EloquentReportExportStore(
            $this->clock,
            new class implements ReportTransitionAudit
            {
                public function append(
                    string $eventId,
                    string $eventType,
                    ReportExecutionContext $context,
                    array $subject,
                    DateTimeImmutable $occurredAt,
                ): void {}
            },
            new ReportExportHydrator,
            new class implements ReportDispatchIntentStore
            {
                public function addRunIntent(
                    string $runId,
                    int $organizationId,
                    string $eventKey,
                    DateTimeImmutable $occurredAt,
                ): void {}

                public function addExportIntent(
                    string $exportId,
                    int $organizationId,
                    string $eventKey,
                    DateTimeImmutable $occurredAt,
                ): void {}

                public function claimDue(
                    int $limit,
                    DateTimeImmutable $now,
                    DateTimeImmutable $leasedUntil,
                    string $leaseToken,
                ): array {
                    return [];
                }

                public function markPublished(
                    string $intentId,
                    string $leaseToken,
                    DateTimeImmutable $occurredAt,
                ): void {}

                public function markPublicationFailed(
                    string $intentId,
                    string $leaseToken,
                    ReportErrorCode $errorCode,
                    DateTimeImmutable $occurredAt,
                    DateTimeImmutable $nextAttemptAt,
                ): void {}

                public function reclaimExpiredLeases(int $limit, DateTimeImmutable $occurredAt): int
                {
                    return 0;
                }
            },
            3600,
            1000,
            \Tests\Support\Reporting\ReportRuntimeFixture::configuration(),
        );
    }

    private function insertReadyRun(): void
    {
        $source = $this->source;
        $definitionSnapshot = [
            'output_classification' => [
                'totals_sensitive' => $source->outputClassification->totalsSensitive,
                'totals_audit' => $source->outputClassification->totalsAudit,
                'provenance_audit' => $source->outputClassification->provenanceAudit,
            ],
        ];
        $resourceColumn = Schema::hasColumn('report_runs', 'scope_resources')
            ? 'scope_resources'
            : 'scope_resource_ids';

        DB::table('report_runs')->insert([
            'id' => '01J00000000000000000000000',
            'organization_id' => 1,
            'requester_actor_id' => 1,
            'report_code' => 'report',
            'status' => 'ready',
            'definition_hash' => $source->query->definition->definitionHash->value,
            'definition_snapshot_hash' => str_repeat('1', 64),
            'query_hash' => $source->query->queryHash->value,
            'source_hash' => $source->snapshot->sourceHash->value,
            'result_hash' => $source->resultHash->value,
            'idempotency_key_hash' => str_repeat('2', 64),
            'input_fingerprint' => str_repeat('3', 64),
            'saved_view_id' => '01J00000000000000000000010',
            'saved_view_revision' => 1,
            'saved_view_hash' => str_repeat('7', 64),
            'contract_version' => $source->contractVersion,
            'formula_version' => $source->formulaVersion,
            'source_schema_version' => $source->sourceSchemaVersion,
            'renderer_version' => $source->rendererVersion,
            'definition_snapshot' => json_encode($definitionSnapshot, JSON_THROW_ON_ERROR),
            'canonical_query_json' => '{}',
            'scope_holding_organization_ids' => '[1]',
            'scope_project_ids' => '[]',
            $resourceColumn => '[]',
            'scope_timezone' => $source->query->scope->timezone->getName(),
            'filters' => '[]',
            'comparison' => '[]',
            'as_of' => $this->now,
            'locale' => 'ru-RU',
            'snapshot_classification' => $source->snapshot->classification->value,
            'data_classification' => $source->dataClassification->value,
            'sensitive_column_ids' => '["secret"]',
            'audit_column_ids' => '["audit"]',
            'progress' => 100,
            'row_count' => 2,
            'result_metadata' => '[]',
            'totals' => '[]',
            'freshness' => 'fresh',
            'quality' => '[]',
            'provenance' => '[]',
            'row_schema' => '[]',
            'capabilities' => '[]',
            'snapshot_kind' => $source->snapshot->kind,
            'snapshot_id' => $source->snapshot->id,
            'snapshot_generated_at' => $source->snapshot->generatedAt,
            'snapshot_stale_at' => null,
            'snapshot_watermarks' => '[]',
            'queued_at' => $this->now->modify('-2 minutes'),
            'started_at' => $this->now->modify('-90 seconds'),
            'ready_at' => $this->now->modify('-1 minute'),
            'created_at' => $this->now->modify('-2 minutes'),
            'updated_at' => $this->now->modify('-1 minute'),
            'expires_at' => $this->now->modify('+1 hour'),
        ]);
    }

    private function source(): ReportRunExportSource
    {
        $definitionHash = new Sha256Hash(str_repeat('a', 64));
        $classification = new ReportOutputClassification(
            ReportDataClassification::SENSITIVE,
            ['secret'],
            ['audit'],
            false,
            true,
            true,
        );
        $definition = (new ReportDefinitionBuilder)
            ->definitionHash($definitionHash)
            ->columns([['id' => 'audit'], ['id' => 'secret']])
            ->sorts([['id' => 'audit']])
            ->outputClassification($classification)
            ->payload();
        $scope = new ReportScope(1, [1], [], [], new DateTimeZone('UTC'));
        $query = new ReportQuery(
            $definition,
            $scope,
            new ReportFilterSet([]),
            [],
            $this->now,
            'ru-RU',
        );
        $sourceHash = new Sha256Hash(str_repeat('c', 64));
        $generatedAt = $this->now->modify('-1 minute');
        $snapshot = new ReportSnapshotRef(
            'materialized',
            'snapshot-1',
            $scope,
            $definitionHash,
            $definition->formulaVersion,
            $sourceHash,
            $generatedAt,
            null,
            [],
            ReportSnapshotClassification::OPERATIONAL,
            null,
        );
        $quality = new ReportQuality(
            ReportQualityStatus::COMPLETE,
            null,
            [],
            0,
            ReportReconciliationStatus::MATCHED,
            [],
            [],
        );
        $provenance = new ReportProvenance(
            'system',
            [new ReportSourceRef('system', 'table', 'snapshot_1', '1', 'wm_1', 2, $sourceHash)],
            $sourceHash,
            null,
        );
        $metadata = new ReportResultMetadata($snapshot, 2, $generatedAt, null);
        $result = new ReportResult(
            $metadata,
            [],
            ReportFreshnessStatus::FRESH,
            $quality,
            $provenance,
            [['id' => 'audit'], ['id' => 'secret']],
            [],
        );
        $run = (new ReportRunBuilder)
            ->definitionHash($definitionHash)
            ->queryHash($query->queryHash)
            ->sourceHash($sourceHash)
            ->rowCount(2)
            ->resultMetadata($metadata)
            ->totals([])
            ->freshness(ReportFreshnessStatus::FRESH)
            ->quality($quality)
            ->provenance($provenance)
            ->updatedAt($generatedAt)
            ->readyAt($generatedAt)
            ->expiresAt($this->now->modify('+1 hour'))
            ->ready();
        $projection = (new ReflectionClass(ReportRunExportSource::class))
            ->getMethod('resultProjection')
            ->invoke(null, $result);
        $resultHash = new Sha256Hash(hash('sha256', CanonicalJson::encode($projection)));

        return new ReportRunExportSource(
            $run,
            $query,
            $result,
            $resultHash,
            $snapshot,
            ReportDataClassification::SENSITIVE,
            $classification,
            '1',
            '1',
            '1',
            '1',
        );
    }

    private function insertExport(
        string $id,
        string $status,
        DateTimeImmutable $expiresAt,
    ): void {
        $ready = $status === 'ready';

        DB::table('report_exports')->insert([
            'id' => $id,
            'run_id' => '01J00000000000000000000000',
            'organization_id' => 1,
            'requester_actor_id' => 1,
            'report_code' => 'report',
            'status' => $status,
            'definition_hash' => $this->source->query->definition->definitionHash->value,
            'query_hash' => $this->source->query->queryHash->value,
            'source_hash' => $this->source->snapshot->sourceHash->value,
            'result_hash' => $this->source->resultHash->value,
            'export_hash' => str_repeat('e', 64),
            'idempotency_key_hash' => hash('sha256', 'idempotency-'.$id),
            'input_fingerprint' => str_repeat('f', 64),
            'scope_holding_organization_ids' => '[1]',
            'scope_project_ids' => '[]',
            'scope_resources' => '[]',
            'scope_timezone' => 'UTC',
            'snapshot_kind' => 'materialized',
            'snapshot_id' => 'snapshot-1',
            'snapshot_generated_at' => $this->now->modify('-1 minute'),
            'snapshot_stale_at' => null,
            'snapshot_watermarks' => '[]',
            'snapshot_classification' => 'operational',
            'data_classification' => 'sensitive',
            'sensitive_column_ids' => '["secret"]',
            'audit_column_ids' => '["audit"]',
            'totals_sensitive' => false,
            'totals_audit' => true,
            'provenance_audit' => true,
            'contract_version' => '1',
            'formula_version' => '1',
            'source_schema_version' => '1',
            'renderer_version' => '1',
            'format' => 'csv',
            'selected_columns' => '["audit","secret"]',
            'sort_field' => 'audit',
            'sort_direction' => 'asc',
            'locale' => 'ru-RU',
            'render_timezone' => 'UTC',
            'artifact_path' => $ready ? 'org-1/reports/report.csv' : null,
            'artifact_version_id' => $ready ? 'version-1' : null,
            'artifact_etag' => $ready ? 'etag-1' : null,
            'artifact_mime' => $ready ? 'text/csv' : null,
            'artifact_checksum' => $ready ? str_repeat('9', 64) : null,
            'artifact_size_bytes' => $ready ? 128 : null,
            'row_count' => $ready ? 2 : null,
            'queued_at' => $this->now->modify('-2 minutes'),
            'ready_at' => $ready ? $this->now->modify('-1 minute') : null,
            'created_at' => $this->now->modify('-2 minutes'),
            'updated_at' => $this->now->modify('-1 minute'),
            'expires_at' => $expiresAt,
        ]);
    }

    private function subject(ReportExportRecord $record): ReportAuthorizationSubject
    {
        $definition = (new ReportDefinitionBuilder)->payload();
        $snapshot = new ReportSnapshotRef(
            'materialized',
            'snapshot-1',
            $this->context->scope,
            $definition->definitionHash,
            $definition->formulaVersion,
            new Sha256Hash(str_repeat('c', 64)),
            $this->now->modify('-1 minute'),
            null,
            [],
            ReportSnapshotClassification::OPERATIONAL,
            null,
        );

        return new ReportAuthorizationSubject(
            ReportDispatchAggregate::EXPORT,
            (string) $record->id,
            $definition,
            $this->context->scope,
            $snapshot,
            (string) $record->run_id,
            $record->artifact_checksum === null
                ? null
                : new Sha256Hash((string) $record->artifact_checksum),
            ReportExportAuthorizationIdentity::fromRecord($record),
        );
    }

    /**
     * @param  list<ReportOperation>  $operations
     */
    private function fence(
        ReportAuthorizationSubject $subject,
        array $operations,
        mixed $onFirstAuthorization = null,
    ): ReportAuthorizationFence {
        return new ReportAuthorizationFence(
            $subject,
            $operations,
            new class($this->context, $onFirstAuthorization) implements CurrentReportScopeAuthorizer
            {
                private bool $authorized = false;

                public function __construct(
                    private readonly ReportExecutionContext $context,
                    private readonly mixed $onFirstAuthorization,
                ) {}

                public function authorizeCatalog(
                    int $actorId,
                    int $organizationId,
                    DateTimeZone $timezone,
                    array $targets,
                ): ReportCatalogAuthorization {
                    throw new RuntimeException('Unexpected catalog authorization.');
                }

                public function authorizeForOrganization(
                    int $actorId,
                    int $organizationId,
                    DateTimeZone $timezone,
                    CurrentReportAuthorizationTarget $target,
                ): CurrentReportAuthorization {
                    throw new RuntimeException('Unexpected organization authorization.');
                }

                public function authorizeExact(
                    int $actorId,
                    ReportScope $requestedScope,
                    CurrentReportAuthorizationTarget $target,
                ): CurrentReportAuthorization {
                    return $this->authorization($target);
                }

                public function authorizeExactMany(
                    int $actorId,
                    ReportScope $requestedScope,
                    array $targets,
                ): array {
                    if (! $this->authorized && is_callable($this->onFirstAuthorization)) {
                        $this->authorized = true;
                        ($this->onFirstAuthorization)();
                    }

                    return array_map(
                        fn (CurrentReportAuthorizationTarget $target): CurrentReportAuthorization => $this->authorization(
                            $target,
                        ),
                        $targets,
                    );
                }

                private function authorization(
                    CurrentReportAuthorizationTarget $target,
                ): CurrentReportAuthorization {
                    return new CurrentReportAuthorization(
                        $this->context->actor,
                        $this->context->authorization,
                        new ReportVisibility(true, true, true, true, true, true, true),
                        $target,
                    );
                }
            },
            new ReportExecutionContextFactory,
        );
    }
}
