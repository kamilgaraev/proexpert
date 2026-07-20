<?php

declare(strict_types=1);

namespace App\Services\LegalArchive\Workflow;

use App\BusinessModules\Core\ImmutableAudit\Services\ImmutableAuditIntegrityService;
use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument;
use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocumentVersion;
use App\BusinessModules\Features\LegalArchive\Models\LegalWorkflowDecision;
use App\BusinessModules\Features\LegalArchive\Models\LegalWorkflowInstance;
use App\BusinessModules\Features\LegalArchive\Models\LegalWorkflowStep;
use App\Models\User;
use App\Services\LegalArchive\Audit\LegalDocumentAudit;
use App\Services\LegalArchive\Workflow\DTO\WorkflowDecisionInput;
use App\Services\LegalArchive\Workflow\DTO\WorkflowOverride;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use DomainException;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;

final class LegalDocumentWorkflowService
{
    private const ACTIVE_STATUSES = ['in_progress'];

    private const STEP_ACTIONS = ['approve', 'reject', 'return', 'reassign'];

    public function __construct(
        private readonly LegalWorkflowTemplateService $templates,
        private readonly LegalWorkflowAuthorization $authorization,
        private readonly LegalWorkflowActorResolver $actors,
        private readonly LegalWorkflowAssignmentValidator $assignments,
        private readonly LegalDocumentAudit $audit,
        private readonly ImmutableAuditIntegrityService $integrity,
        private readonly ConnectionInterface $connection,
    ) {}

