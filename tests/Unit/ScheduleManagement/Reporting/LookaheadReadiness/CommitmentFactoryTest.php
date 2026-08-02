<?php

declare(strict_types=1);

namespace Tests\Unit\ScheduleManagement\Reporting\LookaheadReadiness;

use App\BusinessModules\Features\ScheduleManagement\Reporting\LookaheadReadiness\DTO\CommitmentDraft;
use App\BusinessModules\Features\ScheduleManagement\Reporting\LookaheadReadiness\DTO\ReadinessPolicyDefinition;
use App\BusinessModules\Features\ScheduleManagement\Reporting\LookaheadReadiness\DTO\ScheduleRevisionDraft;
use App\BusinessModules\Features\ScheduleManagement\Reporting\LookaheadReadiness\Services\CommitmentFactory;
use App\BusinessModules\Features\ScheduleManagement\Reporting\LookaheadReadiness\Services\ScheduleRevisionFactory;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class CommitmentFactoryTest extends TestCase
{
    public function test_published_commitment_pins_schedule_policy_window_timezone_and_task_snapshot(): void
    {
        $schedule = ScheduleRevisionDraft::fromArray($this->schedule());
        $policy = ReadinessPolicyDefinition::v1(10);
        $draft = CommitmentDraft::fromArray([
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
        ]);

        $commitment = (new CommitmentFactory)->publish(
            $draft,
            $schedule,
            (new ScheduleRevisionFactory)->contentHash($schedule),
            $policy,
            actorId: 9,
            publishedAt: new DateTimeImmutable('2026-08-05T08:00:00+03:00'),
        );

        self::assertSame($policy->hash(), $commitment->policyHash);
        self::assertSame('Europe/Moscow', $commitment->planningTimezone);
        self::assertSame('task-a', $commitment->tasks[0]['schedule_task_external_id']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $commitment->contentHash);
    }

    public function test_rejects_task_outside_window_or_not_in_pinned_schedule_revision(): void
    {
        $schedule = ScheduleRevisionDraft::fromArray($this->schedule());
        $draft = CommitmentDraft::fromArray([
            'organization_id' => 10,
            'project_id' => 20,
            'schedule_id' => 40,
            'window_start' => '2026-08-10',
            'window_end' => '2026-08-16',
            'planning_timezone' => 'Europe/Moscow',
            'tasks' => [[
                'schedule_task_external_id' => 'missing',
                'committed_start' => '2026-08-18',
                'committed_end' => '2026-08-19',
                'planned_quantity' => null,
                'planned_work_hours' => null,
                'responsible_role' => null,
                'responsible_user_id' => null,
                'inclusion_reason' => 'manual',
            ]],
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('lookahead_readiness_commitment_task_invalid');

        (new CommitmentFactory)->publish(
            $draft,
            $schedule,
            (new ScheduleRevisionFactory)->contentHash($schedule),
            ReadinessPolicyDefinition::v1(10),
            9,
            new DateTimeImmutable('2026-08-05T08:00:00+03:00'),
        );
    }

    public function test_rejects_task_whose_end_is_outside_the_closed_commitment_window(): void
    {
        $schedule = ScheduleRevisionDraft::fromArray($this->schedule());
        $draft = CommitmentDraft::fromArray([
            'organization_id' => 10,
            'project_id' => 20,
            'schedule_id' => 40,
            'window_start' => '2026-08-10',
            'window_end' => '2026-08-16',
            'planning_timezone' => 'Europe/Moscow',
            'tasks' => [[
                'schedule_task_external_id' => 'task-a',
                'committed_start' => '2026-08-16',
                'committed_end' => '2026-12-31',
                'planned_quantity' => '2.0000',
                'planned_work_hours' => '16.0000',
                'responsible_role' => null,
                'responsible_user_id' => null,
                'inclusion_reason' => 'starts_in_window',
            ]],
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('lookahead_readiness_commitment_task_invalid');

        (new CommitmentFactory)->publish(
            $draft,
            $schedule,
            (new ScheduleRevisionFactory)->contentHash($schedule),
            ReadinessPolicyDefinition::v1(10),
            9,
            new DateTimeImmutable('2026-08-05T08:00:00+03:00'),
        );
    }

    private function schedule(): array
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
