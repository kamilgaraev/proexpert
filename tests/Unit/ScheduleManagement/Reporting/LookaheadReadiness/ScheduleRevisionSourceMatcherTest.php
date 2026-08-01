<?php

declare(strict_types=1);

namespace Tests\Unit\ScheduleManagement\Reporting\LookaheadReadiness;

use App\BusinessModules\Features\ScheduleManagement\Reporting\LookaheadReadiness\DTO\ScheduleRevisionDraft;
use App\BusinessModules\Features\ScheduleManagement\Reporting\LookaheadReadiness\Services\ScheduleRevisionSourceMatcher;
use LogicException;
use PHPUnit\Framework\TestCase;

final class ScheduleRevisionSourceMatcherTest extends TestCase
{
    public function test_rejects_caller_supplied_planning_fact_that_differs_from_locked_operational_source(): void
    {
        $draft = ScheduleRevisionDraft::fromArray($this->draftData([
            'name' => 'Invented historical name',
        ]));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('lookahead_readiness_schedule_snapshot_mismatch');

        (new ScheduleRevisionSourceMatcher)->assertMatches(
            $draft,
            $this->scheduleRow(),
            [$this->taskRow()],
            [],
        );
    }

    public function test_accepts_complete_snapshot_with_pseudonymous_external_identity_and_exact_planning_facts(): void
    {
        $draft = ScheduleRevisionDraft::fromArray($this->draftData());

        (new ScheduleRevisionSourceMatcher)->assertMatches(
            $draft,
            $this->scheduleRow(),
            [$this->taskRow()],
            [],
        );

        self::addToAssertionCount(1);
    }

    private function draftData(array $taskOverrides = []): array
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
            'expected_source_watermark' => 'schedule:40:'.str_repeat('a', 64),
            'observed_source_watermark' => 'schedule:40:'.str_repeat('a', 64),
            'tasks' => [[
                ...[
                    'external_id' => 'task-a',
                    'source_task_id' => 70,
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
                ],
                ...$taskOverrides,
            ]],
            'dependencies' => [],
        ];
    }

    private function scheduleRow(): array
    {
        return [
            'id' => 40,
            'organization_id' => 10,
            'project_id' => 20,
            'timezone' => 'Europe/Moscow',
        ];
    }

    private function taskRow(): array
    {
        return [
            'id' => 70,
            'parent_task_id' => null,
            'name' => 'Task A',
            'wbs_code' => '1.1',
            'planned_start_date' => '2026-08-10',
            'planned_end_date' => '2026-08-11',
            'planned_work_hours' => '16.0000',
            'quantity' => '2.0000',
            'is_critical' => true,
        ];
    }
}
