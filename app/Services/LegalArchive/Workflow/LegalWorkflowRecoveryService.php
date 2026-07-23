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
use App\Services\LegalArchive\LegalDocumentAggregateLock;
use DomainException;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Throwable;

final readonly class LegalWorkflowRecoveryService
{
    private LegalWorkflowTemplateService $templates;

    private LegalDocumentAggregateLock $aggregateLock;

    public function __construct(
        private ImmutableAuditIntegrityService $integrity,
        private LegalDocumentAudit $audit,
        private ConnectionInterface $connection,
        ?LegalWorkflowTemplateService $templates = null,
        ?LegalDocumentAggregateLock $aggregateLock = null,
    ) {
        $this->templates = $templates ?? new LegalWorkflowTemplateService($integrity, $connection);
        $this->aggregateLock = $aggregateLock ?? new LegalDocumentAggregateLock;
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
                $reference = $this->instances()
                    ->whereKey($instanceId)
                    ->where('organization_id', $organizationId)
                    ->whereNotNull('reconciliation_required_at')
                    ->first();
                if (! $reference instanceof LegalWorkflowInstance) {
                    throw new DomainException('legal_workflow_reconciliation_candidate_not_found');
                }
                $document = $this->aggregateLock->lockDocument(
                    $this->connection,
                    $organizationId,
                    (int) $reference->document_id,
                );
                $version = $this->aggregateLock->lockVersion(
                    $this->connection,
                    $document,
                    (int) $reference->document_version_id,
                );
                $instance = $this->instances()
                    ->whereKey($instanceId)
                    ->where('organization_id', $organizationId)
                    ->whereNotNull('reconciliation_required_at')
                    ->lockForUpdate()
                    ->first();
                if (! $instance instanceof LegalWorkflowInstance) {
                    throw new DomainException('legal_workflow_reconciliation_candidate_not_found');
                }
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
                $steps = $this->assertStepsMatchSnapshot($instance);
                $projection = $this->deriveProjection($instance, $steps);
                $this->authorizeDatabaseRecovery();
                $this->applyStepProjection($steps, $projection['steps']);
                $instance->forceFill([
                    'status' => $projection['status'],
                    'due_at' => $projection['due_at'],
                    'completed_at' => $projection['completed_at'],
                    'cancelled_at' => $projection['cancelled_at'],
                    'expired_at' => $projection['expired_at'],
                ])->save();
                $documentProjection = LegalWorkflowDocumentProjection::forInstanceStatus($projection['status']);
                if (
                    $document->approval_status !== $documentProjection['approval_status']
                    || $document->lifecycle_status !== $documentProjection['lifecycle_status']
                ) {
                    $document->forceFill([
                        ...$documentProjection,
                        'lock_version' => ((int) $document->lock_version) + 1,
                    ])->save();
                }
                $this->verifyProjection($instance->refresh(), $document->refresh(), $projection);
                $nextLockVersion = ((int) $instance->lock_version) + 1;
                $this->audit->recordForActorId('workflow_reconciled', $document, null, [
                    'source_event_id' => "workflow-reconcile:{$instance->id}:{$nextLockVersion}",
                    'idempotency_key' => "workflow-reconcile:{$instance->id}:{$nextLockVersion}",
                    'workflow_instance_id' => (int) $instance->id,
                    'document_version_id' => (int) $instance->document_version_id,
                    'document_content_hash' => (string) $instance->document_content_hash,
                    'snapshot_hash' => (string) $instance->snapshot_hash,
                ]);
                $instance->forceFill([
                    'reconciliation_required_at' => null,
                    'reconciliation_reason' => null,
                    'reconciliation_attempts' => min(((int) $instance->reconciliation_attempts) + 1, 100),
                    'reconciliation_last_error' => null,
                    'lock_version' => $nextLockVersion,
                ])->save();

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

    /** @return Collection<int, LegalWorkflowStep> */
    private function assertStepsMatchSnapshot(LegalWorkflowInstance $instance): Collection
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
        }

        return $steps;
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

    /**
     * @param  Collection<int, LegalWorkflowStep>  $steps
     * @return array{status: string, due_at: mixed, completed_at: mixed, cancelled_at: mixed, expired_at: mixed, steps: array<int, array<string, mixed>>}
     */
    private function deriveProjection(LegalWorkflowInstance $instance, Collection $steps): array
    {
        $decisions = $instance->decisions()->orderBy('id')->get();
        $terminalByStep = [];
        $instanceTerminal = null;
        foreach ($decisions as $decision) {
            if (
                (int) $decision->organization_id !== (int) $instance->organization_id
                || (int) $decision->document_id !== (int) $instance->document_id
                || (int) $decision->document_version_id !== (int) $instance->document_version_id
                || ! hash_equals((string) $decision->document_content_hash, (string) $instance->document_content_hash)
            ) {
                throw new DomainException('legal_workflow_reconciliation_decision_invalid');
            }
            if (in_array($decision->action, ['cancel', 'expire'], true)) {
                $expectedInstanceStatus = $decision->action === 'cancel' ? 'cancelled' : 'expired';
                if ($decision->step_id !== null || $decision->from_status !== 'in_progress' || $decision->to_status !== $expectedInstanceStatus) {
                    throw new DomainException('legal_workflow_reconciliation_decision_invalid');
                }
                if ($instanceTerminal !== null) {
                    throw new DomainException('legal_workflow_reconciliation_decision_invalid');
                }
                $instanceTerminal = $decision;

                continue;
            }
            if ($decision->step_id === null || ! $steps->contains('id', (int) $decision->step_id)) {
                throw new DomainException('legal_workflow_reconciliation_decision_invalid');
            }
            if ($decision->action === 'reassign') {
                if ($decision->from_status !== 'active' || $decision->to_status !== 'active') {
                    throw new DomainException('legal_workflow_reconciliation_decision_invalid');
                }

                continue;
            }
            $expectedToStatus = match ($decision->action) {
                'approve' => 'approved',
                'reject' => 'rejected',
                'return' => 'returned',
                default => throw new DomainException('legal_workflow_reconciliation_decision_invalid'),
            };
            if ($decision->from_status !== 'active' || $decision->to_status !== $expectedToStatus) {
                throw new DomainException('legal_workflow_reconciliation_decision_invalid');
            }
            if (isset($terminalByStep[(int) $decision->step_id])) {
                throw new DomainException('legal_workflow_reconciliation_decision_invalid');
            }
            $terminalByStep[(int) $decision->step_id] = $decision;
        }

        $rejects = collect($terminalByStep)->where('action', 'reject');
        $returns = collect($terminalByStep)->where('action', 'return');
        if ($instanceTerminal !== null && collect($terminalByStep)->contains(static fn ($decision): bool => $decision->action !== 'approve')) {
            throw new DomainException('legal_workflow_reconciliation_decision_invalid');
        }
        if ($rejects->count() > 1 || $returns->count() > 1 || ($rejects->isNotEmpty() && $returns->isNotEmpty())) {
            throw new DomainException('legal_workflow_reconciliation_decision_invalid');
        }
        $status = match (true) {
            $instanceTerminal !== null => (string) $instanceTerminal->action === 'cancel' ? 'cancelled' : 'expired',
            $rejects->isNotEmpty() => 'rejected',
            $returns->isNotEmpty() => 'returned',
            count($terminalByStep) === $steps->count()
                && collect($terminalByStep)->every(static fn ($decision): bool => $decision->action === 'approve') => 'approved',
            default => 'in_progress',
        };
        if ($status === 'in_progress' && collect($terminalByStep)->contains(static fn ($decision): bool => $decision->action !== 'approve')) {
            throw new DomainException('legal_workflow_reconciliation_decision_invalid');
        }

        $incompleteSequence = $status === 'in_progress'
            ? $steps->reject(static fn (LegalWorkflowStep $step): bool => isset($terminalByStep[(int) $step->id]))->min('sequence')
            : null;
        if ($status === 'in_progress' && $incompleteSequence === null) {
            throw new DomainException('legal_workflow_reconciliation_state_ambiguous');
        }

        $stepProjection = [];
        foreach ($steps as $step) {
            $definition = (array) collect((array) ($instance->template_snapshot['steps'] ?? []))
                ->firstWhere('key', (string) $step->step_key);
            $assignment = $this->deriveAssignment($instance, $step, $definition, $terminalByStep);
            $terminal = $terminalByStep[(int) $step->id] ?? null;
            $stepStatus = match (true) {
                $terminal !== null => (string) $terminal->to_status,
                $status === 'in_progress' && (int) $step->sequence === (int) $incompleteSequence => 'active',
                $status === 'in_progress' => 'pending',
                $status === 'expired' => 'expired',
                default => 'cancelled',
            };
            $activatedAt = in_array($stepStatus, ['pending'], true) ? null : $assignment['activated_at'];
            $stepProjection[(int) $step->id] = [
                ...$assignment,
                'status' => $stepStatus,
                'activated_at' => $activatedAt,
                'due_at' => $stepStatus === 'pending' ? null : $assignment['due_at'],
                'completed_at' => in_array($stepStatus, ['active', 'pending'], true)
                    ? null
                    : ($terminal?->decided_at ?? $instanceTerminal?->decided_at ?? $instance->completed_at ?? now()),
            ];
        }
        $activeDue = collect($stepProjection)
            ->where('status', 'active')
            ->pluck('due_at')
            ->filter()
            ->sortBy(static fn ($due): int => $due->getTimestamp())
            ->first();
        $completedAt = $status === 'in_progress'
            ? null
            : ($instanceTerminal?->decided_at
                ?? collect($terminalByStep)->max('decided_at')
                ?? $instance->completed_at
                ?? now());

        return [
            'status' => $status,
            'due_at' => $status === 'in_progress' ? $activeDue : null,
            'completed_at' => $completedAt,
            'cancelled_at' => $status === 'cancelled' ? $completedAt : null,
            'expired_at' => $status === 'expired' ? $completedAt : null,
            'steps' => $stepProjection,
        ];
    }

    /**
     * @param  array<string, mixed>  $definition
     * @param  array<int, mixed>  $terminalByStep
     * @return array{actor_type: string, actor_reference: string, assignment_revision: int, last_reassign_decision_id: ?int, activated_at: mixed, due_at: mixed}
     */
    private function deriveAssignment(
        LegalWorkflowInstance $instance,
        LegalWorkflowStep $step,
        array $definition,
        array $terminalByStep,
    ): array {
        $actorType = (string) ($definition['actor_type'] ?? '');
        $actorReference = (string) ($definition['actor_reference'] ?? '');
        $activatedAt = $this->activationTime($instance, $step, $terminalByStep);
        $dueAt = $activatedAt === null
            ? null
            : ($step->deadline_at ?? ($step->due_in_hours === null ? null : $activatedAt->copy()->addHours((int) $step->due_in_hours)));
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

        return [
            'actor_type' => $actorType,
            'actor_reference' => $actorReference,
            'assignment_revision' => $revision,
            'last_reassign_decision_id' => $previousDecisionId,
            'activated_at' => $activatedAt,
            'due_at' => $dueAt,
        ];
    }

    /** @param array<int, mixed> $terminalByStep */
    private function activationTime(LegalWorkflowInstance $instance, LegalWorkflowStep $step, array $terminalByStep): mixed
    {
        $minimumSequence = collect((array) ($instance->template_snapshot['steps'] ?? []))->min('sequence');
        if ((int) $step->sequence === (int) $minimumSequence) {
            return $instance->submitted_at;
        }
        $previousSequence = collect((array) ($instance->template_snapshot['steps'] ?? []))
            ->where('sequence', '<', (int) $step->sequence)
            ->max('sequence');
        $previousSteps = $this->steps()->where('instance_id', $instance->id)->where('sequence', $previousSequence)->get();
        $approvals = $previousSteps->map(static fn (LegalWorkflowStep $previous) => $terminalByStep[(int) $previous->id] ?? null);
        if ($approvals->contains(null) || $approvals->contains(static fn ($decision): bool => $decision->action !== 'approve')) {
            return null;
        }

        return $approvals->max('decided_at');
    }

    /** @param Collection<int, LegalWorkflowStep> $steps @param array<int, array<string, mixed>> $projection */
    private function applyStepProjection(Collection $steps, array $projection): void
    {
        foreach ($steps as $step) {
            $expected = $projection[(int) $step->id];
            $changed = false;
            foreach ($expected as $field => $value) {
                $current = $step->{$field};
                $changed = $changed || ($current instanceof \DateTimeInterface || $value instanceof \DateTimeInterface
                    ? ! $this->sameInstant($current, $value)
                    : $current !== $value);
            }
            if ($changed) {
                $step->applyRecoveryProjection([
                    ...$expected,
                    'lock_version' => ((int) $step->lock_version) + 1,
                ]);
            }
        }
    }

    /** @param array<string, mixed> $projection */
    private function verifyProjection(LegalWorkflowInstance $instance, LegalArchiveDocument $document, array $projection): void
    {
        $documentProjection = LegalWorkflowDocumentProjection::forInstanceStatus((string) $projection['status']);
        $actualDue = $this->steps()->where('instance_id', $instance->id)->where('status', 'active')->whereNotNull('due_at')->min('due_at');
        if (
            $instance->status !== $projection['status']
            || ! $this->sameInstant($instance->due_at, $actualDue === null ? null : new \DateTimeImmutable((string) $actualDue))
            || $document->approval_status !== $documentProjection['approval_status']
            || $document->lifecycle_status !== $documentProjection['lifecycle_status']
        ) {
            throw new DomainException('legal_workflow_reconciliation_projection_invalid');
        }
    }

    private function authorizeDatabaseRecovery(): void
    {
        if ($this->connection->getDriverName() === 'pgsql') {
            $this->connection->statement("SET LOCAL app.legal_workflow_recovery = 'service'");
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
}
