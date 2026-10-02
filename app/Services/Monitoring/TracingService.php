<?php

declare(strict_types=1);

namespace App\Services\Monitoring;

use Illuminate\Contracts\Queue\Job;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Redis\Events\CommandExecuted;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Log;
use OpenTelemetry\API\Common\Time\Clock;
use OpenTelemetry\API\Trace\NoopTracerProvider;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanContext;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Contrib\Otlp\OtlpHttpTransportFactory;
use OpenTelemetry\Contrib\Otlp\SpanExporter;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Trace\Sampler\ParentBased;
use OpenTelemetry\SDK\Trace\Sampler\AlwaysOffSampler;
use OpenTelemetry\SDK\Trace\Sampler\TraceIdRatioBasedSampler;
use OpenTelemetry\SDK\Trace\SpanProcessor\BatchSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\SDK\Trace\TracerProviderBuilder;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class TracingService
{
    private array $activeJobs = [];
    private array $activeCommands = [];
    private array $activeHttp = [];

    public function __construct(private readonly TracerProviderInterface $provider)
    {
    }

    public static function fromConfig(): self
    {
        if (! config('monitoring.tracing_enabled')) {
            return self::withoutExport();
        }

        try {
            $endpoint = (string) config('monitoring.tracing_endpoint');
            $ratio = max(0.0, min(1.0, (float) config('monitoring.tracing_sample_ratio', 0.1)));
            $transport = (new OtlpHttpTransportFactory())->create($endpoint, 'application/json', timeout: 1.0, maxRetries: 0);
            $processor = new BatchSpanProcessor(
                new SpanExporter($transport),
                Clock::getDefault(),
                maxQueueSize: 2048,
                maxExportBatchSize: 512,
                autoFlush: false,
            );
            $resource = ResourceInfo::create(Attributes::create([
                'service.name' => 'most-api',
                'deployment.environment.name' => (string) config('app.env'),
            ]));
            $provider = (new TracerProviderBuilder())
                ->addSpanProcessor($processor)
                ->setResource($resource)
                ->setSampler(new SlowQuerySampler(new ParentBased(new TraceIdRatioBasedSampler($ratio))))
                ->build();

            return new self($provider);
        } catch (Throwable $exception) {
            error_log('OpenTelemetry initialization failed: '.$exception::class);

            return self::withoutExport();
        }
    }

    public static function currentTraceId(): ?string
    {
        $context = Span::getCurrent()->getContext();

        return $context->isValid() ? $context->getTraceId() : null;
    }

    private static function withoutExport(): self
    {
        if (! class_exists(TracerProviderBuilder::class)) {
            return new self(new NoopTracerProvider);
        }

        return new self((new TracerProviderBuilder())->setSampler(new AlwaysOffSampler())->build());
    }

    private function withCorrelation(SpanInterface $span): SpanInterface
    {
        return $span->getContext()->isValid()
            ? $span
            : Span::wrap(SpanContext::create(bin2hex(random_bytes(16)), bin2hex(random_bytes(8))));
    }

    public function executionContext(): array
    {
        if ($this->activeJobs !== []) {
            return $this->activeJobs[array_key_last($this->activeJobs)][2];
        }
        if ($this->activeHttp !== []) {
            $request = $this->activeHttp[array_key_last($this->activeHttp)];
            $route = $request->route();
            return ['kind' => 'http', 'method' => $request->method(), 'route' => $route instanceof Route ? $route->uri() : 'unmatched'];
        }
        if ($this->activeCommands !== []) {
            return ['kind' => 'console', 'command' => $this->activeCommands[array_key_last($this->activeCommands)][1]];
        }

        return ['kind' => app()->runningInConsole() ? 'console' : 'http'];
    }

    public function startCommand(CommandStarting $event): void
    {
        $name = preg_match('/^[A-Za-z0-9_:-]{1,100}$/D', $event->command) === 1 ? $event->command : 'command';
        $span = $this->provider->getTracer('most.laravel')->spanBuilder('console '.$name)
            ->setAttribute('console.command', $name)->startSpan();
        $span = $this->withCorrelation($span);
        $this->activeCommands[] = [$event->input, $name, $span, $span->activate()];
    }

    public function finishCommand(CommandFinished $event): void
    {
        foreach (array_reverse(array_keys($this->activeCommands)) as $key) {
            [$input, , $span, $scope] = $this->activeCommands[$key];
            if ($input !== $event->input) {
                continue;
            }
            unset($this->activeCommands[$key]);
            try {
                $span->setAttribute('console.exit_code', $event->exitCode);
                if ($event->exitCode !== 0) {
                    $span->setStatus(StatusCode::STATUS_ERROR);
                }
                $span->end();
            } finally {
                $scope->detach();
                $this->flush();
            }
            return;
        }
    }

    public function slowQueryTrace(QueryExecuted $event): array
    {
        $parent = Span::getCurrent()->getContext();
        if ($parent->isValid() && $parent->isSampled() && Span::getCurrent()->isRecording()) {
            return ['trace_id' => $parent->getTraceId(), 'span_id' => $parent->getSpanId(), 'trace_sampled' => true, 'trace_kind' => 'execution'];
        }
        try {
            $end = Clock::getDefault()->now();
            $builder = $this->provider->getTracer('most.laravel')->spanBuilder('db.slow_query '.$this->operation($event->sql))
                ->setParent(Context::getRoot())
                ->setSpanKind(SpanKind::KIND_CLIENT)
                ->setStartTimestamp(max(0, $end - (int) round(max(0.0, $event->time) * 1_000_000)))
                ->setAttributes([
                    'most.slow_query' => true,
                    'db.system.name' => 'postgresql',
                    'db.operation.name' => $this->operation($event->sql),
                    'db.connection.name' => (string) $event->connectionName,
                    'db.query.fingerprint' => hash('sha256', $event->sql),
                    'db.query.duration_ms' => $event->time,
                ]);
            if ($parent->isValid()) {
                $builder->addLink($parent);
            }
            $span = $builder->startSpan();
            $span->end($end);
            $context = $span->getContext();

            return [
                'trace_id' => $context->isValid() ? $context->getTraceId() : bin2hex(random_bytes(16)),
                'span_id' => $context->isValid() ? $context->getSpanId() : null,
                'trace_sampled' => $context->isSampled(),
                'trace_kind' => 'slow_query',
                'parent_trace_id' => $parent->isValid() ? $parent->getTraceId() : null,
            ];
        } catch (Throwable) {
            return ['trace_id' => self::currentTraceId() ?? bin2hex(random_bytes(16)), 'trace_sampled' => false, 'trace_kind' => 'correlation'];
        }
    }

    public function startHttp(Request $request): array
    {
        $carrier = [];
        if (is_string($request->header('traceparent'))) {
            $carrier['traceparent'] = $request->header('traceparent');
        }
        if (is_string($request->header('tracestate'))) {
            $carrier['tracestate'] = $request->header('tracestate');
        }

        $parent = TraceContextPropagator::getInstance()->extract($carrier, context: Context::getRoot());
        $span = $this->provider->getTracer('most.laravel')
            ->spanBuilder('HTTP '.$request->method())
            ->setParent($parent)
            ->setSpanKind(SpanKind::KIND_SERVER)
            ->setAttribute('http.request.method', $request->method())
            ->startSpan();
        $span = $this->withCorrelation($span);

        $scope = $span->activate();
        $this->activeHttp[spl_object_id($request)] = $request;

        return [$span, $scope];
    }

    public function endHttpContext(Request $request): void
    {
        unset($this->activeHttp[spl_object_id($request)]);
    }

    public function finishHttp(SpanInterface $span, Request $request, Response $response): void
    {
        $route = $request->route();
        if ($route !== null) {
            $routeName = $route->uri();
            $span->updateName($request->method().' '.$routeName);
            $span->setAttribute('http.route', $routeName);
        }

        $status = $response->getStatusCode();
        $span->setAttribute('http.response.status_code', $status);
        if ($status >= 500) {
            $span->setStatus(StatusCode::STATUS_ERROR);
        }
    }

    public function markError(SpanInterface $span, Throwable $exception): void
    {
        $span->setStatus(StatusCode::STATUS_ERROR);
        $span->setAttribute('error.type', $exception::class);
    }

    public function logCompletedTrace(SpanInterface $span, string $kind): void
    {
        try {
            Log::channel('single')->info('trace_completed', [
                'trace_id' => $span->getContext()->getTraceId(),
                'span_id' => $span->getContext()->getSpanId(),
                'kind' => $kind,
            ]);
        } catch (Throwable) {
            return;
        }
    }

    public function recordSql(QueryExecuted $event): void
    {
        $operation = $this->operation($event->sql);
        $this->recordCompletedOperation('db.query '.$operation, $event->time, [
            'db.system.name' => 'postgresql',
            'db.operation.name' => $operation,
            'db.connection.name' => (string) $event->connectionName,
        ]);
    }

    public function recordRedis(CommandExecuted $event): void
    {
        $operation = $this->operation($event->command);
        $this->recordCompletedOperation('redis '.$operation, (float) $event->time, [
            'db.system.name' => 'redis',
            'db.operation.name' => $operation,
            'db.connection.name' => (string) $event->connectionName,
        ]);
    }

    public function queuePayload(): array
    {
        try {
            $carrier = [];
            TraceContextPropagator::getInstance()->inject($carrier);

            if (! isset($carrier['traceparent'])) {
                return [];
            }

            return [
                'otel_traceparent' => $carrier['traceparent'],
                'otel_tracestate' => $carrier['tracestate'] ?? null,
            ];
        } catch (Throwable) {
            return [];
        }
    }

    public function startJob(JobProcessing $event): void
    {
        try {
            $payload = $event->job->payload();
            $carrier = [];
            if (is_string($payload['otel_traceparent'] ?? null)) {
                $carrier['traceparent'] = $payload['otel_traceparent'];
            }
            if (is_string($payload['otel_tracestate'] ?? null)) {
                $carrier['tracestate'] = $payload['otel_tracestate'];
            }

            $parent = TraceContextPropagator::getInstance()->extract($carrier, context: Context::getRoot());
            $jobName = class_basename((string) ($payload['displayName'] ?? $event->job->getName()));
            $jobName = preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,100}$/', $jobName) ? $jobName : 'Job';
            $span = $this->provider->getTracer('most.laravel')
                ->spanBuilder('queue.process '.$jobName)
                ->setParent($parent)
                ->setSpanKind(SpanKind::KIND_CONSUMER)
                ->setAttribute('messaging.system', 'laravel-queue')
                ->setAttribute('messaging.operation.name', 'process')
                ->setAttribute('job.type', $jobName)
                ->startSpan();
            $span = $this->withCorrelation($span);

            $jobId = $payload['uuid'] ?? null;
            $this->activeJobs[spl_object_id($event->job)] = [$span, $span->activate(), [
                'kind' => 'queue', 'job' => $jobName,
                'job_id' => is_string($jobId) && preg_match('/^[0-9a-f-]{36}$/D', $jobId) === 1 ? $jobId : null,
            ]];
        } catch (Throwable) {
            return;
        }
    }

    public function markJobFailed(Job $job, Throwable $exception): void
    {
        $active = $this->activeJobs[spl_object_id($job)] ?? null;
        if ($active !== null) {
            $this->markError($active[0], $exception);
        }
    }

    public function finishJob(Job $job, ?Throwable $exception = null): void
    {
        $id = spl_object_id($job);
        $active = $this->activeJobs[$id] ?? null;
        if ($active === null) {
            return;
        }

        unset($this->activeJobs[$id]);
        [$span, $scope] = $active;
        try {
            if ($exception !== null) {
                $this->markError($span, $exception);
            }
            $span->end();
        } finally {
            $scope->detach();
            if ($span->getContext()->isSampled()) {
                $this->logCompletedTrace($span, 'queue');
            }
            $this->flush();
        }
    }

    public function flush(): void
    {
        if ($this->provider instanceof TracerProvider) {
            try {
                $this->provider->forceFlush();
            } catch (Throwable) {
                return;
            }
        }
    }

    private function recordCompletedOperation(string $name, float $durationMs, array $attributes): void
    {
        if (! Span::getCurrent()->isRecording()) {
            return;
        }

        try {
            $end = Clock::getDefault()->now();
            $start = max(0, $end - (int) round(max(0.0, $durationMs) * 1_000_000));
            $span = $this->provider->getTracer('most.laravel')
                ->spanBuilder($name)
                ->setSpanKind(SpanKind::KIND_CLIENT)
                ->setStartTimestamp($start)
                ->setAttributes($attributes)
                ->startSpan();
            $span->end($end);
        } catch (Throwable) {
            return;
        }
    }

    private function operation(string $value): string
    {
        return preg_match('/^\s*([a-z]{1,20})\b/i', $value, $matches)
            ? strtoupper($matches[1])
            : 'OTHER';
    }
}
