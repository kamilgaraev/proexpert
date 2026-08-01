<?php

declare(strict_types=1);

namespace Tests\Unit\QualityControl\Reporting\DefectFlow;

use App\BusinessModules\Features\QualityControl\Enums\QualityDefectStatusEnum;
use App\BusinessModules\Features\QualityControl\Reporting\DefectFlow\DTO\QualityDefectFlowEvent;
use App\BusinessModules\Features\QualityControl\Reporting\DefectFlow\DTO\QualityDefectFlowPolicyDefinition;
use App\BusinessModules\Features\QualityControl\Reporting\DefectFlow\DTO\QualityDefectFlowSnapshot;
use App\BusinessModules\Features\QualityControl\Reporting\DefectFlow\Enums\QualityDefectFlowEventKind;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class QualityDefectFlowEventTest extends TestCase
{
    public function test_snapshot_is_canonical_and_contains_only_approved_non_sensitive_dimensions(): void
    {
        $left = QualityDefectFlowSnapshot::fromArray($this->snapshot());
        $right = QualityDefectFlowSnapshot::fromArray(array_reverse($this->snapshot(), true));

        self::assertSame($left->canonical(), $right->canonical());
        self::assertSame($left->hash(), $right->hash());
        self::assertSame([
            'assignee_id',
            'contractor_id',
            'due_date',
            'has_due_date',
            'inspection_required',
            'organization_id',
            'project_id',
            'quality_defect_id',
            'schedule_task_id',
            'schema_version',
            'severity',
            'source_link',
        ], array_keys($left->canonical()));
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $left->hash());
    }

    #[DataProvider('sensitiveSnapshotProvider')]
    public function test_snapshot_rejects_sensitive_or_mutable_payload(array $unsafe): void
    {
        $this->expectException(InvalidArgumentException::class);

        QualityDefectFlowSnapshot::fromArray([...$this->snapshot(), ...$unsafe]);
    }

    public static function sensitiveSnapshotProvider(): array
    {
        return [
            'title' => [['title' => 'Leak']],
            'description' => [['description' => 'Leak']],
            'comment' => [['comment' => 'Leak']],
            'photo' => [['photo_url' => 'https://example.test/private']],
            'metadata' => [['metadata' => ['token' => 'secret']]],
            'display name' => [['actor_name' => 'Person']],
        ];
    }

    public function test_event_hashes_stable_source_identity_and_policy_pin(): void
    {
        $event = new QualityDefectFlowEvent(
            eventKind: QualityDefectFlowEventKind::STARTED,
            fromStatus: QualityDefectStatusEnum::ASSIGNED,
            toStatus: QualityDefectStatusEnum::IN_PROGRESS,
            actorId: 14,
            occurredAt: new DateTimeImmutable('2026-08-01T12:30:00.123456+03:00'),
            snapshot: QualityDefectFlowSnapshot::fromArray($this->snapshot()),
            sourceIdentity: [
                'kind' => 'quality_defect_status_history',
                'id' => '91',
            ],
            policy: QualityDefectFlowPolicyDefinition::v1(),
        );

        self::assertSame('2026-08-01T09:30:00.123456Z', $event->occurredAtUtc());
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $event->sourceIdentityHash());
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $event->sourceHash());
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $event->evidenceHash(
            '018f6f5a-4ca2-7a11-bf61-0242ac120002',
            2,
        ));
        self::assertSame(QualityDefectFlowPolicyDefinition::v1()->hash(), $event->policyHash());
    }

    public function test_event_rejects_transition_not_approved_by_pinned_policy(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('quality_defect_flow_transition_not_allowed');

        new QualityDefectFlowEvent(
            eventKind: QualityDefectFlowEventKind::STARTED,
            fromStatus: QualityDefectStatusEnum::RESOLVED,
            toStatus: QualityDefectStatusEnum::IN_PROGRESS,
            actorId: 14,
            occurredAt: new DateTimeImmutable('2026-08-01T09:30:00+00:00'),
            snapshot: QualityDefectFlowSnapshot::fromArray($this->snapshot()),
            sourceIdentity: ['kind' => 'quality_defect_status_history', 'id' => '91'],
            policy: QualityDefectFlowPolicyDefinition::v1(),
        );
    }

    private function snapshot(): array
    {
        return [
            'schema_version' => QualityDefectFlowSnapshot::SCHEMA_VERSION,
            'organization_id' => '10',
            'project_id' => '20',
            'quality_defect_id' => '30',
            'contractor_id' => '40',
            'schedule_task_id' => '50',
            'severity' => 'major',
            'due_date' => '2026-08-10',
            'has_due_date' => true,
            'inspection_required' => true,
            'assignee_id' => '60',
            'source_link' => [
                'classification' => 'acceptance_finding',
                'acceptance_scope_id' => '70',
                'acceptance_session_id' => '80',
            ],
        ];
    }
}