    public function submit(
        LegalArchiveDocument $document,
        int $versionId,
        User $actor,
        WorkflowOverride $override,
    ): LegalWorkflowInstance {
        $this->authorization->assertCan($actor, $document, LegalWorkflowPermissions::SUBMIT);
        $idempotencyKey = $this->validKey($override->idempotencyKey);
        $clientRequestHash = hash('sha256', $this->integrity->canonicalJson([
            'document_id' => (int) $document->id,
            'document_version_id' => $versionId,
            'command' => $override->canonicalPayload(),
        ]));

        try {
            return $this->connection->transaction(function () use (
                $document,
                $versionId,
                $actor,
                $override,
                $idempotencyKey,
                $clientRequestHash,
            ): LegalWorkflowInstance {
                $existing = $this->findSubmitReplay(
                    (int) $document->organization_id,
                    (int) $document->id,
                    $idempotencyKey,
                    $clientRequestHash,
                );
                if ($existing instanceof LegalWorkflowInstance) {
                    return $existing;
                }
                $lockedDocument = $this->documents()->whereKey($document->getKey())->lockForUpdate()->first();
                if (! $lockedDocument instanceof LegalArchiveDocument) {
                    throw new DomainException('legal_workflow_document_not_found');
                }
                $this->authorization->assertCan($actor, $lockedDocument, LegalWorkflowPermissions::SUBMIT);
                $existing = $this->findSubmitReplay(
                    (int) $lockedDocument->organization_id,
                    (int) $lockedDocument->id,
                    $idempotencyKey,
                    $clientRequestHash,
                );
                if ($existing instanceof LegalWorkflowInstance) {
                    return $existing;
                }
                if (
                    $override->expectedDocumentLockVersion !== null
                    && (int) $lockedDocument->lock_version !== $override->expectedDocumentLockVersion
                ) {
                    throw new DomainException('legal_workflow_stale_action');
                }

                $version = $this->versions()->whereKey($versionId)->lockForUpdate()->first();
                $this->assertSubmittableVersion($lockedDocument, $version);
                $template = $this->templates->resolveForDocument($lockedDocument, $override->templateId);
                if ((int) $template->organization_id !== (int) $lockedDocument->organization_id) {
                    throw new DomainException('legal_workflow_template_not_found');
                }
                $snapshot = $this->templates->snapshot($template, $override);
                foreach ($snapshot->payload['steps'] as $definition) {
                    if (! $this->assignments->exists(
                        (string) $definition['actor_type'],
                        (string) $definition['actor_reference'],
                        $lockedDocument,
                    )) {
                        throw new DomainException('legal_workflow_actor_target_not_available');
                    }
                }
                $requestHash = hash('sha256', $this->integrity->canonicalJson([
                    'document_id' => (int) $lockedDocument->id,
                    'document_version_id' => $versionId,
                    'document_content_hash' => (string) $version->content_hash,
                    'template_id' => (int) $template->id,
                    'snapshot_hash' => $snapshot->hash,
                    'override' => $override->canonicalPayload(),
                ]));

                $latest = $this->instances()
                    ->where('organization_id', (int) $lockedDocument->organization_id)
                    ->where('document_id', (int) $lockedDocument->id)
                    ->latest('id')
                    ->first();
                if (
                    $latest instanceof LegalWorkflowInstance
                    && in_array($latest->status, ['returned', 'rejected'], true)
                    && (int) $latest->document_version_id === $versionId
                    && hash_equals((string) $latest->document_content_hash, (string) $version->content_hash)
                ) {
                    throw new DomainException('legal_workflow_new_version_required');
                }
                if ($this->instances()
                    ->where('organization_id', (int) $lockedDocument->organization_id)
                    ->where('document_id', (int) $lockedDocument->id)
                    ->whereIn('status', self::ACTIVE_STATUSES)
                    ->exists()
                ) {
                    throw new DomainException('legal_workflow_active_instance_exists');
                }

                $submittedAt = CarbonImmutable::now('UTC');
                $firstSequence = min(array_column($snapshot->payload['steps'], 'sequence'));
                $instance = $this->newInstance()->newQuery()->create([
                    'organization_id' => (int) $lockedDocument->organization_id,
                    'document_id' => (int) $lockedDocument->id,
                    'document_version_id' => $versionId,
                    'document_content_hash' => (string) $version->content_hash,
                    'template_id' => (int) $template->id,
                    'template_version' => (int) $template->version,
                    'template_definition_hash' => (string) $template->definition_hash,
                    'template_snapshot' => $snapshot->payload,
                    'snapshot_hash' => $snapshot->hash,
                    'client_request_hash' => $clientRequestHash,
                    'request_hash' => $requestHash,
                    'idempotency_key' => $idempotencyKey,
                    'status' => 'in_progress',
                    'lock_version' => 1,
                    'submitted_by_user_id' => (int) $actor->id,
                    'submitted_at' => $submittedAt,
                    'due_at' => $this->activeGroupDueAt($snapshot->payload['steps'], $submittedAt, $firstSequence),
                ]);
                foreach ($snapshot->payload['steps'] as $definition) {
                    $active = (int) $definition['sequence'] === $firstSequence;
                    $this->newStep()->newQuery()->create([
                        'instance_id' => (int) $instance->id,
                        'organization_id' => (int) $lockedDocument->organization_id,
                        'step_key' => $definition['key'],
                        'label' => $definition['label'],
                        'sequence' => $definition['sequence'],
                        'parallel_group' => $definition['parallel_group'],
                        'required' => $definition['required'],
                        'policy_key' => $definition['policy_key'],
                        'actor_type' => $definition['actor_type'],
                        'actor_reference' => $definition['actor_reference'],
                        'status' => $active ? 'active' : 'pending',
                        'lock_version' => 0,
                        'activated_at' => $active ? $submittedAt : null,
                        'due_in_hours' => $definition['due_in_hours'],
                        'deadline_at' => $definition['due_at'],
                        'due_at' => $active ? $this->stepDueAt($definition, $submittedAt) : null,
                    ]);
                }

                $before = ['approval_status' => $lockedDocument->approval_status, 'lock_version' => $lockedDocument->lock_version];
                $lockedDocument->forceFill([
                    'approval_status' => 'in_review',
                    'lock_version' => ((int) $lockedDocument->lock_version) + 1,
                ])->save();
                $this->audit->record('workflow_submitted', $lockedDocument, $actor, [
                    'source_event_id' => "workflow-submit:{$instance->id}",
                    'idempotency_key' => "workflow-submit:{$idempotencyKey}",
                    'before' => $before,
                    'after' => ['approval_status' => 'in_review', 'lock_version' => $lockedDocument->lock_version],
                    'workflow_instance_id' => (int) $instance->id,
                    'document_version_id' => $versionId,
                    'document_content_hash' => (string) $version->content_hash,
                    'snapshot_hash' => $snapshot->hash,
                ]);

                return $instance->load('steps', 'decisions');
            }, 3);
        } catch (QueryException $exception) {
            $existing = $this->instances()
                ->where('organization_id', (int) $document->organization_id)
                ->where('document_id', (int) $document->id)
                ->where('idempotency_key', $idempotencyKey)
                ->first();
            if ($existing instanceof LegalWorkflowInstance) {
                $this->assertSameRequest($existing->client_request_hash, $clientRequestHash);

                return $existing->load('steps', 'decisions');
            }
            if ($this->instances()
                ->where('organization_id', (int) $document->organization_id)
                ->where('document_id', (int) $document->id)
                ->whereIn('status', self::ACTIVE_STATUSES)
                ->exists()
            ) {
                throw new DomainException('legal_workflow_active_instance_exists', previous: $exception);
            }
            throw $exception;
        }
    }

