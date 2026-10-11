<?php

declare(strict_types=1);

namespace Tests\Unit\Monitoring;

use App\Services\Monitoring\ApiQueryMetrics;
use App\Services\Logging\SensitiveDataRedactor;
use App\Domain\Authorization\Services\RoleScanner;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Domain\Authorization\Services\PermissionResolver;
use App\Models\User;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;

final class ApiQuerySourceMetricsTest extends TestCase
{
    private Container $previousContainer;

    public function test_measured_operation_preserves_return_value_exception_and_opt_in_sql_deltas(): void
    {
        [$request, $metrics] = $this->metrics();
        app()->instance('request', $request);
        $calls = 0;
        $value = new \stdClass;
        $result = ApiQueryMetrics::measureProcessingPhase('rag_acl_entity_build', static function () use (&$calls, $request, $value): object {
            $calls++;
            ApiQueryMetrics::record($request, 2.5);

            return $value;
        });
        self::assertSame($value, $result);
        self::assertSame(1, $calls);
        $phase = $metrics->summary()['processing_phases']['rag_acl_entity_build'];
        self::assertSame(1, $phase['count']);
        self::assertSame(1, $phase['sql_count']);
        self::assertSame(2.5, $phase['sql_total_ms']);
        $failure = new \RuntimeException('expected failure');
        try {
            ApiQueryMetrics::measureProcessingPhase('rag_acl_register', static function () use ($failure): never { throw $failure; });
            self::fail('The operation exception must propagate.');
        } catch (\RuntimeException $actual) {
            self::assertSame($failure, $actual);
        }
        self::assertSame(1, $metrics->summary()['processing_phases']['rag_acl_register']['count']);
        [$request, $disabled] = $this->metrics(false);
        app()->instance('request', $request);
        self::assertSame($value, ApiQueryMetrics::measureProcessingPhase('rag_acl_register', static fn (): object => $value));
        self::assertArrayNotHasKey('processing_phases', $disabled->summary());
        Container::setInstance(new Container);
        self::assertSame($value, ApiQueryMetrics::measureProcessingPhase('rag_acl_register', static fn (): object => $value));
    }

    public function test_access_evaluation_counts_actual_cache_misses_and_preserves_false_decisions_and_fresh_scope(): void
    {
        [$request, $metrics] = $this->metrics();
        app()->instance('request', $request);
        $resolver = \Mockery::mock(PermissionResolver::class);
        $resolver->shouldReceive('forCurrentChecks')->andReturnSelf();
        $resolver->shouldReceive('forReadScope')->andReturnSelf();
        $service = new class($resolver) extends AuthorizationService {
            public int $evaluations = 0;
            public bool $allowed = false;

            public function __construct(PermissionResolver $resolver) { $this->permissionResolver = $resolver; }

            protected function checkPermission(User $user, string $permission, ?array $context = null): bool
            {
                $this->evaluations++;

                return $this->allowed;
            }
        };
        $scope = $service->forCurrentChecks(true);
        $actor = new User;
        $actor->id = 71;
        foreach ([false, false] as $expected) {
            self::assertSame($expected, $scope->canCurrent($actor, 'projects.view', ['organization_id' => 1]));
        }
        self::assertSame(1, $scope->evaluations);
        $scope->allowed = true;
        self::assertFalse($scope->canCurrent($actor, 'projects.view', ['organization_id' => 1]));
        self::assertTrue($scope->forCurrentChecks(true)->canCurrent($actor, 'projects.view', ['organization_id' => 1]));
        $phases = $metrics->summary()['processing_phases'];
        self::assertSame(4, $phases['current_access_check']['count']);
        self::assertSame(2, $phases['current_access_evaluate']['count']);
        \Mockery::close();
    }

