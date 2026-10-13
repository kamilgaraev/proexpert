<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\AssistantScheduleImpactCalculator;
use PHPUnit\Framework\TestCase;

final class AssistantScheduleImpactCalculatorTest extends TestCase
{
    public function test_fs_chain_propagates_delay_and_branch_reserve_absorbs_it(): void
    {
        $tasks = [$this->task(1, 2, '2026-10-01', '2026-10-03'), $this->task(2, 3, '2026-10-03', '2026-10-06')];
        $result = (new AssistantScheduleImpactCalculator)->calculate($tasks, [$this->edge(1, 2)], '2026-10-05');
        self::assertTrue($result['available']);
        self::assertSame(2.0, $result['candidates'][0]['finish_shift_days']);
        self::assertSame([2], $result['candidates'][0]['direct_successors']);
        $tasks[] = $this->task(3, 20, '2026-10-01', '2026-10-21');
        $result = (new AssistantScheduleImpactCalculator)->calculate($tasks, [$this->edge(1, 2)], '2026-10-05');
        self::assertSame(0.0, $result['candidates'][0]['finish_shift_days']);
    }

    public function test_four_dependency_endpoints_have_distinct_finish_dates_and_lag_units(): void
    {
        $tasks = [$this->task(1, 10, '2026-10-01', '2026-10-11'), $this->task(2, 20, '2026-10-01', '2026-10-21')];
        foreach (['FS' => '2026-10-31', 'SS' => '2026-10-21', 'FF' => '2026-10-21', 'SF' => '2026-10-21'] as $type => $finish) {
            $result = (new AssistantScheduleImpactCalculator)->calculate($tasks, [$this->edge(1, 2, $type)], '2026-10-02');
            self::assertSame($finish, $result['baseline_network_finish']);
        }
        foreach ([['days', 2, 0], ['hours', 0, 48], ['percent', 20, 0]] as [$unit, $days, $hours]) {
            $edge = $this->edge(1, 2) + ['lag_type' => $unit];
            $edge['lag_type'] = $unit; $edge['lag_days'] = $days; $edge['lag_hours'] = $hours;
            self::assertSame('2026-11-02', (new AssistantScheduleImpactCalculator)->calculate($tasks, [$edge], '2026-10-02')['baseline_network_finish']);
        }
    }

    public function test_ff_and_sf_constrain_finish_rather_than_successor_start(): void
    {
        $tasks = [$this->task(1, 20, '2026-10-01', '2026-10-21'), $this->task(2, 2, '2026-10-01', '2026-10-03')];
        $ff = (new AssistantScheduleImpactCalculator)->calculate($tasks, [$this->edge(1, 2, 'FF')], '2026-10-04');
        self::assertSame('2026-10-21', $ff['baseline_network_finish']);
        self::assertSame(1.0, $ff['candidates'][0]['finish_shift_days']);
        $sf = (new AssistantScheduleImpactCalculator)->calculate($tasks, [$this->edge(1, 2, 'SF')], '2026-10-04');
        self::assertSame('2026-10-21', $sf['baseline_network_finish']);
    }

    public function test_cycles_missing_edges_and_date_constraints_fail_closed(): void
    {
        $tasks = [$this->task(1, 2, '2026-10-01', '2026-10-03'), $this->task(2, 2, '2026-10-01', '2026-10-03')];
        self::assertSame('cycle', (new AssistantScheduleImpactCalculator)->calculate($tasks, [$this->edge(1, 2), $this->edge(2, 1)], '2026-10-05')['reason']);
        self::assertSame('incomplete_dependencies', (new AssistantScheduleImpactCalculator)->calculate($tasks, [$this->edge(1, 3)], '2026-10-05')['reason']);
        $tasks[0]['constraint_type'] = 'must_finish_on';
        self::assertSame('incomplete_plan', (new AssistantScheduleImpactCalculator)->calculate($tasks, [], '2026-10-05')['reason']);
        $tasks[0]['constraint_type'] = 'none';
        $tasks[0]['planned_end_date'] = '2026-09-30';
        self::assertSame('incomplete_plan', (new AssistantScheduleImpactCalculator)->calculate($tasks, [], '2026-10-05')['reason']);
    }

    private function task(int $id, int $duration, string $start, string $end): array
    {
        return ['id' => $id, 'name' => 'Task '.$id, 'task_type' => 'task', 'status' => 'in_progress', 'progress_percent' => 10,
            'planned_duration_days' => $duration, 'planned_start_date' => $start, 'planned_end_date' => $end, 'constraint_type' => 'none'];
    }

    private function edge(int $pred, int $succ, string $type = 'FS'): array
    {
        return ['predecessor_task_id' => $pred, 'successor_task_id' => $succ, 'dependency_type' => $type,
            'lag_type' => 'days', 'lag_days' => 0, 'lag_hours' => 0, 'is_active' => true, 'validation_status' => 'valid'];
    }
}