    private function findSubmitReplay(
        int $organizationId,
        int $documentId,
        string $idempotencyKey,
        string $clientRequestHash,
    ): ?LegalWorkflowInstance {
        $existing = $this->instances()
            ->where('organization_id', $organizationId)
            ->where('document_id', $documentId)
            ->where('idempotency_key', $idempotencyKey)
            ->first();
        if (! $existing instanceof LegalWorkflowInstance) {
            return null;
        }
        $this->assertSameRequest($existing->client_request_hash, $clientRequestHash);

        return $existing->load('steps', 'decisions');
    }

    public function decide(
        LegalWorkflowStep $step,
        User $actor,
        WorkflowDecisionInput $input,
    ): LegalWorkflowInstance {
        if (! in_array($input->action, self::STEP_ACTIONS, true)) {
            throw new DomainException('legal_workflow_action_invalid');
        }
        $idempotencyKey = $this->validKey($input->idempotencyKey);
        $requestHash = hash('sha256', $this->integrity->canonicalJson($input->canonicalPayload()));

        return $this->connection->transaction(function () use (
            $step,
            $actor,
            $input,
            $idempotencyKey,
            $requestHash,
        ): LegalWorkflowInstance {
            $instance = $this->instances()->whereKey($step->instance_id)->lockForUpdate()->first();
            if (! $instance instanceof LegalWorkflowInstance) {
                throw new DomainException('legal_workflow_instance_not_found');
            }
            $lockedStep = $this->steps()
                ->whereKey($step->getKey())
                ->where('instance_id', $instance->id)
                ->lockForUpdate()
                ->first();
            if (! $lockedStep instanceof LegalWorkflowStep) {
                throw new DomainException('legal_workflow_step_not_found');
            }
            $document = $this->documents()->whereKey($instance->document_id)->lockForUpdate()->first();
            if (! $document instanceof LegalArchiveDocument || (int) $document->organization_id !== (int) $instance->organization_id) {
                throw new DomainException('legal_workflow_document_not_found');
            }
            $boundVersion = $this->versions()->whereKey($instance->document_version_id)->first();
            if (
                ! $boundVersion instanceof LegalArchiveDocumentVersion
                || (int) $boundVersion->organization_id !== (int) $instance->organization_id
                || (int) $boundVersion->document_id !== (int) $document->id
                || (int) $document->current_primary_version_id !== (int) $boundVersion->id
                || ! (bool) $boundVersion->is_current
                || $boundVersion->processing_status !== 'ready'
                || ! hash_equals((string) $instance->document_content_hash, (string) $boundVersion->content_hash)
            ) {
                throw new DomainException('legal_workflow_version_changed');
            }
            $permission = LegalWorkflowPermissions::forAction($input->action);
            $this->authorization->assertCan($actor, $document, $permission);

            $existing = $this->decisions()
                ->where('organization_id', (int) $instance->organization_id)
                ->where('instance_id', (int) $instance->id)
                ->where('idempotency_key', $idempotencyKey)
                ->first();
            if ($existing instanceof LegalWorkflowDecision) {
                $this->assertSameRequest($existing->request_hash, $requestHash);
                if ((int) $existing->actor_user_id !== (int) $actor->id || (int) $existing->step_id !== (int) $lockedStep->id) {
                    throw new DomainException('legal_workflow_idempotency_conflict');
                }

                return $instance->load('steps', 'decisions');
            }
            if (
                (int) $instance->lock_version !== $input->expectedInstanceLockVersion
                || (int) $lockedStep->lock_version !== $input->expectedStepLockVersion
            ) {
                throw new DomainException('legal_workflow_stale_action');
            }
            if ($instance->status !== 'in_progress' || $lockedStep->status !== 'active') {
                throw new DomainException('legal_workflow_step_not_active');
            }
            if ($lockedStep->due_at !== null && $lockedStep->due_at->isPast()) {
                throw new DomainException('legal_workflow_step_expired');
            }
            if ($input->action !== 'reassign' && ! $this->actors->canAct($actor, $lockedStep, $document)) {
                throw new DomainException('legal_workflow_actor_not_resolved');
            }
            $this->assertDecisionInput($input);

            $fromStatus = (string) $lockedStep->status;
            $instanceFromStatus = (string) $instance->status;
            $context = [];
            $decision = null;
            if ($input->action === 'reassign') {
                if (! $this->assignments->exists(
                    (string) $input->reassignActorType,
                    trim((string) $input->reassignActorReference),
                    $document,
                )) {
                    throw new DomainException('legal_workflow_actor_target_not_available');
                }
                $newDueAt = CarbonImmutable::parse((string) $input->dueAt)->utc();
                $newActorReference = trim((string) $input->reassignActorReference);
                $context = [
                    'from_actor_type' => (string) $lockedStep->actor_type,
                    'from_actor_reference' => (string) $lockedStep->actor_reference,
                    'from_due_at' => $lockedStep->due_at?->utc()->toAtomString(),
                    'to_actor_type' => $input->reassignActorType,
                    'to_actor_reference' => $newActorReference,
                    'to_due_at' => $newDueAt->toAtomString(),
                ];
                $decision = $this->createStepDecision(
                    $instance,
                    $lockedStep,
                    $document,
                    $actor,
                    $input,
                    $requestHash,
                    $idempotencyKey,
                    $fromStatus,
                    $fromStatus,
                    $context,
                    [
                        'from_actor_type' => (string) $lockedStep->actor_type,
                        'from_actor_reference' => (string) $lockedStep->actor_reference,
                        'from_due_at' => $lockedStep->due_at,
                        'to_actor_type' => (string) $input->reassignActorType,
                        'to_actor_reference' => $newActorReference,
                        'to_due_at' => $newDueAt,
                        'assignment_revision' => ((int) $lockedStep->assignment_revision) + 1,
                        'previous_reassign_decision_id' => $lockedStep->last_reassign_decision_id,
                    ],
                );
                $this->authorizeDatabaseReassignment($decision);
                $lockedStep->applyReassignment($decision);
            } else {
                $toStatus = match ($input->action) {
                    'approve' => 'approved',
                    'reject' => 'rejected',
                    'return' => 'returned',
                };
                $lockedStep->forceFill([
                    'status' => $toStatus,
                    'completed_at' => now(),
                    'lock_version' => ((int) $lockedStep->lock_version) + 1,
                ])->save();
            }

            $instance->forceFill(['lock_version' => ((int) $instance->lock_version) + 1]);
            $this->applyInstanceTransition($instance, $lockedStep, $document, $input->action);
            $instance->save();

            $decision ??= $this->createStepDecision(
                $instance,
                $lockedStep,
                $document,
                $actor,
                $input,
                $requestHash,
                $idempotencyKey,
                $fromStatus,
                (string) $lockedStep->status,
                $context,
            );
            $this->audit->record("workflow_{$input->action}", $document, $actor, [
                'source_event_id' => "workflow-decision:{$decision->id}",
                'idempotency_key' => "workflow-decision:{$instance->id}:{$idempotencyKey}",
                'before' => ['workflow_status' => $instanceFromStatus, 'step_status' => $fromStatus],
                'after' => ['workflow_status' => $instance->status, 'step_status' => $lockedStep->status],
                'workflow_instance_id' => (int) $instance->id,
                'workflow_step_id' => (int) $lockedStep->id,
                'document_version_id' => (int) $instance->document_version_id,
                'document_content_hash' => (string) $instance->document_content_hash,
                'reason' => $input->reason,
                'reassignment' => $context,
            ]);

            return $instance->refresh()->load('steps', 'decisions');
        }, 3);
    }

