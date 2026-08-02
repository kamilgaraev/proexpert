<?php

declare(strict_types=1);

namespace Tests\Unit\ScheduleManagement\Reporting\LookaheadReadiness;

use App\BusinessModules\Features\ScheduleManagement\Reporting\LookaheadReadiness\DTO\ReadinessEvent;
use App\BusinessModules\Features\ScheduleManagement\Reporting\LookaheadReadiness\DTO\ReadinessPolicyDefinition;
use App\BusinessModules\Features\ScheduleManagement\Reporting\LookaheadReadiness\Enums\ReadinessEventType;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ReadinessEvidenceTest extends TestCase
{
    public function test_event_has_exact_utc_identity_lineage_and_canonical_hashes(): void
    {
        $event = ReadinessEvent::fromArray([
            'event_id' => '018f6f5a-4ca2-7a11-bf61-0242ac120002',
            'idempotency_key' => 'constraint-900-created-v1',
            'organization_id' => 10,
            'project_id' => 20,
            'schedule_id' => 40,
            'commitment_revision_id' => 50,
            'commitment_task_id' => 60,
            'event_type' => ReadinessEventType::CONSTRAINT_REGISTERED->value,
            'occurred_at' => '2026-08-05T08:00:00.123456+03:00',
            'actor_id' => 9,
            'aggregate_id' => 'constraint:permit:900',
            'payload' => [
                'category' => 'permit',
                'severity' => 'hard',
                'owner_ref' => 'user:77',
                'due_at' => '2026-08-09T18:00:00+03:00',
            ],
            'evidence' => [
                'type' => 'document',
                'locator' => 'org-10/readiness/permit-900',
                'version' => 'v3',
                'hash' => str_repeat('e', 64),
            ],
            'prior_event_id' => null,
        ], ReadinessPolicyDefinition::v1(10));

        self::assertSame('2026-08-05T05:00:00.123456Z', $event->occurredAtUtc());
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $event->payloadHash());
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $event->evidenceHash());
    }

    public function test_waiver_approval_requires_permission_snapshot_reason_evidence_and_bounded_validity(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('lookahead_readiness_waiver_invalid');

        ReadinessEvent::fromArray([
            'event_id' => '018f6f5a-4ca2-7a11-bf61-0242ac120002',
            'idempotency_key' => 'waiver-900-approved-v1',
            'organization_id' => 10,
            'project_id' => 20,
            'schedule_id' => 40,
            'commitment_revision_id' => 50,
            'commitment_task_id' => 60,
            'event_type' => ReadinessEventType::WAIVER_APPROVED->value,
            'occurred_at' => '2026-08-05T08:00:00+03:00',
            'actor_id' => 9,
            'aggregate_id' => 'waiver:permit:900',
            'payload' => [
                'category' => 'permit',
                'reason' => '',
                'approver_permission' => 'schedule.view',
                'valid_until' => '2027-08-05T08:00:00+03:00',
                'schedule_revision_hash' => str_repeat('a', 64),
            ],
            'evidence' => null,
            'prior_event_id' => '018f6f5a-4ca2-7a11-bf61-0242ac120001',
        ], ReadinessPolicyDefinition::v1(10));
    }

    public function test_event_integrity_hash_changes_for_every_audit_lineage_field(): void
    {
        $base = [
            'event_id' => '018f6f5a-4ca2-7a11-bf61-0242ac120002',
            'idempotency_key' => 'constraint-900-created-v1',
            'organization_id' => 10,
            'project_id' => 20,
            'schedule_id' => 40,
            'commitment_revision_id' => 50,
            'commitment_task_id' => 60,
            'event_type' => ReadinessEventType::CONSTRAINT_REGISTERED->value,
            'occurred_at' => '2026-08-05T08:00:00.123456+03:00',
            'actor_id' => 9,
            'aggregate_id' => 'constraint:permit:900',
            'payload' => ['category' => 'permit', 'severity' => 'hard', 'owner_ref' => 'user:77'],
            'evidence' => null,
            'prior_event_id' => null,
        ];
        $policy = ReadinessPolicyDefinition::v1(10);
        $expected = ReadinessEvent::fromArray($base, $policy)->evidenceHash();

        foreach ([
            'idempotency_key' => 'constraint-900-created-v2',
            'project_id' => 21,
            'schedule_id' => 41,
            'commitment_revision_id' => 51,
            'commitment_task_id' => 61,
            'actor_id' => 10,
        ] as $field => $changedValue) {
            $changed = ReadinessEvent::fromArray([...$base, $field => $changedValue], $policy);

            self::assertNotSame($expected, $changed->evidenceHash(), $field);
        }
    }

    public function test_task_event_requires_a_stable_aggregate_identity(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('lookahead_readiness_event_aggregate_invalid');

        ReadinessEvent::fromArray([
            'event_id' => '018f6f5a-4ca2-7a11-bf61-0242ac120002',
            'idempotency_key' => 'constraint-900-created-v1',
            'organization_id' => 10,
            'project_id' => 20,
            'schedule_id' => 40,
            'commitment_revision_id' => 50,
            'commitment_task_id' => 60,
            'event_type' => ReadinessEventType::CONSTRAINT_REGISTERED->value,
            'occurred_at' => '2026-08-05T08:00:00+03:00',
            'actor_id' => 9,
            'payload' => ['category' => 'permit', 'severity' => 'hard', 'owner_ref' => 'user:77'],
            'evidence' => null,
            'prior_event_id' => null,
        ], ReadinessPolicyDefinition::v1(10));
    }
}
