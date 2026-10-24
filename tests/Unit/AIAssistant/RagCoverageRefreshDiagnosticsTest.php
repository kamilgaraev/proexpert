<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\AssistantEntityAccessCatalog;
use App\BusinessModules\Features\AIAssistant\Services\StatusSnapshots\AssistantStatusSnapshotDiagnostics;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Log;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class RagCoverageRefreshDiagnosticsTest extends TestCase
{
    private Container $previousContainer;
    private mixed $previousFacadeApplication;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousContainer = Container::getInstance();
        $this->previousFacadeApplication = Facade::getFacadeApplication();
        $container = new Container;
        $container->instance('config', new Repository(['ai-assistant' => ['status_snapshot_release' => str_repeat('a', 40)]]));
        Container::setInstance($container);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($container);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->previousFacadeApplication);
        Container::setInstance($this->previousContainer);
        parent::tearDown();
    }

    #[DataProvider('phases')]
    public function test_events_contain_only_bounded_lifecycle_metadata(string $phase, bool $global): void
    {
        $records = [];
        $logger = Mockery::mock();
        $logger->shouldReceive('channel')->with('api_latency')->andReturnSelf();
        $logger->shouldReceive('info')->once()->with('assistant_rag_coverage_refresh', Mockery::on(static function (array $context) use (&$records): bool {
            $records[] = $context;

            return true;
        }));
        Log::swap($logger);
        AssistantStatusSnapshotDiagnostics::coverageRefresh($phase, $global, 12.345, false, true, new RuntimeException('private_failure_fixture'));
        self::assertSame(['phase', 'scope', 'release_sha', 'duration_ms', 'counts_known', 'generation_present', 'exception_class'], array_keys($records[0]));
        self::assertSame($phase, $records[0]['phase']);
        self::assertSame($global ? 'global' : 'filtered', $records[0]['scope']);
        self::assertSame(str_repeat('a', 40), $records[0]['release_sha']);
        self::assertSame(12.35, $records[0]['duration_ms']);
        self::assertFalse($records[0]['counts_known']);
        self::assertTrue($records[0]['generation_present']);
        self::assertSame(RuntimeException::class, $records[0]['exception_class']);
        self::assertStringNotContainsString('private_', json_encode($records, JSON_THROW_ON_ERROR));
    }

    #[DataProvider('projectionQueries')]
    public function test_expected_projection_query_events_export_only_mode_and_bounded_numeric_metadata(bool $proof, bool $completed, int $ordinal, float $elapsed, string $mode, float $expectedElapsed): void
    {
        $record = null;
        $logger = Mockery::mock();
        $logger->shouldReceive('channel')->once()->with('api_latency')->andReturnSelf();
        $logger->shouldReceive('info')->once()->with('assistant_rag_expected_projection_query', Mockery::on(static function (array $context) use (&$record): bool {
            $record = $context;

            return true;
        }));
        Log::swap($logger);
        AssistantStatusSnapshotDiagnostics::expectedProjectionQuery($proof, $ordinal, $elapsed, $completed);
        self::assertIsArray($record);
        self::assertSame(['projection_mode', 'query_ordinal', 'query_elapsed_ms', 'query_completed', 'release_sha',
            'source_type_filters', 'source_type_filters_complete', 'entity_type_filters', 'entity_type_filters_complete'], array_keys($record));
        self::assertSame($mode, $record['projection_mode']);
        self::assertSame($ordinal, $record['query_ordinal']);
        self::assertSame($expectedElapsed, $record['query_elapsed_ms']);
        self::assertSame($completed, $record['query_completed']);
        self::assertSame(str_repeat('a', 40), $record['release_sha']);
        self::assertNull($record['source_type_filters']);
        self::assertNull($record['entity_type_filters']);
        self::assertFalse($record['source_type_filters_complete']);
        self::assertFalse($record['entity_type_filters_complete']);
    }

    #[DataProvider('fastIfcProjectionQueries')]
    public function test_fast_ifc_counts_emit_bounded_execution_evidence(int $ordinal, float $elapsed, bool $completed, float $expectedElapsed): void
    {
        $record = null;
        $logger = Mockery::mock();
        $logger->shouldReceive('channel')->once()->with('api_latency')->andReturnSelf();
        $logger->shouldReceive('info')->once()->with('assistant_rag_expected_projection_query', Mockery::on(static function (array $context) use (&$record): bool {
            $record = $context;

            return true;
        }));
        Log::swap($logger);
        AssistantStatusSnapshotDiagnostics::expectedProjectionQuery(false, $ordinal, $elapsed, $completed,
            ['design_additional'], ['design_ifc_model_element']);
        self::assertIsArray($record);
        self::assertSame([
            'projection_mode' => 'counts', 'query_ordinal' => $ordinal, 'query_elapsed_ms' => $expectedElapsed,
            'query_completed' => $completed, 'release_sha' => str_repeat('a', 40),
            'source_type_filters' => ['design_additional'], 'source_type_filters_complete' => true,
            'entity_type_filters' => ['design_ifc_model_element'], 'entity_type_filters_complete' => true,
        ], $record);
    }

    public static function fastIfcProjectionQueries(): array
    {
        return [[1, 0.0, true, 0.0], [32, 499.99, true, 499.99], [1, 12.345, false, 12.35], [32, 499.994, false, 499.99]];
    }

    public function test_fast_ifc_query_logger_failure_does_not_escape(): void
    {
        $logger = Mockery::mock();
        $logger->shouldReceive('channel')->once()->with('api_latency')->andThrow(new RuntimeException('private_logger_failure_fixture'));
        Log::swap($logger);
        AssistantStatusSnapshotDiagnostics::expectedProjectionQuery(false, 1, 100.0, true, ['design_additional'], ['design_ifc_model_element']);
        self::assertTrue(true);
    }

    public function test_fast_ifc_query_does_not_export_invalid_release(): void
    {
        config(['ai-assistant.status_snapshot_release' => 'private_release_fixture']);
        $record = null;
        $logger = Mockery::mock();
        $logger->shouldReceive('channel')->once()->with('api_latency')->andReturnSelf();
        $logger->shouldReceive('info')->once()->with('assistant_rag_expected_projection_query', Mockery::on(static function (array $context) use (&$record): bool {
            $record = $context;

            return true;
        }));
        Log::swap($logger);
        AssistantStatusSnapshotDiagnostics::expectedProjectionQuery(false, 1, 100.0, true, ['design_additional'], ['design_ifc_model_element']);
        self::assertIsArray($record);
        self::assertNull($record['release_sha']);
        self::assertStringNotContainsString('private_', json_encode($record, JSON_THROW_ON_ERROR));
    }

    public function test_fast_ifc_evidence_requires_exact_actual_filters_and_counts_mode(): void
    {
        $logger = Mockery::mock();
        $logger->shouldNotReceive('channel');
        Log::swap($logger);
        $source = ['design_additional'];
        $entity = ['design_ifc_model_element'];
        $unprintable = new class implements \Stringable {
            public function __toString(): string { throw new RuntimeException('private_stringable_fixture'); }
        };
        foreach ([
            [null, null], [[], []], [$source, null], [null, $entity], [$source, []], [[], $entity],
            [['project'], $entity], [$source, ['project']],
            [['design_additional', 'project'], $entity], [$source, ['design_ifc_model_element', 'project']],
            [['design_additional', 'design_additional'], $entity], [$source, ['design_ifc_model_element', 'design_ifc_model_element']],
            [['design_additional', 'private_source_fixture'], $entity], [$source, ['design_ifc_model_element', 'private_entity_fixture']],
            [['design_additional', $unprintable], $entity], [$source, ['design_ifc_model_element', $unprintable]],
            [[5 => 'design_additional'], $entity], [$source, [5 => 'design_ifc_model_element']],
        ] as [$sources, $entities]) {
            AssistantStatusSnapshotDiagnostics::expectedProjectionQuery(false, 1, 100.0, true, $sources, $entities);
        }
        AssistantStatusSnapshotDiagnostics::expectedProjectionQuery(true, 1, 100.0, true, $source, $entity);
        foreach ([-1.0, NAN, INF, -INF] as $elapsed) {
            AssistantStatusSnapshotDiagnostics::expectedProjectionQuery(false, 1, $elapsed, true, $source, $entity);
        }
        foreach ([0, -1, 33] as $ordinal) {
            AssistantStatusSnapshotDiagnostics::expectedProjectionQuery(false, $ordinal, 100.0, true, $source, $entity);
        }
        self::assertTrue(true);
    }

    #[DataProvider('abortEvents')]
    public function test_abort_events_contain_only_a_fixed_site_scope_and_elapsed_time(string $abortSite, bool $global): void
    {
        $record = null;
        $logger = Mockery::mock();
        $logger->shouldReceive('channel')->once()->with('api_latency')->andReturnSelf();
        $logger->shouldReceive('info')->once()->with('assistant_rag_coverage_aborted', Mockery::on(static function (array $context) use (&$record): bool {
            $record = $context;

            return true;
        }));
        Log::swap($logger);
        AssistantStatusSnapshotDiagnostics::coverageAbort($global, 12.345, $abortSite);
        self::assertIsArray($record);
        self::assertSame(['scope', 'release_sha', 'elapsed_ms', 'abort_site'], array_keys($record));
        self::assertSame($global ? 'global' : 'filtered', $record['scope']);
        self::assertSame(str_repeat('a', 40), $record['release_sha']);
        self::assertSame(12.35, $record['elapsed_ms']);
        self::assertSame($abortSite, $record['abort_site']);
    }

    public static function abortEvents(): array
    {
        return [['refresh_guard', true], ['publish_revision_mismatch', false], ['unclassified', true]];
    }

    public function test_abort_events_reject_unbounded_sites_and_invalid_elapsed_time(): void
    {
        $logger = Mockery::mock();
        $logger->shouldNotReceive('channel');
        Log::swap($logger);
        AssistantStatusSnapshotDiagnostics::coverageAbort(true, 100, 'private_site_fixture');
        foreach ([-1.0, NAN, INF, -INF] as $elapsed) {
            AssistantStatusSnapshotDiagnostics::coverageAbort(true, $elapsed, 'refresh_guard');
        }
        self::assertTrue(true);
    }

    public function test_abort_logger_failure_does_not_escape(): void
    {
        $logger = Mockery::mock();
        $logger->shouldReceive('channel')->once()->with('api_latency')->andThrow(new RuntimeException('private_logger_failure_fixture'));
        Log::swap($logger);
        AssistantStatusSnapshotDiagnostics::coverageAbort(true, 100, 'refresh_guard');
        self::assertTrue(true);
    }

    #[DataProvider('filterCases')]
    public function test_projection_filters_export_only_closed_catalog_labels(bool $proof, ?array $sources, ?array $entities, ?array $expectedSources, bool $sourcesComplete, ?array $expectedEntities, bool $entitiesComplete): void
    {
        $record = null;
        $logger = Mockery::mock();
        $logger->shouldReceive('channel')->once()->with('api_latency')->andReturnSelf();
        $logger->shouldReceive('info')->once()->with('assistant_rag_expected_projection_query', Mockery::on(static function (array $context) use (&$record): bool {
            $record = $context;

            return true;
        }));
        Log::swap($logger);
        AssistantStatusSnapshotDiagnostics::expectedProjectionQuery($proof, 1, 600, true, $sources, $entities);
        self::assertIsArray($record);
        self::assertSame($expectedSources, $record['source_type_filters']);
        self::assertSame($sourcesComplete, $record['source_type_filters_complete']);
        self::assertSame($expectedEntities, $record['entity_type_filters']);
        self::assertSame($entitiesComplete, $record['entity_type_filters_complete']);
        self::assertStringNotContainsString('private_', json_encode($record, JSON_THROW_ON_ERROR));
    }

    public static function filterCases(): array
    {
        $unprintable = new class implements \Stringable {
            public function __toString(): string { throw new RuntimeException('private_stringable_fixture'); }
        };

        return [
            [false, ['project', 'project', 'file_document', 'estimate_reference'], ['project', 'assistant_document', 'estimate_template'], ['project', 'file_document', 'estimate_reference'], true, ['project', 'assistant_document', 'estimate_template'], true],
            [false, [], [], [], true, [], true],
            [false, null, null, null, false, null, false],
            [false, ['private_source_fixture', 42, $unprintable, 'project'], ['private_entity_fixture', null, ['private_nested'], 'project'], ['project'], false, ['project'], false],
            [true, ['project'], ['project'], null, false, null, false],
            [false, array_fill(0, 128, 'project'), array_fill(0, 128, 'project'), ['project'], true, ['project'], true],
            [false, array_fill(0, 129, 'project'), array_fill(0, 129, 'project'), ['project'], false, ['project'], false],
        ];
    }

    public function test_projection_filters_cap_unique_labels_and_report_truncation(): void
    {
        $definitions = AssistantEntityAccessCatalog::entityDefinitions();
        $entities = array_keys($definitions);
        $sources = ['file_document'];
        foreach ($definitions as $entity => $definition) {
            array_push($sources, ...AssistantEntityAccessCatalog::indexedSourceTypes($entity, $definition[0]));
        }
        $sources = array_values(array_unique($sources));
        self::assertGreaterThan(32, count($entities));
        self::assertGreaterThan(32, count($sources));
        $records = [];
        $logger = Mockery::mock();
        $logger->shouldReceive('channel')->twice()->with('api_latency')->andReturnSelf();
        $logger->shouldReceive('info')->twice()->with('assistant_rag_expected_projection_query', Mockery::on(static function (array $context) use (&$records): bool {
            $records[] = $context;

            return true;
        }));
        Log::swap($logger);
        foreach ([32, 33] as $limit) {
            AssistantStatusSnapshotDiagnostics::expectedProjectionQuery(false, 1, 600, true, array_slice($sources, 0, $limit), array_slice($entities, 0, $limit));
        }
        foreach ($records as $record) {
            self::assertSame(array_slice($sources, 0, 32), $record['source_type_filters']);
            self::assertSame(array_slice($entities, 0, 32), $record['entity_type_filters']);
        }
        self::assertTrue($records[0]['source_type_filters_complete']);
        self::assertTrue($records[0]['entity_type_filters_complete']);
        self::assertFalse($records[1]['source_type_filters_complete']);
        self::assertFalse($records[1]['entity_type_filters_complete']);
    }

    public static function projectionQueries(): array
    {
        return [[true, true, 1, 500.125, 'proof', 500.13], [false, true, 32, 500.0, 'counts', 500.0],
            [true, false, 32, 991.124, 'proof', 991.12], [false, false, 1, 705.0, 'counts', 705.0]];
    }

    #[DataProvider('excludedProjectionQueries')]
    public function test_expected_projection_query_diagnostics_skip_fast_invalid_and_over_limit_queries(int $ordinal, float $elapsed): void
    {
        $logger = Mockery::mock();
        $logger->shouldNotReceive('channel');
        Log::swap($logger);
        AssistantStatusSnapshotDiagnostics::expectedProjectionQuery(false, $ordinal, $elapsed, true);
        self::assertTrue(true);
    }

    public static function excludedProjectionQueries(): array
    {
        return [[0, 500.0], [-1, 500.0], [33, 500.0], [1, 499.99], [1, -1.0], [1, NAN], [1, INF], [1, -INF]];
    }

    public function test_expected_projection_query_logger_failure_does_not_escape(): void
    {
        $logger = Mockery::mock();
        $logger->shouldReceive('channel')->once()->with('api_latency')->andThrow(new RuntimeException('private_logger_failure_fixture'));
        Log::swap($logger);
        AssistantStatusSnapshotDiagnostics::expectedProjectionQuery(true, 1, 600.0, false);
        self::assertTrue(true);
    }

    public function test_expected_projection_query_diagnostics_do_not_export_invalid_release(): void
    {
        config(['ai-assistant.status_snapshot_release' => 'private_release_fixture']);
        $record = null;
        $logger = Mockery::mock();
        $logger->shouldReceive('channel')->once()->with('api_latency')->andReturnSelf();
        $logger->shouldReceive('info')->once()->with('assistant_rag_expected_projection_query', Mockery::on(static function (array $context) use (&$record): bool {
            $record = $context;

            return true;
        }));
        Log::swap($logger);
        AssistantStatusSnapshotDiagnostics::expectedProjectionQuery(false, 1, 600.0, true);
        self::assertIsArray($record);
        self::assertNull($record['release_sha']);
        self::assertStringNotContainsString('private_', json_encode($record, JSON_THROW_ON_ERROR));
    }

    public static function phases(): array
    {
        return [['queued', true], ['queue_failed', false], ['started', true], ['finished', false], ['failed', true], ['terminal_failed', false]];
    }

    public function test_logger_failure_is_ignored_and_unknown_phases_are_not_emitted(): void
    {
        $logger = Mockery::mock();
        $logger->shouldReceive('channel')->once()->with('api_latency')->andThrow(new RuntimeException('private_logger_failure_fixture'));
        Log::swap($logger);
        AssistantStatusSnapshotDiagnostics::coverageRefresh('private_phase_fixture', true);
        AssistantStatusSnapshotDiagnostics::coverageRefresh('failed', true);
        self::assertTrue(true);
    }

    public function test_invalid_release_duration_and_anonymous_exception_are_not_exported(): void
    {
        config(['ai-assistant.status_snapshot_release' => 'private_release_fixture']);
        $record = null;
        $logger = Mockery::mock();
        $logger->shouldReceive('channel')->with('api_latency')->andReturnSelf();
        $logger->shouldReceive('info')->once()->with('assistant_rag_coverage_refresh', Mockery::on(static function (array $context) use (&$record): bool {
            $record = $context;

            return true;
        }));
        Log::swap($logger);
        AssistantStatusSnapshotDiagnostics::coverageRefresh('failed', true, INF, exception: new class('private_failure_fixture') extends RuntimeException {});
        self::assertNull($record['release_sha']);
        self::assertNull($record['duration_ms']);
        self::assertSame('unclassified', $record['exception_class']);
        self::assertStringNotContainsString('private_', json_encode($record, JSON_THROW_ON_ERROR));
    }
}
