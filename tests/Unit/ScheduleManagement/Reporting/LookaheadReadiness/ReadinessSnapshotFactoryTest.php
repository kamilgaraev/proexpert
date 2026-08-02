<?php

declare(strict_types=1);

namespace Tests\Unit\ScheduleManagement\Reporting\LookaheadReadiness;

use App\BusinessModules\Features\ScheduleManagement\Reporting\LookaheadReadiness\DTO\ReadinessEvaluation;
use App\BusinessModules\Features\ScheduleManagement\Reporting\LookaheadReadiness\Enums\ReadinessState;
use App\BusinessModules\Features\ScheduleManagement\Reporting\LookaheadReadiness\Services\ReadinessSnapshotFactory;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class ReadinessSnapshotFactoryTest extends TestCase
{
    public function test_snapshot_is_sealed_from_pinned_evaluation_without_unverified_actual_fact(): void
    {
        $evaluation = new ReadinessEvaluation(
            ReadinessState::BLOCKED,
            [['category' => 'permit', 'outcome' => 'unsatisfied']],
            ['hard_prerequisite_unsatisfied'],
        );
        $factory = new ReadinessSnapshotFactory;
        $base = [
            'organization_id' => 10,
            'project_id' => 20,
            'schedule_id' => 40,
            'commitment_revision_id' => 50,
            'commitment_task_id' => 60,
            'snapshot_revision' => 1,
            'policy_id' => 70,
            'policy_hash' => str_repeat('p', 64),
            'schedule_revision_hash' => str_repeat('s', 64),
            'commitment_revision_hash' => str_repeat('c', 64),
            'source_watermark' => 'events:60:4',
            'blocker_event_ids' => ['018f6f5a-4ca2-7a11-bf61-0242ac120002'],
            'waiver_event_ids' => [],
            'evaluation_event_id' => '018f6f5a-4ca2-7a11-bf61-0242ac120010',
            'sealed_by_actor_id' => 9,
            'authorization_decision_hash' => str_repeat('d', 64),
            'as_of_utc' => '2026-08-05T05:00:00.000000Z',
        ];

        $withoutActual = $factory->seal(
            $base,
            $evaluation,
            new DateTimeImmutable('2026-08-05T08:00:00+03:00'),
            null,
        );
        self::assertSame(ReadinessState::BLOCKED, $withoutActual->state);
        self::assertNull($withoutActual->actualComparison);
        self::assertSame('018f6f5a-4ca2-7a11-bf61-0242ac120010', $withoutActual->evaluationEventId);
        self::assertSame(9, $withoutActual->sealedByActorId);
        self::assertSame(str_repeat('d', 64), $withoutActual->authorizationDecisionHash);
    }

    public function test_rejects_mutable_or_untyped_actual_comparison(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('lookahead_readiness_actual_comparison_invalid');

        (new ReadinessSnapshotFactory)->seal(
            [
                'organization_id' => 10,
                'project_id' => 20,
                'schedule_id' => 40,
                'commitment_revision_id' => 50,
                'commitment_task_id' => 60,
                'snapshot_revision' => 1,
                'policy_id' => 70,
                'policy_hash' => str_repeat('a', 64),
                'schedule_revision_hash' => str_repeat('b', 64),
                'commitment_revision_hash' => str_repeat('c', 64),
                'source_watermark' => 'events:60:4',
                'blocker_event_ids' => [],
                'waiver_event_ids' => [],
                'evaluation_event_id' => '018f6f5a-4ca2-7a11-bf61-0242ac120010',
                'sealed_by_actor_id' => 9,
                'authorization_decision_hash' => str_repeat('d', 64),
                'as_of_utc' => '2026-08-05T05:00:00.000000Z',
            ],
            new ReadinessEvaluation(ReadinessState::UNKNOWN, [], ['missing_source']),
            new DateTimeImmutable('2026-08-05T08:00:00+03:00'),
            ['journal_entry_id' => 91, 'status' => 'accepted'],
        );
    }

    public function test_rejects_unverified_accepted_event_identity_until_an_immutable_actual_source_exists(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('lookahead_readiness_actual_source_unavailable');

        (new ReadinessSnapshotFactory)->seal(
            [
                'organization_id' => 10,
                'project_id' => 20,
                'schedule_id' => 40,
                'commitment_revision_id' => 50,
                'commitment_task_id' => 60,
                'snapshot_revision' => 1,
                'policy_id' => 70,
                'policy_hash' => str_repeat('a', 64),
                'schedule_revision_hash' => str_repeat('b', 64),
                'commitment_revision_hash' => str_repeat('c', 64),
                'source_watermark' => 'events:60:4',
                'blocker_event_ids' => [],
                'waiver_event_ids' => [],
                'evaluation_event_id' => '018f6f5a-4ca2-7a11-bf61-0242ac120010',
                'sealed_by_actor_id' => 9,
                'authorization_decision_hash' => str_repeat('d', 64),
                'as_of_utc' => '2026-08-05T05:00:00.000000Z',
            ],
            new ReadinessEvaluation(ReadinessState::UNKNOWN, [], ['missing_source']),
            new DateTimeImmutable('2026-08-05T08:00:00+03:00'),
            [
                'source_kind' => 'construction_journal_acceptance',
                'accepted_event_id' => '018f6f5a-4ca2-7a11-bf61-0242ac120099',
            ],
        );
    }
}
