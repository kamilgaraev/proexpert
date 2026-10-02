<?php

declare(strict_types=1);

namespace Tests\Unit\Monitoring;

use App\Services\Monitoring\TracingService;
use App\Services\Monitoring\SlowQuerySampler;
use App\Http\Middleware\TraceHttpRequest;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Redis\Events\CommandExecuted;
use Mockery;
use OpenTelemetry\SDK\Trace\Sampler\AlwaysOnSampler;
use OpenTelemetry\SDK\Trace\Sampler\AlwaysOffSampler;
use OpenTelemetry\SDK\Trace\Sampler\ParentBased;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProviderBuilder;
use OpenTelemetry\API\Trace\NoopTracerProvider;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanContext;
use OpenTelemetry\API\Trace\TraceFlags;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\HttpFoundation\Response;

final class TracingServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_http_sql_redis_and_queued_job_share_trace_without_sensitive_values(): void
    {
        $exporter = new InMemoryExporter();
        $provider = (new TracerProviderBuilder())
            ->addSpanProcessor(new SimpleSpanProcessor($exporter))
            ->setSampler(new AlwaysOnSampler())
            ->build();
        $tracing = new TracingService($provider);
        $request = Request::create('/api/v1/admin/users/secret@example.com?token=private', 'GET');
        [$httpSpan, $scope] = $tracing->startHttp($request);

        $connection = new class {
            public function getName(): string
            {
                return 'primary';
            }
        };
        $tracing->recordSql(new QueryExecuted(
            "SELECT * FROM users WHERE email = 'secret@example.com'",
            ['secret@example.com'],
            12.5,
            $connection,
        ));
        $tracing->recordRedis(new CommandExecuted('get', ['private-cache-key'], 3.0, $connection));
        $payload = $tracing->queuePayload();
        $payload['displayName'] = 'App\\Jobs\\ExampleJob';

        $httpSpan->end();
        $scope->detach();

        $job = Mockery::mock(Job::class);
        $job->shouldReceive('payload')->andReturn($payload);
        $job->shouldReceive('getName')->andReturn('App\\Jobs\\ExampleJob@call');
        $tracing->startJob(new JobProcessing('redis', $job));
        $tracing->recordSql(new QueryExecuted('select 1', [], 1.0, $connection));
        $tracing->finishJob($job, new RuntimeException('secret@example.com'));

        $spans = $exporter->getSpans();
        self::assertCount(5, $spans);
        $byName = [];
        foreach ($spans as $span) {
            $byName[$span->getName()][] = $span;
        }

        $http = $byName['HTTP GET'][0];
        $jobSpan = $byName['queue.process ExampleJob'][0];
        self::assertSame($http->getTraceId(), $jobSpan->getTraceId());
        self::assertSame($http->getSpanId(), $jobSpan->getParentSpanId());
        self::assertSame($http->getSpanId(), $byName['db.query SELECT'][0]->getParentSpanId());
        self::assertSame($http->getSpanId(), $byName['redis GET'][0]->getParentSpanId());
        self::assertSame($jobSpan->getSpanId(), $byName['db.query SELECT'][1]->getParentSpanId());
        self::assertSame('Error', $jobSpan->getStatus()->getCode());

        foreach ($spans as $span) {
            $serialized = json_encode([$span->getName(), $span->getAttributes()->toArray(), $span->getEvents()]);
            self::assertIsString($serialized);
            self::assertStringNotContainsString('secret@example.com', $serialized);
            self::assertStringNotContainsString('private-cache-key', $serialized);
            self::assertStringNotContainsString('token=private', $serialized);
        }
    }

    public function test_unsampled_http_keeps_correlation_and_exports_every_slow_query_with_a_parent_link(): void
    {
        $exporter = new InMemoryExporter();
        $provider = (new TracerProviderBuilder())->addSpanProcessor(new SimpleSpanProcessor($exporter))
            ->setSampler(new SlowQuerySampler(new ParentBased(new AlwaysOffSampler())))->build();
        $tracing = new TracingService($provider);
        [$span, $scope] = $tracing->startHttp(Request::create('/api/projects?token=secret', 'GET'));
        try {
            self::assertFalse($span->getContext()->isSampled());
            self::assertSame($span->getContext()->getTraceId(), TracingService::currentTraceId());
            $connection = new class {
                public function getName(): string { return 'primary'; }
            };
            $tracing->recordSql(new QueryExecuted('select 1', [], 5.0, $connection));
            self::assertCount(0, $exporter->getSpans());
            foreach ([501.0, 602.0] as $duration) {
                $context = $tracing->slowQueryTrace(new QueryExecuted('select * from organizations where id = ?', [123], $duration, $connection));
                self::assertTrue($context['trace_sampled']);
                self::assertSame($span->getContext()->getTraceId(), $context['parent_trace_id']);
                self::assertSame($context['trace_id'], $exporter->getSpans()[array_key_last($exporter->getSpans())]->getTraceId());
            }
            self::assertCount(2, $exporter->getSpans());
            foreach ($exporter->getSpans() as $slow) {
                self::assertCount(1, $slow->getLinks());
                self::assertSame($span->getContext()->getTraceId(), $slow->getLinks()[0]->getSpanContext()->getTraceId());
                self::assertArrayNotHasKey('db.statement', $slow->getAttributes()->toArray());
            }
        } finally {
            $span->end();
            $scope->detach();
        }
        self::assertNull(TracingService::currentTraceId());
    }

    public function test_console_dispatch_passes_trace_to_jobs_and_cleans_context_between_jobs(): void
    {
        $exporter = new InMemoryExporter();
        $provider = (new TracerProviderBuilder())->addSpanProcessor(new SimpleSpanProcessor($exporter))->setSampler(new AlwaysOnSampler())->build();
        $tracing = new TracingService($provider);
        $input = new ArrayInput([]);
        $output = new NullOutput();
        $tracing->startCommand(new CommandStarting('ai-assistant:index', $input, $output));
        $commandTrace = TracingService::currentTraceId();
        self::assertSame(['kind' => 'console', 'command' => 'ai-assistant:index'], $tracing->executionContext());
        $payload = $tracing->queuePayload() + ['displayName' => 'App\\Jobs\\ExampleJob', 'uuid' => '12345678-1234-1234-1234-123456789abc'];
        $tracing->finishCommand(new CommandFinished('ai-assistant:index', $input, $output, 0));
        self::assertNull(TracingService::currentTraceId());
        $job = Mockery::mock(Job::class);
        $job->shouldReceive('payload')->andReturn($payload);
        $tracing->startJob(new JobProcessing('redis', $job));
        self::assertSame($commandTrace, TracingService::currentTraceId());
        self::assertSame('queue', $tracing->executionContext()['kind']);
        self::assertSame('ExampleJob', $tracing->executionContext()['job']);
        self::assertSame($payload['uuid'], $tracing->executionContext()['job_id']);
        $tracing->finishJob($job);
        self::assertNull(TracingService::currentTraceId());
        $another = Mockery::mock(Job::class);
        $another->shouldReceive('payload')->andReturn(['displayName' => 'App\\Jobs\\NextJob']);
        $tracing->startJob(new JobProcessing('redis', $another));
        self::assertNotSame($commandTrace, TracingService::currentTraceId());
        $tracing->finishJob($another);
        self::assertNull(TracingService::currentTraceId());
    }

    public function test_export_disabled_still_creates_valid_ids_without_exporting_or_claiming_sampling(): void
    {
        $exporter = new InMemoryExporter();
        $provider = (new TracerProviderBuilder())->addSpanProcessor(new SimpleSpanProcessor($exporter))->setSampler(new AlwaysOffSampler())->build();
        $tracing = new TracingService($provider);
        [$span, $scope] = $tracing->startHttp(Request::create('/api/projects', 'GET'));
        try {
            self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', TracingService::currentTraceId());
            $connection = new class { public function getName(): string { return 'primary'; } };
            $context = $tracing->slowQueryTrace(new QueryExecuted('delete from organizations where id = ?', [123], 5001.0, $connection));
            self::assertFalse($context['trace_sampled']);
            self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $context['trace_id']);
            self::assertCount(0, $exporter->getSpans());
        } finally {
            $span->end();
            $scope->detach();
        }
    }

    public function test_http_middleware_returns_trace_header_for_unsampled_requests_and_restores_context(): void
    {
        $tracing = new TracingService((new TracerProviderBuilder())->setSampler(new AlwaysOffSampler())->build());
        $traceId = null;
        $response = (new TraceHttpRequest($tracing))->handle(Request::create('/api/projects', 'GET'), static function () use (&$traceId): Response {
            $traceId = TracingService::currentTraceId();
            return new Response('ok');
        });
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $traceId);
        self::assertSame($traceId, $response->headers->get('X-Trace-ID'));
        self::assertNull(TracingService::currentTraceId());
    }

    public function test_http_context_overrides_octane_console_command_and_is_cleared_after_request_failure(): void
    {
        $tracing = new TracingService((new TracerProviderBuilder())->setSampler(new AlwaysOffSampler())->build());
        $input = new ArrayInput([]);
        $output = new NullOutput();
        $tracing->startCommand(new CommandStarting('octane:start', $input, $output));
        try {
            (new TraceHttpRequest($tracing))->handle(Request::create('/api/projects?token=private', 'GET'), static function () use ($tracing): Response {
                self::assertSame(['kind' => 'http', 'method' => 'GET', 'route' => 'unmatched'], $tracing->executionContext());
                throw new RuntimeException('request failure');
            });
            self::fail('Request exception must propagate.');
        } catch (RuntimeException $exception) {
            self::assertSame('request failure', $exception->getMessage());
        }
        self::assertSame(['kind' => 'console', 'command' => 'octane:start'], $tracing->executionContext());
        $tracing->finishCommand(new CommandFinished('octane:start', $input, $output, 0));
        self::assertNull(TracingService::currentTraceId());
    }

    public function test_noop_provider_without_sdk_keeps_http_and_console_job_correlation(): void
    {
        $tracing = new TracingService(new NoopTracerProvider);
        $response = (new TraceHttpRequest($tracing))->handle(Request::create('/api/projects', 'GET'), static fn (): Response => new Response('ok'));
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $response->headers->get('X-Trace-ID'));
        self::assertNull(TracingService::currentTraceId());
        $input = new ArrayInput([]);
        $output = new NullOutput();
        $tracing->startCommand(new CommandStarting('ai-assistant:index', $input, $output));
        $traceId = TracingService::currentTraceId();
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $traceId);
        $payload = $tracing->queuePayload() + ['displayName' => 'App\\Jobs\\ExampleJob'];
        self::assertArrayHasKey('otel_traceparent', $payload);
        $tracing->finishCommand(new CommandFinished('ai-assistant:index', $input, $output, 0));
        $job = Mockery::mock(Job::class);
        $job->shouldReceive('payload')->andReturn($payload);
        $tracing->startJob(new JobProcessing('redis', $job));
        self::assertSame($traceId, TracingService::currentTraceId());
        $tracing->finishJob($job);
        self::assertNull(TracingService::currentTraceId());
    }

    public function test_sampled_remote_context_with_noop_provider_does_not_claim_local_export(): void
    {
        $tracing = new TracingService(new NoopTracerProvider);
        $context = SpanContext::create(str_repeat('a', 32), str_repeat('b', 16), TraceFlags::SAMPLED);
        $scope = Span::wrap($context)->activate();
        try {
            $connection = new class { public function getName(): string { return 'primary'; } };
            $slow = $tracing->slowQueryTrace(new QueryExecuted('select 1', [], 501.0, $connection));
            self::assertFalse($slow['trace_sampled']);
            self::assertSame($context->getTraceId(), $slow['parent_trace_id']);
        } finally {
            $scope->detach();
        }
    }
}
