<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

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
        self::assertSame(['projection_mode', 'query_ordinal', 'query_elapsed_ms', 'query_completed', 'release_sha'], array_keys($record));
        self::assertSame($mode, $record['projection_mode']);
        self::assertSame($ordinal, $record['query_ordinal']);
        self::assertSame($expectedElapsed, $record['query_elapsed_ms']);
        self::assertSame($completed, $record['query_completed']);
        self::assertSame(str_repeat('a', 40), $record['release_sha']);
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
