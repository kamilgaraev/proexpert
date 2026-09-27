<?php

declare(strict_types=1);

namespace App\Services\Monitoring;

use Illuminate\Contracts\Queue\Job;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Redis\Events\CommandExecuted;
use Illuminate\Support\Facades\Log;
use OpenTelemetry\API\Common\Time\Clock;
use OpenTelemetry\API\Trace\NoopTracerProvider;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ScopeInterface;
use OpenTelemetry\Contrib\Otlp\OtlpHttpTransportFactory;
use OpenTelemetry\Contrib\Otlp\SpanExporter;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Trace\Sampler\ParentBased;
use OpenTelemetry\SDK\Trace\Sampler\TraceIdRatioBasedSampler;
use OpenTelemetry\SDK\Trace\SpanProcessor\BatchSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\SDK\Trace\TracerProviderBuilder;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class TracingService
{
    private array $activeJobs = [];

    public function __construct(private readonly TracerProviderInterface $provider)
    {
    }

    public static function fromConfig(): self
    {
        if (! config('monitoring.tracing_enabled')) {
            return new self(new NoopTracerProvider());
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
                ->setSampler(new ParentBased(new TraceIdRatioBasedSampler($ratio)))
                ->build();

            return new self($provider);
        } catch (Throwable $exception) {
            error_log('OpenTelemetry initialization failed: '.$exception::class);

            return new self(new NoopTracerProvider());
        }
    }

    public static function currentTraceId(): ?string
    {
        $context = Span::getCurrent()->getContext();

        return $context->isValid() && $context->isSampled() ? $context->getTraceId() : null;
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

        return [$span, $span->activate()];
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

            $this->activeJobs[spl_object_id($event->job)] = [$span, $span->activate()];
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
                $this->flush();
            }
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