    /** @param array<string, mixed> $context @param array<string, mixed> $reassignment */
    private function createStepDecision(
        LegalWorkflowInstance $instance,
        LegalWorkflowStep $step,
        LegalArchiveDocument $document,
        User $actor,
        WorkflowDecisionInput $input,
        string $requestHash,
        string $idempotencyKey,
        string $fromStatus,
        string $toStatus,
        array $context,
        array $reassignment = [],
    ): LegalWorkflowDecision {
        return $this->newDecision()->newQuery()->create([
            'organization_id' => (int) $instance->organization_id,
            'instance_id' => (int) $instance->id,
            'step_id' => (int) $step->id,
            'document_id' => (int) $document->id,
            'document_version_id' => (int) $instance->document_version_id,
            'document_content_hash' => (string) $instance->document_content_hash,
            'actor_type' => 'user',
            'actor_user_id' => (int) $actor->id,
            'action' => $input->action,
            'comment' => $this->nullableTrim($input->comment),
            'reason' => $this->nullableTrim($input->reason),
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'context' => $context,
            ...$reassignment,
            'request_hash' => $requestHash,
            'idempotency_key' => $idempotencyKey,
            'decided_at' => now(),
        ]);
    }

    private function authorizeDatabaseReassignment(LegalWorkflowDecision $decision): void
    {
        if ($this->connection->getDriverName() !== 'pgsql') {
            return;
        }
        $this->connection->select("SELECT set_config('app.legal_workflow_reassign_decision_id', ?, true)", [
            (string) $decision->id,
        ]);
    }

