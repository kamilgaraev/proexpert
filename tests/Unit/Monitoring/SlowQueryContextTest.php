<?php

declare(strict_types=1);

namespace Tests\Unit\Monitoring;

use App\Services\Logging\EnsureLogFailuresAreNonFatal;
use App\Services\Monitoring\SlowQueryContext;
use App\Services\Monitoring\TracingService;
use DateTimeImmutable;
use Illuminate\Container\Container;
use Illuminate\Config\Repository;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Application;
use Illuminate\Log\Logger as IlluminateLogger;
use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\TestHandler;
use Monolog\Logger as MonologLogger;
use OpenTelemetry\SDK\Trace\Sampler\AlwaysOffSampler;
use OpenTelemetry\SDK\Trace\TracerProviderBuilder;
use PHPUnit\Framework\TestCase;

final class SlowQueryContextTest extends TestCase
{
    public function test_full_statement_fingerprint_survives_log_redaction_without_exposing_sql_or_bindings(): void
    {
        $previous = Container::getInstance();
        $application = new Application(dirname(__DIR__, 3));
        $key = str_repeat('test-hmac-key-', 3);
        $configuration = new Repository(['app' => ['key' => 'base64:'.base64_encode($key)]]);
        $application->instance('config', $configuration);
        try {
            $binding = str_repeat('private-binding-', 8);
            $earlyLiteral = 'private-literal-before-prefix';
            $literal = 'private-literal-after-prefix';
            $sql = "select id from records where note = '".$earlyLiteral."' and id in (".implode(',', array_fill(0, 2500, '?')).") and note = '".$literal."'";
            $contextFactory = new SlowQueryContext(new TracingService(null));
            $context = $contextFactory->forQuery(new QueryExecuted($sql, [$binding], 600, new Connection(null)));
            $other = $contextFactory->forQuery(new QueryExecuted($sql.' limit 1', [$binding], 600, new Connection(null)));
            self::assertSame($context['sql'], $other['sql']);

            $handler = new TestHandler;
            $handler->setFormatter(new JsonFormatter);
            $monolog = new MonologLogger('slow_queries', [$handler]);
            $logger = new IlluminateLogger($monolog);
            (new EnsureLogFailuresAreNonFatal)($logger);
            $logger->warning('slow_query', $context);
            $serialized = $handler->getRecords()[0]->formatted;
            $logged = json_decode($serialized, true, 512, JSON_THROW_ON_ERROR)['context'];

            self::assertSame('[REDACTED]', $logged['sql_fingerprint']);
            self::assertSame('hmac-sha256', $logged['sql_fingerprint_algorithm']);
            self::assertSame(str_split(hash_hmac('sha256', $sql, $key), 32), $logged['sql_fingerprint_parts']);
            self::assertNotSame($context['sql_fingerprint_parts'], $other['sql_fingerprint_parts']);
            self::assertSame(hash_hmac('sha256', $sql, $key), implode('', $logged['sql_fingerprint_parts']));
            self::assertNotSame(hash('sha256', $sql), implode('', $logged['sql_fingerprint_parts']));
            self::assertSame(mb_strlen($sql), $logged['sql_length']);
            self::assertTrue($logged['bindings'][0]['redacted']);
            self::assertStringNotContainsString($earlyLiteral, $serialized);
            self::assertStringNotContainsString($literal, $serialized);
            self::assertStringNotContainsString($binding, $serialized);
            self::assertStringNotContainsString($key, $serialized);
            self::assertStringNotContainsString(base64_encode($key), $serialized);

            $configuration->set('app.key', $key);
            $plainKey = $contextFactory->forQuery(new QueryExecuted($sql, [$binding], 600, new Connection(null)));
            self::assertSame($context['sql_fingerprint_parts'], $plainKey['sql_fingerprint_parts']);
            $configuration->set('app.key', str_repeat('rotated-key-', 3));
            $rotated = $contextFactory->forQuery(new QueryExecuted($sql, [$binding], 600, new Connection(null)));
            self::assertNotSame($context['sql_fingerprint_parts'], $rotated['sql_fingerprint_parts']);
            foreach ([null, '', 'short', 'base64:not-valid!', 'base64:'.base64_encode('short')] as $invalidKey) {
                $configuration->set('app.key', $invalidKey);
                $missing = $contextFactory->forQuery(new QueryExecuted($sql, [$binding], 600, new Connection(null)));
                self::assertNull($missing['sql_fingerprint_parts']);
            }
        } finally {
            Container::setInstance($previous);
        }
    }

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

    public function test_sensitive_columns_redact_even_numeric_credentials_and_all_parameter_positions_remain(): void
    {
        $bindings = SlowQueryContext::safeBindings('insert into api_tokens (token, password_hash) values (?, ?)', [123456, 'completed']);
        foreach ($bindings as $binding) {
            self::assertTrue($binding['redacted']);
            self::assertArrayNotHasKey('value', $binding);
        }
        $many = SlowQueryContext::safeBindings('select ?', array_fill(0, 1000, str_repeat('x', 10000)));
        self::assertCount(1000, $many);
        self::assertSame(999, $many[999]['position']);
        self::assertTrue($many[999]['redacted']);
        self::assertSame(10000, $many[999]['length']);
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

    public function test_query_without_registered_execution_or_tracer_provider_keeps_every_binding_and_a_trace_id(): void
    {
        $previous = Container::getInstance();
        new Application(dirname(__DIR__, 3));
        try {
            $bindings = range(1, 538);
            $query = new QueryExecuted('select id from records where id in ('.implode(',', array_fill(0, 538, '?')).')', $bindings, 600, new Connection(null));
            $context = (new SlowQueryContext(new TracingService(null)))->forQuery($query);
            self::assertCount(538, $context['bindings']);
            self::assertSame(538, $context['bindings_count']);
            self::assertSame(538, $context['bindings'][537]['value']);
            self::assertFalse($context['bindings_truncated']);
            self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $context['trace_id']);
            self::assertFalse($context['trace_sampled']);
            self::assertSame('correlation', $context['trace_kind']);
        } finally {
            Container::setInstance($previous);
        }
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
