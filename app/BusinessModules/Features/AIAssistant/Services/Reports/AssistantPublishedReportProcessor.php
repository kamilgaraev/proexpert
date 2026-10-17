<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Reports;

use App\BusinessModules\Core\Reporting\Application\Contracts\CreateReportExportAction;
use App\BusinessModules\Core\Reporting\Application\Contracts\CreateReportRunAction;
use App\BusinessModules\Core\Reporting\Application\Contracts\GetReportRunAction;
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
    ) {}

    public function process(string $id): void
    {
        $task = $this->claim($id);
        if ($task === null) {
            return;
        }
        try {
            $this->tasks->assertInput($task);
            $this->access->actor((int) $task->user_id, (int) $task->organization_id);
            $parameters = $task->parameters;
            $context = $this->authorization->createRun((int) $task->user_id, (int) $task->organization_id, $parameters);
            if ($task->run_id === null) {
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
            $permanent = $exception instanceof AuthorizationException || $exception instanceof InvalidArgumentException
                || $exception instanceof ModelNotFoundException;
            $failures = (int) $task->failure_count + 1;
            Log::warning('assistant_published_report_attempt_failed', ['task_id' => $id, 'exception_type' => $exception::class, 'attempt' => $failures]);
            $this->finish($task, ['status' => $permanent || $failures >= 5 ? 'failed' : $task->status,
                'failure_count' => $failures, 'error_code' => $permanent ? 'ACCESS_OR_INPUT_CHANGED' : 'REPORT_UNAVAILABLE'], min(60, 5 * $failures));
        }
    }

    public function recover(): void
    {
        AssistantCoreReportTask::query()->whereIn('status', AssistantCoreReportTask::PENDING)
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
            if ($task === null || ! in_array($task->status, AssistantCoreReportTask::PENDING, true)
                || $task->retry_at->isFuture() || ($task->lease_expires_at !== null && $task->lease_expires_at->isFuture())) {
                return null;
            }
            if ($this->stop($task)) {
                return null;
            }
            if ($task->lease_token !== null) {
                $task->failure_count++;
                if ($task->failure_count >= 5) {
                    $task->fill(['status' => 'failed', 'error_code' => 'EXECUTION_INTERRUPTED', 'lease_token' => null, 'lease_expires_at' => null])->save();

                    return null;
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
        $dispatch = DB::transaction(function () use ($claimed, $changes, $delay): bool {
            $task = AssistantCoreReportTask::query()->lockForUpdate()->find($claimed->id);
            if ($task === null || $task->lease_token !== $claimed->lease_token
                || ! hash_equals((string) $task->input_sha256, (string) $claimed->input_sha256)
                || ! in_array($task->status, AssistantCoreReportTask::PENDING, true)) {
                return false;
            }
            if ($this->stop($task)) {
                return false;
            }
            $task->fill($changes + ['retry_at' => now()->addSeconds($delay), 'lease_token' => null, 'lease_expires_at' => null])->save();

            return in_array($task->status, AssistantCoreReportTask::PENDING, true);
        }, 3);
        if ($dispatch) {
            $this->tasks->dispatch((string) $claimed->id, $delay);
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
            $task->fill(['status' => $status, 'lease_token' => null, 'lease_expires_at' => null])->save();
        }

        return $status !== null;
    }
}
