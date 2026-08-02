<?php

declare(strict_types=1);

namespace Tests\Unit\ScheduleManagement\Reporting\LookaheadReadiness;

use App\BusinessModules\Features\ScheduleManagement\Reporting\LookaheadReadiness\Contracts\LookaheadReadinessAuthorizer;
use App\BusinessModules\Features\ScheduleManagement\Reporting\LookaheadReadiness\Contracts\LookaheadReadinessSourceStore;
use App\BusinessModules\Features\ScheduleManagement\Reporting\LookaheadReadiness\DTO\AuthorizationDecision;
use App\BusinessModules\Features\ScheduleManagement\Reporting\LookaheadReadiness\DTO\CommitmentDraft;
use App\BusinessModules\Features\ScheduleManagement\Reporting\LookaheadReadiness\DTO\ReadinessEvent;
use App\BusinessModules\Features\ScheduleManagement\Reporting\LookaheadReadiness\DTO\ReadinessPolicyDefinition;
use App\BusinessModules\Features\ScheduleManagement\Reporting\LookaheadReadiness\DTO\ScheduleRevisionDraft;
use App\BusinessModules\Features\ScheduleManagement\Reporting\LookaheadReadiness\DTO\SourceWriteReceipt;
use App\BusinessModules\Features\ScheduleManagement\Reporting\LookaheadReadiness\Enums\ReadinessEventType;
use App\BusinessModules\Features\ScheduleManagement\Reporting\LookaheadReadiness\Services\LookaheadReadinessAbility;
use App\BusinessModules\Features\ScheduleManagement\Reporting\LookaheadReadiness\Services\LookaheadReadinessCanonicalJson;
use App\BusinessModules\Features\ScheduleManagement\Reporting\LookaheadReadiness\Services\LookaheadReadinessOwnerWriter;
use App\BusinessModules\Features\ScheduleManagement\Reporting\LookaheadReadiness\Services\ScheduleRevisionFactory;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class LookaheadReadinessOwnerWriterTest extends TestCase
{
    public function test_schedule_approval_is_atomic_authorized_and_exactly_idempotent(): void
    {
        $store = new InMemoryLookaheadReadinessSourceStore;
        $authorizer = new RecordingLookaheadReadinessAuthorizer;
        $writer = new LookaheadReadinessOwnerWriter($store, $authorizer, new ScheduleRevisionFactory);
        $draft = ScheduleRevisionDraft::fromArray($this->scheduleDraft());
        $at = new DateTimeImmutable('2026-08-05T08:00:00+03:00');

        $first = $writer->approveScheduleRevision($draft, 9, $at, 'schedule-40-v7-approved');
        $replay = $writer->approveScheduleRevision($draft, 9, $at, 'schedule-40-v7-approved');

        self::assertSame($first->entityId, $replay->entityId);
        self::assertFalse($first->replay);
        self::assertTrue($replay->replay);
        self::assertSame(1, count($store->scheduleRevisions));
        self::assertSame([
            [9, LookaheadReadinessAbility::APPROVE_SCHEDULE_REVISION, 10, 20],
            [9, LookaheadReadinessAbility::APPROVE_SCHEDULE_REVISION, 10, 20],
        ], $authorizer->checks);
    }

    public function test_conflicting_idempotency_payload_rolls_back_without_partial_revision(): void
    {
        $store = new InMemoryLookaheadReadinessSourceStore;
        $writer = new LookaheadReadinessOwnerWriter(
            $store,
            new RecordingLookaheadReadinessAuthorizer,
            new ScheduleRevisionFactory,
        );
        $first = ScheduleRevisionDraft::fromArray($this->scheduleDraft());
        $changed = $this->scheduleDraft();
        $changed['tasks'][0]['planned_work_hours'] = '17.0000';
        $second = ScheduleRevisionDraft::fromArray($changed);

        $writer->approveScheduleRevision(
            $first,
            9,
            new DateTimeImmutable('2026-08-05T08:00:00+03:00'),
            'schedule-40-v7-approved',
        );

        try {
            $writer->approveScheduleRevision(
                $second,
                9,
                new DateTimeImmutable('2026-08-05T08:00:00+03:00'),
                'schedule-40-v7-approved',
            );
            self::fail('Conflicting replay must fail.');
        } catch (RuntimeException $exception) {
            self::assertSame('lookahead_readiness_idempotency_conflict', $exception->getMessage());
        }

        self::assertSame(1, count($store->scheduleRevisions));
    }

    public function test_commitment_publication_atomically_appends_the_required_lifecycle_event(): void
    {
        $store = new InMemoryLookaheadReadinessSourceStore;
        $authorizer = new RecordingLookaheadReadinessAuthorizer;
        $writer = new LookaheadReadinessOwnerWriter($store, $authorizer, new ScheduleRevisionFactory);
        $schedule = ScheduleRevisionDraft::fromArray($this->scheduleDraft());
        $policy = ReadinessPolicyDefinition::v1(10);

        $receipt = $writer->publishCommitment(
            CommitmentDraft::fromArray([
                'organization_id' => 10,
                'project_id' => 20,
                'schedule_id' => 40,
                'window_start' => '2026-08-10',
                'window_end' => '2026-08-16',
                'planning_timezone' => 'Europe/Moscow',
                'tasks' => [[
                    'schedule_task_external_id' => 'task-a',
                    'committed_start' => '2026-08-10',
                    'committed_end' => '2026-08-11',
                    'planned_quantity' => '2.0000',
                    'planned_work_hours' => '16.0000',
                    'responsible_role' => 'site_manager',
                    'responsible_user_id' => 77,
                    'inclusion_reason' => 'starts_in_window',
                ]],
            ]),
            $schedule,
            11,
            (new ScheduleRevisionFactory)->contentHash($schedule),
            $policy,
            21,
            9,
            new DateTimeImmutable('2026-08-05T08:00:00+03:00'),
            'commitment-40-v1',
        );

        self::assertSame(31, $receipt->entityId);
        self::assertCount(1, $store->events);
        self::assertSame(ReadinessEventType::COMMITMENT_PUBLISHED, $store->events[0]->eventType);
        self::assertNull($store->events[0]->commitmentTaskId);
        self::assertSame('commitment-40-v1:published-event', $store->events[0]->idempotencyKey);
        self::assertSame([
            [9, LookaheadReadinessAbility::PUBLISH_COMMITMENT, 10, 20],
        ], $authorizer->checks);
    }

    public function test_authorization_decision_is_made_inside_the_write_transaction(): void
    {
        $store = new InMemoryLookaheadReadinessSourceStore;
        $authorizer = new RecordingLookaheadReadinessAuthorizer($store);
        $writer = new LookaheadReadinessOwnerWriter($store, $authorizer, new ScheduleRevisionFactory);

        $writer->approveScheduleRevision(
            ScheduleRevisionDraft::fromArray($this->scheduleDraft()),
            9,
            new DateTimeImmutable('2026-08-05T08:00:00+03:00'),
            'schedule-40-v7-approved',
        );

        self::assertSame([true], $authorizer->transactionStates);
    }

    public function test_readiness_evaluation_requires_seal_permission_and_cannot_use_constraint_permission(): void
    {
        $store = new InMemoryLookaheadReadinessSourceStore;
        $authorizer = new RecordingLookaheadReadinessAuthorizer;
        $writer = new LookaheadReadinessOwnerWriter($store, $authorizer, new ScheduleRevisionFactory);
        $event = ReadinessEvent::fromArray([
            'event_id' => '018f6f5a-4ca2-7a11-bf61-0242ac120002',
            'idempotency_key' => 'evaluation-60-v1',
            'organization_id' => 10,
            'project_id' => 20,
            'schedule_id' => 40,
            'commitment_revision_id' => 50,
            'commitment_task_id' => 60,
            'event_type' => ReadinessEventType::READINESS_EVALUATED->value,
            'occurred_at' => '2026-08-10T09:00:00+03:00',
            'actor_id' => 9,
            'aggregate_id' => 'evaluation:60:1',
            'payload' => [
                'as_of_utc' => '2026-08-10T06:00:00.000000Z',
                'policy_hash' => ReadinessPolicyDefinition::v1(10)->hash(),
                'schedule_revision_hash' => str_repeat('a', 64),
                'state' => 'unknown',
                'component_outcomes' => [],
            ],
            'evidence' => null,
            'prior_event_id' => null,
        ], ReadinessPolicyDefinition::v1(10));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('lookahead_readiness_owner_materialization_required');

        $writer->appendEvent($event);
    }

    public function test_materialization_owner_accepts_only_identity_as_of_actor_and_idempotency(): void
    {
        $store = new InMemoryLookaheadReadinessSourceStore;
        $authorizer = new RecordingLookaheadReadinessAuthorizer($store);
        $writer = new LookaheadReadinessOwnerWriter($store, $authorizer, new ScheduleRevisionFactory);

        self::assertTrue(
            method_exists($writer, 'materializeReadiness'),
            'The source owner must expose the event-sourced materialization command.',
        );

        $receipt = $writer->materializeReadiness(
            10,
            20,
            40,
            50,
            60,
            new DateTimeImmutable('2026-08-10T09:00:00+03:00'),
            9,
            'evaluation-60-v1',
        );

        self::assertSame(71, $receipt->entityId);
        self::assertSame([true], $authorizer->transactionStates);
        self::assertSame(LookaheadReadinessAbility::SEAL_EVALUATION, $authorizer->checks[0][1]);
        self::assertSame([
            'organization_id' => 10,
            'project_id' => 20,
            'schedule_id' => 40,
            'commitment_revision_id' => 50,
            'commitment_task_id' => 60,
            'as_of_utc' => '2026-08-10T06:00:00.000000Z',
            'actor_id' => 9,
            'idempotency_key' => 'evaluation-60-v1',
        ], $store->materializationCommands[0]);
    }

    public function test_caller_supplied_snapshot_has_no_owner_write_path(): void
    {
        $writer = new LookaheadReadinessOwnerWriter(
            new InMemoryLookaheadReadinessSourceStore,
            new RecordingLookaheadReadinessAuthorizer,
            new ScheduleRevisionFactory,
        );

        self::assertFalse(method_exists($writer, 'sealSnapshot'));
    }

    private function scheduleDraft(): array
    {
        return [
            'organization_id' => 10,
            'project_id' => 20,
            'schedule_id' => 40,
            'planning_timezone' => 'Europe/Moscow',
            'calendar' => [
                'calendar_id' => 'calendar-2026-v1',
                'calendar_hash' => str_repeat('c', 64),
                'working_weekdays' => [1, 2, 3, 4, 5],
            ],
            'expected_source_watermark' => 'schedule:40:v7',
            'observed_source_watermark' => 'schedule:40:v7',
            'tasks' => [[
                'external_id' => 'task-a',
                'source_task_id' => 101,
                'wbs_code' => '1.1',
                'name' => 'Task A',
                'task_class' => 'standard',
                'planned_start' => '2026-08-10',
                'planned_end' => '2026-08-11',
                'duration_minutes' => 960,
                'planned_quantity' => '2.0000',
                'planned_work_hours' => '16.0000',
                'critical' => true,
                'constraint_point' => 'finish',
                'parent_external_id' => null,
            ]],
            'dependencies' => [],
        ];
    }
}

