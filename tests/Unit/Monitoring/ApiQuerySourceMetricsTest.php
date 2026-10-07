<?php

declare(strict_types=1);

namespace Tests\Unit\Monitoring;

use App\Services\Monitoring\ApiQueryMetrics;
use Illuminate\Container\Container;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

final class ApiQuerySourceMetricsTest extends TestCase
{
    private Container $previousContainer;

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
        $summary = $metrics->summary();
        self::assertSame(40, $summary['sql_count']);
        self::assertSame(60.0, $summary['sql_total_ms']);
        self::assertCount(32, $summary['sql_sources']);
        self::assertSame(8, $summary['sql_sources_dropped_count']);
        self::assertSame(32, array_sum(array_column($summary['sql_sources'], 'count')));
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
