<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Infrastructure\Listeners;

use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportExecutionClock;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportExecutionTelemetry;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Application\Execution\ReportRunAttemptFinalizer;
use App\BusinessModules\Core\Reporting\Infrastructure\Jobs\MaterializeReportRunJob;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Str;
use Throwable;

final readonly class FinalizeFailedReportRunAttempt
{
    public function __construct(
        private ReportRunAttemptFinalizer $finalizer,
        private ReportExecutionClock $clock,
        private ReportExecutionTelemetry $telemetry,
    ) {}

    public function __invoke(JobFailed $event): void
    {
        $uuid = $event->job->uuid();
        $failure = $event->exception;
        $runId = $this->runId($event);
        if (
            $event->connectionName !== 'redis_reports'
            || $event->job->getQueue() !== 'reports'
            || $event->job->resolveName() !== MaterializeReportRunJob::class
            || ! is_string($uuid)
            || ! Str::isUuid($uuid)
            || $runId === null
            || ! $failure instanceof Throwable
        ) {
            $this->telemetry->executionAttempt('run', ReportErrorCode::REPORT_INTERNAL_ERROR->value);

            return;
        }

        $this->finalizer->finalize($runId, strtolower($uuid), $failure, $this->clock->now());
    }

    private function runId(JobFailed $event): ?string
    {
        $payload = $event->job->payload();
        $serialized = is_array($payload)
            && isset($payload['data'])
            && is_array($payload['data'])
            && isset($payload['data']['command'])
            && is_string($payload['data']['command'])
            ? $payload['data']['command']
            : null;
        if ($serialized === null || strlen($serialized) > 32768) {
            return null;
        }
        $class = preg_quote(MaterializeReportRunJob::class, '/');
        if (preg_match('/^O:\d+:"'.$class.'":\d+:{/', $serialized) !== 1) {
            return null;
        }

        try {
            $command = @unserialize($serialized, ['allowed_classes' => [MaterializeReportRunJob::class]]);
        } catch (Throwable) {
            return null;
        }
        if (! $command instanceof MaterializeReportRunJob) {
            return null;
        }

        return preg_match('/^[0-9ABCDEFGHJKMNPQRSTVWXYZ]{26}$/', $command->runId) === 1
            ? $command->runId
            : null;
    }
}