    public function test_numeric_access_and_acl_phases_survive_production_redaction(): void
    {
        [$request, $metrics] = $this->metrics();
        foreach (['current_access_check', 'rag_acl_discovery', 'rag_acl_batch_compile', 'rag_acl_finish',
            'current_access_evaluate', 'rag_acl_entity_build', 'rag_acl_register'] as $phase) {
            $checkpoint = ApiQueryMetrics::processingCheckpoint($request);
            self::assertNotNull($checkpoint);
            ApiQueryMetrics::recordProcessingPhase($request, $phase, $checkpoint['started_at'], $checkpoint);
        }
        $summary = $metrics->summary();
        $summary['authorization'] = 'Bearer private-token';
        $redacted = (new SensitiveDataRedactor)->redact($summary);
        self::assertSame($summary['processing_phases'], $redacted['processing_phases']);
        self::assertSame('[REDACTED]', $redacted['authorization']);
        self::assertArrayNotHasKey('authorization_current', $redacted['processing_phases']);
        foreach ($redacted['processing_phases'] as $phase) {
            self::assertSame(1, $phase['count']);
            self::assertSame(0, $phase['sql_count']);
            self::assertArrayNotHasKey('metrics', $phase);
        }
    }

    public function test_processing_phases_are_numeric_closed_request_local_and_opt_in(): void
    {
        $request = Request::create('/api/v1/admin/procurement/purchase-requests', 'GET');
        $metrics = new ApiQueryMetrics(true);
        $request->attributes->set(ApiQueryMetrics::REQUEST_ATTRIBUTE, $metrics);
        ApiQueryMetrics::recordProcessingPhase($request, 'private-secret-phase', hrtime(true));
        ApiQueryMetrics::recordProcessingPhase($request, 'list_prepare', -1);
        ApiQueryMetrics::recordProcessingPhase($request, 'list_prepare', PHP_INT_MAX);
        self::assertSame([], $metrics->summary()['processing_phases']);
        ApiQueryMetrics::recordProcessingPhase($request, 'list_prepare', hrtime(true));
        ApiQueryMetrics::recordProcessingPhase($request, 'list_prepare', hrtime(true));
        $phase = $metrics->summary()['processing_phases']['list_prepare'];
        self::assertSame(2, $phase['count']);
        self::assertGreaterThanOrEqual(0, $phase['total_ms']);
        self::assertGreaterThanOrEqual(0, $phase['max_ms']);
        self::assertLessThanOrEqual($phase['total_ms'], $phase['max_ms']);
        self::assertStringNotContainsString('private-secret-phase', json_encode($metrics->summary(), JSON_THROW_ON_ERROR));
        $other = new ApiQueryMetrics;
        $request->attributes->set(ApiQueryMetrics::REQUEST_ATTRIBUTE, $other);
        ApiQueryMetrics::recordProcessingPhase($request, 'list_encode', hrtime(true));
        self::assertArrayNotHasKey('processing_phases', $other->summary());
        self::assertSame(0, $metrics->summary()['sql_count']);
    }

    public function test_snapshot_metadata_accepts_only_the_closed_schema(): void
    {
        $metrics = new ApiQueryMetrics;
        $metrics->recordAssistantSnapshot(['phase' => 'private-value', 'section' => 'sources']);
        self::assertArrayNotHasKey('assistant_snapshot', $metrics->summary());
        $metrics->recordAssistantSnapshot(['phase' => 'snapshot_missing', 'section' => 'sources',
            'key_hash' => 'private-key', 'release_sha' => str_repeat('a', 40), 'age_seconds' => -1,
            'refresh_queued' => 'private-flag', 'actor_id' => 42, 'secret' => 'private-secret']);
        $snapshot = $metrics->summary()['assistant_snapshot'];
        self::assertNull($snapshot['key_hash']);
        self::assertNull($snapshot['age_seconds']);
        self::assertNull($snapshot['refresh_queued']);
        self::assertSame(str_repeat('a', 40), $snapshot['release_sha']);
        self::assertCount(8, $snapshot);
        self::assertStringNotContainsString('private-', json_encode($snapshot, JSON_THROW_ON_ERROR));
        self::assertArrayNotHasKey('actor_id', $snapshot);
        $metrics->recordAssistantSnapshotEpoch('private-phase', 'private-data');
        self::assertArrayNotHasKey('assistant_snapshot_epoch', $metrics->summary());
        $metrics->recordAssistantSnapshotEpoch('relation_mutation_present', 'ai_rag_sources');
        self::assertSame(['phase' => 'relation_mutation_present', 'relation' => 'ai_rag_sources'], $metrics->summary()['assistant_snapshot_epoch']);
        $metrics->recordAssistantSnapshotEpoch('schema_rejected', 'personal@example.test');
        self::assertSame(['phase' => 'schema_rejected', 'relation' => null], $metrics->summary()['assistant_snapshot_epoch']);
    }

