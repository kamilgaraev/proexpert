<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services;

final readonly class AssistantScheduleImpactCalculator
{
    public function calculate(array $tasks, array $dependencies, string $asOf): array
    {
        $nodes = [];
        $incoming = [];
        $outgoing = [];
        $degree = [];
        foreach ($tasks as $task) {
            if (($task['task_type'] ?? null) === 'container' || ($task['status'] ?? null) === 'cancelled') { continue; }
            if (! is_numeric($task['planned_duration_days'] ?? null) || $task['planned_duration_days'] < 0
                || ! $this->date($task['planned_start_date'] ?? null) || ! $this->date($task['planned_end_date'] ?? null)
                || $task['planned_end_date'] < $task['planned_start_date']
                || ! in_array($task['constraint_type'] ?? null, [null, 'none', 'asap', 'ASAP'], true)) { return ['available' => false, 'reason' => 'incomplete_plan']; }
            $id = (int) $task['id'];
            $nodes[$id] = $task + ['duration' => (float) $task['planned_duration_days'], 'start' => $this->day($task['planned_start_date'])];
            $degree[$id] = 0;
        }
        foreach ($dependencies as $dependency) {
            if (! ($dependency['is_active'] ?? false)) { continue; }
            $pred = (int) $dependency['predecessor_task_id'];
            $succ = (int) $dependency['successor_task_id'];
            if (($dependency['validation_status'] ?? null) !== 'valid' || ! isset($nodes[$pred], $nodes[$succ])) {
                return ['available' => false, 'reason' => 'incomplete_dependencies'];
            }
            if (! in_array($dependency['dependency_type'] ?? null, ['FS', 'SS', 'FF', 'SF'], true)
                || ! in_array($dependency['lag_type'] ?? null, ['days', 'hours', 'percent'], true)
                || ! is_numeric($dependency['lag_days'] ?? null) || ! is_numeric($dependency['lag_hours'] ?? null)) {
                return ['available' => false, 'reason' => 'incomplete_dependencies'];
            }
            $lag = match ($dependency['lag_type']) {
                'hours' => (float) $dependency['lag_hours'] / 24,
                'percent' => (float) $dependency['lag_days'] * $nodes[$pred]['duration'] / 100,
                default => (float) $dependency['lag_days'],
            };
            $offset = match ($dependency['dependency_type']) {
                'FS' => $nodes[$pred]['duration'] + $lag,
                'SS' => $lag,
                'FF' => $nodes[$pred]['duration'] + $lag - $nodes[$succ]['duration'],
                'SF' => $lag - $nodes[$succ]['duration'],
            };
            $incoming[$succ][] = ['pred' => $pred, 'offset' => $offset];
            $outgoing[$pred][] = ['succ' => $succ, 'offset' => $offset];
            $degree[$succ]++;
        }
        $queue = array_keys(array_filter($degree, static fn (int $value): bool => $value === 0));
        $order = [];
        for ($index = 0; $index < count($queue); $index++) {
            $id = $queue[$index];
            $order[] = $id;
            foreach ($outgoing[$id] ?? [] as $edge) { if (--$degree[$edge['succ']] === 0) { $queue[] = $edge['succ']; } }
        }
        if (count($order) !== count($nodes)) { return ['available' => false, 'reason' => 'cycle']; }
        $starts = [];
        $baseline = null;
        foreach ($order as $id) {
            $start = $nodes[$id]['start'];
            foreach ($incoming[$id] ?? [] as $edge) { $start = max($start, $starts[$edge['pred']] + $edge['offset']); }
            $starts[$id] = $start;
            $baseline = max($baseline ?? -INF, $start + $nodes[$id]['duration']);
        }
        $latest = [];
        foreach (array_reverse($order) as $id) {
            $latest[$id] = $baseline - $nodes[$id]['duration'];
            foreach ($outgoing[$id] ?? [] as $edge) { $latest[$id] = min($latest[$id], $latest[$edge['succ']] - $edge['offset']); }
        }
        $candidates = [];
        foreach ($nodes as $id => $task) {
            if (in_array($task['status'], ['completed', 'cancelled'], true) || (float) $task['progress_percent'] >= 100
                || $task['planned_end_date'] >= $asOf) { continue; }
            $late = (int) round($this->day($asOf) - $this->day($task['planned_end_date']));
            $reserve = max(0, $latest[$id] - $starts[$id]);
            $delta = max(0, $late - $reserve);
            $candidates[] = ['id' => $id, 'name' => $task['name'], 'deadline' => $task['planned_end_date'], 'overdue_days' => $late,
                'finish_shift_days' => round($delta, 3), 'float_days' => round($reserve, 3), 'direct_successors' => array_values(array_unique(array_column($outgoing[$id] ?? [], 'succ'))),
                'unfinished_predecessors' => array_values(array_filter(array_unique(array_column($incoming[$id] ?? [], 'pred')),
                    static fn (int $pred): bool => $nodes[$pred]['status'] !== 'completed' && (float) $nodes[$pred]['progress_percent'] < 100))];
        }
        usort($candidates, static fn (array $a, array $b): int => $b['finish_shift_days'] <=> $a['finish_shift_days']
            ?: $b['overdue_days'] <=> $a['overdue_days'] ?: $a['id'] <=> $b['id']);
        return ['available' => true, 'dependency_count' => count($dependencies), 'task_count' => count($nodes),
            'baseline_network_finish' => $baseline === null ? null : gmdate('Y-m-d', (int) round($baseline * 86400)), 'candidates' => $candidates,
            'critical_tasks' => array_values(array_map(static fn (int $id): array => ['id' => $id, 'name' => $nodes[$id]['name']],
                array_filter($order, static fn (int $id): bool => abs($latest[$id] - $starts[$id]) < 0.00001))),
            'dependencies' => array_map(static fn (array $edge): array => ['predecessor_id' => (int) $edge['predecessor_task_id'],
                'successor_id' => (int) $edge['successor_task_id'], 'type' => $edge['dependency_type'], 'lag_type' => $edge['lag_type'],
                'lag_days' => $edge['lag_days'], 'lag_hours' => $edge['lag_hours']], $dependencies)];
    }

    private function date(mixed $value): bool
    {
        if (! is_string($value)) { return false; }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date instanceof \DateTimeImmutable && $date->format('Y-m-d') === $value;
    }

    private function day(string $date): float
    {
        return (new \DateTimeImmutable($date, new \DateTimeZone('UTC')))->getTimestamp() / 86400;
    }
}
