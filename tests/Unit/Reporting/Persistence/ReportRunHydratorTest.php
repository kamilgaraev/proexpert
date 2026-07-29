<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Persistence;

use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportRunStore;
use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\EloquentReportRunStore;
use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\Models\ReportRunRecord;
use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\ReportRunHydrator;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use Illuminate\Container\Container;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Facade;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class ReportRunHydratorTest extends TestCase
{
    public function test_store_contract_has_exact_typed_surface(): void
    {
        $reflection = new ReflectionClass(ReportRunStore::class);
        $methods = [];

        foreach ($reflection->getMethods() as $method) {
            $methods[$method->getName()] = [
                'parameters' => array_map(
                    static fn ($parameter): array => [
                        $parameter->getName(),
                        (string) $parameter->getType(),
                    ],
                    $method->getParameters(),
                ),
                'return' => (string) $method->getReturnType(),
            ];
        }
        ksort($methods);

        $expected = [
            'cancel' => [
                'parameters' => [['context', 'App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext'], ['runId', 'string'], ['occurredAt', 'DateTimeImmutable']],
                'return' => 'App\BusinessModules\Core\Reporting\Domain\DTO\ReportRun',
            ],
            'createOrReuse' => [
                'parameters' => [['context', 'App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext'], ['query', 'App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery'], ['savedView', '?App\BusinessModules\Core\Reporting\Domain\DTO\ReportSavedViewRef'], ['idempotencyKey', 'App\BusinessModules\Core\Reporting\Domain\ValueObjects\IdempotencyKey']],
                'return' => 'App\BusinessModules\Core\Reporting\Domain\DTO\ReportRun',
            ],
            'claimMaterialization' => [
                'parameters' => [['context', 'App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext'], ['runId', 'string'], ['leaseToken', 'string'], ['leaseExpiresAt', 'DateTimeImmutable'], ['occurredAt', 'DateTimeImmutable']],
                'return' => 'App\BusinessModules\Core\Reporting\Domain\DTO\ReportRun',
            ],
            'exportSource' => [
                'parameters' => [['context', 'App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext'], ['runId', 'string']],
                'return' => 'App\BusinessModules\Core\Reporting\Application\Execution\ReportRunExportSource',
            ],
            'fail' => [
                'parameters' => [['context', 'App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext'], ['runId', 'string'], ['leaseToken', '?string'], ['errorCode', 'App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode'], ['occurredAt', 'DateTimeImmutable']],
                'return' => 'App\BusinessModules\Core\Reporting\Domain\DTO\ReportRun',
            ],
            'get' => [
                'parameters' => [['context', 'App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext'], ['runId', 'string']],
                'return' => 'App\BusinessModules\Core\Reporting\Domain\DTO\ReportRun',
            ],
            'persistProgress' => [
                'parameters' => [['context', 'App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext'], ['runId', 'string'], ['leaseToken', 'string'], ['progress', 'App\BusinessModules\Core\Reporting\Domain\DTO\ReportProgress'], ['leaseExpiresAt', 'DateTimeImmutable'], ['occurredAt', 'DateTimeImmutable']],
                'return' => 'App\BusinessModules\Core\Reporting\Domain\DTO\ReportRun',
            ],
            'queryForRun' => [
                'parameters' => [['context', 'App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext'], ['runId', 'string']],
                'return' => 'App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery',
            ],
            'retrySource' => [
                'parameters' => [['context', 'App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext'], ['runId', 'string']],
                'return' => 'App\BusinessModules\Core\Reporting\Application\Execution\ReportRunRetrySource',
            ],
            'sealReady' => [
                'parameters' => [['context', 'App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext'], ['runId', 'string'], ['leaseToken', 'string'], ['snapshot', 'App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef'], ['result', 'App\BusinessModules\Core\Reporting\Domain\DTO\ReportResult'], ['sourceHash', 'App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash'], ['occurredAt', 'DateTimeImmutable']],
                'return' => 'App\BusinessModules\Core\Reporting\Domain\DTO\ReportRun',
            ],
        ];
        ksort($expected);
        self::assertSame($expected, $methods);
    }

    public function test_store_constructor_and_dependency_free_hydrator_surface_are_exact(): void
    {
        $storeConstructor = (new ReflectionClass(EloquentReportRunStore::class))->getConstructor();
        self::assertNotNull($storeConstructor);
        self::assertSame([
            ['clock', 'App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportExecutionClock'],
            ['audit', 'App\BusinessModules\Core\Reporting\Application\Audit\ReportTransitionAudit'],
            ['hydrator', 'App\BusinessModules\Core\Reporting\Infrastructure\Persistence\ReportRunHydrator'],
            ['dispatchIntents', 'App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportDispatchIntentStore'],
            ['runTtlSeconds', 'int'],
            ['pollAfterMs', 'int'],
        ], array_map(
            static fn ($parameter): array => [$parameter->getName(), (string) $parameter->getType()],
            $storeConstructor->getParameters(),
        ));

        $hydrator = new ReflectionClass(ReportRunHydrator::class);
        self::assertNull($hydrator->getConstructor());
        $methods = array_map(
            static fn ($method): string => $method->getName(),
            $hydrator->getMethods(\ReflectionMethod::IS_PUBLIC),
        );
        sort($methods);
        self::assertSame(['exportSource', 'hydrate', 'query', 'retrySource'], $methods);
        self::assertSame(
            [
                ['record', 'App\BusinessModules\Core\Reporting\Infrastructure\Persistence\Models\ReportRunRecord'],
                ['httpDisposition', 'string'],
                ['pollAfterMs', 'int'],
            ],
            array_map(
                static fn ($parameter): array => [$parameter->getName(), (string) $parameter->getType()],
                $hydrator->getMethod('hydrate')->getParameters(),
            ),
        );
        self::assertSame('App\BusinessModules\Core\Reporting\Domain\DTO\ReportRun', (string) $hydrator->getMethod('hydrate')->getReturnType());
        self::assertSame(
            [['record', 'App\BusinessModules\Core\Reporting\Infrastructure\Persistence\Models\ReportRunRecord']],
            array_map(
                static fn ($parameter): array => [$parameter->getName(), (string) $parameter->getType()],
                $hydrator->getMethod('query')->getParameters(),
            ),
        );
        self::assertSame('App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery', (string) $hydrator->getMethod('query')->getReturnType());
        self::assertSame(
            [
                ['record', 'App\BusinessModules\Core\Reporting\Infrastructure\Persistence\Models\ReportRunRecord'],
                ['pollAfterMs', 'int'],
            ],
            array_map(
                static fn ($parameter): array => [$parameter->getName(), (string) $parameter->getType()],
                $hydrator->getMethod('retrySource')->getParameters(),
            ),
        );
        self::assertSame('App\BusinessModules\Core\Reporting\Application\Execution\ReportRunRetrySource', (string) $hydrator->getMethod('retrySource')->getReturnType());
        self::assertSame(
            [
                ['record', 'App\BusinessModules\Core\Reporting\Infrastructure\Persistence\Models\ReportRunRecord'],
                ['pollAfterMs', 'int'],
            ],
            array_map(
                static fn ($parameter): array => [$parameter->getName(), (string) $parameter->getType()],
                $hydrator->getMethod('exportSource')->getParameters(),
            ),
        );
        self::assertSame('App\BusinessModules\Core\Reporting\Application\Execution\ReportRunExportSource', (string) $hydrator->getMethod('exportSource')->getReturnType());
    }

    public function test_hydrates_queued_run_and_reconstructs_exact_query(): void
    {
        $record = $this->record();
        $hydrator = new ReportRunHydrator;

        $query = $hydrator->query($record);
        $run = $hydrator->hydrate($record, 'created', 1250);

        self::assertSame($record->canonical_query_json, $query->canonicalJson);
        self::assertSame($record->query_hash, $query->queryHash->value);
        self::assertSame('created', $run->httpDisposition);
        self::assertSame(1250, $run->pollAfterMs);
        self::assertSame('2026-07-26T01:00:00+00:00', $run->expiresAt->format(DATE_ATOM));
        self::assertNull($run->sourceHash);
        self::assertSame([], $run->totals);
    }

    public function test_migration_emits_microsecond_timestamptz_for_every_persisted_instant(): void
    {
        $application = new Container;
        $schema = Mockery::mock();
        $database = Mockery::mock();
        $blueprint = null;
        $application->instance('db.schema', $schema);
        $application->instance('db', $database);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($application);
        $database->shouldReceive('raw')
            ->once()
            ->with("'[]'::jsonb")
            ->andReturn(new Expression("'[]'::jsonb"));
        $database->shouldReceive('statement')->times(24)->andReturnTrue();
        $schema->shouldReceive('create')
            ->once()
            ->with('report_runs', Mockery::on(static function (callable $callback) use (&$blueprint): bool {
                $blueprint = new Blueprint('report_runs');
                $callback($blueprint);

                return true;
            }));

        try {
            $migration = require dirname(__DIR__, 4).'/database/migrations/2026_07_26_000001_create_report_runs_table.php';
            $migration->up();
            self::assertInstanceOf(Blueprint::class, $blueprint);
            $timestampColumns = [];
            foreach ($blueprint->getColumns() as $column) {
                if ($column->type === 'timestampTz') {
                    $timestampColumns[$column->name] = $column->precision;
                }
            }

            self::assertSame([
                'as_of' => 6,
                'snapshot_generated_at' => 6,
                'snapshot_stale_at' => 6,
                'snapshot_sealed_at' => 6,
                'execution_lease_expires_at' => 6,
                'execution_heartbeat_at' => 6,
                'queued_at' => 6,
                'started_at' => 6,
                'ready_at' => 6,
                'failed_at' => 6,
                'cancel_requested_at' => 6,
                'cancelled_at' => 6,
                'expired_at' => 6,
                'created_at' => 6,
                'updated_at' => 6,
                'expires_at' => 6,
            ], $timestampColumns);
        } finally {
            Facade::clearResolvedInstances();
            Facade::setFacadeApplication(null);
            Mockery::close();
        }
    }

    #[DataProvider('errorCodeCorruptions')]
    public function test_error_code_is_closed_to_failed_lifecycle(callable $mutate): void
    {
        $record = $this->record();
        $mutate($record);

        $this->expectException(\Throwable::class);
        (new ReportRunHydrator)->hydrate($record, 'reused', 1250);
    }

    public static function errorCodeCorruptions(): iterable
    {
        yield 'unknown on failed' => [static function (ReportRunRecord $record): void {
            $record->status = 'failed';
            $record->error_code = 'UNKNOWN';
        }];
        yield 'missing on failed' => [static function (ReportRunRecord $record): void {
            $record->status = 'failed';
            $record->error_code = null;
        }];
        yield 'present on queued' => [static function (ReportRunRecord $record): void {
            $record->error_code = 'REPORT_INTERNAL_ERROR';
        }];
    }

    #[DataProvider('definitionMemberMutations')]
    public function test_definition_snapshot_content_mutation_is_rejected_by_store_digest(string $member, mixed $replacement): void
    {
        $record = $this->record();
        $snapshot = $record->definition_snapshot;
        $snapshot[$member] = $replacement;
        $record->definition_snapshot = $snapshot;

        $this->expectException(\Throwable::class);
        (new ReportRunHydrator)->query($record);
    }

    public static function definitionMemberMutations(): iterable
    {
        yield 'code' => ['code', 'changed_report'];
        yield 'definition_hash' => ['definition_hash', str_repeat('b', 64)];
        yield 'contract_version' => ['contract_version', '2.0.0'];
        yield 'formula_version' => ['formula_version', '2'];
        yield 'source_schema_version' => ['source_schema_version', '2'];
        yield 'renderer_version' => ['renderer_version', '2'];
        yield 'filters' => ['filters', [['id' => 'changed_filter']]];
        yield 'columns' => ['columns', [['id' => 'changed_column']]];
        yield 'sorts' => ['sorts', [['id' => 'changed_sort']]];
        yield 'formats' => ['formats', ['xlsx']];
        yield 'permission_policy' => ['permission_policy', [
            'view_permissions' => ['reports.changed'],
            'export_permissions' => ['reports.export'],
            'sensitive_permissions' => [],
            'audit_permissions' => [],
        ]];
        yield 'publication_readiness' => ['publication_readiness', 'candidate'];
        yield 'supports_subscriptions' => ['supports_subscriptions', true];
    }

    public function test_ready_row_schema_mutation_is_rejected_by_complete_result_digest(): void
    {
        $record = $this->readyRecord();
        $record->row_schema = [['id' => 'mutated']];

        $this->expectException(\Throwable::class);
        (new ReportRunHydrator)->hydrate($record, 'reused', 1250);
    }

    public function test_ready_fixture_hydrates_before_integrity_mutations(): void
    {
        $run = (new ReportRunHydrator)->hydrate($this->readyRecord(), 'reused', 1250);

        self::assertSame('ready', $run->status->value);
        self::assertSame(1, $run->rowCount);
    }

    public function test_export_source_rehydrates_complete_result_and_rederives_identity(): void
    {
        $record = $this->readyRecord();

        $source = (new ReportRunHydrator)->exportSource($record, 1250);

        self::assertSame('ready', $source->run->status->value);
        self::assertSame($record->result_hash, $source->resultHash->value);
        self::assertSame($record->snapshot_id, $source->snapshot->id);
        self::assertSame([['id' => 'persisted_amount']], $source->result->rowSchema);
        self::assertSame(['drill_down' => true], $source->result->capabilities);
        self::assertSame('standard', $source->dataClassification->value);
    }

    public function test_retry_source_accepts_only_terminal_run_and_preserves_error(): void
    {
        $record = $this->record();
        $attributes = $record->getAttributes();
        $attributes['status'] = 'failed';
        $attributes['error_code'] = 'REPORT_SOURCE_UNAVAILABLE';
        $attributes['failed_at'] = '2026-07-26T00:10:00.000000Z';
        $attributes['updated_at'] = '2026-07-26T00:10:00.000000Z';
        $record->setRawAttributes($attributes);

        $source = (new ReportRunHydrator)->retrySource($record, 1250);

        self::assertSame('failed', $source->run->status->value);
        self::assertSame('REPORT_SOURCE_UNAVAILABLE', $source->errorCode?->value);
        self::assertNull($source->savedView);
    }

    #[DataProvider('leaseCorruptions')]
    public function test_execution_lease_shape_fails_closed(callable $mutate): void
    {
        $record = $this->record();
        $mutate($record);

        $this->expectException(\Throwable::class);
        (new ReportRunHydrator)->hydrate($record, 'reused', 1250);
    }

    public static function leaseCorruptions(): iterable
    {
        yield 'queued carries lease' => [static function (ReportRunRecord $record): void {
            $record->setRawAttributes([...$record->getAttributes(),
                'execution_lease_token' => '00000000-0000-4000-8000-000000000001',
                'execution_lease_expires_at' => '2026-07-26T00:20:00.000000Z',
                'execution_heartbeat_at' => '2026-07-26T00:10:00.000000Z',
            ]);
        }];
        yield 'materializing missing lease' => [static function (ReportRunRecord $record): void {
            $record->status = 'materializing';
        }];
        yield 'materializing expired before heartbeat' => [static function (ReportRunRecord $record): void {
            $record->setRawAttributes([...$record->getAttributes(),
                'status' => 'materializing',
                'execution_lease_token' => '00000000-0000-4000-8000-000000000001',
                'execution_lease_expires_at' => '2026-07-26T00:10:00.000000Z',
                'execution_heartbeat_at' => '2026-07-26T00:10:00.000000Z',
            ]);
        }];
    }

    public function test_ready_capabilities_mutation_is_rejected_by_complete_result_digest(): void
    {
        $record = $this->readyRecord();
        $record->capabilities = ['unexpected'];

        $this->expectException(\Throwable::class);
        (new ReportRunHydrator)->hydrate($record, 'reused', 1250);
    }

    #[DataProvider('sealedResultMutations')]
    public function test_every_sealed_result_member_is_bound_by_result_digest(string $attribute, mixed $replacement): void
    {
        $record = $this->readyRecord();
        $record->{$attribute} = $replacement;

        $this->expectException(\Throwable::class);
        (new ReportRunHydrator)->hydrate($record, 'reused', 1250);
    }

    public static function sealedResultMutations(): iterable
    {
        yield 'metadata' => ['result_metadata', [
            'row_count' => 1,
            'generated_at' => '2026-07-26T00:30:00.000000Z',
            'stale_at' => '2026-07-26T00:45:00.000000Z',
        ]];
        yield 'metadata equivalent non-UTC instant' => ['result_metadata', [
            'row_count' => 1,
            'generated_at' => '2026-07-25T20:30:00.000000-04:00',
            'stale_at' => null,
        ]];
        yield 'totals' => ['totals', ['amount' => '101.00']];
        yield 'freshness' => ['freshness', 'stale'];
        yield 'quality' => ['quality', [
            'status' => 'partial',
            'coverage' => null,
            'warnings' => [],
            'unmatched_count' => 0,
            'reconciliation' => 'matched',
            'unknown_metrics' => [],
            'excluded_sources' => [],
        ]];
        yield 'provenance' => ['provenance', [
            'source_of_truth' => 'registry',
            'source_refs' => [[
                'source' => 'ledger',
                'snapshot_kind' => 'materialized',
                'snapshot_id' => 'snapshot_one',
                'schema_version' => 'v1',
                'watermark' => 'watermark_one',
                'row_count' => 1,
                'hash' => str_repeat('e', 64),
            ]],
            'source_hash' => str_repeat('e', 64),
            'external_confirmation_role' => null,
        ]];
        yield 'row schema' => ['row_schema', [['id' => 'changed_schema']]];
        yield 'capabilities' => ['capabilities', ['drill_down' => false]];
        yield 'result hash' => ['result_hash', str_repeat('f', 64)];
    }

    #[DataProvider('corruptions')]
    public function test_query_hydration_fails_closed(callable $mutate): void
    {
        $record = $this->record();
        $mutate($record);

        $this->expectException(\Throwable::class);
        (new ReportRunHydrator)->query($record);
    }

    public static function corruptions(): iterable
    {
        yield 'unknown definition member' => [static function (ReportRunRecord $record): void {
            $record->definition_snapshot = [...$record->definition_snapshot, 'unknown' => true];
        }];
        yield 'missing definition member' => [static function (ReportRunRecord $record): void {
            $snapshot = $record->definition_snapshot;
            unset($snapshot['columns']);
            $record->definition_snapshot = $snapshot;
        }];
        yield 'definition hash drift' => [static function (ReportRunRecord $record): void {
            $record->definition_hash = str_repeat('b', 64);
        }];
        yield 'snapshot classification drift' => [static function (ReportRunRecord $record): void {
            $record->snapshot_classification = 'official';
        }];
        yield 'data classification drift' => [static function (ReportRunRecord $record): void {
            $record->data_classification = 'sensitive';
        }];
        yield 'sensitive columns drift' => [static function (ReportRunRecord $record): void {
            $record->sensitive_column_ids = ['amount'];
        }];
        yield 'incomplete saved view reference' => [static function (ReportRunRecord $record): void {
            $record->saved_view_id = '01J00000000000000000000000';
        }];
        yield 'saved view fingerprint drift' => [static function (ReportRunRecord $record): void {
            $record->saved_view_id = '01J00000000000000000000000';
            $record->saved_view_revision = 1;
            $record->saved_view_hash = str_repeat('f', 64);
        }];
        yield 'scope drift' => [static function (ReportRunRecord $record): void {
            $record->scope_project_ids = [999];
        }];
        yield 'filter drift' => [static function (ReportRunRecord $record): void {
            $record->filters = ['period' => 'changed'];
        }];
        yield 'comparison drift' => [static function (ReportRunRecord $record): void {
            $record->comparison = ['mode' => 'previous'];
        }];
        yield 'timezone drift' => [static function (ReportRunRecord $record): void {
            $record->scope_timezone = 'Europe/Moscow';
        }];
        yield 'locale drift' => [static function (ReportRunRecord $record): void {
            $record->locale = 'en';
        }];
        yield 'as-of drift' => [static function (ReportRunRecord $record): void {
            $attributes = $record->getAttributes();
            $attributes['as_of'] = '2026-07-26T00:01:00+00:00';
            $record->setRawAttributes($attributes);
        }];
        yield 'canonical bytes drift' => [static function (ReportRunRecord $record): void {
            $record->canonical_query_json .= ' ';
        }];
    }

    private function record(): ReportRunRecord
    {
        $definitionHash = str_repeat('a', 64);
        $snapshot = [
            'code' => 'cost_control',
            'definition_hash' => $definitionHash,
            'contract_version' => '1.0.0',
            'formula_version' => '1',
            'source_schema_version' => '1',
            'renderer_version' => '1',
            'filters' => [['id' => 'period']],
            'columns' => [['id' => 'amount']],
            'sorts' => [['id' => 'amount']],
            'formats' => ['csv'],
            'permission_policy' => [
                'view_permissions' => ['reports.view'],
                'export_permissions' => ['reports.export'],
                'sensitive_permissions' => [],
                'audit_permissions' => [],
            ],
            'snapshot_classification' => 'operational',
            'output_classification' => [
                'default_classification' => 'standard',
                'sensitive_column_ids' => [],
                'audit_column_ids' => [],
                'totals_sensitive' => false,
                'totals_audit' => false,
                'provenance_audit' => false,
            ],
            'publication_readiness' => 'published',
            'supports_subscriptions' => false,
        ];
        $queryData = [
            'as_of' => '2026-07-26T00:00:00+00:00',
            'comparison' => [],
            'definition_hash' => $definitionHash,
            'filters' => ['period' => 'month'],
            'locale' => 'ru',
            'scope' => [
                'organization_id' => 10,
                'holding_organization_ids' => [10, 11],
                'project_ids' => [20],
                'resources' => [['kind' => 'task', 'id' => 30, 'project_id' => 20]],
                'timezone' => 'UTC',
            ],
        ];
        $canonical = CanonicalJson::encode($queryData);
        $definitionSnapshotCanonical = CanonicalJson::encode($snapshot);
        $definitionSnapshotHash = hash('sha256', $definitionSnapshotCanonical);
        $inputFingerprint = hash('sha256', CanonicalJson::encode([
            'definition_snapshot_hash' => $definitionSnapshotHash,
            'query' => $queryData,
            'saved_view' => null,
        ]));

        $record = new ReportRunRecord;
        $record->setRawAttributes([
            'id' => '01J3R6W7H8K9M0NPQRSTVWXYZ1',
            'organization_id' => 10,
            'requester_actor_id' => 100,
            'report_code' => 'cost_control',
            'status' => 'queued',
            'definition_hash' => $definitionHash,
            'definition_snapshot_hash' => $definitionSnapshotHash,
            'query_hash' => hash('sha256', $canonical),
            'source_hash' => null,
            'idempotency_key_hash' => str_repeat('c', 64),
            'input_fingerprint' => $inputFingerprint,
            'contract_version' => '1.0.0',
            'formula_version' => '1',
            'source_schema_version' => '1',
            'renderer_version' => '1',
            'definition_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR),
            'canonical_query_json' => $canonical,
            'scope_holding_organization_ids' => '[10,11]',
            'scope_project_ids' => '[20]',
            'scope_resources' => '[{"id":30,"project_id":20,"kind":"task"}]',
            'scope_timezone' => 'UTC',
            'filters' => '{"period":"month"}',
            'comparison' => '[]',
            'as_of' => '2026-07-26T00:00:00+00:00',
            'locale' => 'ru',
            'saved_view_id' => null,
            'saved_view_revision' => null,
            'saved_view_hash' => null,
            'snapshot_classification' => 'operational',
            'data_classification' => 'standard',
            'sensitive_column_ids' => '[]',
            'audit_column_ids' => '[]',
            'progress' => 0,
            'totals' => '[]',
            'created_at' => '2026-07-26T00:00:00+00:00',
            'updated_at' => '2026-07-26T00:00:00+00:00',
            'expires_at' => '2026-07-26T01:00:00+00:00',
        ]);

        return $record;
    }

    private function readyRecord(): ReportRunRecord
    {
        $record = $this->record();
        $sourceHash = str_repeat('e', 64);
        $metadata = [
            'row_count' => 1,
            'generated_at' => '2026-07-26T00:30:00.000000Z',
            'stale_at' => null,
        ];
        $quality = [
            'status' => 'complete',
            'coverage' => null,
            'warnings' => [],
            'unmatched_count' => 0,
            'reconciliation' => 'matched',
            'unknown_metrics' => [],
            'excluded_sources' => [],
        ];
        $provenance = [
            'source_of_truth' => 'ledger',
            'source_refs' => [[
                'source' => 'ledger',
                'snapshot_kind' => 'materialized',
                'snapshot_id' => 'snapshot_one',
                'schema_version' => 'v1',
                'watermark' => 'watermark_one',
                'row_count' => 1,
                'hash' => $sourceHash,
            ]],
            'source_hash' => $sourceHash,
            'external_confirmation_role' => null,
        ];
        $rowSchema = [['id' => 'persisted_amount']];
        $capabilities = ['drill_down' => true];
        $resultProjection = [
            'metadata' => [
                'snapshot' => [
                    'kind' => 'materialized',
                    'id' => 'snapshot_one',
                    'scope' => [
                        'organization_id' => 10,
                        'holding_organization_ids' => [10, 11],
                        'project_ids' => [20],
                        'resources' => [['kind' => 'task', 'id' => 30, 'project_id' => 20]],
                        'timezone' => 'UTC',
                    ],
                    'definition_hash' => str_repeat('a', 64),
                    'formula_version' => '1',
                    'source_hash' => $sourceHash,
                    'generated_at' => '2026-07-26T00:30:00.000000Z',
                    'stale_at' => null,
                    'watermarks' => ['ledger' => 'watermark_one'],
                    'classification' => 'operational',
                    'seal' => null,
                ],
                'row_count' => 1,
                'generated_at' => '2026-07-26T00:30:00.000000Z',
                'stale_at' => null,
            ],
            'totals' => ['amount' => '100.00'],
            'freshness' => 'fresh',
            'quality' => $quality,
            'provenance' => $provenance,
            'row_schema' => $rowSchema,
            'capabilities' => $capabilities,
        ];
        $attributes = $record->getAttributes();
        $attributes += [
            'result_hash' => hash('sha256', CanonicalJson::encode($resultProjection)),
            'row_count' => 1,
            'result_metadata' => CanonicalJson::encode($metadata),
            'freshness' => 'fresh',
            'quality' => CanonicalJson::encode($quality),
            'provenance' => CanonicalJson::encode($provenance),
            'row_schema' => CanonicalJson::encode($rowSchema),
            'capabilities' => CanonicalJson::encode($capabilities),
            'snapshot_kind' => 'materialized',
            'snapshot_id' => 'snapshot_one',
            'snapshot_generated_at' => '2026-07-26T00:30:00.000000Z',
            'snapshot_stale_at' => null,
            'snapshot_watermarks' => CanonicalJson::encode(['ledger' => 'watermark_one']),
            'snapshot_seal_key_id' => null,
            'snapshot_seal_algorithm' => null,
            'snapshot_sealed_payload_hash' => null,
            'snapshot_seal_signature' => null,
            'snapshot_sealed_at' => null,
            'ready_at' => '2026-07-26T00:31:00.000000Z',
        ];
        $attributes['status'] = 'ready';
        $attributes['source_hash'] = $sourceHash;
        $attributes['progress'] = 100;
        $attributes['totals'] = CanonicalJson::encode(['amount' => '100.00']);
        $attributes['updated_at'] = '2026-07-26T00:31:00.000000Z';
        $record->setRawAttributes($attributes);

        return $record;
    }
}