    public function test_processing_sql_deltas_are_request_local_and_exclude_invalid_checkpoints(): void
    {
        [$request, $metrics] = $this->metrics();
        $checkpoint = ApiQueryMetrics::processingCheckpoint($request);
        self::assertNotNull($checkpoint);
        ApiQueryMetrics::record($request, 1.25);
        ApiQueryMetrics::record($request, 2.75);
        ApiQueryMetrics::recordProcessingPhase($request, 'rag_source_counts', $checkpoint['started_at'], $checkpoint);
        $next = ApiQueryMetrics::processingCheckpoint($request);
        self::assertNotNull($next);
        ApiQueryMetrics::record($request, 3.5);
        ApiQueryMetrics::recordProcessingPhase($request, 'rag_source_counts', $next['started_at'], $next);
        $phase = $metrics->summary()['processing_phases']['rag_source_counts'];
        self::assertSame(2, $phase['count']);
        self::assertSame(3, $phase['sql_count']);
        self::assertSame(7.5, $phase['sql_total_ms']);
        self::assertGreaterThanOrEqual(0, $phase['total_ms']);
        self::assertArrayNotHasKey('metrics', $phase);
        self::assertArrayNotHasKey('started_at', $phase);
        foreach ([array_replace($checkpoint, ['sql_count' => 99]), array_replace($checkpoint, ['sql_total_ms' => NAN])] as $invalid) {
            ApiQueryMetrics::recordProcessingPhase($request, 'rag_prepare', $invalid['started_at'], $invalid);
        }
        self::assertArrayNotHasKey('rag_prepare', $metrics->summary()['processing_phases']);
        $other = new ApiQueryMetrics(true);
        $request->attributes->set(ApiQueryMetrics::REQUEST_ATTRIBUTE, $other);
        ApiQueryMetrics::recordProcessingPhase($request, 'rag_source_counts', $checkpoint['started_at'], $checkpoint);
        self::assertSame([], $other->summary()['processing_phases']);
        $request->attributes->set(ApiQueryMetrics::REQUEST_ATTRIBUTE, new ApiQueryMetrics);
        self::assertNull(ApiQueryMetrics::processingCheckpoint($request));
    }

    public function test_processing_cpu_measurement_is_numeric_optional_and_does_not_include_wait_time(): void
    {
        [$request, $metrics] = $this->metrics();
        $checkpoint = ApiQueryMetrics::processingCheckpoint($request);
        self::assertNotNull($checkpoint);
        usleep(30_000);
        ApiQueryMetrics::recordProcessingPhase($request, 'rag_prepare', $checkpoint['started_at'], $checkpoint);
        $phase = $metrics->summary()['processing_phases']['rag_prepare'];
        self::assertGreaterThanOrEqual(25, $phase['total_ms']);
        if ($checkpoint['process_cpu_ms'] !== null) {
            self::assertIsFloat($phase['process_cpu_ms']);
            self::assertGreaterThanOrEqual(0, $phase['process_cpu_ms']);
            self::assertLessThan($phase['total_ms'] + 20, $phase['process_cpu_ms']);
        }
        $invalid = array_replace($checkpoint, ['process_cpu_ms' => NAN]);
        ApiQueryMetrics::recordProcessingPhase($request, 'role_catalog', $invalid['started_at'], $invalid);
        self::assertArrayNotHasKey('process_cpu_ms', $metrics->summary()['processing_phases']['role_catalog']);
    }

