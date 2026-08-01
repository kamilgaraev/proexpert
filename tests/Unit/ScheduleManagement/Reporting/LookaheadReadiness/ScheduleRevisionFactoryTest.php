<?php

declare(strict_types=1);

namespace Tests\Unit\ScheduleManagement\Reporting\LookaheadReadiness;

use App\BusinessModules\Features\ScheduleManagement\Reporting\LookaheadReadiness\DTO\ScheduleRevisionDraft;
use App\BusinessModules\Features\ScheduleManagement\Reporting\LookaheadReadiness\Services\ScheduleRevisionFactory;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ScheduleRevisionFactoryTest extends TestCase
{
    public function test_hash_is_deterministic_for_equivalent_task_and_dependency_order(): void
    {
        $first = ScheduleRevisionDraft::fromArray($this->draft());
        $secondData = $this->draft();
        $secondData['tasks'] = array_reverse($secondData['tasks']);
        $secondData['dependencies'] = array_reverse($secondData['dependencies']);
        $second = ScheduleRevisionDraft::fromArray($secondData);

        $factory = new ScheduleRevisionFactory;

        self::assertSame($factory->contentHash($first), $factory->contentHash($second));
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $factory->contentHash($first));
    }

    public function test_rejects_stale_source_watermark_and_cross_lineage_tasks(): void
    {
        $data = $this->draft();
        $data['observed_source_watermark'] = 'schedule:40:v8';

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('lookahead_readiness_stale_schedule_source');

        ScheduleRevisionDraft::fromArray($data);
    }

    public function test_rejects_dependency_that_does_not_reference_snapshot_tasks(): void
    {
        $data = $this->draft();
        $data['dependencies'][0]['successor_external_id'] = 'missing';

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('lookahead_readiness_dependency_lineage_invalid');

        ScheduleRevisionDraft::fromArray($data);
    }

    private function draft(): array
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
            'tasks' => [
                [
                    'external_id' => 'task-b',
                    'source_task_id' => 102,
                    'wbs_code' => '1.2',
                    'name' => 'Task B',
                    'task_class' => 'standard',
                    'planned_start' => '2026-08-11',
                    'planned_end' => '2026-08-12',
                    'duration_minutes' => 960,
                    'planned_quantity' => '3.0000',
                    'planned_work_hours' => '16.0000',
                    'critical' => false,
                    'constraint_point' => null,
                    'parent_external_id' => 'task-a',
                ],
                [
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
                ],
            ],
            'dependencies' => [[
                'predecessor_external_id' => 'task-a',
                'successor_external_id' => 'task-b',
                'type' => 'finish_to_start',
                'lag_minutes' => 0,
            ]],
        ];
    }
}
