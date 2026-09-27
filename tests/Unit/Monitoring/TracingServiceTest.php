<?php

declare(strict_types=1);

namespace Tests\Unit\Monitoring;

use App\Services\Monitoring\TracingService;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Redis\Events\CommandExecuted;
use Mockery;
use OpenTelemetry\SDK\Trace\Sampler\AlwaysOnSampler;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProviderBuilder;
use PHPUnit\Framework\TestCase;
use RuntimeException;

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

        $httpSpan->end();
        $scope->detach();

        $job = Mockery::mock(Job::class);
        $job->shouldReceive('payload')->andReturn($payload);
        $job->shouldReceive('getName')->andReturn('App\\Jobs\\ExampleJob');
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
}
