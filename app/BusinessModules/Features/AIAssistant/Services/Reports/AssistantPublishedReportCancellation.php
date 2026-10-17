<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Reports;

use App\BusinessModules\Core\Reporting\Application\Contracts\CancelReportExportAction;
use App\BusinessModules\Core\Reporting\Application\Contracts\CancelReportRunAction;
use App\BusinessModules\Core\Reporting\Application\Contracts\GetReportExportAction;
use App\BusinessModules\Core\Reporting\Application\Contracts\GetReportRunAction;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportOperation;
use App\BusinessModules\Features\AIAssistant\Models\AssistantCoreReportTask;

final readonly class AssistantPublishedReportCancellation
{
    public function __construct(
        private AssistantPublishedReportTasks $tasks,
        private AssistantPublishedReportAuthorization $authorization,
        private GetReportRunAction $runs,
        private GetReportExportAction $exports,
        private CancelReportRunAction $cancelRuns,
        private CancelReportExportAction $cancelExports,
    ) {}

    public function cancel(AssistantCoreReportTask $task): void
    {
        $this->tasks->assertInput($task);
        $actorId = (int) $task->user_id;
        $organizationId = (int) $task->organization_id;
        if ($task->export_id !== null) {
            $context = $this->authorization->export($actorId, $organizationId, $task->export_id);
            $export = $this->exports->handle($context, $task->export_id);
            $this->tasks->assertExport($task, $export);
            if (in_array($export->status->value, ['queued', 'running', 'uploading'], true)) {
                $this->cancelExports->handle($this->authorization->export($actorId, $organizationId, $task->export_id, ReportOperation::EXPORT), $task->export_id);
            }
        }
        if ($task->run_id !== null) {
            $context = $this->authorization->run($actorId, $organizationId, $task->run_id);
            $run = $this->runs->handle($context, $task->run_id);
            $this->tasks->assertRun($task, $run);
            if (in_array($run->status->value, ['queued', 'materializing'], true)) {
                $this->cancelRuns->handle($this->authorization->run($actorId, $organizationId, $task->run_id, ReportOperation::RUN), $task->run_id);
            }
        }
    }
}
