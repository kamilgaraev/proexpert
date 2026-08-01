<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ScheduleManagement\Reporting\LookaheadReadiness\Services;

use App\BusinessModules\Features\ScheduleManagement\Reporting\LookaheadReadiness\Contracts\LookaheadReadinessSourceStore;
use App\BusinessModules\Features\ScheduleManagement\Reporting\LookaheadReadiness\DTO\AuthorizationDecision;
use App\BusinessModules\Features\ScheduleManagement\Reporting\LookaheadReadiness\DTO\PublishedCommitment;
use App\BusinessModules\Features\ScheduleManagement\Reporting\LookaheadReadiness\DTO\ReadinessEvent;
use App\BusinessModules\Features\ScheduleManagement\Reporting\LookaheadReadiness\DTO\ReadinessPolicyDefinition;
use App\BusinessModules\Features\ScheduleManagement\Reporting\LookaheadReadiness\DTO\ScheduleRevisionDraft;
use App\BusinessModules\Features\ScheduleManagement\Reporting\LookaheadReadiness\DTO\SourceWriteReceipt;
use App\BusinessModules\Features\ScheduleManagement\Reporting\LookaheadReadiness\Enums\ReadinessEventType;
use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Domain\Authorization\Services\RoleScanner;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use LogicException;

final readonly class EloquentLookaheadReadinessSourceStore implements LookaheadReadinessSourceStore
{
    public function __construct(
        private ScheduleRevisionFactory $scheduleRevisionFactory,
        private EloquentScheduleRevisionSourceGuard $sourceGuard,
        private ReadinessEventStateMachine $eventStateMachine,
        private ReadinessPolicyEvaluator $policyEvaluator,
        private ReadinessSnapshotFactory $snapshotFactory,
        private RoleScanner $roleScanner,
    ) {}

    public function transaction(callable $operation): mixed
    {
        return DB::transaction($operation, 3);
    }

    public function approveScheduleRevision(
        ScheduleRevisionDraft $draft,
        string $contentHash,
        AuthorizationDecision $authorizationDecision,
        string $approvedAtUtc,
        string $idempotencyKey,
    ): SourceWriteReceipt {
        $this->assertTransaction();
        $this->assertAuthorizationDecision(
            $authorizationDecision,
            $authorizationDecision->actorId,
            LookaheadReadinessAbility::APPROVE_SCHEDULE_REVISION,
            $draft->organizationId,
            $draft->projectId,
        );
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
        $previousState = $previous === null ? null : DB::table('schedule_plan_revision_lifecycle_events')
            ->where('schedule_plan_revision_id', $previous->id)
            ->orderByDesc('sequence')
            ->lockForUpdate()
            ->value('to_state');
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
            'approved_by_user_id' => $authorizationDecision->actorId,
            ...$this->authorizationColumns($authorizationDecision),
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
        foreach ([
            [1, null, 'draft'],
            [2, 'draft', 'approved'],
        ] as [$sequence, $fromState, $toState]) {
            DB::table('schedule_plan_revision_lifecycle_events')->insert([
                'schedule_plan_revision_id' => $revisionId,
                'sequence' => $sequence,
                'from_state' => $fromState,
                'to_state' => $toState,
                'effective_at' => $approvedAtUtc,
                'actor_id' => $authorizationDecision->actorId,
                'idempotency_key' => $idempotencyKey.":{$toState}",
                ...$this->authorizationColumns($authorizationDecision),
                'created_at' => $approvedAtUtc,
            ]);
        }
        if ($previous !== null && $previousState === 'approved') {
            DB::table('schedule_plan_revision_lifecycle_events')->insert([
                'schedule_plan_revision_id' => $previous->id,
                'sequence' => 3,
                'from_state' => 'approved',
                'to_state' => 'superseded',
                'effective_at' => $approvedAtUtc,
                'actor_id' => $authorizationDecision->actorId,
                'idempotency_key' => "superseded-by:{$revisionId}",
                ...$this->authorizationColumns($authorizationDecision),
                'created_at' => $approvedAtUtc,
            ]);
        }

        return new SourceWriteReceipt($revisionId, $revisionNumber, $contentHash, false);
    }

    public function publishPolicy(
        ReadinessPolicyDefinition $policy,
        AuthorizationDecision $authorizationDecision,
        string $idempotencyKey,
    ): SourceWriteReceipt {
        $this->assertTransaction();
        $this->assertAuthorizationDecision(
            $authorizationDecision,
            $authorizationDecision->actorId,
            LookaheadReadinessAbility::PUBLISH_POLICY,
            $policy->organizationId,
            0,
        );
        $this->advisoryLock("readiness-policy:{$policy->organizationId}:{$idempotencyKey}");
        $existing = DB::table('lookahead_readiness_policy_versions')
            ->where('organization_id', $policy->organizationId)
            ->where('idempotency_key', $idempotencyKey)
            ->lockForUpdate()
            ->first(['id', 'revision as revision_number', 'policy_hash as content_hash', 'intent_hash']);
        if ($existing !== null) {
            if (! hash_equals((string) $existing->intent_hash, $policy->intentHash())) {
                throw new LogicException('lookahead_readiness_idempotency_conflict');
            }

            return new SourceWriteReceipt(
                (int) $existing->id,
                (int) $existing->revision_number,
                (string) $existing->content_hash,
                true,
            );
        }

        $this->advisoryLock("readiness-policy-number:{$policy->organizationId}:{$policy->policyCode}");
        $previous = DB::table('lookahead_readiness_policy_versions')
            ->where('organization_id', $policy->organizationId)
            ->where('policy_code', $policy->policyCode)
            ->orderByDesc('revision')
            ->lockForUpdate()
            ->first(['id', 'revision']);
        $publishedPolicy = $policy->withRevision($previous === null ? 1 : ((int) $previous->revision) + 1);
        $publishedAt = now(new DateTimeZone('UTC'));
        $policyId = (int) DB::table('lookahead_readiness_policy_versions')->insertGetId([
            'organization_id' => $publishedPolicy->organizationId,
            'policy_code' => $publishedPolicy->policyCode,
            'semantic_version' => $publishedPolicy->semanticVersion,
            'revision' => $publishedPolicy->revision,
            'predecessor_policy_version_id' => $previous?->id,
            'canonical_definition' => LookaheadReadinessCanonicalJson::encode($publishedPolicy->canonicalDefinition()),
            'policy_hash' => $publishedPolicy->hash(),
            'intent_hash' => $publishedPolicy->intentHash(),
            'effective_from' => $publishedAt,
            'effective_until' => null,
            'published_by_user_id' => $authorizationDecision->actorId,
            ...$this->authorizationColumns($authorizationDecision),
            'published_at' => $publishedAt,
            'idempotency_key' => $idempotencyKey,
            'created_at' => $publishedAt,
        ]);

        return new SourceWriteReceipt($policyId, $publishedPolicy->revision, $publishedPolicy->hash(), false);
    }

    public function transitionScheduleRevision(
        int $scheduleRevisionId,
        int $organizationId,
        int $projectId,
        string $targetState,
        string $effectiveAtUtc,
        AuthorizationDecision $authorizationDecision,
        string $idempotencyKey,
    ): SourceWriteReceipt {
        $this->assertTransaction();
        $this->assertAuthorizationDecision(
            $authorizationDecision,
            $authorizationDecision->actorId,
            LookaheadReadinessAbility::APPROVE_SCHEDULE_REVISION,
            $organizationId,
            $projectId,
        );
        if (! in_array($targetState, ['superseded', 'withdrawn'], true)) {
            throw new LogicException('lookahead_readiness_schedule_transition_invalid');
        }
        $this->advisoryLock("schedule-revision-lifecycle:{$scheduleRevisionId}");
        $existing = DB::table('schedule_plan_revision_lifecycle_events')
            ->where('schedule_plan_revision_id', $scheduleRevisionId)
            ->where('idempotency_key', $idempotencyKey)
            ->lockForUpdate()
            ->first(['id', 'sequence', 'to_state']);
        $transitionHash = LookaheadReadinessCanonicalJson::hash([
            'effective_at_utc' => $effectiveAtUtc,
            'schedule_revision_id' => (string) $scheduleRevisionId,
            'target_state' => $targetState,
        ]);
        if ($existing !== null) {
            if ((string) $existing->to_state !== $targetState) {
                throw new LogicException('lookahead_readiness_idempotency_conflict');
            }

            return new SourceWriteReceipt((int) $existing->id, (int) $existing->sequence, $transitionHash, true);
        }
        $revision = DB::table('schedule_plan_revisions')
            ->where('id', $scheduleRevisionId)
            ->where('organization_id', $organizationId)
            ->where('project_id', $projectId)
            ->lockForUpdate()
            ->first(['id']);
        $current = DB::table('schedule_plan_revision_lifecycle_events')
            ->where('schedule_plan_revision_id', $scheduleRevisionId)
            ->orderByDesc('sequence')
            ->lockForUpdate()
            ->first(['sequence', 'to_state']);
        if ($revision === null || $current === null || $current->to_state !== 'approved') {
            throw new LogicException('lookahead_readiness_schedule_transition_invalid');
        }
        $sequence = ((int) $current->sequence) + 1;
        $id = (int) DB::table('schedule_plan_revision_lifecycle_events')->insertGetId([
            'schedule_plan_revision_id' => $scheduleRevisionId,
            'sequence' => $sequence,
            'from_state' => 'approved',
            'to_state' => $targetState,
            'effective_at' => $effectiveAtUtc,
            'actor_id' => $authorizationDecision->actorId,
            'idempotency_key' => $idempotencyKey,
            ...$this->authorizationColumns($authorizationDecision),
            'created_at' => $effectiveAtUtc,
        ]);

        return new SourceWriteReceipt($id, $sequence, $transitionHash, false);
    }

    public function publishCommitment(
        PublishedCommitment $commitment,
        int $scheduleRevisionId,
        int $policyId,
        AuthorizationDecision $authorizationDecision,
        string $idempotencyKey,
    ): SourceWriteReceipt {
        $this->assertTransaction();
        $this->assertAuthorizationDecision(
            $authorizationDecision,
            $commitment->publishedByUserId,
            LookaheadReadinessAbility::PUBLISH_COMMITMENT,
            $commitment->organizationId,
            $commitment->projectId,
        );
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
        $previous = DB::table('lookahead_commitment_revisions')
            ->where('organization_id', $commitment->organizationId)
            ->where('project_id', $commitment->projectId)
            ->where('schedule_id', $commitment->scheduleId)
            ->orderByDesc('revision_number')
            ->lockForUpdate()
            ->first([
                'id',
                'revision_number',
                'readiness_policy_version_id',
                'content_hash',
                'policy_hash',
                'schedule_revision_hash',
            ]);
        $previousState = $previous === null ? null : DB::table('lookahead_commitment_lifecycle_events')
            ->where('commitment_revision_id', $previous->id)
            ->orderByDesc('sequence')
            ->lockForUpdate()
            ->value('to_state');
        $revisionNumber = $previous === null ? 1 : ((int) $previous->revision_number) + 1;
        $scheduleRevision = DB::table('schedule_plan_revisions')
            ->where('id', $scheduleRevisionId)
            ->where('organization_id', $commitment->organizationId)
            ->where('project_id', $commitment->projectId)
            ->where('schedule_id', $commitment->scheduleId)
            ->lockForUpdate()
            ->first(['content_hash', 'approved_at']);
        $policy = DB::table('lookahead_readiness_policy_versions')
            ->where('id', $policyId)
            ->where('organization_id', $commitment->organizationId)
            ->lockForUpdate()
            ->first(['policy_hash', 'published_at', 'effective_from', 'effective_until']);
        $publishedAt = new DateTimeImmutable($commitment->publishedAtUtc);
        if ($scheduleRevision === null
            || $policy === null
            || ! hash_equals((string) $scheduleRevision->content_hash, $commitment->scheduleRevisionHash)
            || ! hash_equals((string) $policy->policy_hash, $commitment->policyHash)
            || new DateTimeImmutable((string) $scheduleRevision->approved_at) > $publishedAt
            || new DateTimeImmutable((string) $policy->published_at) > $publishedAt
            || new DateTimeImmutable((string) $policy->effective_from) > $publishedAt
            || ($policy->effective_until !== null
                && $publishedAt >= new DateTimeImmutable((string) $policy->effective_until))) {
            throw new LogicException('lookahead_readiness_commitment_lineage_invalid');
        }
        $commitmentId = (int) DB::table('lookahead_commitment_revisions')->insertGetId([
            'organization_id' => $commitment->organizationId,
            'project_id' => $commitment->projectId,
            'schedule_id' => $commitment->scheduleId,
            'schedule_plan_revision_id' => $scheduleRevisionId,
            'readiness_policy_version_id' => $policyId,
            'revision_number' => $revisionNumber,
            'predecessor_revision_id' => $previous?->id,
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
            ...$this->authorizationColumns($authorizationDecision),
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
        foreach ([
            [1, null, 'draft'],
            [2, 'draft', 'published'],
        ] as [$sequence, $fromState, $toState]) {
            DB::table('lookahead_commitment_lifecycle_events')->insert([
                'commitment_revision_id' => $commitmentId,
                'sequence' => $sequence,
                'from_state' => $fromState,
                'to_state' => $toState,
                'effective_at' => $commitment->publishedAtUtc,
                'actor_id' => $authorizationDecision->actorId,
                'idempotency_key' => $idempotencyKey.":{$toState}",
                ...$this->authorizationColumns($authorizationDecision),
                'created_at' => $commitment->publishedAtUtc,
            ]);
        }
        if ($previous !== null && $previousState === 'published') {
            $priorEvent = DB::table('lookahead_readiness_events')
                ->where('commitment_revision_id', $previous->id)
                ->where('event_type', ReadinessEventType::COMMITMENT_PUBLISHED->value)
                ->orderByDesc('occurred_at')
                ->orderByDesc('event_id')
                ->lockForUpdate()
                ->first(['event_id']);
            $previousPolicy = DB::table('lookahead_readiness_policy_versions')
                ->where('id', $previous->readiness_policy_version_id)
                ->lockForUpdate()
                ->first(['canonical_definition']);
            if ($priorEvent === null || $previousPolicy === null) {
                throw new LogicException('lookahead_readiness_commitment_transition_invalid');
            }
            $supersedeIdempotency = "superseded-by:{$commitmentId}";
            $supersededEvent = ReadinessEvent::fromArray([
                'event_id' => LookaheadReadinessEventIdentity::fromIdempotency(
                    $commitment->organizationId,
                    $supersedeIdempotency,
                ),
                'idempotency_key' => $supersedeIdempotency,
                'organization_id' => $commitment->organizationId,
                'project_id' => $commitment->projectId,
                'schedule_id' => $commitment->scheduleId,
                'commitment_revision_id' => (int) $previous->id,
                'commitment_task_id' => null,
                'event_type' => ReadinessEventType::COMMITMENT_SUPERSEDED->value,
                'occurred_at' => $commitment->publishedAtUtc,
                'actor_id' => $authorizationDecision->actorId,
                'aggregate_id' => 'commitment:'.$previous->id,
                'payload' => [
                    'superseded_by_commitment_revision_id' => (string) $commitmentId,
                ],
                'evidence' => null,
                'prior_event_id' => (string) $priorEvent->event_id,
            ], ReadinessPolicyDefinition::fromCanonicalDefinition(
                $this->decodeJson($previousPolicy->canonical_definition),
            ));
            $this->appendEvent($supersededEvent, $authorizationDecision);
        }

        return new SourceWriteReceipt($commitmentId, $revisionNumber, $commitment->contentHash, false);
    }

    public function appendEvent(ReadinessEvent $event, AuthorizationDecision $authorizationDecision): SourceWriteReceipt
    {
        $this->assertTransaction();
        $this->assertAuthorizationDecision(
            $authorizationDecision,
            $event->actorId,
            $this->eventPermission($event->eventType),
            $event->organizationId,
            $event->projectId,
        );
        if ($event->commitmentTaskId !== null) {
            $this->advisoryLock("lookahead-task-event-stream:{$event->organizationId}:{$event->commitmentTaskId}");
        }
        $this->advisoryLock("lookahead-event:{$event->organizationId}:{$event->idempotencyKey}");
        $existing = DB::table('lookahead_readiness_events')
            ->where('organization_id', $event->organizationId)
            ->where('idempotency_key', $event->idempotencyKey)
            ->lockForUpdate()
            ->first(['event_id as id', 'evidence_hash as content_hash', 'authorization_decision']);
        if ($existing !== null) {
            $originalDecision = $this->decodeJson($existing->authorization_decision);
            if (($originalDecision['actor_id'] ?? null) !== (string) $authorizationDecision->actorId
                || ($originalDecision['permission'] ?? null) !== $authorizationDecision->permission
                || ($originalDecision['organization_id'] ?? null) !== (string) $authorizationDecision->organizationId
                || ($originalDecision['project_id'] ?? null) !== (string) $authorizationDecision->projectId) {
                throw new LogicException('lookahead_readiness_idempotency_conflict');
            }
            $receipt = $this->replayReceipt((object) [
                'id' => $existing->id,
                'revision_number' => 1,
                'content_hash' => $existing->content_hash,
            ], $event->evidenceHash());

            return new SourceWriteReceipt((string) $receipt->entityId, 1, $receipt->contentHash, true);
        }
        if ($event->commitmentTaskId !== null) {
            $sealedAsOf = DB::table('lookahead_readiness_snapshots')
                ->where('commitment_task_id', $event->commitmentTaskId)
                ->max('as_of');
            if ($sealedAsOf !== null && $event->occurredAtUtc() <= (string) $sealedAsOf) {
                throw new LogicException('lookahead_readiness_event_backdated_after_seal');
            }
        }

        $policyId = $this->policyId($event->organizationId, $event->policy->hash());
        $commitment = DB::table('lookahead_commitment_revisions')
            ->where('id', $event->commitmentRevisionId)
            ->lockForUpdate()
            ->first(['schedule_revision_hash', 'published_at', 'readiness_policy_version_id', 'policy_hash']);
        if ($commitment === null
            || $event->occurredAtUtc() < (string) $commitment->published_at
            || ! hash_equals((string) $commitment->policy_hash, $event->policy->hash())
            || $policyId !== (int) $commitment->readiness_policy_version_id) {
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
            'aggregate_id' => $event->aggregateId,
            'payload' => LookaheadReadinessCanonicalJson::encode($event->payload),
            'payload_hash' => $event->payloadHash(),
            'evidence' => $event->evidence === null ? null : LookaheadReadinessCanonicalJson::encode($event->evidence),
            'evidence_hash' => $event->evidenceHash(),
            'prior_event_id' => $event->priorEventId,
            'policy_hash' => $event->policy->hash(),
            'schedule_revision_hash' => $commitment->schedule_revision_hash,
            ...$this->authorizationColumns($authorizationDecision),
            'created_at' => $event->occurredAtUtc(),
        ]);

        return new SourceWriteReceipt($event->eventId, 1, $event->evidenceHash(), false);
    }

    public function materializeReadiness(array $command, AuthorizationDecision $authorizationDecision): SourceWriteReceipt
    {
        $this->assertTransaction();
        $requiredKeys = [
            'organization_id',
            'project_id',
            'schedule_id',
            'commitment_revision_id',
            'commitment_task_id',
            'as_of_utc',
            'actor_id',
            'idempotency_key',
        ];
        if (array_keys($command) !== $requiredKeys
            || $authorizationDecision->permission !== LookaheadReadinessAbility::SEAL_EVALUATION
            || $authorizationDecision->actorId !== $command['actor_id']
            || $authorizationDecision->organizationId !== $command['organization_id']
            || $authorizationDecision->projectId !== $command['project_id']) {
            throw new LogicException('lookahead_readiness_materialization_command_invalid');
        }

        $commandHash = LookaheadReadinessCanonicalJson::hash($command);
        $this->advisoryLock("lookahead-task-event-stream:{$command['organization_id']}:{$command['commitment_task_id']}");
        $this->advisoryLock("lookahead-materialization:{$command['organization_id']}:{$command['commitment_task_id']}:{$command['idempotency_key']}");
        $existing = DB::table('lookahead_readiness_snapshots')
            ->where('organization_id', $command['organization_id'])
            ->where('idempotency_key', $command['idempotency_key'])
            ->lockForUpdate()
            ->first(['id', 'snapshot_revision as revision_number', 'snapshot_hash as content_hash', 'command_hash']);
        if ($existing !== null) {
            if (! hash_equals((string) $existing->command_hash, $commandHash)) {
                throw new LogicException('lookahead_readiness_idempotency_conflict');
            }

            return new SourceWriteReceipt(
                (int) $existing->id,
                (int) $existing->revision_number,
                (string) $existing->content_hash,
                true,
            );
        }

        $asOf = new DateTimeImmutable($command['as_of_utc']);
        $commitment = DB::table('lookahead_commitment_revisions as commitment')
            ->join('schedule_plan_revisions as schedule_revision', 'schedule_revision.id', '=', 'commitment.schedule_plan_revision_id')
            ->join('lookahead_readiness_policy_versions as policy', 'policy.id', '=', 'commitment.readiness_policy_version_id')
            ->where('commitment.id', $command['commitment_revision_id'])
            ->where('commitment.organization_id', $command['organization_id'])
            ->where('commitment.project_id', $command['project_id'])
            ->where('commitment.schedule_id', $command['schedule_id'])
            ->lock('FOR UPDATE OF commitment, schedule_revision, policy')
            ->first([
                'commitment.id',
                'commitment.content_hash',
                'commitment.policy_hash',
                'commitment.schedule_revision_hash',
                'commitment.published_at',
                'commitment.readiness_policy_version_id',
                'schedule_revision.approved_at as schedule_approved_at',
                'policy.canonical_definition',
                'policy.policy_hash as pinned_policy_hash',
                'policy.published_at as policy_published_at',
                'policy.effective_from as policy_effective_from',
                'policy.effective_until as policy_effective_until',
            ]);
        if ($commitment === null
            || (string) $commitment->policy_hash !== (string) $commitment->pinned_policy_hash
            || new DateTimeImmutable((string) $commitment->schedule_approved_at) > new DateTimeImmutable((string) $commitment->published_at)
            || new DateTimeImmutable((string) $commitment->policy_published_at) > new DateTimeImmutable((string) $commitment->published_at)
            || new DateTimeImmutable((string) $commitment->policy_effective_from) > new DateTimeImmutable((string) $commitment->published_at)
            || ($commitment->policy_effective_until !== null
                && new DateTimeImmutable((string) $commitment->published_at) >= new DateTimeImmutable((string) $commitment->policy_effective_until))
            || new DateTimeImmutable((string) $commitment->published_at) > $asOf) {
            throw new LogicException('lookahead_readiness_materialization_lineage_invalid');
        }
        $commitmentState = DB::table('lookahead_commitment_lifecycle_events')
            ->where('commitment_revision_id', $command['commitment_revision_id'])
            ->orderByDesc('sequence')
            ->lockForUpdate()
            ->value('to_state');
        if ($commitmentState !== 'published') {
            throw new LogicException('lookahead_readiness_materialization_lineage_invalid');
        }

        $task = DB::table('lookahead_commitment_tasks as commitment_task')
            ->join('schedule_plan_revision_tasks as schedule_task', 'schedule_task.id', '=', 'commitment_task.schedule_plan_revision_task_id')
            ->where('commitment_task.id', $command['commitment_task_id'])
            ->where('commitment_task.commitment_revision_id', $command['commitment_revision_id'])
            ->where('commitment_task.organization_id', $command['organization_id'])
            ->where('commitment_task.project_id', $command['project_id'])
            ->where('commitment_task.schedule_id', $command['schedule_id'])
            ->lock('FOR UPDATE OF commitment_task, schedule_task')
            ->first(['commitment_task.id', 'schedule_task.task_class']);
        if ($task === null) {
            throw new LogicException('lookahead_readiness_materialization_lineage_invalid');
        }

        $policy = ReadinessPolicyDefinition::fromCanonicalDefinition(
            $this->decodeJson($commitment->canonical_definition),
        );
        if (! hash_equals($policy->hash(), (string) $commitment->policy_hash)) {
            throw new LogicException('lookahead_readiness_policy_pin_missing');
        }

        $events = DB::table('lookahead_readiness_events')
            ->where('organization_id', $command['organization_id'])
            ->where('project_id', $command['project_id'])
            ->where('schedule_id', $command['schedule_id'])
            ->where('commitment_revision_id', $command['commitment_revision_id'])
            ->where('commitment_task_id', $command['commitment_task_id'])
            ->where('readiness_policy_version_id', $commitment->readiness_policy_version_id)
            ->where('policy_hash', $commitment->policy_hash)
            ->where('occurred_at', '<=', $command['as_of_utc'])
            ->orderBy('occurred_at')
            ->orderBy('event_id')
            ->lockForUpdate()
            ->get([
                'event_id',
                'event_type',
                'aggregate_id',
                'prior_event_id',
                'occurred_at',
                'payload',
                'evidence',
                'authorization_decision',
            ])
            ->map(fn (object $event): array => [
                'event_id' => (string) $event->event_id,
                'event_type' => (string) $event->event_type,
                'aggregate_id' => (string) $event->aggregate_id,
                'prior_event_id' => $event->prior_event_id === null ? null : (string) $event->prior_event_id,
                'occurred_at_utc' => (new DateTimeImmutable((string) $event->occurred_at))
                    ->setTimezone(new DateTimeZone('UTC'))
                    ->format('Y-m-d\TH:i:s.u\Z'),
                'payload' => $this->decodeJson($event->payload),
                'evidence' => $event->evidence === null ? null : $this->decodeJson($event->evidence),
                'authorization_decision' => $this->decodeJson($event->authorization_decision),
            ])
            ->all();

        $projection = $this->eventStateMachine->project(
            $policy,
            (string) $task->task_class,
            $asOf,
            $events,
            (string) $commitment->schedule_revision_hash,
        );
        $evaluation = $this->policyEvaluator->evaluate(
            $policy,
            (string) $task->task_class,
            $asOf,
            array_values($projection->componentsByCategory),
            (string) $commitment->policy_hash,
            (string) $commitment->schedule_revision_hash,
        );

        $this->advisoryLock("lookahead-snapshot-revision:{$command['organization_id']}:{$command['commitment_task_id']}");
        $previous = DB::table('lookahead_readiness_snapshots')
            ->where('commitment_task_id', $command['commitment_task_id'])
            ->orderByDesc('snapshot_revision')
            ->lockForUpdate()
            ->first(['id', 'snapshot_revision']);
        $snapshotRevision = $previous === null ? 1 : ((int) $previous->snapshot_revision) + 1;
        $evaluationEventIdempotency = $command['idempotency_key'].':readiness-evaluated';
        $evaluationEventId = LookaheadReadinessEventIdentity::fromIdempotency(
            $command['organization_id'],
            $evaluationEventIdempotency,
        );
        $evaluationEvent = ReadinessEvent::fromArray([
            'event_id' => $evaluationEventId,
            'idempotency_key' => $evaluationEventIdempotency,
            'organization_id' => $command['organization_id'],
            'project_id' => $command['project_id'],
            'schedule_id' => $command['schedule_id'],
            'commitment_revision_id' => $command['commitment_revision_id'],
            'commitment_task_id' => $command['commitment_task_id'],
            'event_type' => ReadinessEventType::READINESS_EVALUATED->value,
            'occurred_at' => $authorizationDecision->decidedAtUtc,
            'actor_id' => $command['actor_id'],
            'aggregate_id' => "evaluation:{$command['commitment_task_id']}:{$snapshotRevision}",
            'payload' => [
                'as_of_utc' => $command['as_of_utc'],
                'component_outcomes' => $evaluation->componentOutcomes,
                'consumed_event_ids' => $projection->consumedEventIds,
                'policy_hash' => $commitment->policy_hash,
                'reason_codes' => $evaluation->reasonCodes,
                'schedule_revision_hash' => $commitment->schedule_revision_hash,
                'source_watermark' => $projection->sourceWatermark,
                'state' => $evaluation->state->value,
            ],
            'evidence' => null,
            'prior_event_id' => null,
        ], $policy);
        $this->appendEvent($evaluationEvent, $authorizationDecision);

        $snapshot = $this->snapshotFactory->seal([
            'organization_id' => $command['organization_id'],
            'project_id' => $command['project_id'],
            'schedule_id' => $command['schedule_id'],
            'commitment_revision_id' => $command['commitment_revision_id'],
            'commitment_task_id' => $command['commitment_task_id'],
            'snapshot_revision' => $snapshotRevision,
            'policy_id' => (int) $commitment->readiness_policy_version_id,
            'policy_hash' => (string) $commitment->policy_hash,
            'schedule_revision_hash' => (string) $commitment->schedule_revision_hash,
            'commitment_revision_hash' => (string) $commitment->content_hash,
            'source_watermark' => $projection->sourceWatermark,
            'blocker_event_ids' => $projection->blockerEventIds,
            'waiver_event_ids' => $projection->waiverEventIds,
            'evaluation_event_id' => $evaluationEventId,
            'sealed_by_actor_id' => $authorizationDecision->actorId,
            'authorization_decision_hash' => $authorizationDecision->decisionHash,
            'as_of_utc' => $command['as_of_utc'],
        ], $evaluation, new DateTimeImmutable($authorizationDecision->decidedAtUtc), null);

        $snapshotId = (int) DB::table('lookahead_readiness_snapshots')->insertGetId([
            'organization_id' => $snapshot->organizationId,
            'project_id' => $snapshot->projectId,
            'schedule_id' => $snapshot->scheduleId,
            'commitment_revision_id' => $snapshot->commitmentRevisionId,
            'commitment_task_id' => $snapshot->commitmentTaskId,
            'readiness_policy_version_id' => $commitment->readiness_policy_version_id,
            'snapshot_revision' => $snapshot->snapshotRevision,
            'predecessor_snapshot_id' => $previous?->id,
            'state' => $snapshot->state->value,
            'component_outcomes' => LookaheadReadinessCanonicalJson::encode($snapshot->componentOutcomes),
            'reason_codes' => LookaheadReadinessCanonicalJson::encode($snapshot->reasonCodes),
            'blocker_event_ids' => LookaheadReadinessCanonicalJson::encode($snapshot->blockerEventIds),
            'waiver_event_ids' => LookaheadReadinessCanonicalJson::encode($snapshot->waiverEventIds),
            'policy_hash' => $snapshot->policyHash,
            'schedule_revision_hash' => $snapshot->scheduleRevisionHash,
            'commitment_revision_hash' => $snapshot->commitmentRevisionHash,
            'as_of' => $snapshot->asOfUtc,
            'calculated_at' => $snapshot->calculatedAtUtc,
            'source_watermark' => $snapshot->sourceWatermark,
            'evaluation_event_id' => $snapshot->evaluationEventId,
            'sealed_by_actor_id' => $snapshot->sealedByActorId,
            'actual_comparison' => null,
            'readiness_hash' => $snapshot->readinessHash,
            'snapshot_hash' => $snapshot->snapshotHash,
            'command_hash' => $commandHash,
            'idempotency_key' => $command['idempotency_key'],
            ...$this->authorizationColumns($authorizationDecision),
            'created_at' => $snapshot->calculatedAtUtc,
        ]);

        return new SourceWriteReceipt($snapshotId, $snapshotRevision, $snapshot->snapshotHash, false);
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

    private function authorizationColumns(AuthorizationDecision $decision): array
    {
        return [
            'authorization_decision' => LookaheadReadinessCanonicalJson::encode($decision->canonicalSnapshot()),
            'authorization_decision_hash' => $decision->decisionHash,
        ];
    }

    private function assertAuthorizationDecision(
        AuthorizationDecision $decision,
        int $actorId,
        string $permission,
        int $organizationId,
        int $projectId,
    ): void {
        if ($decision->actorId !== $actorId
            || $decision->permission !== $permission
            || $decision->organizationId !== $organizationId
            || $decision->projectId !== $projectId
            || ! hash_equals(
                LookaheadReadinessCanonicalJson::hash($decision->canonicalSnapshot()),
                $decision->decisionHash,
            )) {
            throw new LogicException('lookahead_readiness_authorization_decision_mismatch');
        }

        $this->assertCurrentSystemRoleDefinitions($decision);
    }

    private function assertCurrentSystemRoleDefinitions(AuthorizationDecision $decision): void
    {
        foreach ($decision->grantingAssignments as $grant) {
            if (($grant['role_type'] ?? null) !== UserRoleAssignment::TYPE_SYSTEM) {
                continue;
            }

            $roleSlug = $grant['role_slug'] ?? null;
            $pinnedDefinition = $grant['role_definition'] ?? null;
            $pinnedHash = $grant['role_definition_hash'] ?? null;
            if (! is_string($roleSlug)
                || ! is_array($pinnedDefinition)
                || ! is_string($pinnedHash)) {
                throw new LogicException('lookahead_readiness_authorization_decision_mismatch');
            }

            $currentDefinition = $this->roleScanner->getRoleUncached($roleSlug);
            if (! is_array($currentDefinition)
                || ! hash_equals(
                    LookaheadReadinessCanonicalJson::hash($currentDefinition),
                    $pinnedHash,
                )) {
                throw new LogicException('lookahead_readiness_system_role_definition_revoked');
            }
        }
    }

    private function eventPermission(ReadinessEventType $eventType): string
    {
        return match ($eventType) {
            ReadinessEventType::READINESS_EVALUATED => LookaheadReadinessAbility::SEAL_EVALUATION,
            ReadinessEventType::WAIVER_APPROVED,
            ReadinessEventType::WAIVER_REJECTED,
            ReadinessEventType::WAIVER_REVOKED,
            ReadinessEventType::WAIVER_EXPIRED => LookaheadReadinessAbility::APPROVE_WAIVER,
            ReadinessEventType::COMMITMENT_PUBLISHED,
            ReadinessEventType::COMMITMENT_SUPERSEDED,
            ReadinessEventType::COMMITMENT_WITHDRAWN => LookaheadReadinessAbility::PUBLISH_COMMITMENT,
            default => LookaheadReadinessAbility::MANAGE_CONSTRAINTS,
        };
    }

    private function decodeJson(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        $decoded = json_decode((string) $value, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($decoded)) {
            throw new LogicException('lookahead_readiness_json_invalid');
        }

        return $decoded;
    }
}
