<?php

declare(strict_types=1);

namespace Tests\Unit\ScheduleManagement\Reporting\LookaheadReadiness;

use App\BusinessModules\Features\ScheduleManagement\Reporting\LookaheadReadiness\DTO\ReadinessPolicyDefinition;
use App\BusinessModules\Features\ScheduleManagement\Reporting\LookaheadReadiness\Services\ReadinessEventStateMachine;
use DateTimeImmutable;
use LogicException;
use PHPUnit\Framework\TestCase;

final class ReadinessEventStateMachineTest extends TestCase
{
    public function test_resolved_constraint_with_evidence_projects_a_satisfied_component_and_exact_watermark(): void
    {
        $events = [
            $this->event('00000000-0000-7000-8000-000000000001', 'constraint_registered', 'constraint:permit:1', null, [
                'category' => 'permit',
                'severity' => 'hard',
                'owner_ref' => 'user:77',
            ]),
            $this->event(
                '00000000-0000-7000-8000-000000000002',
                'constraint_evidence_attached',
                'constraint:permit:1',
                '00000000-0000-7000-8000-000000000001',
                ['category' => 'permit'],
                $this->evidence(),
                '2026-08-05T06:01:00.000000Z',
            ),
            $this->event(
                '00000000-0000-7000-8000-000000000003',
                'constraint_resolved',
                'constraint:permit:1',
                '00000000-0000-7000-8000-000000000002',
                ['category' => 'permit'],
                null,
                '2026-08-05T06:02:00.000000Z',
            ),
        ];

        $projection = (new ReadinessEventStateMachine)->project(
            ReadinessPolicyDefinition::v1(10),
            'standard',
            new DateTimeImmutable('2026-08-10T06:00:00.000000Z'),
            $events,
            str_repeat('a', 64),
        );

        self::assertSame('satisfied', $projection->componentsByCategory['permit']['outcome']);
        self::assertSame(str_repeat('e', 64), $projection->componentsByCategory['permit']['evidence_hash']);
        self::assertSame(array_column($events, 'event_id'), $projection->consumedEventIds);
        self::assertSame(
            hash('sha256', implode("\n", array_column($events, 'event_id'))),
            $projection->sourceWatermark,
        );
    }

    public function test_transition_must_keep_aggregate_category_and_exact_prior_state(): void
    {
        $events = [
            $this->event('00000000-0000-7000-8000-000000000001', 'constraint_registered', 'constraint:permit:1', null, [
                'category' => 'permit',
                'severity' => 'hard',
                'owner_ref' => 'user:77',
            ]),
            $this->event(
                '00000000-0000-7000-8000-000000000002',
                'constraint_resolved',
                'constraint:permit:1',
                '00000000-0000-7000-8000-000000000001',
                ['category' => 'design'],
                null,
                '2026-08-05T06:01:00.000000Z',
            ),
        ];

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('lookahead_readiness_event_transition_invalid');

        (new ReadinessEventStateMachine)->project(
            ReadinessPolicyDefinition::v1(10),
            'standard',
            new DateTimeImmutable('2026-08-10T06:00:00.000000Z'),
            $events,
            str_repeat('a', 64),
        );
    }

    public function test_waiver_projection_requires_canonical_authorization_decision_and_half_open_validity(): void
    {
        $events = [
            $this->event('00000000-0000-7000-8000-000000000001', 'constraint_registered', 'constraint:permit:1', null, [
                'category' => 'permit',
                'severity' => 'hard',
                'owner_ref' => 'user:77',
            ]),
            $this->event('00000000-0000-7000-8000-000000000002', 'waiver_requested', 'waiver:permit:1', null, [
                'category' => 'permit',
                'reason' => 'Awaiting authority response',
            ], null, '2026-08-05T06:01:00.000000Z'),
            $this->event(
                '00000000-0000-7000-8000-000000000003',
                'waiver_approved',
                'waiver:permit:1',
                '00000000-0000-7000-8000-000000000002',
                [
                    'category' => 'permit',
                    'reason' => 'Approved exception',
                    'valid_until' => '2026-08-10T06:00:00.000000Z',
                    'schedule_revision_hash' => str_repeat('a', 64),
                ],
                $this->evidence(),
                '2026-08-05T06:02:00.000000Z',
                ['permission' => 'schedule.readiness.waivers.approve'],
            ),
        ];

        $projection = (new ReadinessEventStateMachine)->project(
            ReadinessPolicyDefinition::v1(10),
            'standard',
            new DateTimeImmutable('2026-08-10T06:00:00.000000Z'),
            $events,
            str_repeat('a', 64),
        );

        self::assertSame('unsatisfied', $projection->componentsByCategory['permit']['outcome']);
        self::assertSame([], $projection->waiverEventIds);
    }

    private function event(
        string $eventId,
        string $type,
        string $aggregateId,
        ?string $priorEventId,
        array $payload,
        ?array $evidence = null,
        string $occurredAt = '2026-08-05T06:00:00.000000Z',
        ?array $authorizationDecision = null,
    ): array {
        return [
            'event_id' => $eventId,
            'event_type' => $type,
            'aggregate_id' => $aggregateId,
            'prior_event_id' => $priorEventId,
            'occurred_at_utc' => $occurredAt,
            'payload' => $payload,
            'evidence' => $evidence,
            'authorization_decision' => $authorizationDecision,
        ];
    }

    private function evidence(): array
    {
        return [
            'type' => 'document',
            'locator' => 'org-10/readiness/permit-1',
            'version' => 'v1',
            'hash' => str_repeat('e', 64),
        ];
    }
}