    public function test_role_catalog_diagnostics_preserve_result_and_exception_without_logging_catalog(): void
    {
        [$request, $metrics] = $this->metrics();
        $previousFacadeApp = Facade::getFacadeApplication();
        Facade::setFacadeApplication(Container::getInstance());
        try {
            $cache = new Repository(new ArrayStore);
            Cache::swap($cache);
            $catalog = collect(['private-role-fixture' => ['slug' => 'private-role-fixture']]);
            $cache->put('authorization_roles:v2', $catalog, 60);
            app()->instance('request', $request);
            self::assertSame($catalog, (new RoleScanner)->getAllRoles());
            $failure = $this->createMock(Repository::class);
            $failure->expects(self::once())->method('remember')->willThrowException(new \RuntimeException('catalog-read-fixture'));
            Cache::swap($failure);
            try {
                (new RoleScanner)->getAllRoles();
                self::fail('Expected catalog read exception');
            } catch (\RuntimeException $exception) {
                self::assertSame('catalog-read-fixture', $exception->getMessage());
            }
            $summary = $metrics->summary();
            self::assertSame(2, $summary['processing_phases']['role_catalog']['count']);
            self::assertSame(0, $summary['processing_phases']['role_catalog']['sql_count']);
            self::assertStringNotContainsString('private-role-fixture', json_encode($summary, JSON_THROW_ON_ERROR));
        } finally {
            Facade::clearResolvedInstance('cache');
            Facade::setFacadeApplication($previousFacadeApp);
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousContainer = Container::getInstance();
        new Application(dirname(__DIR__, 3));
    }

    protected function tearDown(): void
    {
        Container::setInstance($this->previousContainer);
        parent::tearDown();
    }

    public function test_source_aggregates_distinguish_variants_without_disclosing_sql_bindings_or_hashes(): void
    {
        [$request, $metrics] = $this->metrics();
        $sql = "select * from secret_table where token='private literal' and password=?";
        foreach (['private token', 'private token', 'personal@example.test'] as $binding) {
            $this->recordOne($request, new QueryExecuted($sql, [$binding], 2.5, new Connection(null)));
        }
        $summary = $metrics->summary();
        self::assertSame(3, $summary['sql_count']);
        self::assertCount(1, $summary['sql_sources']);
        $source = $summary['sql_sources'][0];
        self::assertSame(3, $source['count']);
        self::assertSame(7.5, $source['total_ms']);
        self::assertSame(2, $source['distinct_variants']);
        self::assertFalse($source['distinct_variants_capped']);
        self::assertSame('tests/Unit/Monitoring/ApiQuerySourceMetricsTest.php', $source['source']['file']);
        self::assertIsInt($source['source']['line']);
        $serialized = json_encode($summary, JSON_THROW_ON_ERROR);
        foreach (['secret_table', 'private literal', 'private token', 'personal@example.test', hash('sha256', $sql), '"variants":', '"bindings":', '"args":'] as $sensitive) {
            self::assertStringNotContainsString($sensitive, $serialized);
        }
        self::assertStringNotContainsString(hash('sha256', $sql."\0".serialize(0).serialize('private token')."\0"), $serialized);
        [, $other] = $this->metrics();
        self::assertSame([], $other->summary()['sql_sources']);
    }

    public function test_source_diagnostics_are_opt_in_and_variant_storage_is_bounded(): void
    {
        [$request, $metrics] = $this->metrics(false);
        $this->recordOne($request, new QueryExecuted('select secret', ['private'], 1, new Connection(null)));
        self::assertArrayNotHasKey('sql_sources', $metrics->summary());
        [$request, $metrics] = $this->metrics();
        for ($i = 0; $i < 20; $i++) {
            $this->recordOne($request, new QueryExecuted('select ?', [$i], 1, new Connection(null)));
        }
        $source = $metrics->summary()['sql_sources'][0];
        self::assertSame(20, $source['count']);
        self::assertSame(16, $source['distinct_variants']);
        self::assertTrue($source['distinct_variants_capped']);
        $this->recordOne($request, new QueryExecuted('select ?', [new \stdClass], 1, new Connection(null)));
        self::assertSame(1, $metrics->summary()['sql_sources'][0]['unclassified_variants']);
    }

    public function test_source_group_overflow_preserves_complete_request_totals(): void
    {
        [$request, $metrics] = $this->metrics();
        $checkpoint = ApiQueryMetrics::processingCheckpoint($request);
        self::assertNotNull($checkpoint);
        $queries = ['select * from role_conditions', 'select * from authorization_contexts', 'select * from pg_attribute a',
            'select count(*) as stored_count from records', 'select * from ai_rag_sources', 'select * from files', 'SET LOCAL statement_timeout=1', 'select 1'];
        foreach ($queries as $sql) {
            $event = new QueryExecuted($sql, [], 1.5, new Connection(null));
            $this->recordOne($request, $event);
            $this->recordTwo($request, $event);
            $this->recordThree($request, $event);
            $this->recordFour($request, $event);
            $this->recordFive($request, $event);
        }
        ApiQueryMetrics::recordProcessingPhase($request, 'rag_expected_counts', $checkpoint['started_at'], $checkpoint);
        $summary = $metrics->summary();
        self::assertSame(40, $summary['sql_count']);
        self::assertSame(60.0, $summary['sql_total_ms']);
        self::assertCount(32, $summary['sql_sources']);
        self::assertSame(8, $summary['sql_sources_dropped_count']);
        self::assertSame(32, array_sum(array_column($summary['sql_sources'], 'count')));
        self::assertSame(40, $summary['processing_phases']['rag_expected_counts']['sql_count']);
        self::assertSame(60.0, $summary['processing_phases']['rag_expected_counts']['sql_total_ms']);
    }

    public function test_diagnostic_failure_preserves_query_totals_and_does_not_escape(): void
    {
        Container::setInstance(new Container);
        [$request, $metrics] = $this->metrics();
        $this->recordOne($request, new QueryExecuted('select 1', [], 3.5, new Connection(null)));
        $summary = $metrics->summary();
        self::assertSame(1, $summary['sql_count']);
        self::assertSame(3.5, $summary['sql_total_ms']);
        self::assertSame([], $summary['sql_sources']);
        self::assertSame(1, $summary['sql_sources_dropped_count']);
    }

    public function test_variant_input_limits_reject_oversized_values_without_calling_object_methods(): void
    {
        [$request, $metrics] = $this->metrics();
        $date = new class('2026-10-07') extends \DateTimeImmutable
        {
            public int $formatCalls = 0;

            public function format(string $format): string
            {
                $this->formatCalls++;

                return str_repeat('private', 100000);
            }
        };
        foreach ([['select ?', [$date]], [str_repeat('x', 32769), []], ['select ?', [str_repeat('x', 4097)]],
            ['select ?', array_fill(0, 1025, 1)], [str_repeat('x', 32768), array_fill(0, 9, str_repeat('x', 4096))]] as [$sql, $bindings]) {
            $this->recordOne($request, new QueryExecuted($sql, $bindings, 1, new Connection(null)));
        }
        self::assertSame(0, $date->formatCalls);
        $source = $metrics->summary()['sql_sources'][0];
        self::assertSame(5, $source['unclassified_variants']);
        self::assertSame(0, $source['distinct_variants']);
        self::assertSame(5, $source['count']);
    }

    private function metrics(bool $capture = true): array
    {
        $request = Request::create('/api/v1/admin/ai-assistant/conversations', 'GET');
        $metrics = new ApiQueryMetrics($capture);
        $request->attributes->set(ApiQueryMetrics::REQUEST_ATTRIBUTE, $metrics);

        return [$request, $metrics];
    }

    private function recordOne(Request $request, QueryExecuted $query): void
    {
        ApiQueryMetrics::recordQuery($request, $query);
    }

    private function recordTwo(Request $request, QueryExecuted $query): void
    {
        ApiQueryMetrics::recordQuery($request, $query);
    }

    private function recordThree(Request $request, QueryExecuted $query): void
    {
        ApiQueryMetrics::recordQuery($request, $query);
    }

    private function recordFour(Request $request, QueryExecuted $query): void
    {
        ApiQueryMetrics::recordQuery($request, $query);
    }

    private function recordFive(Request $request, QueryExecuted $query): void
    {
        ApiQueryMetrics::recordQuery($request, $query);
    }
}
