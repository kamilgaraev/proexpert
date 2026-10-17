<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Reports;

use App\BusinessModules\Core\Reporting\Application\Contracts\GetReportExportAction;
use App\BusinessModules\Core\Reporting\Application\Contracts\GetReportRunAction;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExport;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportRun;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportOperation;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use App\BusinessModules\Features\AIAssistant\Jobs\GenerateAssistantPublishedReport;
use App\BusinessModules\Features\AIAssistant\Models\AssistantCoreReportTask;
use App\BusinessModules\Features\AIAssistant\Models\AssistantRequest;
use App\BusinessModules\Features\AIAssistant\Services\AssistantCurrentRequest;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\AIAssistant\Services\AssistantRequestProjectScope;
use App\BusinessModules\Features\KnowledgeHub\Enums\KnowledgeSurface;
use App\Models\User;
use DateTimeImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

final readonly class AssistantPublishedReportTasks
{
    public function __construct(
        private AssistantPublishedReportAccess $access,
        private AssistantPublishedReportInput $input,
        private AssistantPublishedReportAuthorization $authorization,
        private AssistantCurrentRequest $current,
        private AssistantDataAccessPolicy $policy,
        private GetReportRunAction $runs,
        private GetReportExportAction $exports,
    ) {}

    public function create(array $arguments, User $actor, int $organizationId): array
    {
        $actor = $this->access->actor((int) $actor->id, $organizationId, true);
        if ($this->policy->trustedSurface() !== KnowledgeSurface::ADMIN) {
            throw new AuthorizationException;
        }
        $request = $this->currentRequest($actor, $organizationId);
        if ($request->status !== 'running' || $request->cancel_requested_at !== null) {
            throw new AuthorizationException;
        }
        $parameters = $this->input->prepare($arguments, $actor, $organizationId, DateTimeImmutable::createFromInterface($request->created_at));
        $this->assertProject($request, $parameters);
        $hash = hash('sha256', CanonicalJson::encode($parameters));
        $task = DB::transaction(function () use ($request, $parameters, $hash, $actor, $organizationId): AssistantCoreReportTask {
            $root = AssistantRequest::query()->lockForUpdate()->findOrFail($request->id);
            if ($root->status !== 'running' || $root->cancel_requested_at !== null) {
                throw new AuthorizationException;
            }

            return AssistantCoreReportTask::query()->firstOrCreate([
                'assistant_request_id' => $root->id, 'input_sha256' => $hash,
            ], [
                'id' => (string) Str::ulid(), 'organization_id' => $organizationId, 'user_id' => $actor->id,
                'surface' => 'admin', 'parameters' => $parameters, 'status' => 'queued', 'retry_at' => now(),
                'execution_expires_at' => now()->addMinutes(30), 'expires_at' => now()->addDay(),
            ]);
        }, 3);
        $this->dispatch((string) $task->id);

        return ['status' => 'success', 'report_task_id' => (string) $task->id,
            'message' => trans_message('ai_published_reports.queued')];
    }

    public function read(string $id, User $actor, int $organizationId): array
    {
        $this->access->actor((int) $actor->id, $organizationId, true);
        $task = $this->owned($id, $actor, $organizationId);

        return $this->state($task);
    }

    public function artifact(string $id, User $actor, int $organizationId): array
    {
        if ($this->policy->trustedSurface() !== KnowledgeSurface::ADMIN) {
            throw new AuthorizationException;
        }
        $this->access->actor((int) $actor->id, $organizationId, true);
        $task = $this->owned($id, $actor, $organizationId);
        $request = $this->currentRequest($actor, $organizationId);
        if ((int) $task->assistant_request_id !== (int) $request->id) {
            throw new AuthorizationException;
        }
        $this->assertProject($request, $task->parameters);
        $state = $this->state($task);
        if (! in_array($state['status'], [...AssistantCoreReportTask::PENDING, 'ready'], true)) {
            throw new InvalidArgumentException('assistant_report_task_not_available');
        }

        return ['artifact_type' => 'core_report_task', 'task_id' => (string) $task->id,
            'title' => $state['title'], 'type' => $state['format'], 'format' => $state['format'],
            'report_type' => $task->parameters['report_code'], 'status' => $state['status'],
            'url' => '/ai-assistant/report-tasks/'.$task->id];
    }

    public function state(AssistantCoreReportTask $task): array
    {
        $this->assertInput($task);
        $this->access->actor((int) $task->user_id, (int) $task->organization_id);
        $this->authorization->createRun((int) $task->user_id, (int) $task->organization_id, $task->parameters);
        $status = $task->status;
        $root = $task->assistantRequest;
        if ($root === null || ! $this->sameOwner($task, $root)) {
            throw new AuthorizationException;
        }
        if ($root->cancel_requested_at !== null || in_array($root->status, ['failed', 'cancelled'], true)) {
            $status = 'cancelled';
        } elseif ($task->expires_at->isPast() || (in_array($status, AssistantCoreReportTask::PENDING, true) && $task->execution_expires_at->isPast())) {
            $status = 'expired';
        } elseif ($task->run_id !== null) {
            $run = $this->runs->handle($this->authorization->run((int) $task->user_id, (int) $task->organization_id, $task->run_id), $task->run_id);
            $this->assertRun($task, $run);
            if (in_array($run->status->value, ['failed', 'cancelled', 'expired'], true)) {
                $status = $run->status->value;
            } elseif ($task->export_id !== null) {
                $export = $this->exports->handle($this->authorization->export((int) $task->user_id, (int) $task->organization_id, $task->export_id), $task->export_id);
                $this->assertExport($task, $export);
                if (in_array($export->status->value, ['ready', 'failed', 'cancelled', 'expired'], true)) {
                    $status = $export->status->value;
                }
                if ($status === 'ready') {
                    $this->authorization->export((int) $task->user_id, (int) $task->organization_id, $export->id, ReportOperation::DOWNLOAD);
                }
            }
        }

        return ['id' => (string) $task->id, 'status' => $status, 'title' => $task->parameters['title'],
            'format' => $task->parameters['format'], 'export_id' => $status === 'ready' ? $task->export_id : null,
            'expires_at' => $task->expires_at->toIso8601String(), 'poll_after_ms' => 2000];
    }

    public function assertInput(AssistantCoreReportTask $task): void
    {
        if (! hash_equals((string) $task->input_sha256, hash('sha256', CanonicalJson::encode($task->parameters))) || $task->surface !== 'admin') {
            throw new AuthorizationException;
        }
    }

    public function assertRun(AssistantCoreReportTask $task, ReportRun $run): void
    {
        if ($run->id !== $task->run_id || $run->reportCode !== $task->parameters['report_code']
            || ! hash_equals($task->parameters['definition_sha256'], $run->definitionHash->value)) {
            throw new AuthorizationException;
        }
    }

    public function assertExport(AssistantCoreReportTask $task, ReportExport $export): void
    {
        $parameters = $task->parameters;
        if ($export->id !== $task->export_id || $export->runId !== $task->run_id
            || $export->format !== $parameters['format'] || $export->columns !== $parameters['columns']
            || $export->sort->field !== $parameters['sort']['field'] || $export->sort->direction->value !== $parameters['sort']['direction']
            || $export->locale !== $parameters['locale'] || $export->timezone->getName() !== $parameters['timezone']) {
            throw new AuthorizationException;
        }
    }

    public function sameOwner(AssistantCoreReportTask $task, AssistantRequest $request): bool
    {
        return (int) $request->organization_id === (int) $task->organization_id
            && (int) $request->user_id === (int) $task->user_id && $request->surface === 'admin';
    }

    public function dispatch(string $id, int $delay = 0): void
    {
        try {
            GenerateAssistantPublishedReport::dispatch($id)->delay($delay)->afterCommit();
        } catch (Throwable $exception) {
            Log::warning('assistant_published_report_dispatch_deferred', ['task_id' => $id, 'exception_type' => $exception::class]);
        }
    }

    private function owned(string $id, User $actor, int $organizationId): AssistantCoreReportTask
    {
        if (! Str::isUlid($id)) {
            throw new AuthorizationException;
        }

        return AssistantCoreReportTask::query()->where('organization_id', $organizationId)->where('user_id', $actor->id)
            ->where('surface', 'admin')->findOrFail($id);
    }

    private function currentRequest(User $actor, int $organizationId): AssistantRequest
    {
        $id = $this->current->idFor($actor, $organizationId);
        if ($id === null) {
            throw new AuthorizationException;
        }

        return AssistantRequest::query()->where('organization_id', $organizationId)->where('user_id', $actor->id)
            ->where('surface', 'admin')->where('request_id', $id)->firstOrFail();
    }

    private function assertProject(AssistantRequest $request, array $parameters): void
    {
        $scope = AssistantRequestProjectScope::fromPlan(['request' => $request->payload]);
        if (($scope->requested && $scope->projectId === null)
            || ($scope->projectId !== null && $parameters['project_id'] !== $scope->projectId)
            || (AssistantRequestProjectScope::organizationWide((string) ($request->payload['message'] ?? '')) && $parameters['project_id'] !== null)) {
            throw new AuthorizationException;
        }
    }
}