    public function cancel(LegalWorkflowInstance $instance, User $actor, WorkflowDecisionInput $input): LegalWorkflowInstance
    {
        if ($input->action !== 'cancel' || $this->nullableTrim($input->reason) === null) {
            throw new DomainException('legal_workflow_reason_required');
        }
        $idempotencyKey = $this->validKey($input->idempotencyKey);
        $requestHash = hash('sha256', $this->integrity->canonicalJson($input->canonicalPayload()));

        return $this->connection->transaction(function () use ($instance, $actor, $input, $idempotencyKey, $requestHash): LegalWorkflowInstance {
            $locked = $this->instances()->whereKey($instance->id)->lockForUpdate()->first();
            if (! $locked instanceof LegalWorkflowInstance) {
                throw new DomainException('legal_workflow_instance_not_found');
            }
            $document = $this->documents()->whereKey($locked->document_id)->lockForUpdate()->firstOrFail();
            $this->authorization->assertCan($actor, $document, LegalWorkflowPermissions::CANCEL);
            $existing = $this->decisions()->where('instance_id', $locked->id)->where('idempotency_key', $idempotencyKey)->first();
            if ($existing instanceof LegalWorkflowDecision) {
                $this->assertSameRequest($existing->request_hash, $requestHash);

                return $locked->load('steps', 'decisions');
            }
            if ((int) $locked->lock_version !== $input->expectedInstanceLockVersion || $locked->status !== 'in_progress') {
                throw new DomainException('legal_workflow_stale_action');
            }
            $this->finishInstance($locked, $document, 'cancelled');
            $locked->forceFill(['cancelled_at' => now(), 'lock_version' => ((int) $locked->lock_version) + 1])->save();
            $decision = $this->newDecision()->newQuery()->create([
                'organization_id' => (int) $locked->organization_id,
                'instance_id' => (int) $locked->id,
                'step_id' => null,
                'document_id' => (int) $document->id,
                'document_version_id' => (int) $locked->document_version_id,
                'document_content_hash' => (string) $locked->document_content_hash,
                'actor_type' => 'user',
                'actor_user_id' => (int) $actor->id,
                'action' => 'cancel',
                'reason' => trim((string) $input->reason),
                'from_status' => 'in_progress',
                'to_status' => 'cancelled',
                'context' => [],
                'request_hash' => $requestHash,
                'idempotency_key' => $idempotencyKey,
                'decided_at' => now(),
            ]);
            $this->audit->record('workflow_cancel', $document, $actor, [
                'source_event_id' => "workflow-decision:{$decision->id}",
                'idempotency_key' => "workflow-decision:{$locked->id}:{$idempotencyKey}",
                'workflow_instance_id' => (int) $locked->id,
                'document_version_id' => (int) $locked->document_version_id,
                'document_content_hash' => (string) $locked->document_content_hash,
                'reason' => $input->reason,
            ]);

            return $locked->refresh()->load('steps', 'decisions');
        }, 3);
    }