final class RecordingLookaheadReadinessAuthorizer implements LookaheadReadinessAuthorizer
{
    public array $checks = [];

    public array $transactionStates = [];

    public function __construct(private readonly ?InMemoryLookaheadReadinessSourceStore $store = null) {}

    public function authorize(
        int $actorId,
        string $permission,
        int $organizationId,
        int $projectId,
    ): AuthorizationDecision {
        $this->checks[] = [$actorId, $permission, $organizationId, $projectId];
        if ($this->store !== null) {
            $this->transactionStates[] = $this->store->transactionDepth > 0;
        }

        $contextFactors = [
            'organization_membership' => [
                'organization_id' => (string) $organizationId,
                'project_access_mode' => 'all_projects',
                'updated_at' => '2026-08-10T05:59:00.000000Z',
            ],
            'project_membership' => null,
            'context_ids' => ['1', '2'],
        ];
        $roleDefinition = [
            'module_permissions' => [],
            'slug' => 'project_manager',
            'system_permissions' => [$permission],
        ];
        $grants = [[
            'assignment_id' => '100',
            'context_id' => '2',
            'matched_permission' => $permission,
            'role_definition' => $roleDefinition,
            'role_definition_hash' => LookaheadReadinessCanonicalJson::hash($roleDefinition),
            'role_slug' => 'project_manager',
            'role_type' => 'system',
            'assignment_updated_at' => '2026-08-10T05:59:00.000000Z',
            'conditions_hash' => str_repeat('d', 64),
        ]];

        return new AuthorizationDecision(
            $actorId,
            $permission,
            $organizationId,
            $projectId,
            LookaheadReadinessCanonicalJson::hash($grants),
            LookaheadReadinessCanonicalJson::hash([
                'context_factors' => $contextFactors,
                'granting_assignments' => $grants,
                'permission' => $permission,
            ]),
            ['project_manager'],
            '2026-08-10T06:00:00.000000Z',
            $contextFactors,
            $grants,
        );
    }
}

