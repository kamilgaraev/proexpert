<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Infrastructure\Listeners;

use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportExecutionClock;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportExecutionTelemetry;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Application\Exports\ReportExportAttemptFinalizer;
use App\BusinessModules\Core\Reporting\Infrastructure\Jobs\GenerateReportExportJob;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Str;
use Throwable;

final readonly class FinalizeFailedReportExportAttempt
{
    public function __construct(
        private ReportExportAttemptFinalizer $finalizer,
        private ReportExecutionClock $clock,
        private ReportExecutionTelemetry $telemetry,
    ) {}

    public function __invoke(JobFailed $event): void
    {
        if (
            $event->connectionName !== 'redis_reports'
            || $event->job->getQueue() !== 'reports'
            || $event->job->resolveName() !== GenerateReportExportJob::class
        ) {
            return;
        }

        $uuid = $event->job->uuid();
        $failure = $event->exception;
        $exportId = $this->exportId($event);
        if (
            ! is_string($uuid)
            || ! Str::isUuid($uuid)
            || $exportId === null
            || ! $failure instanceof Throwable
        ) {
            $this->telemetry->executionAttempt(
                'export',
                ReportErrorCode::REPORT_INTERNAL_ERROR->value,
            );

            return;
        }

        $this->finalizer->finalize(
            $exportId,
            strtolower($uuid),
            $failure,
            $this->clock->now(),
        );
    }

    private function exportId(JobFailed $event): ?string
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

        $class = preg_quote(GenerateReportExportJob::class, '/');
        if (preg_match('/^O:\d+:"'.$class.'":\d+:{/', $serialized) !== 1) {
            return null;
        }

        try {
            $command = @unserialize(
                $serialized,
                ['allowed_classes' => [GenerateReportExportJob::class]],
            );
        } catch (Throwable) {
            return null;
        }
        if (! $command instanceof GenerateReportExportJob) {
            return null;
        }

        return preg_match(
            '/^[0-9ABCDEFGHJKMNPQRSTVWXYZ]{26}$/D',
            $command->exportId,
        ) === 1
            ? $command->exportId
            : null;
    }
}