    public function expireDue(int $organizationId, DateTimeInterface $at, int $limit = 100): int
    {
        $ids = $this->instances()
            ->where('organization_id', $organizationId)
            ->where('status', 'in_progress')
            ->whereNotNull('due_at')
            ->where('due_at', '<=', $at)
            ->orderBy('due_at')
            ->limit(max(1, min($limit, 1000)))
            ->pluck('id');
        $expired = 0;
        foreach ($ids as $id) {
            $changed = $this->connection->transaction(function () use ($id, $at): bool {
                $instance = $this->instances()->whereKey($id)->lockForUpdate()->first();
                if (! $instance instanceof LegalWorkflowInstance || $instance->status !== 'in_progress' || $instance->due_at?->isAfter($at)) {
                    return false;
                }
                $document = $this->documents()->whereKey($instance->document_id)->lockForUpdate()->firstOrFail();
                $this->finishInstance($instance, $document, 'expired');
                $instance->forceFill([
                    'expired_at' => $at,
                    'lock_version' => ((int) $instance->lock_version) + 1,
                ])->save();
                $decisionKey = "workflow-expire:{$instance->id}";
                $this->newDecision()->newQuery()->firstOrCreate([
                    'organization_id' => (int) $instance->organization_id,
                    'instance_id' => (int) $instance->id,
                    'idempotency_key' => $decisionKey,
                ], [
                    'step_id' => null,
                    'document_id' => (int) $document->id,
                    'document_version_id' => (int) $instance->document_version_id,
                    'document_content_hash' => (string) $instance->document_content_hash,
                    'actor_type' => 'system',
                    'actor_user_id' => null,
                    'action' => 'expire',
                    'comment' => null,
                    'reason' => null,
                    'from_status' => 'in_progress',
                    'to_status' => 'expired',
                    'context' => [],
                    'request_hash' => hash('sha256', $this->integrity->canonicalJson([
                        'action' => 'expire',
                        'instance_id' => (int) $instance->id,
                    ])),
                    'decided_at' => now(),
                ]);
                $this->audit->recordForActorId('workflow_expired', $document, null, [
                    'source_event_id' => "workflow-expire:{$instance->id}",
                    'idempotency_key' => "workflow-expire:{$instance->id}",
                    'workflow_instance_id' => (int) $instance->id,
                    'document_version_id' => (int) $instance->document_version_id,
                    'document_content_hash' => (string) $instance->document_content_hash,
                ]);

                return true;
            }, 3);
            $expired += $changed ? 1 : 0;
        }

        return $expired;
    }

