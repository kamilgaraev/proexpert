<?php

declare(strict_types=1);

namespace Tests\Unit\Monitoring;

use App\Services\Monitoring\SlowQueryContext;
use App\Services\Monitoring\TracingService;
use DateTimeImmutable;
use Illuminate\Container\Container;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Application;
use OpenTelemetry\SDK\Trace\Sampler\AlwaysOffSampler;
use OpenTelemetry\SDK\Trace\TracerProviderBuilder;
use PHPUnit\Framework\TestCase;

final class SlowQueryContextTest extends TestCase
{
    public function test_bindings_keep_diagnostic_ids_dates_and_status_without_disclosing_text_or_payloads(): void
    {
        $bindings = SlowQueryContext::safeBindings('update estimate_dataset_versions set status = ?, meta = ?, started_at = ? where id = ?', [
            'importing', '{"secret":"private","email":"person@example.com"}', new DateTimeImmutable('2026-10-01T13:01:00+03:00'), 123,
        ]);
        self::assertSame('importing', $bindings[0]['value']);
        self::assertTrue($bindings[1]['redacted']);
        self::assertSame('2026-10-01T13:01:00+03:00', $bindings[2]['value']);
        self::assertSame(123, $bindings[3]['value']);
        self::assertStringNotContainsString('person@example.com', json_encode($bindings, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('private', json_encode($bindings, JSON_THROW_ON_ERROR));
    }

    public function test_sensitive_columns_redact_even_numeric_credentials_and_values_are_bounded(): void
    {
        $bindings = SlowQueryContext::safeBindings('insert into api_tokens (token, password_hash) values (?, ?)', [123456, 'completed']);
        foreach ($bindings as $binding) {
            self::assertTrue($binding['redacted']);
            self::assertArrayNotHasKey('value', $binding);
        }
        self::assertCount(100, SlowQueryContext::safeBindings('select ?', array_fill(0, 1000, str_repeat('x', 10000))));
    }

    public function test_sql_literals_are_redacted_before_truncation_while_placeholders_and_identifiers_remain(): void
    {
        $sql = "select \"id\" from users where email = 'person@example.com' and name = E'private\\'name' and id = ? and meta = \$payload\$private\$payload\$";
        $safe = SlowQueryContext::safeSql($sql);
        self::assertStringNotContainsString('person@example.com', $safe);
        self::assertStringNotContainsString('private', $safe);
        self::assertStringContainsString('"id"', $safe);
        self::assertStringContainsString('id = ?', $safe);
        self::assertStringNotContainsString(str_repeat('x', 4000), SlowQueryContext::safeSql("select '".str_repeat('x', 10000)."'"));
    }

    public function test_slow_query_context_contains_all_bindings_source_and_valid_trace_without_a_database_call(): void
    {
        $previous = Container::getInstance();
        new Application(dirname(__DIR__, 3));
        try {
            $tracing = new TracingService((new TracerProviderBuilder)->setSampler(new AlwaysOffSampler)->build());
            $connection = new Connection(null, 'testing', '', ['name' => 'primary']);
            $query = new QueryExecuted('delete from organizations where id = ?', [123], 550.12, $connection);
            $context = (new SlowQueryContext($tracing))->forQuery($query);
            self::assertSame(123, $context['bindings'][0]['value']);
            self::assertSame(1, $context['bindings_count']);
            self::assertFalse($context['bindings_truncated']);
            self::assertSame('console', $context['execution_context']['kind']);
            self::assertSame('tests/Unit/Monitoring/SlowQueryContextTest.php', $context['source']['file']);
            self::assertGreaterThan(0, $context['source']['line']);
            self::assertSame(0, $context['transaction_level']);
            self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $context['trace_id']);
            self::assertFalse($context['trace_sampled']);
        } finally {
            Container::setInstance($previous);
        }
    }
}
