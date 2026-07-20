<?php

declare(strict_types=1);

namespace App\Services\LegalArchive\Workflow;

use App\BusinessModules\Core\ImmutableAudit\Services\ImmutableAuditIntegrityService;
use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument;
use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocumentVersion;
use App\BusinessModules\Features\LegalArchive\Models\LegalWorkflowInstance;
use App\BusinessModules\Features\LegalArchive\Models\LegalWorkflowStep;
use App\BusinessModules\Features\LegalArchive\Models\LegalWorkflowTemplate;
use App\Services\LegalArchive\Audit\LegalDocumentAudit;
use DomainException;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Throwable;

final readonly class LegalWorkflowRecoveryService
{
    private LegalWorkflowTemplateService $templates;

    public function __construct(
        private ImmutableAuditIntegrityService $integrity,
        private LegalDocumentAudit $audit,
        private ConnectionInterface $connection,
        ?LegalWorkflowTemplateService $templates = null,
    ) {
        $this->templates = $templates ?? new LegalWorkflowTemplateService($integrity, $connection);
    }

    public function markRequired(LegalWorkflowInstance $instance, string $reason): void
    {
        if (trim($reason) === '') {
            throw new DomainException('legal_workflow_reconciliation_reason_required');
        }
        $this->instances()
            ->whereKey($instance->id)
            ->where('organization_id', (int) $instance->organization_id)
            ->update([
                'reconciliation_required_at' => now(),
                'reconciliation_reason' => trim($reason),
                'reconciliation_last_error' => null,
                'updated_at' => now(),
            ]);
    }

    /** @return Collection<int, LegalWorkflowInstance> */
    public function candidates(int $organizationId, int $limit = 100): Collection
    {
        return $this->instances()
            ->where('organization_id', $organizationId)
            ->whereNotNull('reconciliation_required_at')
            ->orderBy('reconciliation_required_at')
            ->limit(max(1, min($limit, 1000)))
            ->get();
    }

    public function reconcile(int $organizationId, int $instanceId): LegalWorkflowInstance
    {
        try {
            return $this->connection->transaction(function () use ($organizationId, $instanceId): LegalWorkflowInstance {
                $instance = $this->instances()
                    ->whereKey($instanceId)
                    ->where('organization_id', $organizationId)
                    ->whereNotNull('reconciliation_required_at')
                    ->lockForUpdate()
                    ->first();
                if (! $instance instanceof LegalWorkflowInstance) {
                    throw new DomainException('legal_workflow_reconciliation_candidate_not_found');
                }
                $document = $this->documents()->whereKey($instance->document_id)->lockForUpdate()->first();
                $version = $this->versions()->whereKey($instance->document_version_id)->first();
                if (
                    ! $document instanceof LegalArchiveDocument
                    || ! $version instanceof LegalArchiveDocumentVersion
                    || (int) $document->organization_id !== $organizationId
                    || (int) $version->organization_id !== $organizationId
                    || (int) $version->document_id !== (int) $document->id
                    || (int) $document->current_primary_version_id !== (int) $version->id
                    || ! (bool) $version->is_current
                    || $version->processing_status !== 'ready'
                    || ! hash_equals((string) $instance->document_content_hash, (string) $version->content_hash)
                    || ! hash_equals(
                        (string) $instance->snapshot_hash,
                        hash('sha256', $this->integrity->canonicalJson($instance->template_snapshot)),
                    )
                ) {
                    throw new DomainException('legal_workflow_reconciliation_integrity_failed');
                }
                $this->assertTemplateIdentity($instance);
                $this->assertStepsMatchSnapshot($instance);

                if ($instance->status === 'in_progress') {
                    $activeSequences = $this->steps()
                        ->where('instance_id', $instance->id)
                        ->where('status', 'active')
                        ->distinct()
                        ->pluck('sequence');
                    if ($activeSequences->count() > 1) {
                        throw new DomainException('legal_workflow_reconciliation_multiple_active_sequences');
                    }
                    if ($activeSequences->isEmpty()) {
                        $next = $this->steps()
                            ->where('instance_id', $instance->id)
                            ->where('status', 'pending')
                            ->min('sequence');
                        if ($next !== null) {
                            $activatedAt = now();
                            foreach ($this->steps()->where('instance_id', $instance->id)->where('sequence', $next)->lockForUpdate()->get() as $step) {
                                $step->forceFill([
                                    'status' => 'active',
                                    'activated_at' => $activatedAt,
                                    'due_at' => $step->deadline_at ?? ($step->due_in_hours === null ? null : $activatedAt->copy()->addHours((int) $step->due_in_hours)),
                                    'lock_version' => ((int) $step->lock_version) + 1,
                                ])->save();
                            }
                            $instance->forceFill(['due_at' => $this->steps()
                                ->where('instance_id', $instance->id)
                                ->where('sequence', $next)
                                ->whereNotNull('due_at')
                                ->min('due_at')]);
                        } elseif ($this->steps()->where('instance_id', $instance->id)->where('status', 'approved')->count()
                            === $this->steps()->where('instance_id', $instance->id)->count()
                        ) {
                            $instance->forceFill(['status' => 'approved', 'completed_at' => now(), 'due_at' => null]);
                            $document->forceFill([
                                'approval_status' => 'approved',
                                'lock_version' => ((int) $document->lock_version) + 1,
                            ])->save();
                        } else {
                            throw new DomainException('legal_workflow_reconciliation_state_ambiguous');
                        }
                    }
                }

                $instance->forceFill([
                    'reconciliation_required_at' => null,
                    'reconciliation_reason' => null,
                    'reconciliation_attempts' => min(((int) $instance->reconciliation_attempts) + 1, 100),
                    'reconciliation_last_error' => null,
                    'lock_version' => ((int) $instance->lock_version) + 1,
                ])->save();
                $this->audit->recordForActorId('workflow_reconciled', $document, null, [
                    'source_event_id' => "workflow-reconcile:{$instance->id}:{$instance->lock_version}",
                    'idempotency_key' => "workflow-reconcile:{$instance->id}:{$instance->lock_version}",
                    'workflow_instance_id' => (int) $instance->id,
                    'document_version_id' => (int) $instance->document_version_id,
                    'document_content_hash' => (string) $instance->document_content_hash,
                    'snapshot_hash' => (string) $instance->snapshot_hash,
                ]);

                return $instance->refresh()->load('steps', 'decisions');
            }, 3);
        } catch (Throwable $exception) {
            $this->instances()
                ->whereKey($instanceId)
                ->where('organization_id', $organizationId)
                ->whereNotNull('reconciliation_required_at')
                ->update([
                    'reconciliation_attempts' => $this->connection->raw('CASE WHEN reconciliation_attempts < 100 THEN reconciliation_attempts + 1 ELSE 100 END'),
                    'reconciliation_last_error' => mb_substr($exception->getMessage(), 0, 2000),
                    'updated_at' => now(),
                ]);
            throw $exception;
        }
    }

    private function assertStepsMatchSnapshot(LegalWorkflowInstance $instance): void
    {
        $snapshot = collect((array) ($instance->template_snapshot['steps'] ?? []))->keyBy('key');
        $steps = $this->steps()->where('instance_id', $instance->id)->lockForUpdate()->get();
        if ($snapshot->count() !== $steps->count()) {
            throw new DomainException('legal_workflow_reconciliation_step_mismatch');
        }
        foreach ($steps as $step) {
            $definition = $snapshot->get((string) $step->step_key);
            if (! is_array($definition) || [
                (string) $step->label,
                (int) $step->sequence,
                (string) $step->parallel_group,
                (bool) $step->required,
                $step->policy_key,
                $step->due_in_hours === null ? null : (int) $step->due_in_hours,
                $step->deadline_at?->utc()->toAtomString(),
            ] !== [
                (string) ($definition['label'] ?? ''),
                (int) ($definition['sequence'] ?? 0),
                (string) ($definition['parallel_group'] ?? ''),
                (bool) ($definition['required'] ?? false),
                $definition['policy_key'] ?? null,
                $definition['due_in_hours'] ?? null,
                $definition['due_at'] ?? null,
            ]) {
                throw new DomainException('legal_workflow_reconciliation_step_mismatch');
            }
            $this->assertAssignmentChain($instance, $step, $definition);
        }
    }

    private function assertTemplateIdentity(LegalWorkflowInstance $instance): void
    {
        $template = (new LegalWorkflowTemplate)->setConnection($this->connection->getName())->newQuery()
            ->whereKey($instance->template_id)
            ->where('organization_id', $instance->organization_id)
            ->where('version', $instance->template_version)
            ->where('definition_hash', $instance->template_definition_hash)
            ->with('steps')
            ->first();
        if (! $template instanceof LegalWorkflowTemplate) {
            throw new DomainException('legal_workflow_reconciliation_template_mismatch');
        }
        $this->templates->assertIntegrity($template);
        $identity = $instance->template_snapshot['template_identity'] ?? null;
        if (! is_array($identity) || [
            (int) ($identity['organization_id'] ?? 0),
            (int) ($identity['template_id'] ?? 0),
            (string) ($identity['code'] ?? ''),
            (int) ($identity['version'] ?? 0),
            (string) ($identity['definition_hash'] ?? ''),
        ] !== [
            (int) $template->organization_id,
            (int) $template->id,
            (string) $template->code,
            (int) $template->version,
            (string) $template->definition_hash,
        ]) {
            throw new DomainException('legal_workflow_reconciliation_template_mismatch');
        }
    }

    /** @param array<string, mixed> $definition */
    private function assertAssignmentChain(
        LegalWorkflowInstance $instance,
        LegalWorkflowStep $step,
        array $definition,
    ): void {
        $actorType = (string) ($definition['actor_type'] ?? '');
        $actorReference = (string) ($definition['actor_reference'] ?? '');
        $dueAt = $step->activated_at === null
            ? null
            : ($step->deadline_at ?? ($step->due_in_hours === null ? null : $step->activated_at->copy()->addHours((int) $step->due_in_hours)));
        $previousDecisionId = null;
        $revision = 0;
        $decisions = $instance->decisions()
            ->where('step_id', $step->id)
            ->where('action', 'reassign')
            ->orderBy('assignment_revision')
            ->get();
        foreach ($decisions as $decision) {
            $revision++;
            if (
                (int) $decision->assignment_revision !== $revision
                || $decision->previous_reassign_decision_id !== $previousDecisionId
                || $decision->from_actor_type !== $actorType
                || $decision->from_actor_reference !== $actorReference
                || ! $this->sameInstant($decision->from_due_at, $dueAt)
            ) {
                throw new DomainException('legal_workflow_reconciliation_assignment_chain_invalid');
            }
            $actorType = (string) $decision->to_actor_type;
            $actorReference = (string) $decision->to_actor_reference;
            $dueAt = $decision->to_due_at;
            $previousDecisionId = (int) $decision->id;
        }
        if (
            (int) $step->assignment_revision !== $revision
            || $step->last_reassign_decision_id !== $previousDecisionId
            || $step->actor_type !== $actorType
            || $step->actor_reference !== $actorReference
            || ! $this->sameInstant($step->due_at, $dueAt)
        ) {
            throw new DomainException('legal_workflow_reconciliation_assignment_chain_invalid');
        }
    }

    private function sameInstant(mixed $left, mixed $right): bool
    {
        if ($left === null || $right === null) {
            return $left === null && $right === null;
        }

        if (! $left instanceof \DateTimeInterface || ! $right instanceof \DateTimeInterface) {
            return false;
        }

        return $left->getTimestamp() === $right->getTimestamp();
    }

    private function instances(): Builder
    {
        return (new LegalWorkflowInstance)->setConnection($this->connection->getName())->newQuery();
    }

    private function steps(): Builder
    {
        return (new LegalWorkflowStep)->setConnection($this->connection->getName())->newQuery();
    }

    private function documents(): Builder
    {
        return (new LegalArchiveDocument)->setConnection($this->connection->getName())->newQuery();
    }

    private function versions(): Builder
    {
        return (new LegalArchiveDocumentVersion)->setConnection($this->connection->getName())->newQuery();
    }
}