    private function applyInstanceTransition(
        LegalWorkflowInstance $instance,
        LegalWorkflowStep $step,
        LegalArchiveDocument $document,
        string $action,
    ): void {
        if ($action === 'reassign') {
            return;
        }
        if ($action === 'reject') {
            $this->finishInstance($instance, $document, 'rejected');

            return;
        }
        if ($action === 'return') {
            $this->finishInstance($instance, $document, 'returned');

            return;
        }

        $unfinishedAtSequence = $this->steps()
            ->where('instance_id', $instance->id)
            ->where('sequence', $step->sequence)
            ->whereNotIn('status', ['approved'])
            ->exists();
        if ($unfinishedAtSequence) {
            return;
        }
        $nextSequence = $this->steps()
            ->where('instance_id', $instance->id)
            ->where('status', 'pending')
            ->min('sequence');
        if ($nextSequence !== null) {
            $activatedAt = now();
            foreach ($this->steps()->where('instance_id', $instance->id)->where('sequence', $nextSequence)->lockForUpdate()->get() as $next) {
                $next->forceFill([
                    'status' => 'active',
                    'activated_at' => $activatedAt,
                    'due_at' => $next->deadline_at ?? ($next->due_in_hours === null ? null : $activatedAt->copy()->addHours((int) $next->due_in_hours)),
                    'lock_version' => ((int) $next->lock_version) + 1,
                ])->save();
            }
            $instance->forceFill(['due_at' => $this->steps()
                ->where('instance_id', $instance->id)
                ->where('sequence', $nextSequence)
                ->whereNotNull('due_at')
                ->min('due_at')]);

            return;
        }
        $instance->forceFill(['status' => 'approved', 'completed_at' => now()]);
        $document->forceFill([
            'approval_status' => 'approved',
            'lock_version' => ((int) $document->lock_version) + 1,
        ])->save();
    }

    private function finishInstance(LegalWorkflowInstance $instance, LegalArchiveDocument $document, string $status): void
    {
        foreach ($this->steps()
            ->where('instance_id', $instance->id)
            ->whereIn('status', ['pending', 'active'])
            ->lockForUpdate()
            ->get() as $other
        ) {
            $other->forceFill([
                'status' => $status === 'expired' ? 'expired' : 'cancelled',
                'completed_at' => now(),
                'lock_version' => ((int) $other->lock_version) + 1,
            ])->save();
        }
        $instance->forceFill(['status' => $status, 'completed_at' => now()]);
        $document->forceFill([
            'approval_status' => $status,
            'lock_version' => ((int) $document->lock_version) + 1,
        ])->save();
    }

    private function assertDecisionInput(WorkflowDecisionInput $input): void
    {
        if (in_array($input->action, ['reject', 'return', 'reassign'], true)
            && $this->nullableTrim($input->comment) === null
            && $this->nullableTrim($input->reason) === null
        ) {
            throw new DomainException('legal_workflow_reason_required');
        }
        if ($input->action !== 'reassign') {
            return;
        }
        $actorType = trim((string) $input->reassignActorType);
        $actorReference = trim((string) $input->reassignActorReference);
        if ($actorReference === '') {
            throw new DomainException('legal_workflow_actor_reference_required');
        }
        if (! in_array($actorType, ['user', 'role', 'party', 'external'], true)) {
            throw new DomainException('legal_workflow_actor_type_invalid');
        }
        if (! $this->actors->supports($actorType)) {
            throw new DomainException('legal_workflow_actor_type_unavailable');
        }
        try {
            $dueAt = CarbonImmutable::parse((string) $input->dueAt);
        } catch (\Throwable) {
            throw new DomainException('legal_workflow_deadline_invalid');
        }
        if ($dueAt->isPast()) {
            throw new DomainException('legal_workflow_deadline_invalid');
        }
    }

