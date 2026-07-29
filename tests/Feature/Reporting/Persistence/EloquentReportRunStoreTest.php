<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting\Persistence;

use App\BusinessModules\Core\Reporting\Application\Audit\ReportTransitionAudit;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportRunStore;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Domain\DTO\AuthorizationDecisionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportActor;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportFilterSet;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportProvenance;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportProgress;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuality;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportResult;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportResultMetadata;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSavedViewRef;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotSeal;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSourceRef;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportFreshnessStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportQualityStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportReconciliationStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportRunStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSnapshotClassification;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\IdempotencyKey;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\EloquentReportRunStore;
use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\EloquentReportDispatchIntentStore;
use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\Models\ReportRunRecord;
use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\Models\ReportAuditIntentRecord;
use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\Models\ReportDispatchIntentRecord;
use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\ReportRunHydrator;
use App\Models\Organization;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use ReflectionClass;
use Tests\TestCase;
use Tests\Support\Reporting\FakeReportExecutionClock;
use Tests\Support\Reporting\FakeReportTransitionAudit;
use Tests\Support\Reporting\ReportDefinitionBuilder;
use Tests\Support\Reporting\ReportExecutionContextBuilder;

#[Group('postgresql')]
final class EloquentReportRunStoreTest extends TestCase
{
    private const LEASE_TOKEN = '00000000-0000-4000-8000-000000000001';
    use RefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        self::assertSame('pgsql', DB::connection()->getDriverName(), 'Task 3 persistence tests require isolated PostgreSQL.');
    }

    protected function setUp(): void
    {
        parent::setUp();

        self::assertSame('pgsql', DB::connection()->getDriverName(), 'Task 3 persistence tests require isolated PostgreSQL.');
        foreach ([1, 2, 10] as $organizationId) {
            Organization::factory()->create(['id' => $organizationId]);
        }
    }

    public function test_schema_has_exact_named_constraints_and_indexes(): void
    {
        $constraints = DB::table('pg_constraint')
            ->whereIn('conname', [
                'report_runs_status_check',
                'report_runs_progress_check',
                'report_runs_definition_hash_check',
                'report_runs_definition_snapshot_hash_check',
                'report_runs_query_hash_check',
                'report_runs_source_hash_check',
                'report_runs_result_hash_check',
                'report_runs_idempotency_hash_check',
                'report_runs_input_fingerprint_check',
                'report_runs_saved_view_check',
                'report_runs_classification_check',
                'report_runs_snapshot_seal_check',
                'report_runs_ready_seal_classification_check',
                'report_runs_error_code_check',
                'report_runs_execution_lease_check',
                'report_runs_expiry_order_check',
                'report_runs_ready_identity_check',
                'report_runs_terminal_timestamps_check',
                'report_runs_expired_seal_check',
                'report_runs_correlation_lineage_check',
            ])
            ->pluck('conname')
            ->sort()
            ->values()
            ->all();

        self::assertSame([
            'report_runs_classification_check',
            'report_runs_correlation_lineage_check',
            'report_runs_definition_hash_check',
            'report_runs_definition_snapshot_hash_check',
            'report_runs_error_code_check',
            'report_runs_execution_lease_check',
            'report_runs_expired_seal_check',
            'report_runs_expiry_order_check',
            'report_runs_idempotency_hash_check',
            'report_runs_input_fingerprint_check',
            'report_runs_progress_check',
            'report_runs_query_hash_check',
            'report_runs_ready_identity_check',
            'report_runs_ready_seal_classification_check',
            'report_runs_result_hash_check',
            'report_runs_saved_view_check',
            'report_runs_snapshot_seal_check',
            'report_runs_source_hash_check',
            'report_runs_status_check',
            'report_runs_terminal_timestamps_check',
        ], $constraints);

        $indexes = DB::table('pg_indexes')
            ->where('tablename', 'report_runs')
            ->whereIn('indexname', [
                'report_runs_org_idempotency_unique',
                'report_runs_org_id_lookup',
                'report_runs_queued_idx',
                'report_runs_execution_lease_idx',
                'report_runs_retention_idx',
                'report_runs_execution_lease_token_unique',
            ])
            ->pluck('indexname')
            ->sort()
            ->values()
            ->all();

        self::assertSame([
            'report_runs_execution_lease_idx',
            'report_runs_execution_lease_token_unique',
            'report_runs_org_id_lookup',
            'report_runs_org_idempotency_unique',
            'report_runs_queued_idx',
            'report_runs_retention_idx',
        ], $indexes);

        self::assertSame(
            ['data_type' => 'text', 'is_nullable' => 'YES'],
            (array) DB::table('information_schema.columns')
                ->where('table_schema', 'public')
                ->where('table_name', 'report_runs')
                ->where('column_name', 'correlation_lineage_id')
                ->first(['data_type', 'is_nullable']),
        );
    }

    public function test_official_snapshot_persists_and_hydrates_the_complete_seal(): void
    {
        $context = $this->context(10, 100);
        $store = $this->store(new FakeReportTransitionAudit());
        $query = $this->query($context, ReportSnapshotClassification::OFFICIAL);
        $run = $store->createOrReuse($context, $query, null, new IdempotencyKey('official-seal-key'));
        $this->claim($store, $context, $run->id, new DateTimeImmutable('2026-07-26T00:10:00Z'));
        $seal = new ReportSnapshotSeal(
            'key_1',
            'ed25519-sha256',
            new Sha256Hash(str_repeat('f', 64)),
            str_repeat('A', 86),
            new DateTimeImmutable('2026-07-26T00:30:01Z'),
        );
        [$snapshot, $result, $sourceHash] = $this->sealedResult(
            $context,
            '100.00',
            ReportSnapshotClassification::OFFICIAL,
            $seal,
        );

        $ready = $store->sealReady(
            $context,
            $run->id,
            self::LEASE_TOKEN,
            $snapshot,
            $result,
            $sourceHash,
            new DateTimeImmutable('2026-07-26T00:31:00Z'),
        );

        self::assertSame(ReportSnapshotClassification::OFFICIAL, $ready->resultMetadata?->snapshot->classification);
        self::assertSame('key_1', $ready->resultMetadata?->snapshot->seal?->keyId);
        self::assertSame(str_repeat('A', 86), $ready->resultMetadata?->snapshot->seal?->signature);
    }

    public function test_schema_has_closed_columns_nullability_microsecond_precision_and_real_predicates(): void
    {
        $columns = DB::table('information_schema.columns')
            ->where('table_schema', 'public')
            ->where('table_name', 'report_runs')
            ->orderBy('ordinal_position')
            ->get(['column_name', 'data_type', 'is_nullable', 'datetime_precision'])
            ->mapWithKeys(static fn ($column): array => [(string) $column->column_name => [
                'type' => (string) $column->data_type,
                'nullable' => $column->is_nullable === 'YES',
                'precision' => $column->datetime_precision === null ? null : (int) $column->datetime_precision,
            ]])
            ->all();

        self::assertSame([
            'id', 'organization_id', 'requester_actor_id', 'report_code', 'status',
            'definition_hash', 'definition_snapshot_hash', 'query_hash', 'source_hash',
            'result_hash', 'idempotency_key_hash', 'input_fingerprint', 'contract_version',
            'formula_version', 'source_schema_version', 'renderer_version',
            'definition_snapshot', 'canonical_query_json', 'scope_holding_organization_ids',
            'scope_project_ids', 'scope_resource_ids', 'scope_timezone', 'filters',
            'comparison', 'as_of', 'locale', 'saved_view_id', 'saved_view_revision',
            'saved_view_hash', 'snapshot_classification', 'data_classification',
            'sensitive_column_ids', 'audit_column_ids', 'progress', 'row_count',
            'result_metadata', 'totals', 'freshness', 'quality', 'provenance', 'row_schema',
            'capabilities', 'snapshot_kind', 'snapshot_id', 'snapshot_generated_at',
            'snapshot_stale_at', 'snapshot_watermarks', 'snapshot_seal_key_id',
            'snapshot_seal_algorithm', 'snapshot_sealed_payload_hash',
            'snapshot_seal_signature', 'snapshot_sealed_at', 'error_code',
            'execution_lease_token', 'execution_lease_expires_at', 'execution_heartbeat_at', 'queued_at',
            'started_at', 'ready_at', 'failed_at', 'cancel_requested_at', 'cancelled_at',
            'expired_at', 'created_at', 'updated_at', 'expires_at',
        ], array_keys($columns));

        foreach ([
            'as_of', 'snapshot_generated_at', 'snapshot_stale_at', 'snapshot_sealed_at',
            'execution_lease_expires_at', 'execution_heartbeat_at',
            'queued_at', 'started_at',
            'ready_at', 'failed_at', 'cancel_requested_at', 'cancelled_at', 'expired_at',
            'created_at', 'updated_at', 'expires_at',
        ] as $timestamp) {
            self::assertSame('timestamp with time zone', $columns[$timestamp]['type']);
            self::assertSame(6, $columns[$timestamp]['precision']);
        }
        foreach (['definition_snapshot', 'scope_holding_organization_ids', 'scope_project_ids', 'scope_resource_ids', 'filters', 'comparison', 'sensitive_column_ids', 'audit_column_ids', 'result_metadata', 'totals', 'quality', 'provenance', 'row_schema', 'capabilities', 'snapshot_watermarks'] as $jsonb) {
            self::assertSame('jsonb', $columns[$jsonb]['type']);
        }
        foreach (['source_hash', 'result_hash', 'saved_view_id', 'saved_view_revision', 'saved_view_hash', 'row_count', 'result_metadata', 'freshness', 'quality', 'provenance', 'row_schema', 'capabilities', 'snapshot_kind', 'snapshot_id', 'snapshot_generated_at', 'snapshot_stale_at', 'snapshot_watermarks', 'snapshot_seal_key_id', 'snapshot_seal_algorithm', 'snapshot_sealed_payload_hash', 'snapshot_seal_signature', 'snapshot_sealed_at', 'error_code', 'execution_lease_token', 'execution_lease_expires_at', 'execution_heartbeat_at', 'started_at', 'ready_at', 'failed_at', 'cancel_requested_at', 'cancelled_at', 'expired_at'] as $nullable) {
            self::assertTrue($columns[$nullable]['nullable'], $nullable);
        }
        $nullableNames = array_keys(array_filter($columns, static fn (array $column): bool => $column['nullable']));
        self::assertSame([
            'source_hash', 'result_hash', 'saved_view_id', 'saved_view_revision', 'saved_view_hash',
            'row_count', 'result_metadata',
            'freshness', 'quality', 'provenance', 'row_schema', 'capabilities',
            'snapshot_kind', 'snapshot_id', 'snapshot_generated_at', 'snapshot_stale_at',
            'snapshot_watermarks', 'snapshot_seal_key_id', 'snapshot_seal_algorithm',
            'snapshot_sealed_payload_hash', 'snapshot_seal_signature', 'snapshot_sealed_at',
            'error_code', 'execution_lease_token', 'execution_lease_expires_at',
            'execution_heartbeat_at', 'started_at', 'ready_at', 'failed_at',
            'cancel_requested_at', 'cancelled_at', 'expired_at',
        ], $nullableNames);
        foreach (['id', 'definition_hash', 'definition_snapshot_hash', 'query_hash', 'idempotency_key_hash', 'input_fingerprint', 'saved_view_id', 'saved_view_hash', 'source_hash', 'result_hash', 'snapshot_sealed_payload_hash'] as $character) {
            self::assertSame('character', $columns[$character]['type']);
        }
        foreach (['organization_id', 'requester_actor_id', 'saved_view_revision', 'row_count'] as $bigint) {
            self::assertSame('bigint', $columns[$bigint]['type']);
        }
        self::assertSame('smallint', $columns['progress']['type']);
        self::assertSame('uuid', $columns['execution_lease_token']['type']);
        foreach (['report_code', 'status', 'contract_version', 'formula_version', 'source_schema_version', 'renderer_version', 'canonical_query_json', 'scope_timezone', 'locale', 'snapshot_classification', 'data_classification', 'freshness', 'snapshot_kind', 'snapshot_id', 'snapshot_seal_key_id', 'snapshot_seal_algorithm', 'snapshot_seal_signature', 'error_code'] as $text) {
            self::assertSame('text', $columns[$text]['type']);
        }

        $errorConstraint = DB::selectOne(
            "SELECT pg_get_constraintdef(oid) AS definition FROM pg_constraint WHERE conname = 'report_runs_error_code_check'",
        );
        self::assertNotNull($errorConstraint);
        self::assertStringContainsString("status = 'failed'", (string) $errorConstraint->definition);
        foreach (ReportErrorCode::cases() as $errorCode) {
            self::assertStringContainsString($errorCode->value, (string) $errorConstraint->definition);
        }
        self::assertStringContainsString('error_code IS NULL', (string) $errorConstraint->definition);

        $indexDefinitions = DB::table('pg_indexes')
            ->where('tablename', 'report_runs')
            ->pluck('indexdef', 'indexname');
        self::assertStringContainsString('(organization_id, idempotency_key_hash)', $indexDefinitions['report_runs_org_idempotency_unique']);
        self::assertStringContainsString("WHERE (status = 'queued'", $indexDefinitions['report_runs_queued_idx']);
        self::assertStringContainsString("WHERE (status = 'materializing'", $indexDefinitions['report_runs_execution_lease_idx']);
        self::assertStringContainsString("status = ANY (ARRAY['ready'::text, 'expired'::text])", $indexDefinitions['report_runs_retention_idx']);
    }

    public function test_store_implements_only_locked_execution_port(): void
    {
        self::assertTrue(is_subclass_of(EloquentReportRunStore::class, ReportRunStore::class));

        $public = array_values(array_filter(
            (new ReflectionClass(EloquentReportRunStore::class))->getMethods(),
            static fn ($method): bool => $method->isPublic() && $method->getDeclaringClass()->getName() === EloquentReportRunStore::class,
        ));

        $names = array_map(static fn ($method): string => $method->getName(), $public);
        sort($names);

        self::assertSame(
            ['__construct', 'cancel', 'claimMaterialization', 'createOrReuse', 'exportSource', 'fail', 'get', 'persistProgress', 'queryForRun', 'retrySource', 'sealReady'],
            $names,
        );
    }

    public function test_postgresql_supports_non_throwing_idempotency_conflict_path_in_a_healthy_transaction(): void
    {
        DB::transaction(function (): void {
            $first = DB::selectOne(
                "INSERT INTO report_runs (id, organization_id, requester_actor_id, report_code, status, definition_hash, definition_snapshot_hash, query_hash, idempotency_key_hash, input_fingerprint, contract_version, formula_version, source_schema_version, renderer_version, definition_snapshot, canonical_query_json, scope_holding_organization_ids, scope_project_ids, scope_resource_ids, scope_timezone, filters, comparison, as_of, locale, snapshot_classification, data_classification, sensitive_column_ids, audit_column_ids, progress, totals, queued_at, created_at, updated_at, expires_at) VALUES (?, 1, 1, 'cost_control', 'queued', ?, ?, ?, ?, ?, '1', '1', '1', '1', ?::jsonb, '{}', '[1]'::jsonb, '[]'::jsonb, '[]'::jsonb, 'UTC', '{}'::jsonb, '[]'::jsonb, now(), 'ru', 'operational', 'standard', '[]'::jsonb, '[]'::jsonb, 0, '[]'::jsonb, now(), now(), now(), now() + interval '1 hour') ON CONFLICT (organization_id, idempotency_key_hash) DO NOTHING RETURNING id",
                ['01J3R6W7H8K9M0NPQRSTVWXYZ1', str_repeat('a', 64), str_repeat('f', 64), str_repeat('b', 64), str_repeat('c', 64), str_repeat('d', 64), '{}'],
            );
            $second = DB::selectOne(
                "INSERT INTO report_runs (id, organization_id, requester_actor_id, report_code, status, definition_hash, definition_snapshot_hash, query_hash, idempotency_key_hash, input_fingerprint, contract_version, formula_version, source_schema_version, renderer_version, definition_snapshot, canonical_query_json, scope_holding_organization_ids, scope_project_ids, scope_resource_ids, scope_timezone, filters, comparison, as_of, locale, snapshot_classification, data_classification, sensitive_column_ids, audit_column_ids, progress, totals, queued_at, created_at, updated_at, expires_at) VALUES (?, 1, 2, 'cost_control', 'queued', ?, ?, ?, ?, ?, '1', '1', '1', '1', ?::jsonb, '{}', '[1]'::jsonb, '[]'::jsonb, '[]'::jsonb, 'UTC', '{}'::jsonb, '[]'::jsonb, now(), 'ru', 'operational', 'standard', '[]'::jsonb, '[]'::jsonb, 0, '[]'::jsonb, now(), now(), now(), now() + interval '1 hour') ON CONFLICT (organization_id, idempotency_key_hash) DO NOTHING RETURNING id",
                ['01J3R6W7H8K9M0NPQRSTVWXYZ2', str_repeat('a', 64), str_repeat('f', 64), str_repeat('b', 64), str_repeat('c', 64), str_repeat('d', 64), '{}'],
            );

            self::assertNotNull($first);
            self::assertNull($second);
            self::assertSame(1, DB::table('report_runs')->where('organization_id', 1)->count());

        });
    }

    public function test_idempotency_is_organization_wide_actor_independent_and_body_bound(): void
    {
        $audit = new FakeReportTransitionAudit();
        $store = $this->store($audit);
        $context = (new ReportExecutionContextBuilder())->build();
        $query = $this->query($context);
        $key = new IdempotencyKey('same-key-123');

        $created = $store->createOrReuse($context, $query, null, $key);
        $otherActor = (new ReportExecutionContextBuilder())
            ->actor(new ReportActor(2, 'active', ['reports.view']))
            ->build();
        $reused = $store->createOrReuse($otherActor, $query, null, $key);

        self::assertSame($created->id, $reused->id);
        self::assertSame('created', $created->httpDisposition);
        self::assertSame('reused', $reused->httpDisposition);
        self::assertSame(
            '2026-07-26T00:00:00.123456Z',
            $store->queryForRun($context, $created->id)->asOf->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z'),
        );
        self::assertSame(1, ReportRunRecord::query()->count());

        try {
            $store->createOrReuse($otherActor, $query, new ReportSavedViewRef(
                '01J3R6W7H8K9M0NPQRSTVWXYZ9',
                1,
                new Sha256Hash(str_repeat('f', 64)),
            ), $key);
            self::fail('Expected organization-wide idempotency conflict.');
        } catch (ReportContractException $exception) {
            self::assertSame(ReportErrorCode::REPORT_IDEMPOTENCY_CONFLICT, $exception->errorCode);
        }

        $otherOrganization = $this->context(2, 3);
        $other = $store->createOrReuse($otherOrganization, $this->query($otherOrganization), null, $key);
        self::assertNotSame($created->id, $other->id);
        self::assertSame(2, ReportRunRecord::query()->count());
    }

    public function test_ready_audit_is_before_cas_replay_safe_and_binds_complete_result_hash(): void
    {
        $audit = new FakeReportTransitionAudit();
        $store = $this->store($audit);
        $context = (new ReportExecutionContextBuilder())->build();
        $run = $store->createOrReuse($context, $this->query($context), null, new IdempotencyKey('ready-key-123'));
        $this->claim($store, $context, $run->id, new DateTimeImmutable('2026-07-26T00:30:00Z'));
        [$snapshot, $result, $sourceHash] = $this->sealedResult($context);

        $ready = $store->sealReady(
            $context,
            $run->id,
            self::LEASE_TOKEN,
            $snapshot,
            $result,
            $sourceHash,
            new DateTimeImmutable('2026-07-26T00:31:00Z'),
        );
        $replayed = $store->sealReady(
            $context,
            $run->id,
            self::LEASE_TOKEN,
            $snapshot,
            $result,
            $sourceHash,
            new DateTimeImmutable('2026-07-26T00:40:00Z'),
        );

        self::assertSame('ready', $ready->status->value);
        self::assertSame($ready->id, $replayed->id);
        self::assertSame(
            '2026-07-26T00:30:00.234567Z',
            $ready->resultMetadata?->snapshot->generatedAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z'),
        );
        self::assertCount(3, $audit->events());
        $record = ReportRunRecord::query()->findOrFail($run->id);
        self::assertSame($record->result_hash, $audit->events()[2]['subject']['result_hash']);
        self::assertSame("reports:run:{$run->id}:ready:{$record->result_hash}", $audit->events()[2]['event_id']);

        [$changedSnapshot, $changedResult, $changedSourceHash] = $this->sealedResult($context, '101.00');
        try {
            $store->sealReady(
                $context,
                $run->id,
                self::LEASE_TOKEN,
                $changedSnapshot,
                $changedResult,
                $changedSourceHash,
                new DateTimeImmutable('2026-07-26T00:41:00Z'),
            );
            self::fail('Expected mismatched ready replay rejection.');
        } catch (ReportContractException $exception) {
            self::assertSame(ReportErrorCode::REPORT_INTERNAL_ERROR, $exception->errorCode);
        }
        self::assertCount(3, $audit->events());
    }

    public function test_audit_failure_rolls_back_ready_transition(): void
    {
        $audit = new class implements ReportTransitionAudit {
            public function append(string $eventId, string $eventType, ReportExecutionContext $context, array $subject, DateTimeImmutable $occurredAt): void
            {
                if ($eventType === 'report.run.ready') {
                    throw ReportContractException::fromCode(ReportErrorCode::REPORT_DEPENDENCY_FAILED);
                }
            }
        };
        $store = $this->store($audit);
        $context = (new ReportExecutionContextBuilder())->build();
        $run = $store->createOrReuse($context, $this->query($context), null, new IdempotencyKey('failed-audit-key'));
        $this->claim($store, $context, $run->id, new DateTimeImmutable('2026-07-26T00:05:00Z'));
        [$snapshot, $result, $sourceHash] = $this->sealedResult($context);

        try {
            $store->sealReady(
                $context,
                $run->id,
                self::LEASE_TOKEN,
                $snapshot,
                $result,
                $sourceHash,
                new DateTimeImmutable('2026-07-26T00:31:00Z'),
            );
            self::fail('Expected audit failure.');
        } catch (ReportContractException $exception) {
            self::assertSame(ReportErrorCode::REPORT_DEPENDENCY_FAILED, $exception->errorCode);
        }

        $record = ReportRunRecord::query()->findOrFail($run->id);
        self::assertSame('materializing', $record->status);
        self::assertSame(0, $record->progress);
        foreach ([
            'source_hash', 'result_hash', 'row_count', 'result_metadata', 'freshness',
            'quality', 'provenance', 'row_schema', 'capabilities', 'snapshot_kind',
            'snapshot_id', 'snapshot_generated_at', 'snapshot_stale_at',
            'snapshot_watermarks', 'ready_at',
        ] as $attribute) {
            self::assertNull($record->{$attribute}, $attribute);
        }
        self::assertSame([], $record->totals);
    }

    public function test_initial_audit_failure_rolls_back_run_dispatch_and_audit_rows_together(): void
    {
        $audit = new class implements ReportTransitionAudit {
            public function append(string $eventId, string $eventType, ReportExecutionContext $context, array $subject, DateTimeImmutable $occurredAt): void
            {
                ReportAuditIntentRecord::query()->create([
                    'id' => (string) \Illuminate\Support\Str::ulid(),
                    'event_key' => $eventId,
                    'event_type' => $eventType,
                    'organization_id' => $context->scope->organizationId,
                    'actor_id' => $context->actor->id,
                    'subject' => $subject,
                    'status' => 'pending',
                    'attempt_count' => 0,
                    'occurred_at' => $occurredAt,
                    'available_at' => $occurredAt,
                    'created_at' => $occurredAt,
                    'updated_at' => $occurredAt,
                ]);
                throw ReportContractException::fromCode(ReportErrorCode::REPORT_DEPENDENCY_FAILED);
            }
        };
        $store = $this->store($audit);
        $context = (new ReportExecutionContextBuilder())->build();

        try {
            $store->createOrReuse($context, $this->query($context), null, new IdempotencyKey('atomic-outbox-rollback'));
            self::fail('Expected audit outbox failure.');
        } catch (ReportContractException $exception) {
            self::assertSame(ReportErrorCode::REPORT_DEPENDENCY_FAILED, $exception->errorCode);
        }

        self::assertSame(0, ReportRunRecord::query()->count());
        self::assertSame(0, ReportDispatchIntentRecord::query()->count());
        self::assertSame(0, ReportAuditIntentRecord::query()->count());
    }

    public function test_export_source_rejects_ready_run_at_exact_expiry_microsecond(): void
    {
        $now = new DateTimeImmutable('2026-07-26T00:31:00.900000Z');
        $store = $this->store(new FakeReportTransitionAudit(), $now);
        $context = (new ReportExecutionContextBuilder())->build();
        $run = $store->createOrReuse($context, $this->query($context), null, new IdempotencyKey('export-expiry-key'));
        $this->claim($store, $context, $run->id, new DateTimeImmutable('2026-07-26T00:30:00Z'));
        [$snapshot, $result, $sourceHash] = $this->sealedResult($context);
        $store->sealReady($context, $run->id, self::LEASE_TOKEN, $snapshot, $result, $sourceHash, $now);
        $record = ReportRunRecord::query()->findOrFail($run->id);

        $record->update(['expires_at' => '2026-07-26 00:31:00.900001+00:00']);
        self::assertSame($run->id, $store->exportSource($context, $run->id)->run->id);

        foreach (['2026-07-26 00:31:00.900000+00:00', '2026-07-26 00:31:00.899999+00:00'] as $expiresAt) {
            $record->update(['expires_at' => $expiresAt]);
            try {
                $store->exportSource($context, $run->id);
                self::fail('Expired ready run was accepted as export source.');
            } catch (ReportContractException $exception) {
                self::assertSame(ReportErrorCode::REPORT_SNAPSHOT_EXPIRED, $exception->errorCode);
            }
        }
    }

    public function test_legal_transitions_progress_failure_cancellation_and_terminal_rejection(): void
    {
        $store = $this->store(new FakeReportTransitionAudit());
        $context = (new ReportExecutionContextBuilder())->build();
        $run = $store->createOrReuse($context, $this->query($context), null, new IdempotencyKey('transition-key'));
        $expiresAt = $run->expiresAt;
        $materializing = $store->claimMaterialization(
            $context,
            $run->id,
            self::LEASE_TOKEN,
            new DateTimeImmutable('2026-07-26T00:15:00.222222Z'),
            new DateTimeImmutable('2026-07-26T00:05:00.222222Z'),
        );
        self::assertSame('materializing', $materializing->status->value);
        self::assertSame(35, $store->persistProgress(
            $context,
            $run->id,
            self::LEASE_TOKEN,
            new ReportProgress(35),
            new DateTimeImmutable('2026-07-26T00:16:00.333333Z'),
            new DateTimeImmutable('2026-07-26T00:06:00.333333Z'),
        )->progress);
        self::assertEquals($expiresAt, $store->get($context, $run->id)->expiresAt);

        try {
            $store->persistProgress(
                $context,
                $run->id,
                self::LEASE_TOKEN,
                new ReportProgress(34),
                new DateTimeImmutable('2026-07-26T00:17:00.444444Z'),
                new DateTimeImmutable('2026-07-26T00:07:00.444444Z'),
            );
            self::fail('Expected decreasing progress rejection.');
        } catch (ReportContractException $exception) {
            self::assertSame(ReportErrorCode::REPORT_SNAPSHOT_NOT_READY, $exception->errorCode);
        }

        $failed = $store->fail(
            $context,
            $run->id,
            self::LEASE_TOKEN,
            ReportErrorCode::REPORT_SOURCE_UNAVAILABLE,
            new DateTimeImmutable('2026-07-26T00:08:00.555555Z'),
        );
        self::assertSame('failed', $failed->status->value);
        self::assertEquals($expiresAt, $failed->expiresAt);
        $failedRecord = ReportRunRecord::query()->findOrFail($run->id);
        self::assertSame('REPORT_SOURCE_UNAVAILABLE', $failedRecord->error_code);
        self::assertSame('2026-07-26 00:08:00.555555+00', $failedRecord->getRawOriginal('failed_at'));

        try {
            $store->cancel($context, $run->id, new DateTimeImmutable('2026-07-26T00:09:00.666666Z'));
            self::fail('Expected terminal transition rejection.');
        } catch (ReportContractException $exception) {
            self::assertSame(ReportErrorCode::REPORT_SNAPSHOT_NOT_READY, $exception->errorCode);
        }

        $cancelRun = $store->createOrReuse($context, $this->query($context), null, new IdempotencyKey('cancel-key-123'));
        $cancelled = $store->cancel(
            $context,
            $cancelRun->id,
            new DateTimeImmutable('2026-07-26T00:10:00.777777Z'),
        );
        self::assertSame('cancelled', $cancelled->status->value);
        self::assertEquals($cancelRun->expiresAt, $cancelled->expiresAt);
        $cancelRecord = ReportRunRecord::query()->findOrFail($cancelRun->id);
        self::assertSame($cancelRecord->cancel_requested_at, $cancelRecord->cancelled_at);
        self::assertNull($cancelRecord->error_code);
    }

    public function test_execution_lease_preserves_fractional_expiry_boundary(): void
    {
        $store = $this->store(new FakeReportTransitionAudit());
        $context = (new ReportExecutionContextBuilder())->build();
        $run = $store->createOrReuse($context, $this->query($context), null, new IdempotencyKey('fractional-lease-key'));
        $store->claimMaterialization(
            $context,
            $run->id,
            self::LEASE_TOKEN,
            new DateTimeImmutable('2026-07-26T10:00:00.900000Z'),
            new DateTimeImmutable('2026-07-26T09:59:00.000000Z'),
        );

        self::assertSame(10, $store->persistProgress(
            $context,
            $run->id,
            self::LEASE_TOKEN,
            new ReportProgress(10),
            new DateTimeImmutable('2026-07-26T10:00:01.000000Z'),
            new DateTimeImmutable('2026-07-26T10:00:00.500000Z'),
        )->progress);
        try {
            $store->persistProgress(
                $context,
                $run->id,
                self::LEASE_TOKEN,
                new ReportProgress(20),
                new DateTimeImmutable('2026-07-26T10:00:02.000000Z'),
                new DateTimeImmutable('2026-07-26T10:00:00.900000Z'),
            );
            self::fail('Lease remained active at its exact expiry instant.');
        } catch (ReportContractException $exception) {
            self::assertSame(ReportErrorCode::REPORT_SNAPSHOT_NOT_READY, $exception->errorCode);
        }
    }

    public function test_same_token_live_claim_renews_without_restarting_progress_or_repeating_audit(): void
    {
        $audit = new FakeReportTransitionAudit();
        $store = $this->store($audit);
        $context = (new ReportExecutionContextBuilder())->build();
        $run = $store->createOrReuse($context, $this->query($context), null, new IdempotencyKey('renew-live-lease'));
        $startedAt = new DateTimeImmutable('2026-07-26T09:55:00.111111Z');
        $store->claimMaterialization(
            $context,
            $run->id,
            self::LEASE_TOKEN,
            new DateTimeImmutable('2026-07-26T10:00:00.900000Z'),
            $startedAt,
        );
        ReportRunRecord::query()->whereKey($run->id)->update(['progress' => 41]);
        $auditCount = count($audit->events());

        $renewed = $store->claimMaterialization(
            $context,
            $run->id,
            self::LEASE_TOKEN,
            new DateTimeImmutable('2026-07-26T10:15:00.333333Z'),
            new DateTimeImmutable('2026-07-26T09:59:59.222222Z'),
        );

        self::assertSame(ReportRunStatus::MATERIALIZING, $renewed->status);
        self::assertSame(41, $renewed->progress);
        self::assertCount($auditCount, $audit->events());
        $record = ReportRunRecord::query()->findOrFail($run->id);
        self::assertSame('2026-07-26 09:55:00.111111+00', $record->getRawOriginal('started_at'));
        self::assertSame('2026-07-26 10:15:00.333333+00', $record->getRawOriginal('execution_lease_expires_at'));
        self::assertSame('2026-07-26 09:59:59.222222+00', $record->getRawOriginal('execution_heartbeat_at'));
    }

    public function test_same_token_renewal_is_monotonic_and_equality_is_idempotent(): void
    {
        $audit = new FakeReportTransitionAudit();
        $store = $this->store($audit);
        $context = (new ReportExecutionContextBuilder())->build();
        $run = $store->createOrReuse($context, $this->query($context), null, new IdempotencyKey('monotonic-renewal'));
        $store->claimMaterialization(
            $context,
            $run->id,
            self::LEASE_TOKEN,
            new DateTimeImmutable('2026-07-26T10:00:00.900000Z'),
            new DateTimeImmutable('2026-07-26T09:55:00.111111Z'),
        );
        $auditCount = count($audit->events());
        $initial = ReportRunRecord::query()->whereKey($run->id)->firstOrFail();
        $initialProjection = [
            'lease' => $initial->getRawOriginal('execution_lease_expires_at'),
            'heartbeat' => $initial->getRawOriginal('execution_heartbeat_at'),
            'started' => $initial->getRawOriginal('started_at'),
            'progress' => $initial->progress,
            'updated' => $initial->getRawOriginal('updated_at'),
        ];

        foreach ([
            [
                'lease' => '2026-07-26T10:00:00.899999Z',
                'occurred' => '2026-07-26T09:56:00.222222Z',
            ],
        ] as $proposal) {
            try {
                $store->claimMaterialization(
                    $context,
                    $run->id,
                    self::LEASE_TOKEN,
                    new DateTimeImmutable($proposal['lease']),
                    new DateTimeImmutable($proposal['occurred']),
                );
                self::fail('Expected regressive execution lease renewal to be fenced.');
            } catch (ReportContractException $exception) {
                self::assertSame(ReportErrorCode::REPORT_SNAPSHOT_NOT_READY, $exception->errorCode);
            }

            $unchanged = ReportRunRecord::query()->whereKey($run->id)->firstOrFail();
            self::assertSame($initialProjection, [
                'lease' => $unchanged->getRawOriginal('execution_lease_expires_at'),
                'heartbeat' => $unchanged->getRawOriginal('execution_heartbeat_at'),
                'started' => $unchanged->getRawOriginal('started_at'),
                'progress' => $unchanged->progress,
                'updated' => $unchanged->getRawOriginal('updated_at'),
            ]);
            self::assertCount($auditCount, $audit->events());
        }

        $replayed = $store->claimMaterialization(
            $context,
            $run->id,
            self::LEASE_TOKEN,
            new DateTimeImmutable('2026-07-26T10:00:00.900000Z'),
            new DateTimeImmutable('2026-07-26T09:55:00.111111Z'),
        );

        self::assertSame(ReportRunStatus::MATERIALIZING, $replayed->status);
        $afterReplay = ReportRunRecord::query()->whereKey($run->id)->firstOrFail();
        self::assertSame($initialProjection, [
            'lease' => $afterReplay->getRawOriginal('execution_lease_expires_at'),
            'heartbeat' => $afterReplay->getRawOriginal('execution_heartbeat_at'),
            'started' => $afterReplay->getRawOriginal('started_at'),
            'progress' => $afterReplay->progress,
            'updated' => $afterReplay->getRawOriginal('updated_at'),
        ]);
        self::assertCount($auditCount, $audit->events());
    }

    public function test_same_token_renewal_fences_heartbeat_independently_of_updated_at_and_expiry(): void
    {
        $audit = new FakeReportTransitionAudit();
        $store = $this->store($audit);
        $context = (new ReportExecutionContextBuilder())->build();
        $run = $store->createOrReuse($context, $this->query($context), null, new IdempotencyKey('heartbeat-only-renewal'));
        $store->claimMaterialization(
            $context,
            $run->id,
            self::LEASE_TOKEN,
            new DateTimeImmutable('2026-07-26T10:00:00.900000Z'),
            new DateTimeImmutable('2026-07-26T09:55:00.222222Z'),
        );
        DB::table('report_runs')->where('id', $run->id)->update([
            'updated_at' => '2026-07-26 09:55:00.111111+00',
        ]);
        $auditCount = count($audit->events());
        $before = ReportRunRecord::query()->whereKey($run->id)->firstOrFail();
        $beforeProjection = [
            'lease' => $before->getRawOriginal('execution_lease_expires_at'),
            'heartbeat' => $before->getRawOriginal('execution_heartbeat_at'),
            'started' => $before->getRawOriginal('started_at'),
            'progress' => $before->progress,
            'updated' => $before->getRawOriginal('updated_at'),
        ];

        try {
            $store->claimMaterialization(
                $context,
                $run->id,
                self::LEASE_TOKEN,
                new DateTimeImmutable('2026-07-26T10:15:00.333333Z'),
                new DateTimeImmutable('2026-07-26T09:55:00.166666Z'),
            );
            self::fail('Expected heartbeat-only regression to be fenced.');
        } catch (ReportContractException $exception) {
            self::assertSame(ReportErrorCode::REPORT_SNAPSHOT_NOT_READY, $exception->errorCode);
        }

        $after = ReportRunRecord::query()->whereKey($run->id)->firstOrFail();
        self::assertSame($beforeProjection, [
            'lease' => $after->getRawOriginal('execution_lease_expires_at'),
            'heartbeat' => $after->getRawOriginal('execution_heartbeat_at'),
            'started' => $after->getRawOriginal('started_at'),
            'progress' => $after->progress,
            'updated' => $after->getRawOriginal('updated_at'),
        ]);
        self::assertCount($auditCount, $audit->events());
    }

    public function test_same_token_renewal_fences_updated_at_independently_of_heartbeat_and_expiry(): void
    {
        $audit = new FakeReportTransitionAudit();
        $store = $this->store($audit);
        $context = (new ReportExecutionContextBuilder())->build();
        $run = $store->createOrReuse($context, $this->query($context), null, new IdempotencyKey('updated-only-renewal'));
        $store->claimMaterialization(
            $context,
            $run->id,
            self::LEASE_TOKEN,
            new DateTimeImmutable('2026-07-26T10:00:00.900000Z'),
            new DateTimeImmutable('2026-07-26T09:55:00.111111Z'),
        );
        DB::table('report_runs')->where('id', $run->id)->update([
            'updated_at' => '2026-07-26 09:55:00.222222+00',
        ]);
        $auditCount = count($audit->events());
        $before = ReportRunRecord::query()->whereKey($run->id)->firstOrFail();
        $beforeProjection = [
            'lease' => $before->getRawOriginal('execution_lease_expires_at'),
            'heartbeat' => $before->getRawOriginal('execution_heartbeat_at'),
            'started' => $before->getRawOriginal('started_at'),
            'progress' => $before->progress,
            'updated' => $before->getRawOriginal('updated_at'),
        ];

        try {
            $store->claimMaterialization(
                $context,
                $run->id,
                self::LEASE_TOKEN,
                new DateTimeImmutable('2026-07-26T10:15:00.333333Z'),
                new DateTimeImmutable('2026-07-26T09:55:00.166666Z'),
            );
            self::fail('Expected updated-at-only regression to be fenced.');
        } catch (ReportContractException $exception) {
            self::assertSame(ReportErrorCode::REPORT_SNAPSHOT_NOT_READY, $exception->errorCode);
        }

        $after = ReportRunRecord::query()->whereKey($run->id)->firstOrFail();
        self::assertSame($beforeProjection, [
            'lease' => $after->getRawOriginal('execution_lease_expires_at'),
            'heartbeat' => $after->getRawOriginal('execution_heartbeat_at'),
            'started' => $after->getRawOriginal('started_at'),
            'progress' => $after->progress,
            'updated' => $after->getRawOriginal('updated_at'),
        ]);
        self::assertCount($auditCount, $audit->events());
    }

    public function test_renewal_rejects_expired_equal_expiry_and_different_token_leases(): void
    {
        foreach ([
            ['token' => self::LEASE_TOKEN, 'at' => '2026-07-26T10:00:00.900000Z'],
            ['token' => self::LEASE_TOKEN, 'at' => '2026-07-26T10:00:00.900001Z'],
            ['token' => '00000000-0000-4000-8000-000000000002', 'at' => '2026-07-26T09:59:59.999999Z'],
        ] as $index => $attempt) {
            $store = $this->store(new FakeReportTransitionAudit());
            $context = (new ReportExecutionContextBuilder())->build();
            $run = $store->createOrReuse(
                $context,
                $this->query($context),
                null,
                new IdempotencyKey("reject-renewal-{$index}"),
            );
            $store->claimMaterialization(
                $context,
                $run->id,
                self::LEASE_TOKEN,
                new DateTimeImmutable('2026-07-26T10:00:00.900000Z'),
                new DateTimeImmutable('2026-07-26T09:55:00.000000Z'),
            );

            try {
                $store->claimMaterialization(
                    $context,
                    $run->id,
                    $attempt['token'],
                    new DateTimeImmutable('2026-07-26T10:15:00.000000Z'),
                    new DateTimeImmutable($attempt['at']),
                );
                self::fail('Expected stale execution lease renewal to be fenced.');
            } catch (ReportContractException $exception) {
                self::assertSame(ReportErrorCode::REPORT_SNAPSHOT_NOT_READY, $exception->errorCode);
            }
        }
    }

    public function test_same_token_renewal_uses_the_locked_status_qualified_write_path(): void
    {
        $store = $this->store(new FakeReportTransitionAudit());
        $context = (new ReportExecutionContextBuilder())->build();
        $run = $store->createOrReuse($context, $this->query($context), null, new IdempotencyKey('lock-renewal'));
        $store->claimMaterialization(
            $context,
            $run->id,
            self::LEASE_TOKEN,
            new DateTimeImmutable('2026-07-26T10:00:00.900000Z'),
            new DateTimeImmutable('2026-07-26T09:55:00.000000Z'),
        );

        $this->assertLockedCas(fn () => $store->claimMaterialization(
            $context,
            $run->id,
            self::LEASE_TOKEN,
            new DateTimeImmutable('2026-07-26T10:15:00.000000Z'),
            new DateTimeImmutable('2026-07-26T09:59:00.000000Z'),
        ));
    }

    public function test_correlation_lineage_round_trips_and_schema_rejects_invalid_or_duplicate_leases(): void
    {
        $store = $this->store(new FakeReportTransitionAudit());
        $context = (new ReportExecutionContextBuilder())->build();
        $first = $store->createOrReuse($context, $this->query($context), null, new IdempotencyKey('lineage-first'));
        $second = $store->createOrReuse($context, $this->query($context), null, new IdempotencyKey('lineage-second'));
        self::assertSame(
            $context->correlationId(),
            ReportRunRecord::query()->findOrFail($first->id)->correlation_lineage_id,
        );

        ReportRunRecord::query()->whereKey($second->id)->update(['correlation_lineage_id' => null]);
        self::assertNull(ReportRunRecord::query()->findOrFail($second->id)->correlation_lineage_id);
        foreach (['invalid lineage', str_repeat('a', 129)] as $invalidLineage) {
            try {
                DB::transaction(static function () use ($second, $invalidLineage): void {
                    ReportRunRecord::query()->whereKey($second->id)->update([
                        'correlation_lineage_id' => $invalidLineage,
                    ]);
                });
                self::fail('Expected correlation lineage grammar and length constraint.');
            } catch (QueryException $exception) {
                self::assertSame('23514', $exception->errorInfo[0] ?? null);
            }
        }

        $store->claimMaterialization(
            $context,
            $first->id,
            self::LEASE_TOKEN,
            new DateTimeImmutable('2026-07-26T10:00:00.000000Z'),
            new DateTimeImmutable('2026-07-26T09:55:00.000000Z'),
        );
        try {
            DB::transaction(static function () use ($second): void {
                ReportRunRecord::query()->whereKey($second->id)->update([
                    'status' => ReportRunStatus::MATERIALIZING->value,
                    'execution_lease_token' => self::LEASE_TOKEN,
                    'execution_lease_expires_at' => '2026-07-26 10:00:00.000000+00',
                    'execution_heartbeat_at' => '2026-07-26 09:55:00.000000+00',
                    'started_at' => '2026-07-26 09:55:00.000000+00',
                ]);
            });
            self::fail('Expected execution lease token uniqueness.');
        } catch (QueryException $exception) {
            self::assertSame('23505', $exception->errorInfo[0] ?? null);
        }
    }

    public function test_database_rejects_unknown_missing_and_nonfailed_error_codes(): void
    {
        $store = $this->store(new FakeReportTransitionAudit());
        $context = (new ReportExecutionContextBuilder())->build();
        $queued = $store->createOrReuse($context, $this->query($context), null, new IdempotencyKey('error-check-key'));

        foreach ([
            ['status' => 'queued', 'error_code' => 'REPORT_INTERNAL_ERROR'],
            ['status' => 'failed', 'failed_at' => '2026-07-26 00:05:00.123456+00', 'error_code' => null],
            ['status' => 'failed', 'failed_at' => '2026-07-26 00:05:00.123456+00', 'error_code' => 'UNKNOWN'],
        ] as $mutation) {
            try {
                DB::transaction(static function () use ($queued, $mutation): void {
                    ReportRunRecord::query()->whereKey($queued->id)->update($mutation);
                });
                self::fail('Expected report_runs_error_code_check violation.');
            } catch (QueryException $exception) {
                self::assertSame('23514', $exception->errorInfo[0] ?? null);
            }
            self::assertSame('queued', ReportRunRecord::query()->findOrFail($queued->id)->status);
            self::assertNull(ReportRunRecord::query()->findOrFail($queued->id)->error_code);
        }
    }

    public function test_every_transition_uses_row_lock_and_status_qualified_compare_and_set(): void
    {
        $store = $this->store(new FakeReportTransitionAudit());
        $context = (new ReportExecutionContextBuilder())->build();

        $start = $store->createOrReuse($context, $this->query($context), null, new IdempotencyKey('lock-start-key'));
        $this->assertLockedCas(fn () => $store->claimMaterialization(
            $context,
            $start->id,
            self::LEASE_TOKEN,
            new DateTimeImmutable('2026-07-26T00:15:00.111111Z'),
            new DateTimeImmutable('2026-07-26T00:05:00.111111Z'),
        ));

        $progress = $store->createOrReuse($context, $this->query($context), null, new IdempotencyKey('lock-progress-key'));
        $this->claim($store, $context, $progress->id, new DateTimeImmutable('2026-07-26T00:05:00.111111Z'));
        $this->assertLockedCas(fn () => $store->persistProgress(
            $context,
            $progress->id,
            self::LEASE_TOKEN,
            new ReportProgress(10),
            new DateTimeImmutable('2026-07-26T00:16:00.222222Z'),
            new DateTimeImmutable('2026-07-26T00:06:00.222222Z'),
        ));

        $failed = $store->createOrReuse($context, $this->query($context), null, new IdempotencyKey('lock-fail-key'));
        $this->claim($store, $context, $failed->id, new DateTimeImmutable('2026-07-26T00:05:00.111111Z'));
        $this->assertLockedCas(fn () => $store->fail(
            $context,
            $failed->id,
            self::LEASE_TOKEN,
            ReportErrorCode::REPORT_SOURCE_UNAVAILABLE,
            new DateTimeImmutable('2026-07-26T00:07:00.333333Z'),
        ));

        $cancelled = $store->createOrReuse($context, $this->query($context), null, new IdempotencyKey('lock-cancel-key'));
        $this->assertLockedCas(fn () => $store->cancel(
            $context,
            $cancelled->id,
            new DateTimeImmutable('2026-07-26T00:08:00.444444Z'),
        ));

        $ready = $store->createOrReuse($context, $this->query($context), null, new IdempotencyKey('lock-ready-key'));
        $this->claim($store, $context, $ready->id, new DateTimeImmutable('2026-07-26T00:05:00.111111Z'));
        [$snapshot, $result, $sourceHash] = $this->sealedResult($context);
        $this->assertLockedCas(fn () => $store->sealReady(
            $context,
            $ready->id,
            self::LEASE_TOKEN,
            $snapshot,
            $result,
            $sourceHash,
            new DateTimeImmutable('2026-07-26T00:31:00.555555Z'),
        ));
    }

    private function assertLockedCas(callable $transition): void
    {
        $connection = DB::connection();
        $connection->flushQueryLog();
        $connection->enableQueryLog();
        try {
            $transition();
            $queries = array_column($connection->getQueryLog(), 'query');
        } finally {
            $connection->disableQueryLog();
        }

        self::assertTrue(
            (bool) array_filter($queries, static fn (string $sql): bool => str_contains(strtolower($sql), 'for update')),
            'Transition did not acquire a row lock.',
        );
        self::assertTrue(
            (bool) array_filter($queries, static fn (string $sql): bool => str_starts_with(strtolower($sql), 'update')
                && str_contains($sql, '"organization_id"')
                && str_contains($sql, '"id"')
                && str_contains($sql, '"status"')),
            'Transition update did not use organization, id, and expected status CAS predicates.',
        );
    }

    public function test_tenant_isolation_and_persisted_definition_query_result_corruption_fail_closed(): void
    {
        $store = $this->store(new FakeReportTransitionAudit());
        $context = (new ReportExecutionContextBuilder())->build();
        $otherOrganization = $this->context(2, 2);
        $run = $store->createOrReuse($context, $this->query($context), null, new IdempotencyKey('corruption-key'));

        try {
            $store->get($otherOrganization, $run->id);
            self::fail('Expected cross-tenant not found.');
        } catch (ReportContractException $exception) {
            self::assertSame(ReportErrorCode::REPORT_NOT_FOUND, $exception->errorCode);
        }
        try {
            $store->claimMaterialization(
                $otherOrganization,
                $run->id,
                self::LEASE_TOKEN,
                new DateTimeImmutable('2026-07-26T00:15:00.123456Z'),
                new DateTimeImmutable('2026-07-26T00:05:00.123456Z'),
            );
            self::fail('Expected cross-tenant transition not found.');
        } catch (ReportContractException $exception) {
            self::assertSame(ReportErrorCode::REPORT_NOT_FOUND, $exception->errorCode);
        }

        ReportRunRecord::query()->whereKey($run->id)->update(['definition_snapshot_hash' => str_repeat('f', 64)]);
        try {
            $store->get($context, $run->id);
            self::fail('Expected definition corruption failure.');
        } catch (ReportContractException $exception) {
            self::assertSame(ReportErrorCode::REPORT_INTERNAL_ERROR, $exception->errorCode);
        }

        ReportRunRecord::query()->whereKey($run->id)->update([
            'definition_snapshot_hash' => hash('sha256', \App\BusinessModules\Core\Reporting\Support\CanonicalJson::encode(
                ReportRunRecord::query()->findOrFail($run->id)->definition_snapshot,
            )),
            'canonical_query_json' => '{}',
        ]);
        try {
            $store->queryForRun($context, $run->id);
            self::fail('Expected query corruption failure.');
        } catch (ReportContractException $exception) {
            self::assertSame(ReportErrorCode::REPORT_INTERNAL_ERROR, $exception->errorCode);
        }
    }

    public function test_persisted_ready_result_corruption_fails_closed(): void
    {
        $store = $this->store(new FakeReportTransitionAudit());
        $context = (new ReportExecutionContextBuilder())->build();
        $run = $store->createOrReuse($context, $this->query($context), null, new IdempotencyKey('result-corruption'));
        $this->claim($store, $context, $run->id, new DateTimeImmutable('2026-07-26T00:05:00.222222Z'));
        [$snapshot, $result, $sourceHash] = $this->sealedResult($context);
        $store->sealReady(
            $context,
            $run->id,
            self::LEASE_TOKEN,
            $snapshot,
            $result,
            $sourceHash,
            new DateTimeImmutable('2026-07-26T00:31:00.345678Z'),
        );

        ReportRunRecord::query()->whereKey($run->id)->update(['row_schema' => '[{"id":"corrupted"}]']);
        try {
            $store->get($context, $run->id);
            self::fail('Expected result corruption failure.');
        } catch (ReportContractException $exception) {
            self::assertSame(ReportErrorCode::REPORT_INTERNAL_ERROR, $exception->errorCode);
        }
    }

    public function test_real_concurrent_create_waits_on_uncommitted_slot_then_rechecks_as_reused(): void
    {
        self::assertTrue(function_exists('pcntl_fork'), 'PostgreSQL concurrency gate requires pcntl.');
        self::assertTrue(function_exists('pcntl_waitpid'), 'PostgreSQL concurrency gate requires pcntl.');
        self::assertTrue(function_exists('posix_kill'), 'PostgreSQL concurrency gate requires posix.');
        $suffix = bin2hex(random_bytes(6));
        $organizationId = 900000000 + hexdec(substr($suffix, 0, 6));
        $key = "concurrent-{$suffix}";
        $lockKey = random_int(100000, 2000000000);
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR."report-race-{$suffix}";
        $connectionName = "report_barrier_{$suffix}";
        $function = "report_barrier_fn_{$suffix}";
        $trigger = "report_barrier_trg_{$suffix}";
        $children = [];
        $barrierLocked = false;
        $connection = null;

        self::assertTrue(mkdir($directory));
        try {
            $connection = $this->independentConnection($connectionName);
            $connection->statement("SET lock_timeout = '10s'");
            $connection->statement("SET statement_timeout = '12s'");
            $connection->statement('SELECT pg_advisory_lock(?)', [$lockKey]);
            $barrierLocked = true;
            $connection->unprepared(
                "CREATE FUNCTION {$function}() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN ".
                "IF NEW.organization_id = {$organizationId} THEN PERFORM pg_advisory_xact_lock({$lockKey}); END IF; ".
                'RETURN NEW; END $$',
            );
            $connection->unprepared(
                "CREATE TRIGGER {$trigger} AFTER INSERT ON report_runs FOR EACH ROW EXECUTE FUNCTION {$function}()",
            );

            $children[] = $this->spawnCreateWorker($directory, 0, $organizationId, $key);
            file_put_contents($directory.DIRECTORY_SEPARATOR.'go-0', 'go');
            $pidOne = $this->waitForWorkerBackendPid($directory, 0);
            $this->waitForPostgresWait($connection, $pidOne, 'advisory', 10.0);

            $children[] = $this->spawnCreateWorker($directory, 1, $organizationId, $key);
            file_put_contents($directory.DIRECTORY_SEPARATOR.'go-1', 'go');
            $pidTwo = $this->waitForWorkerBackendPid($directory, 1);
            $this->waitForPostgresWait($connection, $pidTwo, 'transactionid', 10.0);

            $connection->statement('SELECT pg_advisory_unlock(?)', [$lockKey]);
            $barrierLocked = false;
            $this->waitForChildren($children, 15.0);
            $created = [$this->workerResult($directory, 0), $this->workerResult($directory, 1)];

            self::assertSame($created[0]['id'], $created[1]['id']);
            $dispositions = [$created[0]['disposition'], $created[1]['disposition']];
            sort($dispositions);
            self::assertSame(['created', 'reused'], $dispositions);
        } finally {
            $cleanupFailure = null;
            if ($barrierLocked && $connection !== null) {
                $this->cleanupStep(
                    static fn () => $connection->statement('SELECT pg_advisory_unlock(?)', [$lockKey]),
                    $cleanupFailure,
                );
            }
            $this->terminateAndReap($children);
            if ($connection !== null) {
                $this->cleanupStep(
                    static fn () => $connection->unprepared("DROP TRIGGER IF EXISTS {$trigger} ON report_runs"),
                    $cleanupFailure,
                );
                $this->cleanupStep(
                    static fn () => $connection->unprepared("DROP FUNCTION IF EXISTS {$function}()"),
                    $cleanupFailure,
                );
                $this->cleanupStep(
                    static fn () => $connection->table('report_runs')->where('organization_id', $organizationId)->delete(),
                    $cleanupFailure,
                );
            }
            DB::purge($connectionName);
            $this->removeWorkerDirectory($directory);
            if ($cleanupFailure !== null) {
                throw $cleanupFailure;
            }
        }
    }

    public function test_stale_expected_status_hits_zero_row_production_cas_branch(): void
    {
        self::assertTrue(function_exists('pcntl_fork'), 'PostgreSQL CAS gate requires pcntl.');
        self::assertTrue(function_exists('posix_kill'), 'PostgreSQL CAS gate requires posix.');
        $suffix = bin2hex(random_bytes(6));
        $organizationId = 1200000000 + hexdec(substr($suffix, 0, 6));
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR."report-cas-{$suffix}";
        $connectionName = "report_cas_{$suffix}";
        $children = [];
        $connection = null;
        self::assertTrue(mkdir($directory));

        try {
            $connection = $this->independentConnection($connectionName);
            $connection->statement("SET lock_timeout = '10s'");
            $connection->statement("SET statement_timeout = '12s'");
            $children[] = $this->spawnCreateWorker($directory, 0, $organizationId, "cas-{$suffix}");
            file_put_contents($directory.DIRECTORY_SEPARATOR.'go-0', 'go');
            $this->waitForChildren($children, 15.0);
            $created = $this->workerResult($directory, 0);
            $stale = ReportRunRecord::query()
                ->where('organization_id', $organizationId)
                ->whereKey($created['id'])
                ->firstOrFail();
            $connection->table('report_runs')
                ->where('organization_id', $organizationId)
                ->where('id', $created['id'])
                ->update([
                    'status' => ReportRunStatus::MATERIALIZING->value,
                    'started_at' => '2026-07-26 00:05:00.123456+00',
                    'updated_at' => '2026-07-26 00:05:00.123456+00',
                ]);

            $store = $this->store(new FakeReportTransitionAudit());
            $primitive = new \ReflectionMethod(EloquentReportRunStore::class, 'statusQualifiedUpdate');
            self::assertSame(0, $primitive->invoke(
                $store,
                $stale,
                ReportRunStatus::QUEUED,
                ['updated_at' => new DateTimeImmutable('2026-07-26T00:06:00.234567Z')],
            ));
            $cas = new \ReflectionMethod(EloquentReportRunStore::class, 'cas');
            try {
                $cas->invoke(
                    $store,
                    $stale,
                    ReportRunStatus::QUEUED,
                    ['updated_at' => new DateTimeImmutable('2026-07-26T00:06:00.234567Z')],
                );
                self::fail('Expected zero-row stale status CAS rejection.');
            } catch (\ReflectionException $exception) {
                throw $exception;
            } catch (\Throwable $exception) {
                self::assertInstanceOf(ReportContractException::class, $exception);
                self::assertSame(
                    ReportErrorCode::REPORT_SNAPSHOT_NOT_READY,
                    $exception->errorCode,
                );
            }
            self::assertSame(
                'materializing',
                $connection->table('report_runs')->where('id', $created['id'])->value('status'),
            );
        } finally {
            $cleanupFailure = null;
            $this->terminateAndReap($children);
            if ($connection !== null) {
                $this->cleanupStep(
                    static fn () => $connection->table('report_runs')->where('organization_id', $organizationId)->delete(),
                    $cleanupFailure,
                );
            }
            DB::purge($connectionName);
            $this->removeWorkerDirectory($directory);
            if ($cleanupFailure !== null) {
                throw $cleanupFailure;
            }
        }
    }

    public function test_same_token_renewal_serializes_behind_the_run_row_lock(): void
    {
        self::assertTrue(function_exists('pcntl_fork'), 'PostgreSQL renewal gate requires pcntl.');
        self::assertTrue(function_exists('posix_kill'), 'PostgreSQL renewal gate requires posix.');
        $suffix = bin2hex(random_bytes(6));
        $organizationId = 1300000000 + hexdec(substr($suffix, 0, 6));
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR."report-renewal-{$suffix}";
        $connectionName = "report_renewal_{$suffix}";
        $children = [];
        $connection = null;
        $transactionOpen = false;
        self::assertTrue(mkdir($directory));

        try {
            $connection = $this->independentConnection($connectionName);
            $connection->statement("SET lock_timeout = '10s'");
            $connection->statement("SET statement_timeout = '12s'");
            $children[] = $this->spawnCreateWorker($directory, 0, $organizationId, "renewal-{$suffix}");
            file_put_contents($directory.DIRECTORY_SEPARATOR.'go-0', 'go');
            $this->waitForChildren($children, 15.0);
            $children = [];
            $created = $this->workerResult($directory, 0);

            $children[] = $this->spawnClaimWorker(
                $directory,
                1,
                $organizationId,
                $created['id'],
                new DateTimeImmutable('2026-07-26T10:00:00.900000Z'),
                new DateTimeImmutable('2026-07-26T09:55:00.000000Z'),
            );
            file_put_contents($directory.DIRECTORY_SEPARATOR.'go-1', 'go');
            $this->waitForChildren($children, 15.0);
            $children = [];
            self::assertSame('materializing', $this->workerResult($directory, 1)['status']);

            $connection->beginTransaction();
            $transactionOpen = true;
            self::assertNotNull(
                $connection->table('report_runs')
                    ->where('organization_id', $organizationId)
                    ->where('id', $created['id'])
                    ->lockForUpdate()
                    ->first(),
            );

            $children[] = $this->spawnClaimWorker(
                $directory,
                2,
                $organizationId,
                $created['id'],
                new DateTimeImmutable('2026-07-26T10:15:00.333333Z'),
                new DateTimeImmutable('2026-07-26T09:59:59.222222Z'),
            );
            file_put_contents($directory.DIRECTORY_SEPARATOR.'go-2', 'go');
            $renewalPid = $this->waitForWorkerBackendPid($directory, 2);
            $this->waitForPostgresWait($connection, $renewalPid, 'transactionid', 10.0);

            $connection->commit();
            $transactionOpen = false;
            $this->waitForChildren($children, 15.0);
            $children = [];
            self::assertSame('materializing', $this->workerResult($directory, 2)['status']);
            self::assertSame(
                '2026-07-26 10:15:00.333333+00',
                $connection->table('report_runs')
                    ->where('organization_id', $organizationId)
                    ->where('id', $created['id'])
                    ->value('execution_lease_expires_at'),
            );
        } finally {
            $cleanupFailure = null;
            if ($transactionOpen && $connection !== null) {
                $this->cleanupStep(static fn () => $connection->rollBack(), $cleanupFailure);
            }
            $this->terminateAndReap($children);
            if ($connection !== null) {
                $this->cleanupStep(
                    static fn () => $connection->table('report_runs')->where('organization_id', $organizationId)->delete(),
                    $cleanupFailure,
                );
            }
            DB::purge($connectionName);
            $this->removeWorkerDirectory($directory);
            if ($cleanupFailure !== null) {
                throw $cleanupFailure;
            }
        }
    }

    private function spawnCreateWorker(string $directory, int $index, int $organizationId, string $key): int
    {
        $pid = (int) call_user_func('pcntl_fork');
        self::assertGreaterThanOrEqual(0, $pid);
        if ($pid !== 0) {
            return $pid;
        }

        try {
            $this->waitForFile($directory.DIRECTORY_SEPARATOR."go-{$index}", 10.0);
            DB::purge();
            DB::statement("SET lock_timeout = '30s'");
            DB::statement("SET statement_timeout = '30s'");
            $backend = DB::selectOne('SELECT pg_backend_pid() AS pid');
            file_put_contents($directory.DIRECTORY_SEPARATOR."pid-{$index}", (string) $backend->pid);
            $context = $this->context($organizationId, $organizationId + $index + 1);
            $run = $this->store(new FakeReportTransitionAudit())->createOrReuse(
                $context,
                $this->query($context),
                null,
                new IdempotencyKey($key),
            );
            $result = ['ok' => true, 'value' => ['id' => $run->id, 'disposition' => $run->httpDisposition]];
        } catch (\Throwable $exception) {
            $result = ['ok' => false, 'error' => $exception::class, 'message' => $exception->getMessage()];
        }
        file_put_contents(
            $directory.DIRECTORY_SEPARATOR."result-{$index}.json",
            json_encode($result, JSON_THROW_ON_ERROR),
        );
        exit($result['ok'] ? 0 : 1);
    }

    private function spawnClaimWorker(
        string $directory,
        int $index,
        int $organizationId,
        string $runId,
        DateTimeImmutable $leaseExpiresAt,
        DateTimeImmutable $occurredAt,
    ): int {
        $pid = (int) call_user_func('pcntl_fork');
        self::assertGreaterThanOrEqual(0, $pid);
        if ($pid !== 0) {
            return $pid;
        }

        try {
            $this->waitForFile($directory.DIRECTORY_SEPARATOR."go-{$index}", 10.0);
            DB::purge();
            DB::statement("SET lock_timeout = '30s'");
            DB::statement("SET statement_timeout = '30s'");
            $backend = DB::selectOne('SELECT pg_backend_pid() AS pid');
            file_put_contents($directory.DIRECTORY_SEPARATOR."pid-{$index}", (string) $backend->pid);
            $run = $this->store(new FakeReportTransitionAudit())->claimMaterialization(
                $this->context($organizationId, $organizationId + $index + 1),
                $runId,
                self::LEASE_TOKEN,
                $leaseExpiresAt,
                $occurredAt,
            );
            $result = ['ok' => true, 'value' => ['status' => $run->status->value]];
        } catch (\Throwable $exception) {
            $result = ['ok' => false, 'error' => $exception::class, 'message' => $exception->getMessage()];
        }
        file_put_contents(
            $directory.DIRECTORY_SEPARATOR."result-{$index}.json",
            json_encode($result, JSON_THROW_ON_ERROR),
        );
        exit($result['ok'] ? 0 : 1);
    }

    private function waitForWorkerBackendPid(string $directory, int $index): int
    {
        $path = $directory.DIRECTORY_SEPARATOR."pid-{$index}";
        $this->waitForFile($path, 10.0);

        return (int) file_get_contents($path);
    }

    private function waitForPostgresWait($connection, int $backendPid, string $waitEvent, float $timeoutSeconds): void
    {
        $this->waitUntil(function () use ($connection, $backendPid, $waitEvent): bool {
            $activity = $connection->selectOne(
                'SELECT wait_event_type, wait_event FROM pg_stat_activity WHERE pid = ?',
                [$backendPid],
            );

            return $activity !== null
                && $activity->wait_event_type === 'Lock'
                && $activity->wait_event === $waitEvent;
        }, $timeoutSeconds, "Backend {$backendPid} did not enter {$waitEvent} lock wait.");
    }

    private function waitForFile(string $path, float $timeoutSeconds): void
    {
        $this->waitUntil(static fn (): bool => is_file($path), $timeoutSeconds, "Timed out waiting for {$path}.");
    }

    private function waitUntil(callable $condition, float $timeoutSeconds, string $failure): void
    {
        $deadline = microtime(true) + $timeoutSeconds;
        while (!$condition()) {
            if (microtime(true) >= $deadline) {
                self::fail($failure);
            }
            usleep(10000);
        }
    }

    private function waitForChildren(array $children, float $timeoutSeconds): void
    {
        $remaining = array_fill_keys($children, true);
        $deadline = microtime(true) + $timeoutSeconds;
        while ($remaining !== []) {
            foreach (array_keys($remaining) as $pid) {
                $status = 0;
                $result = (int) call_user_func_array('pcntl_waitpid', [$pid, &$status, 1]);
                if ($result === $pid) {
                    self::assertSame(0, $status, "Concurrent worker {$pid} failed.");
                    unset($remaining[$pid]);
                }
            }
            if ($remaining !== [] && microtime(true) >= $deadline) {
                self::fail('Timed out waiting for concurrent workers.');
            }
            if ($remaining !== []) {
                usleep(10000);
            }
        }
    }

    private function terminateAndReap(array $children): void
    {
        foreach ($children as $pid) {
            $status = 0;
            $result = (int) call_user_func_array('pcntl_waitpid', [$pid, &$status, 1]);
            if ($result === 0) {
                call_user_func('posix_kill', $pid, 15);
                $deadline = microtime(true) + 2.0;
                while ($result === 0 && microtime(true) < $deadline) {
                    usleep(10000);
                    $result = (int) call_user_func_array('pcntl_waitpid', [$pid, &$status, 1]);
                }
                if ($result === 0) {
                    call_user_func('posix_kill', $pid, 9);
                    $deadline = microtime(true) + 2.0;
                    while ($result === 0 && microtime(true) < $deadline) {
                        usleep(10000);
                        $result = (int) call_user_func_array('pcntl_waitpid', [$pid, &$status, 1]);
                    }
                }
            }
        }
    }

    private function workerResult(string $directory, int $index): array
    {
        $path = $directory.DIRECTORY_SEPARATOR."result-{$index}.json";
        $this->waitForFile($path, 2.0);
        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($decoded['ok'], (string) ($decoded['message'] ?? 'worker_failed'));

        return $decoded['value'];
    }

    private function independentConnection(string $name)
    {
        $default = (string) config('database.default');
        config(["database.connections.{$name}" => config("database.connections.{$default}")]);

        return DB::connection($name);
    }

    private function removeWorkerDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        foreach (glob($directory.DIRECTORY_SEPARATOR.'*') ?: [] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        rmdir($directory);
    }

    private function cleanupStep(callable $cleanup, ?\Throwable &$failure): void
    {
        try {
            $cleanup();
        } catch (\Throwable $exception) {
            $failure ??= $exception;
        }
    }

    private function store(
        ReportTransitionAudit $audit,
        ?DateTimeImmutable $now = null,
    ): EloquentReportRunStore
    {
        return new EloquentReportRunStore(
            new FakeReportExecutionClock($now ?? new DateTimeImmutable('2026-07-26T00:00:00.111111Z')),
            $audit,
            new ReportRunHydrator(),
            new EloquentReportDispatchIntentStore($audit),
            3600,
            1250,
        );
    }

    private function claim(
        EloquentReportRunStore $store,
        ReportExecutionContext $context,
        string $runId,
        DateTimeImmutable $occurredAt,
    ): void {
        $store->claimMaterialization(
            $context,
            $runId,
            self::LEASE_TOKEN,
            $occurredAt->modify('+10 minutes'),
            $occurredAt,
        );
    }

    private function query(
        ReportExecutionContext $context,
        ReportSnapshotClassification $classification = ReportSnapshotClassification::OPERATIONAL,
    ): ReportQuery
    {
        return new ReportQuery(
            (new ReportDefinitionBuilder())->code('cost_control')->snapshotClassification($classification)->payload(),
            $context->scope,
            new ReportFilterSet(['period' => 'month']),
            [],
            new DateTimeImmutable('2026-07-26T00:00:00.123456Z'),
            'ru',
        );
    }

    private function context(int $organizationId, int $actorId): ReportExecutionContext
    {
        $timezone = new DateTimeZone('UTC');
        $scope = new ReportScope($organizationId, [$organizationId], [], [], $timezone);

        return (new ReportExecutionContextBuilder())
            ->actor(new ReportActor($actorId, 'active', ['reports.view']))
            ->scope($scope)
            ->authorization(new AuthorizationDecisionContext(
                'http',
                $organizationId,
                [$organizationId],
                [],
                [],
                $timezone,
                "report-test-{$organizationId}",
                null,
            ))
            ->build();
    }

    private function sealedResult(
        ReportExecutionContext $context,
        string $total = '100.00',
        ReportSnapshotClassification $classification = ReportSnapshotClassification::OPERATIONAL,
        ?ReportSnapshotSeal $seal = null,
    ): array
    {
        $sourceHash = new Sha256Hash(str_repeat('e', 64));
        $snapshot = new ReportSnapshotRef(
            'materialized',
            'snapshot_one',
            $context->scope,
            new Sha256Hash(str_repeat('a', 64)),
            '1',
            $sourceHash,
            new DateTimeImmutable('2026-07-26T00:30:00.234567Z'),
            null,
            ['ledger' => 'watermark_one'],
            $classification,
            $seal,
        );
        $metadata = new ReportResultMetadata(
            $snapshot,
            1,
            new DateTimeImmutable('2026-07-26T00:30:00.234567Z'),
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
            'ledger',
            [new ReportSourceRef(
                'ledger',
                'materialized',
                'snapshot_one',
                'v1',
                'watermark_one',
                1,
                $sourceHash,
            )],
            $sourceHash,
            null,
        );
        $result = new ReportResult(
            $metadata,
            ['amount' => $total],
            ReportFreshnessStatus::FRESH,
            $quality,
            $provenance,
            [['id' => 'amount']],
            ['drill_down' => true],
        );

        return [$snapshot, $result, $sourceHash];
    }
}