final class InMemoryLookaheadReadinessSourceStore implements LookaheadReadinessSourceStore
{
    public int $transactionDepth = 0;

    public array $scheduleRevisions = [];

    public array $events = [];

    public array $materializationCommands = [];

    private array $idempotency = [];

    public function transaction(callable $operation): mixed
    {
        $before = $this->scheduleRevisions;
        $beforeIdempotency = $this->idempotency;

        try {
            $this->transactionDepth++;

            return $operation();
        } catch (\Throwable $exception) {
            $this->scheduleRevisions = $before;
            $this->idempotency = $beforeIdempotency;
            throw $exception;
        } finally {
            $this->transactionDepth--;
        }
    }

    public function approveScheduleRevision(
        ScheduleRevisionDraft $draft,
        string $contentHash,
        AuthorizationDecision $authorizationDecision,
        string $approvedAtUtc,
        string $idempotencyKey,
    ): SourceWriteReceipt {
        if (isset($this->idempotency[$idempotencyKey])) {
            $existing = $this->idempotency[$idempotencyKey];
            if ($existing['hash'] !== $contentHash) {
                throw new RuntimeException('lookahead_readiness_idempotency_conflict');
            }

            return new SourceWriteReceipt($existing['id'], 1, $contentHash, true);
        }

        $id = count($this->scheduleRevisions) + 1;
        $this->scheduleRevisions[] = [$draft, $contentHash, $authorizationDecision->actorId, $approvedAtUtc];
        $this->idempotency[$idempotencyKey] = ['id' => $id, 'hash' => $contentHash];

        return new SourceWriteReceipt($id, 1, $contentHash, false);
    }

    public function publishPolicy(
        ReadinessPolicyDefinition $policy,
        AuthorizationDecision $authorizationDecision,
        string $idempotencyKey,
    ): SourceWriteReceipt {
        throw new RuntimeException('not_used');
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
        return new SourceWriteReceipt(41, 3, str_repeat('f', 64), false);
    }

    public function publishCommitment(
        \App\BusinessModules\Features\ScheduleManagement\Reporting\LookaheadReadiness\DTO\PublishedCommitment $commitment,
        int $scheduleRevisionId,
        int $policyId,
        AuthorizationDecision $authorizationDecision,
        string $idempotencyKey,
    ): SourceWriteReceipt {
        return new SourceWriteReceipt(31, 1, $commitment->contentHash, false);
    }

    public function appendEvent(ReadinessEvent $event, AuthorizationDecision $authorizationDecision): SourceWriteReceipt
    {
        $this->events[] = $event;

        return new SourceWriteReceipt($event->eventId, 1, $event->evidenceHash(), false);
    }

    public function materializeReadiness(array $command, AuthorizationDecision $authorizationDecision): SourceWriteReceipt
    {
        $this->materializationCommands[] = $command;

        return new SourceWriteReceipt(71, 1, str_repeat('c', 64), false);
    }
}
