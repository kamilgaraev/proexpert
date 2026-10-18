<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Reports;

use App\BusinessModules\Core\Reporting\Application\Contracts\CreateReportExportAction;
use App\BusinessModules\Core\Reporting\Application\Contracts\CreateReportRunAction;
use App\BusinessModules\Core\Reporting\Application\Contracts\GetReportRunAction;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Application\Input\CreateReportExportData;
use App\BusinessModules\Core\Reporting\Application\Input\CreateReportRunData;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportFilterSet;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportWindowSort;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSortDirection;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\IdempotencyKey;
use App\BusinessModules\Features\AIAssistant\Models\AssistantCoreReportTask;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

final readonly class AssistantPublishedReportProcessor
{
    public function __construct(
        private AssistantPublishedReportTasks $tasks,
        private AssistantPublishedReportAccess $access,
        private AssistantPublishedReportAuthorization $authorization,
        private CreateReportRunAction $createRuns,
        private GetReportRunAction $runs,
        private CreateReportExportAction $createExports,
        private AssistantPublishedReportCancellation $cancellation,
        private AssistantPublishedReportSourceReadiness $readiness,
    ) {}

    public function process(string $id): void
    {
        $task = $this->claim($id);
        if ($task === null) {
            return;
        }
        if (in_array($task->status, AssistantCoreReportTask::STOPPED, true)) {
            $this->cleanup($task);

            return;
        }
        try {
            $this->tasks->assertInput($task);
            $this->access->actor((int) $task->user_id, (int) $task->organization_id);
            $parameters = $task->parameters;
            if ($task->run_id === null) {
                $context = $this->authorization->createRun((int) $task->user_id, (int) $task->organization_id, $parameters);
                $run = $this->createRuns->handle($context, new CreateReportRunData(
                    $parameters['report_code'], new ReportFilterSet($parameters['filters']), $parameters['comparison'],
                    new DateTimeImmutable($parameters['as_of']), $parameters['locale'], null,
                ), new IdempotencyKey('assistant-core:'.$task->id.':run:v1'));
                $task->run_id = $run->id;
                $this->tasks->assertRun($task, $run);
                $this->finish($task, ['run_id' => $run->id, 'status' => 'building_run',
                    'expires_at' => min($task->expires_at->toDateTimeImmutable(), $run->expiresAt)]);

                return;
            }
            $state = $this->tasks->state($task);
            if (! in_array($state['status'], AssistantCoreReportTask::PENDING, true)) {
                $this->finish($task, ['status' => $state['status']]);

                return;
            }
            if ($task->export_id !== null) {
                $this->finish($task, ['status' => 'building_export']);

                return;
            }
            $run = $this->runs->handle($this->authorization->run((int) $task->user_id, (int) $task->organization_id, $task->run_id), $task->run_id);
            $this->tasks->assertRun($task, $run);
            if ($run->status->value !== 'ready') {
                $this->finish($task, ['status' => 'building_run']);

                return;
            }
            $export = $this->createExports->handle(
                $this->authorization->createExport((int) $task->user_id, (int) $task->organization_id, $task->run_id, $parameters['format']),
                $task->run_id, new CreateReportExportData($parameters['format'], $parameters['columns'],
                    new ReportWindowSort($parameters['sort']['field'], ReportSortDirection::from($parameters['sort']['direction'])),
                    $parameters['locale'], new DateTimeZone($parameters['timezone'])),
                new IdempotencyKey('assistant-core:'.$task->id.':export:v1'),
            );
            $task->export_id = $export->id;
            $this->tasks->assertExport($task, $export);
            $this->finish($task, ['export_id' => $export->id, 'status' => 'building_export',
                'expires_at' => min($task->expires_at->toDateTimeImmutable(), $export->expiresAt)]);
        } catch (Throwable $exception) {
            $sourceError = null;
            if ($exception instanceof ReportContractException && in_array($exception->errorCode, [ReportErrorCode::REPORT_SOURCE_UNAVAILABLE, ReportErrorCode::REPORT_FILTER_RANGE_INVALID], true)
                && $task->run_id === null && $task->parameters['report_code'] === 'accepted_production_progress') {
                try {
                    $sourceError = $this->readiness->check(
                        $this->authorization->createRun((int) $task->user_id, (int) $task->organization_id, $task->parameters),
                        $task->parameters,
                    );
                } catch (Throwable $reasonException) {
                    if ($reasonException instanceof AuthorizationException || $reasonException instanceof InvalidArgumentException
                        || $reasonException instanceof ModelNotFoundException
                        || ($reasonException instanceof ReportContractException && $reasonException->errorCode === ReportErrorCode::REPORT_SCOPE_FORBIDDEN)) {
                        $exception = $reasonException;
                    } else {
                        Log::warning('assistant_published_report_source_reason_unavailable', ['task_id' => $id, 'exception_type' => $reasonException::class]);
                    }
                }
            }
            $permanent = $exception instanceof AuthorizationException || $exception instanceof InvalidArgumentException
                || $exception instanceof ModelNotFoundException || ($sourceError !== null && $sourceError !== 'REPORT_SOURCE_UNAVAILABLE')
                || ($exception instanceof ReportContractException && $exception->errorCode === ReportErrorCode::REPORT_SCOPE_FORBIDDEN);
            $failures = (int) $task->failure_count + 1;
            $errorCode = $sourceError ?? ($exception instanceof ReportContractException && $exception->errorCode === ReportErrorCode::REPORT_SOURCE_UNAVAILABLE
                ? ReportErrorCode::REPORT_SOURCE_UNAVAILABLE->value : ($permanent ? 'ACCESS_OR_INPUT_CHANGED' : 'REPORT_UNAVAILABLE'));
            Log::warning('assistant_published_report_attempt_failed', ['task_id' => $id, 'exception_type' => $exception::class, 'attempt' => $failures]);
            $this->finish($task, ['status' => $permanent || $failures >= 5 ? 'failed' : $task->status,
                'failure_count' => $failures, 'error_code' => $errorCode], min(60, 5 * $failures));
        }
    }

    public function recover(): void
    {
        AssistantCoreReportTask::query()->whereNull('assistant_request_id')->where('execution_expires_at', '<=', now())
            ->where(static fn ($query) => $query->where('status', 'ready')->orWhereNotNull('core_cleanup_completed_at'))->delete();
        AssistantCoreReportTask::query()->where(static fn ($query) => $query->whereIn('status', AssistantCoreReportTask::PENDING)
            ->orWhere(static fn ($stopped) => $stopped->whereIn('status', AssistantCoreReportTask::STOPPED)
                ->whereNull('core_cleanup_completed_at')->where('core_cleanup_attempts', '<', 5)))
            ->where('retry_at', '<=', now())->where(static fn ($query) => $query->whereNull('lease_expires_at')->orWhere('lease_expires_at', '<=', now()))
            ->orderBy('id')->chunkById(100, function ($tasks): void {
                foreach ($tasks as $task) {
                    $this->tasks->dispatch((string) $task->id);
                }
            });
    }

    private function claim(string $id): ?AssistantCoreReportTask
    {
        return DB::transaction(function () use ($id): ?AssistantCoreReportTask {
            $task = AssistantCoreReportTask::query()->lockForUpdate()->find($id);
            if ($task === null || (! in_array($task->status, AssistantCoreReportTask::PENDING, true)
                && (! in_array($task->status, AssistantCoreReportTask::STOPPED, true)
                    || $task->core_cleanup_completed_at !== null || $task->core_cleanup_attempts >= 5))
                || $task->retry_at->isFuture() || ($task->lease_expires_at !== null && $task->lease_expires_at->isFuture())) {
                return null;
            }
            if (in_array($task->status, AssistantCoreReportTask::PENDING, true)) {
                $this->stop($task);
            }
            if (in_array($task->status, AssistantCoreReportTask::STOPPED, true) && $task->lease_token !== null) {
                $task->core_cleanup_attempts++;
                if ($task->core_cleanup_attempts >= 5) {
                    $task->fill(['error_code' => 'CORE_CLEANUP_UNAVAILABLE', 'lease_token' => null, 'lease_expires_at' => null])->save();

                    return null;
                }
            }
            if (in_array($task->status, AssistantCoreReportTask::PENDING, true) && $task->lease_token !== null) {
                $task->failure_count++;
                if ($task->failure_count >= 5) {
                    $task->fill(['status' => 'failed', 'error_code' => 'EXECUTION_INTERRUPTED']);
                }
            }
            $task->fill(['lease_token' => (string) Str::uuid(), 'lease_expires_at' => now()->addSeconds(120)])->save();

            return $task;
        }, 3);
    }

    private function finish(AssistantCoreReportTask $claimed, array $changes, ?int $delay = null): void
    {
        $delay ??= array_intersect_key($changes, ['run_id' => true, 'export_id' => true]) !== []
            ? 1 : AssistantPublishedReportPolling::seconds(now()->getTimestamp() - $claimed->created_at->getTimestamp());
        $cleanup = null;
        $dispatch = DB::transaction(function () use ($claimed, $changes, $delay, &$cleanup): bool {
            $task = AssistantCoreReportTask::query()->lockForUpdate()->find($claimed->id);
            if ($task === null) {
                $cleanup = $claimed;

                return false;
            }
            if (! hash_equals((string) $task->input_sha256, (string) $claimed->input_sha256)) {
                return false;
            }
            if (in_array($task->status, AssistantCoreReportTask::STOPPED, true)) {
                $task->fill(array_intersect_key($changes, ['run_id' => true, 'export_id' => true]) + [
                    'core_cleanup_completed_at' => null, 'core_cleanup_attempts' => 0, 'retry_at' => now(),
                    'lease_token' => null, 'lease_expires_at' => null,
                ])->save();

                return true;
            }
            if ($task->lease_token !== $claimed->lease_token || ! in_array($task->status, AssistantCoreReportTask::PENDING, true)) {
                return false;
            }
            $task->fill(array_intersect_key($changes, ['run_id' => true, 'export_id' => true]));
            if ($this->stop($task)) {
                $cleanup = $task;

                return false;
            }
            $task->fill($changes + ['retry_at' => now()->addSeconds($delay)]);
            if (in_array($task->status, AssistantCoreReportTask::STOPPED, true)) {
                $cleanup = $task;
            } else {
                $task->fill(['lease_token' => null, 'lease_expires_at' => null]);
            }
            $task->save();

            return in_array($task->status, AssistantCoreReportTask::PENDING, true);
        }, 3);
        if ($cleanup !== null) {
            $this->cleanup($cleanup);
        }
        if ($dispatch) {
            $this->tasks->dispatch((string) $claimed->id, $delay);
        }
    }

    private function cleanup(AssistantCoreReportTask $task): void
    {
        $failed = false;
        try {
            $this->cancellation->cancel($task);
        } catch (Throwable $exception) {
            $failed = true;
            Log::warning('assistant_published_report_cleanup_failed', ['task_id' => $task->id, 'exception_type' => $exception::class]);
        }
        $attempts = (int) $task->core_cleanup_attempts + 1;
        $changes = ['core_cleanup_attempts' => $attempts, 'core_cleanup_completed_at' => $failed ? null : now(),
            'retry_at' => now()->addMinute(), 'lease_token' => null, 'lease_expires_at' => null];
        if ($failed) {
            $changes['error_code'] = 'CORE_CLEANUP_UNAVAILABLE';
        }
        $updated = AssistantCoreReportTask::query()->whereKey($task->id)->where('lease_token', $task->lease_token)
            ->where('input_sha256', $task->input_sha256)->whereIn('status', AssistantCoreReportTask::STOPPED)->update($changes);
        if ($updated > 0 && $failed && $attempts < 5) {
            $this->tasks->dispatch((string) $task->id, 60);
        }
    }

    private function stop(AssistantCoreReportTask $task): bool
    {
        $request = $task->assistantRequest;
        $status = null;
        if ($request === null || ! $this->tasks->sameOwner($task, $request) || $request->cancel_requested_at !== null
            || in_array($request->status, ['failed', 'cancelled'], true)) {
            $status = 'cancelled';
        } elseif ($task->execution_expires_at->isPast() || $task->expires_at->isPast()) {
            $status = 'expired';
        }
        if ($status !== null) {
            $task->fill(['status' => $status])->save();
        }

        return $status !== null;
    }
}
