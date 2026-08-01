<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ScheduleManagement\Reporting\LookaheadReadiness\Services;

use App\BusinessModules\Features\ScheduleManagement\Reporting\LookaheadReadiness\Contracts\LookaheadReadinessSourceStore;
use App\BusinessModules\Features\ScheduleManagement\Reporting\LookaheadReadiness\DTO\PublishedCommitment;
use App\BusinessModules\Features\ScheduleManagement\Reporting\LookaheadReadiness\DTO\ReadinessEvent;
use App\BusinessModules\Features\ScheduleManagement\Reporting\LookaheadReadiness\DTO\ReadinessPolicyDefinition;
use App\BusinessModules\Features\ScheduleManagement\Reporting\LookaheadReadiness\DTO\ReadinessSnapshot;
use App\BusinessModules\Features\ScheduleManagement\Reporting\LookaheadReadiness\DTO\ScheduleRevisionDraft;
use App\BusinessModules\Features\ScheduleManagement\Reporting\LookaheadReadiness\DTO\SourceWriteReceipt;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use LogicException;

final readonly class EloquentLookaheadReadinessSourceStore implements LookaheadReadinessSourceStore
{
    public function __construct(
        private ScheduleRevisionFactory $scheduleRevisionFactory,
        private EloquentScheduleRevisionSourceGuard $sourceGuard,
    ) {}

    public function transaction(callable $operation): mixed
    {
        return DB::transaction($operation, 3);
    }

    public function approveScheduleRevision(
        ScheduleRevisionDraft $draft,
        string $contentHash,
        int $actorId,
        string $approvedAtUtc,
        string $idempotencyKey,
    ): SourceWriteReceipt {
        $this->assertTransaction();
        $this->sourceGuard->assertCurrent($draft);
        $this->advisoryLock("schedule-revision:{$draft->organizationId}:{$draft->scheduleId}:{$idempotencyKey}");
        $existing = DB::table('schedule_plan_revisions')
            ->where('organization_id', $draft->organizationId)
            ->where('idempotency_key', $idempotencyKey)
            ->lockForUpdate()
            ->first(['id', 'revision_number', 'content_hash']);
        if ($existing !== null) {
            return $this->replayReceipt($existing, $contentHash);
        }

        $this->advisoryLock("schedule-revision-number:{$draft->organizationId}:{$draft->scheduleId}");
        $previous = DB::table('schedule_plan_revisions')
            ->where('organization_id', $draft->organizationId)
            ->where('project_id', $draft->projectId)
            ->where('schedule_id', $draft->scheduleId)
            ->orderByDesc('revision_number')
            ->lockForUpdate()
            ->first(['id', 'revision_number']);
        $revisionNumber = $previous === null ? 1 : ((int) $previous->revision_number) + 1;
        $canonical = $this->scheduleRevisionFactory->canonicalSnapshot($draft);
        $createdAt = $approvedAtUtc;
        $revisionId = (int) DB::table('schedule_plan_revisions')->insertGetId([
            'organization_id' => $draft->organizationId,
            'project_id' => $draft->projectId,
            'schedule_id' => $draft->scheduleId,
            'revision_number' => $revisionNumber,
            'status' => 'approved',
            'content_hash' => $contentHash,
            'canonical_snapshot' => LookaheadReadinessCanonicalJson::encode($canonical),
            'source_watermark' => $draft->sourceWatermark,
            'planning_timezone' => $draft->planningTimezone,
            'planning_calendar' => LookaheadReadinessCanonicalJson::encode($draft->calendar),
            'planning_calendar_hash' => LookaheadReadinessCanonicalJson::hash($draft->calendar),
            'predecessor_revision_id' => $previous?->id,
            'approved_at' => $approvedAtUtc,
            'approved_by_user_id' => $actorId,
            'idempotency_key' => $idempotencyKey,
            'created_at' => $createdAt,
        ]);

        foreach ($canonical['tasks'] as $task) {
            DB::table('schedule_plan_revision_tasks')->insert([
                'organization_id' => $draft->organizationId,
                'project_id' => $draft->projectId,
                'schedule_id' => $draft->scheduleId,
                'schedule_plan_revision_id' => $revisionId,
                'external_id' => $task['external_id'],
                'source_task_id' => $task['source_task_id'],
                'wbs_code' => $task['wbs_code'],
                'task_name' => $task['name'],
                'task_class' => $task['task_class'],
                'planned_start' => $task['planned_start'],
                'planned_end' => $task['planned_end'],
                'duration_minutes' => $task['duration_minutes'],
                'planned_quantity' => $task['planned_quantity'],
                'planned_work_hours' => $task['planned_work_hours'],
                'is_critical' => $task['critical'],
                'constraint_point' => $task['constraint_point'],
                'parent_external_id' => $task['parent_external_id'],
                'task_hash' => LookaheadReadinessCanonicalJson::hash($task),
                'created_at' => $createdAt,
            ]);
        }
        foreach ($canonical['dependencies'] as $dependency) {
            DB::table('schedule_plan_revision_dependencies')->insert([
                'organization_id' => $draft->organizationId,
                'project_id' => $draft->projectId,
                'schedule_id' => $draft->scheduleId,
                'schedule_plan_revision_id' => $revisionId,
                'predecessor_external_id' => $dependency['predecessor_external_id'],
                'successor_external_id' => $dependency['successor_external_id'],
                'dependency_type' => $dependency['type'],
                'lag_minutes' => $dependency['lag_minutes'],
                'dependency_hash' => LookaheadReadinessCanonicalJson::hash($dependency),
                'created_at' => $createdAt,
            ]);
        }

        return new SourceWriteReceipt($revisionId, $revisionNumber, $contentHash, false);
    }

    public function publishPolicy(
        ReadinessPolicyDefinition $policy,
        int $actorId,
        string $idempotencyKey,
    ): SourceWriteReceipt {
        $this->assertTransaction();
        $this->advisoryLock("readiness-policy:{$policy->organizationId}:{$idempotencyKey}");
        $existing = DB::table('lookahead_readiness_policy_versions')
            ->where('organization_id', $policy->organizationId)
            ->where('idempotency_key', $idempotencyKey)
            ->lockForUpdate()
            ->first(['id', 'revision as revision_number', 'policy_hash as content_hash']);
        if ($existing !== null) {
            return $this->replayReceipt($existing, $policy->hash());
        }

        $publishedAt = now(new DateTimeZone('UTC'));
        $policyId = (int) DB::table('lookahead_readiness_policy_versions')->insertGetId([
            'organization_id' => $policy->organizationId,
            'policy_code' => $policy->policyCode,
            'semantic_version' => $policy->semanticVersion,
            'revision' => $policy->revision,
            'canonical_definition' => LookaheadReadinessCanonicalJson::encode($policy->canonicalDefinition()),
            'policy_hash' => $policy->hash(),
            'effective_from' => $publishedAt,
            'effective_until' => null,
            'published_by_user_id' => $actorId,
            'published_at' => $publishedAt,
            'idempotency_key' => $idempotencyKey,
            'created_at' => $publishedAt,
        ]);

        return new SourceWriteReceipt($policyId, $policy->revision, $policy->hash(), false);
    }

    public function publishCommitment(
        PublishedCommitment $commitment,
        int $scheduleRevisionId,
        int $policyId,
        string $idempotencyKey,
    ): SourceWriteReceipt {
        $this->assertTransaction();
        $this->advisoryLock("lookahead-commitment:{$commitment->organizationId}:{$commitment->scheduleId}:{$idempotencyKey}");
        $existing = DB::table('lookahead_commitment_revisions')
            ->where('organization_id', $commitment->organizationId)
            ->where('idempotency_key', $idempotencyKey)
            ->lockForUpdate()
            ->first(['id', 'revision_number', 'content_hash']);
        if ($existing !== null) {
            return $this->replayReceipt($existing, $commitment->contentHash);
        }

        $this->advisoryLock("lookahead-commitment-number:{$commitment->organizationId}:{$commitment->scheduleId}");
        $lastRevision = DB::table('lookahead_commitment_revisions')
            ->where('organization_id', $commitment->organizationId)
            ->where('project_id', $commitment->projectId)
            ->where('schedule_id', $commitment->scheduleId)
            ->max('revision_number');
        $revisionNumber = $lastRevision === null ? 1 : ((int) $lastRevision) + 1;
        $commitmentId = (int) DB::table('lookahead_commitment_revisions')->insertGetId([
            'organization_id' => $commitment->organizationId,
            'project_id' => $commitment->projectId,
            'schedule_id' => $commitment->scheduleId,
            'schedule_plan_revision_id' => $scheduleRevisionId,
            'readiness_policy_version_id' => $policyId,
            'revision_number' => $revisionNumber,
            'status' => 'published',
            'window_start' => $commitment->windowStart,
            'window_end' => $commitment->windowEnd,
            'planning_timezone' => $commitment->planningTimezone,
            'schedule_revision_hash' => $commitment->scheduleRevisionHash,
            'policy_hash' => $commitment->policyHash,
            'content_hash' => $commitment->contentHash,
            'canonical_snapshot' => LookaheadReadinessCanonicalJson::encode($commitment->canonicalSnapshot()),
            'published_at' => $commitment->publishedAtUtc,
            'published_by_user_id' => $commitment->publishedByUserId,
            'idempotency_key' => $idempotencyKey,
            'created_at' => $commitment->publishedAtUtc,
        ]);

        foreach ($commitment->tasks as $task) {
            $revisionTask = DB::table('schedule_plan_revision_tasks')
                ->where('schedule_plan_revision_id', $scheduleRevisionId)
                ->where('external_id', $task['schedule_task_external_id'])
                ->lockForUpdate()
                ->first(['id']);
            if ($revisionTask === null) {
                throw new LogicException('lookahead_readiness_commitment_task_invalid');
            }
            DB::table('lookahead_commitment_tasks')->insert([
                'organization_id' => $commitment->organizationId,
                'project_id' => $commitment->projectId,
                'schedule_id' => $commitment->scheduleId,
                'commitment_revision_id' => $commitmentId,
                'schedule_plan_revision_task_id' => $revisionTask->id,
                'schedule_task_external_id' => $task['schedule_task_external_id'],
                'committed_start' => $task['committed_start'],
                'committed_end' => $task['committed_end'],
                'planned_quantity' => $task['planned_quantity'],
                'planned_work_hours' => $task['planned_work_hours'],
                'responsible_role' => $task['responsible_role'],
                'responsible_user_id' => $task['responsible_user_id'],
                'inclusion_reason' => $task['inclusion_reason'],
                'task_hash' => LookaheadReadinessCanonicalJson::hash($task),
                'created_at' => $commitment->publishedAtUtc,
            ]);
        }

        return new SourceWriteReceipt($commitmentId, $revisionNumber, $commitment->contentHash, false);
    }

    public function appendEvent(ReadinessEvent $event): SourceWriteReceipt
    {
        $this->assertTransaction();
        $this->advisoryLock("lookahead-event:{$event->organizationId}:{$event->idempotencyKey}");
        $existing = DB::table('lookahead_readiness_events')
            ->where('organization_id', $event->organizationId)
            ->where('idempotency_key', $event->idempotencyKey)
            ->lockForUpdate()
            ->first(['event_id as id', 'evidence_hash as content_hash']);
        if ($existing !== null) {
            $receipt = $this->replayReceipt((object) [
                'id' => $existing->id,
                'revision_number' => 1,
                'content_hash' => $existing->content_hash,
            ], $event->evidenceHash());

            return new SourceWriteReceipt((string) $receipt->entityId, 1, $receipt->contentHash, true);
        }

        $policyId = $this->policyId($event->organizationId, $event->policy->hash());
        $commitment = DB::table('lookahead_commitment_revisions')
            ->where('id', $event->commitmentRevisionId)
            ->lockForUpdate()
            ->first(['schedule_revision_hash']);
        if ($commitment === null) {
            throw new LogicException('lookahead_readiness_event_lineage_invalid');
        }
        DB::table('lookahead_readiness_events')->insert([
            'event_id' => $event->eventId,
            'organization_id' => $event->organizationId,
            'project_id' => $event->projectId,
            'schedule_id' => $event->scheduleId,
            'commitment_revision_id' => $event->commitmentRevisionId,
            'commitment_task_id' => $event->commitmentTaskId,
            'readiness_policy_version_id' => $policyId,
            'event_type' => $event->eventType->value,
            'idempotency_key' => $event->idempotencyKey,
            'occurred_at' => $event->occurredAtUtc(),
            'actor_id' => $event->actorId,
            'payload' => LookaheadReadinessCanonicalJson::encode($event->payload),
            'payload_hash' => $event->payloadHash(),
            'evidence' => $event->evidence === null ? null : LookaheadReadinessCanonicalJson::encode($event->evidence),
            'evidence_hash' => $event->evidenceHash(),
            'prior_event_id' => $event->priorEventId,
            'policy_hash' => $event->policy->hash(),
            'schedule_revision_hash' => $commitment->schedule_revision_hash,
            'created_at' => $event->occurredAtUtc(),
        ]);

        return new SourceWriteReceipt($event->eventId, 1, $event->evidenceHash(), false);
    }

    public function sealSnapshot(ReadinessSnapshot $snapshot, string $idempotencyKey): SourceWriteReceipt
    {
        $this->assertTransaction();
        $this->advisoryLock("lookahead-snapshot:{$snapshot->organizationId}:{$idempotencyKey}");
        $existing = DB::table('lookahead_readiness_snapshots')
            ->where('organization_id', $snapshot->organizationId)
            ->where('idempotency_key', $idempotencyKey)
            ->lockForUpdate()
            ->first(['id', 'snapshot_revision as revision_number', 'snapshot_hash as content_hash']);
        if ($existing !== null) {
            return $this->replayReceipt($existing, $snapshot->snapshotHash);
        }

        $policyId = $this->policyId($snapshot->organizationId, $snapshot->policyHash);
        $snapshotId = (int) DB::table('lookahead_readiness_snapshots')->insertGetId([
            'organization_id' => $snapshot->organizationId,
            'project_id' => $snapshot->projectId,
            'schedule_id' => $snapshot->scheduleId,
            'commitment_revision_id' => $snapshot->commitmentRevisionId,
            'commitment_task_id' => $snapshot->commitmentTaskId,
            'readiness_policy_version_id' => $policyId,
            'snapshot_revision' => $snapshot->snapshotRevision,
            'state' => $snapshot->state->value,
            'component_outcomes' => LookaheadReadinessCanonicalJson::encode($snapshot->componentOutcomes),
            'reason_codes' => LookaheadReadinessCanonicalJson::encode($snapshot->reasonCodes),
            'blocker_event_ids' => LookaheadReadinessCanonicalJson::encode($snapshot->blockerEventIds),
            'waiver_event_ids' => LookaheadReadinessCanonicalJson::encode($snapshot->waiverEventIds),
            'policy_hash' => $snapshot->policyHash,
            'schedule_revision_hash' => $snapshot->scheduleRevisionHash,
            'commitment_revision_hash' => $snapshot->commitmentRevisionHash,
            'calculated_at' => $snapshot->calculatedAtUtc,
            'source_watermark' => $snapshot->sourceWatermark,
            'actual_comparison' => $snapshot->actualComparison === null
                ? null
                : LookaheadReadinessCanonicalJson::encode($snapshot->actualComparison),
            'readiness_hash' => $snapshot->readinessHash,
            'snapshot_hash' => $snapshot->snapshotHash,
            'idempotency_key' => $idempotencyKey,
            'created_at' => $snapshot->calculatedAtUtc,
        ]);

        return new SourceWriteReceipt(
            $snapshotId,
            $snapshot->snapshotRevision,
            $snapshot->snapshotHash,
            false,
        );
    }

    private function policyId(int $organizationId, string $policyHash): int
    {
        $policy = DB::table('lookahead_readiness_policy_versions')
            ->where('organization_id', $organizationId)
            ->where('policy_hash', $policyHash)
            ->first(['id']);
        if ($policy === null) {
            throw new LogicException('lookahead_readiness_policy_pin_missing');
        }

        return (int) $policy->id;
    }

    private function replayReceipt(object $existing, string $expectedHash): SourceWriteReceipt
    {
        if (! hash_equals((string) $existing->content_hash, $expectedHash)) {
            throw new LogicException('lookahead_readiness_idempotency_conflict');
        }

        return new SourceWriteReceipt(
            is_numeric($existing->id) ? (int) $existing->id : (string) $existing->id,
            (int) $existing->revision_number,
            (string) $existing->content_hash,
            true,
        );
    }

    private function advisoryLock(string $identity): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::selectOne('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [$identity]);
        }
    }

    private function assertTransaction(): void
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('lookahead_readiness_owner_transaction_required');
        }
    }
}
