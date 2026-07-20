<?php

declare(strict_types=1);

namespace App\Services\LegalArchive\Workflow;

use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument;
use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocumentVersion;
use App\BusinessModules\Features\LegalArchive\Models\LegalWorkflowInstance;
use App\BusinessModules\Features\LegalArchive\Models\LegalWorkflowStep;
use App\Models\User;
use App\Services\LegalArchive\Workflow\DTO\WorkflowActionDetail;
use App\Services\LegalArchive\Workflow\DTO\WorkflowSummary;
use Illuminate\Container\Container;

final readonly class LegalWorkflowActionResolver
{
    public function __construct(
        private LegalWorkflowAuthorization $authorization,
        private LegalWorkflowActorResolver $actors,
    ) {}

    public function for(User $actor, LegalArchiveDocument $document): WorkflowSummary
    {
        $instance = $this->latestInstance($document);
        $version = $this->currentVersion($document);
        $problemFlags = [];
        if (! $version instanceof LegalArchiveDocumentVersion || $version->processing_status !== 'ready') {
            $problemFlags[] = 'no_ready_primary_version';
        }
        if ($instance?->due_at?->isPast() === true && $instance->status === 'in_progress') {
            $problemFlags[] = 'workflow_overdue';
        }
        if ($instance instanceof LegalWorkflowInstance
            && $instance->status === 'in_progress'
            && ($version === null
                || (int) $instance->document_version_id !== (int) $version->id
                || ! hash_equals((string) $instance->document_content_hash, (string) $version->content_hash))
        ) {
            $problemFlags[] = 'workflow_version_changed';
        }
        if (in_array($instance?->status, ['returned', 'rejected', 'expired'], true)) {
            $problemFlags[] = "workflow_{$instance->status}";
        }

        $details = $instance instanceof LegalWorkflowInstance && $instance->status === 'in_progress'
            ? $this->decisionActions($actor, $document, $instance, $version)
            : [$this->submitAction($actor, $document, $version, $instance)];

        return new WorkflowSummary(
            status: $instance?->status ?? 'not_started',
            statusLabel: $this->label('statuses.'.($instance?->status ?? 'not_started')),
            instanceId: $instance?->id === null ? null : (int) $instance->id,
            documentVersionId: $instance?->document_version_id === null ? null : (int) $instance->document_version_id,
            documentContentHash: $instance?->document_content_hash === null ? null : (string) $instance->document_content_hash,
            dueAt: $instance?->due_at?->toAtomString(),
            problemFlags: $problemFlags,
            availableActionDetails: $details,
        );
    }

    /** @return list<WorkflowActionDetail> */
    private function decisionActions(
        User $actor,
        LegalArchiveDocument $document,
        LegalWorkflowInstance $instance,
        ?LegalArchiveDocumentVersion $version,
    ): array {
        $activeSteps = $instance->steps->filter(
            fn (LegalWorkflowStep $step): bool => $step->status === 'active' && $this->actors->canAct($actor, $step, $document),
        );
        $hasActiveStep = $instance->steps->contains(
            static fn (LegalWorkflowStep $step): bool => $step->status === 'active',
        );
        $hasActorStep = $activeSteps->isNotEmpty();
        $overdue = $activeSteps->contains(static fn (LegalWorkflowStep $step): bool => $step->due_at?->isPast() === true);
        $versionChanged = ! $version instanceof LegalArchiveDocumentVersion
            || (int) $document->current_primary_version_id !== (int) $instance->document_version_id
            || ! (bool) $version->is_current
            || $version->processing_status !== 'ready'
            || ! hash_equals((string) $instance->document_content_hash, (string) $version->content_hash);
        $decidePermission = 'legal_archive.workflow.decide';
        $canDecide = $this->authorization->can($actor, $document, $decidePermission);
        $decisionBlockers = array_values(array_filter([
            $hasActorStep ? null : $this->label('blockers.actor_not_assigned'),
            $overdue ? $this->label('blockers.step_overdue') : null,
            $versionChanged ? $this->label('blockers.version_changed') : null,
            $canDecide ? null : $this->label('blockers.permission_denied'),
        ]));
        $details = [];
        foreach (['approve', 'reject', 'return'] as $action) {
            $details[] = new WorkflowActionDetail(
                $action,
                $this->label("actions.{$action}"),
                $decidePermission,
                $decisionBlockers === [],
                $decisionBlockers,
            );
        }

        $reassignPermission = 'legal_archive.workflow.reassign';
        $canReassign = $this->authorization->can($actor, $document, $reassignPermission);
        $reassignBlockers = array_values(array_filter([
            $hasActiveStep ? null : $this->label('blockers.no_active_step'),
            $overdue ? $this->label('blockers.step_overdue') : null,
            $versionChanged ? $this->label('blockers.version_changed') : null,
            $canReassign ? null : $this->label('blockers.permission_denied'),
        ]));
        $details[] = new WorkflowActionDetail(
            'reassign',
            $this->label('actions.reassign'),
            $reassignPermission,
            $reassignBlockers === [],
            $reassignBlockers,
        );
        $cancelPermission = 'legal_archive.workflow.cancel';
        $canCancel = $this->authorization->can($actor, $document, $cancelPermission);
        $details[] = new WorkflowActionDetail(
            'cancel',
            $this->label('actions.cancel'),
            $cancelPermission,
            $canCancel,
            $canCancel ? [] : [$this->label('blockers.permission_denied')],
        );

        return $details;
    }

    private function submitAction(
        User $actor,
        LegalArchiveDocument $document,
        ?LegalArchiveDocumentVersion $version,
        ?LegalWorkflowInstance $latest,
    ): WorkflowActionDetail {
        $permission = 'legal_archive.workflow.submit';
        $canSubmit = $this->authorization->can($actor, $document, $permission);
        $ready = $version instanceof LegalArchiveDocumentVersion
            && (bool) $version->is_current
            && $version->processing_status === 'ready'
            && preg_match('/^[a-f0-9]{64}$/D', (string) $version->content_hash) === 1;
        $blockers = array_values(array_filter([
            $canSubmit ? null : $this->label('blockers.permission_denied'),
            $ready ? null : $this->label('blockers.version_not_ready'),
            $latest?->status === 'in_progress' ? $this->label('blockers.active_workflow_exists') : null,
        ]));

        return new WorkflowActionDetail('submit', $this->label('actions.submit'), $permission, $blockers === [], $blockers);
    }

    private function latestInstance(LegalArchiveDocument $document): ?LegalWorkflowInstance
    {
        $model = (new LegalWorkflowInstance)->setConnection($document->getConnectionName());

        return $model->newQuery()
            ->where('organization_id', (int) $document->organization_id)
            ->where('document_id', (int) $document->id)
            ->with('steps')
            ->latest('id')
            ->first();
    }

    private function currentVersion(LegalArchiveDocument $document): ?LegalArchiveDocumentVersion
    {
        if ($document->current_primary_version_id === null) {
            return null;
        }
        $model = (new LegalArchiveDocumentVersion)->setConnection($document->getConnectionName());

        return $model->newQuery()
            ->whereKey($document->current_primary_version_id)
            ->where('organization_id', (int) $document->organization_id)
            ->where('document_id', (int) $document->id)
            ->first();
    }

    private function label(string $key): string
    {
        if (Container::getInstance()->bound('translator')) {
            return trans_message("legal_archive.workflow.{$key}");
        }

        return "legal_archive.workflow.{$key}";
    }
}
