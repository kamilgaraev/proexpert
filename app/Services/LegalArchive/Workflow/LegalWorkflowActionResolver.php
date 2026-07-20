<?php

declare(strict_types=1);

namespace App\Services\LegalArchive\Workflow;

use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument;
use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocumentVersion;
use App\BusinessModules\Features\LegalArchive\Models\LegalDocumentComment;
use App\BusinessModules\Features\LegalArchive\Models\LegalWorkflowInstance;
use App\BusinessModules\Features\LegalArchive\Models\LegalWorkflowStep;
use App\Models\User;
use App\Services\LegalArchive\Comments\LegalDocumentBlockingCommentGuard;
use App\Services\LegalArchive\Workflow\DTO\WorkflowActionDetail;
use App\Services\LegalArchive\Workflow\DTO\WorkflowCurrentStep;
use App\Services\LegalArchive\Workflow\DTO\WorkflowSummary;
use Illuminate\Container\Container;
use Illuminate\Support\Collection;

final readonly class LegalWorkflowActionResolver
{
    private LegalDocumentBlockingCommentGuard $blockingComments;

    public function __construct(
        private LegalWorkflowAuthorization $authorization,
        private LegalWorkflowActorResolver $actors,
        ?LegalDocumentBlockingCommentGuard $blockingComments = null,
    ) {
        $this->blockingComments = $blockingComments ?? new LegalDocumentBlockingCommentGuard;
    }

    public function for(User $actor, LegalArchiveDocument $document): WorkflowSummary
    {
        $this->authorization->assertCan($actor, $document, LegalWorkflowPermissions::VIEW);
        $permissions = [];
        foreach ($this->permissions() as $permission) {
            $permissions[$permission] = $this->authorization->can($actor, $document, $permission);
        }

        return $this->summary($actor, $document, $permissions, []);
    }

    /**
     * @param  array<string, bool>  $permissions
     * @param  array<int, bool>  $actorAssignments
     */
    private function summary(
        User $actor,
        LegalArchiveDocument $document,
        array $permissions,
        array $actorAssignments,
        ?bool $hasBlockingComments = null,
    ): WorkflowSummary {
        if (! ($permissions[LegalWorkflowPermissions::VIEW] ?? false)) {
            return $this->deniedSummary($document);
        }
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
            ? $this->decisionActions($actor, $document, $instance, $version, $permissions, $actorAssignments, $hasBlockingComments)
            : [$this->submitAction($actor, $document, $version, $instance, $permissions, $hasBlockingComments)];
        $currentSteps = $instance instanceof LegalWorkflowInstance
            ? $instance->steps
                ->where('status', 'active')
                ->map(fn (LegalWorkflowStep $step): WorkflowCurrentStep => new WorkflowCurrentStep(
                    id: (int) $step->id,
                    key: (string) $step->step_key,
                    label: (string) $step->label,
                    status: (string) $step->status,
                    sequence: (int) $step->sequence,
                    parallelGroup: (string) $step->parallel_group,
                    required: (bool) $step->required,
                    assigneeType: (string) $step->actor_type,
                    assigneeReference: (string) $step->actor_reference,
                    dueAt: $step->due_at?->toAtomString(),
                    overdue: $step->due_at?->isPast() === true,
                    lockVersion: (int) $step->lock_version,
                    documentVersionId: (int) $instance->document_version_id,
                    documentContentHash: (string) $instance->document_content_hash,
                    assignedToCurrentActor: $actorAssignments[(int) $step->id]
                        ?? $this->actors->canAct($actor, $step, $document),
                    activatedAt: $step->activated_at?->toAtomString(),
                ))
                ->values()
                ->all()
            : [];

        return new WorkflowSummary(
            status: $instance?->status ?? 'not_started',
            statusLabel: $this->label('statuses.'.($instance?->status ?? 'not_started')),
            instanceId: $instance?->id === null ? null : (int) $instance->id,
            documentVersionId: $instance?->document_version_id === null ? null : (int) $instance->document_version_id,
            documentContentHash: $instance?->document_content_hash === null ? null : (string) $instance->document_content_hash,
            dueAt: $instance?->due_at?->toAtomString(),
            problemFlags: $problemFlags,
            availableActionDetails: $details,
            currentSteps: $currentSteps,
            expectedInstanceLockVersion: $instance?->lock_version === null ? null : (int) $instance->lock_version,
        );
    }

    /**
     * @param  Collection<int, LegalArchiveDocument>  $documents
     * @return array<int, WorkflowSummary>
     */
    public function forMany(User $actor, Collection $documents): array
    {
        if ($documents->isEmpty()) {
            return [];
        }
        $permissionMaps = $this->authorization->forMany($actor, $documents, $this->permissions());
        $actorAssignments = $this->actors->forMany($actor, $documents);
        $blockingComments = $this->blockingCommentsForMany($documents, $permissionMaps);
        $summaries = [];
        foreach ($documents as $document) {
            $documentId = (int) $document->id;
            $summaries[$documentId] = $this->summary(
                $actor,
                $document,
                $permissionMaps[$documentId] ?? [],
                $actorAssignments,
                $blockingComments[$documentId] ?? false,
            );
        }

        return $summaries;
    }

    /** @return list<WorkflowActionDetail> */
    private function decisionActions(
        User $actor,
        LegalArchiveDocument $document,
        LegalWorkflowInstance $instance,
        ?LegalArchiveDocumentVersion $version,
        array $permissions,
        array $actorAssignments,
        ?bool $hasBlockingComments,
    ): array {
        $activeSteps = $instance->steps->where('status', 'active');
        $versionChanged = ! $version instanceof LegalArchiveDocumentVersion
            || (int) $document->current_primary_version_id !== (int) $instance->document_version_id
            || ! (bool) $version->is_current
            || $version->processing_status !== 'ready'
            || ! hash_equals((string) $instance->document_content_hash, (string) $version->content_hash);
        $hasBlockingComments ??= $version instanceof LegalArchiveDocumentVersion
            && $this->blockingComments->hasOpen($document, (int) $version->id);
        $details = [];
        foreach ($activeSteps as $step) {
            $assigned = $actorAssignments[(int) $step->id] ?? $this->actors->canAct($actor, $step, $document);
            $overdue = $step->due_at?->isPast() === true;
            foreach (['approve', 'reject', 'return'] as $action) {
                $permission = LegalWorkflowPermissions::forAction($action);
                $can = $permissions[$permission] ?? false;
                $blockers = array_values(array_filter([
                    $assigned ? null : $this->label('blockers.actor_not_assigned'),
                    $overdue ? $this->label('blockers.step_overdue') : null,
                    $versionChanged ? $this->label('blockers.version_changed') : null,
                    $action === 'approve' && $hasBlockingComments
                        ? $this->label('blockers.open_blocking_comments')
                        : null,
                    $can ? null : $this->label('blockers.permission_denied'),
                ]));
                $details[] = new WorkflowActionDetail(
                    action: $action,
                    label: $this->label("actions.{$action}"),
                    permission: $permission,
                    enabled: $blockers === [],
                    blockers: $blockers,
                    targetStepId: (int) $step->id,
                    expectedInstanceLockVersion: (int) $instance->lock_version,
                    expectedStepLockVersion: (int) $step->lock_version,
                    key: "{$action}:step:{$step->id}",
                    scope: 'step',
                    instanceId: (int) $instance->id,
                    requiresComment: in_array($action, ['reject', 'return'], true),
                );
            }
            $permission = LegalWorkflowPermissions::REASSIGN;
            $can = $permissions[$permission] ?? false;
            $blockers = array_values(array_filter([
                $overdue ? $this->label('blockers.step_overdue') : null,
                $versionChanged ? $this->label('blockers.version_changed') : null,
                $can ? null : $this->label('blockers.permission_denied'),
            ]));
            $details[] = new WorkflowActionDetail(
                action: 'reassign',
                label: $this->label('actions.reassign'),
                permission: $permission,
                enabled: $blockers === [],
                blockers: $blockers,
                targetStepId: (int) $step->id,
                expectedInstanceLockVersion: (int) $instance->lock_version,
                expectedStepLockVersion: (int) $step->lock_version,
                key: "reassign:step:{$step->id}",
                scope: 'step',
                instanceId: (int) $instance->id,
                requiresReason: true,
            );
        }
        $cancelPermission = LegalWorkflowPermissions::CANCEL;
        $canCancel = $permissions[$cancelPermission] ?? false;
        $details[] = new WorkflowActionDetail(
            action: 'cancel',
            label: $this->label('actions.cancel'),
            permission: $cancelPermission,
            enabled: $canCancel,
            blockers: $canCancel ? [] : [$this->label('blockers.permission_denied')],
            expectedInstanceLockVersion: (int) $instance->lock_version,
            key: "cancel:instance:{$instance->id}",
            scope: 'instance',
            instanceId: (int) $instance->id,
            requiresReason: true,
        );

        return $details;
    }

    private function submitAction(
        User $actor,
        LegalArchiveDocument $document,
        ?LegalArchiveDocumentVersion $version,
        ?LegalWorkflowInstance $latest,
        array $permissions,
        ?bool $hasBlockingComments,
    ): WorkflowActionDetail {
        $permission = LegalWorkflowPermissions::SUBMIT;
        $canSubmit = $permissions[$permission] ?? false;
        $ready = $version instanceof LegalArchiveDocumentVersion
            && (bool) $version->is_current
            && $version->processing_status === 'ready'
            && preg_match('/^[a-f0-9]{64}$/D', (string) $version->content_hash) === 1;
        $hasBlockingComments ??= $version instanceof LegalArchiveDocumentVersion
            && $this->blockingComments->hasOpen($document, (int) $version->id);
        $blockers = array_values(array_filter([
            $canSubmit ? null : $this->label('blockers.permission_denied'),
            $ready ? null : $this->label('blockers.version_not_ready'),
            $hasBlockingComments ? $this->label('blockers.open_blocking_comments') : null,
            $latest?->status === 'in_progress' ? $this->label('blockers.active_workflow_exists') : null,
            $latest instanceof LegalWorkflowInstance
                && in_array($latest->status, ['returned', 'rejected'], true)
                && $version instanceof LegalArchiveDocumentVersion
                && (int) $latest->document_version_id === (int) $version->id
                && hash_equals((string) $latest->document_content_hash, (string) $version->content_hash)
                    ? $this->label('blockers.new_version_required')
                    : null,
        ]));

        return new WorkflowActionDetail(
            action: 'submit',
            label: $this->label('actions.submit'),
            permission: $permission,
            enabled: $blockers === [],
            blockers: $blockers,
            key: "submit:document:{$document->id}",
            scope: 'document',
        );
    }

    private function deniedSummary(LegalArchiveDocument $document): WorkflowSummary
    {
        return new WorkflowSummary(
            status: 'not_available',
            statusLabel: $this->label('statuses.not_available'),
            instanceId: null,
            documentVersionId: null,
            documentContentHash: null,
            dueAt: null,
            problemFlags: ['workflow_permission_denied'],
            availableActionDetails: [new WorkflowActionDetail(
                action: 'submit',
                label: $this->label('actions.submit'),
                permission: LegalWorkflowPermissions::VIEW,
                enabled: false,
                blockers: [$this->label('blockers.permission_denied')],
                key: "workflow:view:document:{$document->id}",
                scope: 'document',
            )],
        );
    }

    /** @return list<string> */
    private function permissions(): array
    {
        return [
            LegalWorkflowPermissions::VIEW,
            LegalWorkflowPermissions::SUBMIT,
            LegalWorkflowPermissions::APPROVE,
            LegalWorkflowPermissions::REJECT,
            LegalWorkflowPermissions::RETURN,
            LegalWorkflowPermissions::REASSIGN,
            LegalWorkflowPermissions::CANCEL,
        ];
    }

    /**
     * @param  Collection<int, LegalArchiveDocument>  $documents
     * @param  array<int, array<string, bool>>  $permissionMaps
     * @return array<int, bool>
     */
    private function blockingCommentsForMany(Collection $documents, array $permissionMaps): array
    {
        $result = [];
        $unresolved = [];
        foreach ($documents as $document) {
            $documentId = (int) $document->id;
            if (! ($permissionMaps[$documentId][LegalWorkflowPermissions::VIEW] ?? false)) {
                continue;
            }
            if (array_key_exists('open_blocking_comments_count', $document->getAttributes())) {
                $result[$documentId] = (int) $document->open_blocking_comments_count > 0;
            } elseif ($document->current_primary_version_id !== null) {
                $unresolved[$documentId] = (int) $document->current_primary_version_id;
            }
        }
        if ($unresolved === []) {
            return $result;
        }
        $comments = LegalDocumentComment::query()
            ->whereIn('document_id', array_keys($unresolved))
            ->whereIn('document_version_id', array_values($unresolved))
            ->where('is_blocking', true)
            ->where('status', 'open')
            ->get(['document_id', 'document_version_id']);
        foreach ($unresolved as $documentId => $versionId) {
            $result[$documentId] = $comments->contains(
                static fn (LegalDocumentComment $comment): bool => (int) $comment->document_id === $documentId
                    && (int) $comment->document_version_id === $versionId,
            );
        }

        return $result;
    }

    private function latestInstance(LegalArchiveDocument $document): ?LegalWorkflowInstance
    {
        if ($document->relationLoaded('latestWorkflowInstance')) {
            return $document->latestWorkflowInstance;
        }
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
        if ($document->relationLoaded('currentVersion')) {
            return $document->currentVersion;
        }
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
