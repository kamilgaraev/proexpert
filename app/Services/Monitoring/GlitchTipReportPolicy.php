<?php

declare(strict_types=1);

namespace App\Services\Monitoring;

use App\Exceptions\BusinessLogicException;
use App\BusinessModules\Features\AIAssistant\Jobs\IndexRagSourceJob;
use App\Support\LivewirePayloadExceptionClassifier;
use Illuminate\Http\Request;
use Illuminate\Queue\MaxAttemptsExceededException;
use Predis\Connection\ConnectionException as PredisConnectionException;
use Sentry\Severity;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class GlitchTipReportPolicy
{
    public function __construct(private ?array $reportingConfig = null)
    {
        $this->reportingConfig ??= (array) config('glitchtip.reporting', []);
    }

    public function shouldCapture(Throwable $exception, ?Request $request = null): bool
    {
        if (!$this->enabled()) {
            return false;
        }

        if ((new LivewirePayloadExceptionClassifier())->isMalformedClientUpdate($exception, $request)) {
            return false;
        }

        if ($this->isTransientQueueWorkerRedisError($exception, $request)) {
            return false;
        }

        if ($this->isRecoverableRagOrganizationEstimateMaxAttempts($exception)) {
            return false;
        }

        if ($this->matchesConfiguredException($exception, $this->ignoredExceptions())) {
            return false;
        }

        if ($exception instanceof HttpExceptionInterface && $this->isIgnoredHttpStatus($exception->getStatusCode())) {
            return false;
        }

        if ($exception instanceof BusinessLogicException) {
            return (bool) ($this->reportingConfig['business_logic']['capture'] ?? false);
        }

        if ($this->matchesConfiguredException($exception, $this->capturedExceptions())) {
            return true;
        }

        if ($exception instanceof HttpExceptionInterface) {
            return $this->isCapturedHttpStatus($exception->getStatusCode());
        }

        return (bool) ($this->reportingConfig['capture_unexpected'] ?? true);
    }

    public function levelFor(Throwable $exception): string
    {
        if ($exception instanceof BusinessLogicException) {
            return $this->normalizeLevel((string) ($this->reportingConfig['business_logic']['level'] ?? 'warning'));
        }

        foreach ($this->capturedExceptions() as $class => $level) {
            if ($exception instanceof $class) {
                return $this->normalizeLevel((string) $level);
            }
        }

        if ($exception instanceof HttpExceptionInterface) {
            $statusCode = $exception->getStatusCode();
            $levels = (array) ($this->reportingConfig['capture']['http_statuses'] ?? []);

            if (isset($levels[$statusCode])) {
                return $this->normalizeLevel((string) $levels[$statusCode]);
            }

            if ($statusCode >= (int) ($this->reportingConfig['capture']['http_status_min'] ?? 500)) {
                return $this->normalizeLevel((string) ($this->reportingConfig['capture']['http_status_min_level'] ?? 'error'));
            }
        }

        return $this->normalizeLevel((string) ($this->reportingConfig['default_level'] ?? 'error'));
    }

    public function shouldCaptureManually(Throwable $exception): bool
    {
        $manualLevels = (array) ($this->reportingConfig['manual_capture_levels'] ?? ['warning']);

        return in_array($this->levelFor($exception), $manualLevels, true);
    }

    public function stopIgnoringExceptions(): array
    {
        return array_values(array_filter((array) ($this->reportingConfig['stop_ignoring'] ?? [])));
    }

    private function enabled(): bool
    {
        return (bool) ($this->reportingConfig['enabled'] ?? true);
    }

    private function capturedExceptions(): array
    {
        return (array) ($this->reportingConfig['capture']['exceptions'] ?? []);
    }

    private function ignoredExceptions(): array
    {
        return array_values((array) ($this->reportingConfig['ignore']['exceptions'] ?? []));
    }

    private function isCapturedHttpStatus(int $statusCode): bool
    {
        $statuses = (array) ($this->reportingConfig['capture']['http_statuses'] ?? []);

        if (array_key_exists($statusCode, $statuses)) {
            return true;
        }

        $minimum = (int) ($this->reportingConfig['capture']['http_status_min'] ?? 500);

        return $minimum > 0 && $statusCode >= $minimum;
    }

    private function isIgnoredHttpStatus(int $statusCode): bool
    {
        return in_array($statusCode, (array) ($this->reportingConfig['ignore']['http_statuses'] ?? []), true);
    }

    private function isTransientQueueWorkerRedisError(Throwable $exception, ?Request $request): bool
    {
        return $request === null
            && $exception instanceof PredisConnectionException
            && str_contains($exception->getMessage(), 'Stream is already at the end');
    }

    private function isRecoverableRagOrganizationEstimateMaxAttempts(Throwable $exception): bool
    {
        if (! $exception instanceof MaxAttemptsExceededException) {
            return false;
        }

        $payload = $this->queueJobPayload($exception);
        $commandName = $payload['data']['commandName'] ?? null;
        $command = $payload['data']['command'] ?? null;

        if ($commandName !== IndexRagSourceJob::class || ! is_string($command)) {
            return false;
        }

        return str_contains($command, 's:9:"projectId";N;')
            && str_contains($command, 's:10:"sourceType";s:8:"estimate";')
            && str_contains($command, 's:5:"runId";i:');
    }

    private function queueJobPayload(MaxAttemptsExceededException $exception): array
    {
        $job = $exception->job;

        if (! is_object($job) || ! method_exists($job, 'payload')) {
            return [];
        }

        try {
            $payload = $job->payload();
        } catch (Throwable) {
            return [];
        }

        return is_array($payload) ? $payload : [];
    }

    private function matchesConfiguredException(Throwable $exception, array $classes): bool
    {
        foreach ($classes as $key => $value) {
            $class = is_string($key) ? $key : $value;

            if (is_string($class) && is_a($exception, $class)) {
                return true;
            }
        }

        return false;
    }

    private function normalizeLevel(string $level): string
    {
        return in_array($level, Severity::ALLOWED_SEVERITIES, true) ? $level : Severity::ERROR;
    }
}