    private function assertSubmittableVersion(
        LegalArchiveDocument $document,
        ?LegalArchiveDocumentVersion $version,
    ): void {
        if (
            ! $version instanceof LegalArchiveDocumentVersion
            || (int) $version->organization_id !== (int) $document->organization_id
            || (int) $version->document_id !== (int) $document->id
            || (int) $document->current_primary_version_id !== (int) $version->id
            || ! (bool) $version->is_current
            || $version->processing_status !== 'ready'
            || preg_match('/^[a-f0-9]{64}$/D', (string) $version->content_hash) !== 1
        ) {
            throw new DomainException('legal_workflow_version_not_ready');
        }
    }

    /** @param list<array<string, mixed>> $steps */
    private function activeGroupDueAt(array $steps, CarbonImmutable $submittedAt, int $sequence): ?CarbonImmutable
    {
        $deadlines = array_filter(array_map(
            fn (array $step): ?CarbonImmutable => (int) $step['sequence'] === $sequence
                ? $this->stepDueAt($step, $submittedAt)
                : null,
            $steps,
        ));
        if ($deadlines === []) {
            return null;
        }

        return array_reduce($deadlines, static fn (?CarbonImmutable $earliest, CarbonImmutable $due): CarbonImmutable => $earliest === null || $due->isBefore($earliest) ? $due : $earliest);
    }

    /** @param array<string, mixed> $step */
    private function stepDueAt(array $step, CarbonImmutable $submittedAt): ?CarbonImmutable
    {
        if (is_string($step['due_at'] ?? null) && $step['due_at'] !== '') {
            return CarbonImmutable::parse($step['due_at'])->utc();
        }
        if (is_int($step['due_in_hours'] ?? null)) {
            return $submittedAt->addHours($step['due_in_hours']);
        }

        return null;
    }

    private function validKey(string $key): string
    {
        $key = trim($key);
        if ($key === '' || mb_strlen($key) > 191) {
            throw new DomainException('legal_workflow_idempotency_key_invalid');
        }

        return $key;
    }

    private function assertSameRequest(mixed $storedHash, string $requestHash): void
    {
        if (! is_string($storedHash) || ! hash_equals($storedHash, $requestHash)) {
            throw new DomainException('legal_workflow_idempotency_conflict');
        }
    }

    private function nullableTrim(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function documents(): Builder
    {
        return $this->newDocument()->newQuery();
    }

    private function versions(): Builder
    {
        return $this->newVersion()->newQuery();
    }

    private function instances(): Builder
    {
        return $this->newInstance()->newQuery();
    }

    private function steps(): Builder
    {
        return $this->newStep()->newQuery();
    }

    private function decisions(): Builder
    {
        return $this->newDecision()->newQuery();
    }

    private function newDocument(): LegalArchiveDocument
    {
        return (new LegalArchiveDocument)->setConnection($this->connection->getName());
    }

    private function newVersion(): LegalArchiveDocumentVersion
    {
        return (new LegalArchiveDocumentVersion)->setConnection($this->connection->getName());
    }

    private function newInstance(): LegalWorkflowInstance
    {
        return (new LegalWorkflowInstance)->setConnection($this->connection->getName());
    }

    private function newStep(): LegalWorkflowStep
    {
        return (new LegalWorkflowStep)->setConnection($this->connection->getName());
    }

    private function newDecision(): LegalWorkflowDecision
    {
        return (new LegalWorkflowDecision)->setConnection($this->connection->getName());
    }
}
