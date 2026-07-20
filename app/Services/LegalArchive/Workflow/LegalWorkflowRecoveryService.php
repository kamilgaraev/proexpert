<?php

declare(strict_types=1);

namespace App\Services\LegalArchive\Workflow;

use App\BusinessModules\Core\ImmutableAudit\Services\ImmutableAuditIntegrityService;
use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument;
use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocumentVersion;
use App\BusinessModules\Features\LegalArchive\Models\LegalWorkflowInstance;
use App\BusinessModules\Features\LegalArchive\Models\LegalWorkflowStep;
use App\Services\LegalArchive\Audit\LegalDocumentAudit;
use DomainException;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Throwable;

final readonly class LegalWorkflowRecoveryService
{
    public function __construct(
        private ImmutableAuditIntegrityService $integrity,
        private LegalDocumentAudit $audit,
        private ConnectionInterface $connection,
    ) {}

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
                            foreach ($this->steps()->where('instance_id', $instance->id)->where('sequence', $next)->lockForUpdate()->get() as $step) {
                                $step->forceFill([
                                    'status' => 'active',
                                    'activated_at' => now(),
                                    'due_at' => $step->deadline_at ?? ($step->due_in_hours === null ? null : now()->addHours((int) $step->due_in_hours)),
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
        }
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
