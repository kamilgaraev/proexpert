<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

final readonly class AssistantProjectScheduleImpactReader
{
    public const TYPE = 'live_project_schedule_impact_projection';

    public function __construct(private AssistantDataAccessPolicy $access, private AssistantScheduleImpactCalculator $calculator) {}

    public static function matches(string $query): bool
    {
        return preg_match('/задач|график|критическ|зависимост/iu', $query) === 1
            && preg_match('/влия[а-яё]*.*срок|срок.*влия|критическ.*путь|разблокир|зависимост|задержива[а-яё]*.*(?:этап|срок|работ)/iu', $query) === 1
            && preg_match('/создай|измени|назначь|удали|перенеси|перенесите|бюджет|расход|оплат|потрач|затрат|сумм|выручк|марж|долг|документ|дефект|замечан|табел|сотрудник|ifc|bim|бим/iu', $query) !== 1;
    }

    public static function focus(string $query): string
    {
        if (preg_match('/просроч|задерж|влия|разблокир/iu', $query)) { return 'overdue_impact'; }
        return preg_match('/критическ.*путь/iu', $query) ? 'critical_path' : 'dependencies';
    }

    public function read(User $actor, int $organizationId, array $arguments): array
    {
        $projectId = $arguments['project_id'] ?? null;
        $focus = $arguments['focus'] ?? 'overdue_impact';
        if (! is_int($projectId) || $projectId < 1 || ! in_array($focus, ['overdue_impact', 'critical_path', 'dependencies'], true)
            || array_diff(array_keys($arguments), ['project_id', 'focus']) !== []) { throw new \InvalidArgumentException('invalid_schedule_impact_scope'); }
        return $this->access->withCurrentChecks($actor, $organizationId, function () use ($actor, $organizationId, $projectId, $arguments, $focus): array {
            if (! $this->access->canReadDomain($actor, $organizationId, 'assistant') || ! $this->access->canReadDomain($actor, $organizationId, 'schedule')
                || ! $this->access->canReadEntity($actor, $organizationId, 'project', $projectId)) { throw new AuthorizationException; }
            $project = $this->access->entityQuery($actor, $organizationId, 'project')?->whereKey($projectId)->first(['projects.id', 'projects.name']);
            $scheduleQuery = $this->access->entityQuery($actor, $organizationId, 'schedule');
            if ($project === null || $scheduleQuery === null) { throw new AuthorizationException; }
            $schedules = $scheduleQuery->where('project_id', $projectId)->where('status', 'active')->orderBy('id')->limit(21)->get(['id', 'name', 'updated_at']);
            $results = [];
            if ($schedules->count() > 20) { $results[] = ['available' => false, 'reason' => 'limit', 'schedule_name' => null]; }
            else {
                foreach ($schedules as $schedule) {
                    $taskQuery = $this->access->entityQuery($actor, $organizationId, 'schedule_task');
                    $dependencyQuery = $this->access->entityQuery($actor, $organizationId, 'core_task_dependency');
                    if ($taskQuery === null || $dependencyQuery === null) { throw new AuthorizationException; }
                    $tasks = $taskQuery->where('schedule_id', $schedule->id)->orderBy('id')->limit(1001)->get([
                        'id', 'name', 'task_type', 'status', 'planned_start_date', 'planned_end_date', 'planned_duration_days', 'progress_percent', 'constraint_type', 'updated_at']);
                    $dependencies = $dependencyQuery->where('schedule_id', $schedule->id)->where('is_active', true)->orderBy('id')->limit(5001)->get([
                        'id', 'predecessor_task_id', 'successor_task_id', 'dependency_type', 'lag_days', 'lag_hours', 'lag_type', 'is_active', 'validation_status', 'updated_at']);
                    $taskRows = $tasks->map(static function ($task): array {
                        $row = $task->getAttributes();
                        foreach (['planned_start_date', 'planned_end_date'] as $field) { $row[$field] = is_string($row[$field] ?? null) ? substr($row[$field], 0, 10) : null; }
                        return $row;
                    })->all();
                    $dependencyRows = $dependencies->map(static fn ($dependency): array => $dependency->getAttributes())->all();
                    $analysis = $tasks->count() > 1000 || $dependencies->count() > 5000 ? ['available' => false, 'reason' => 'limit']
                        : $this->calculator->calculate($taskRows, $dependencyRows, today()->toDateString());
                    $results[] = $analysis + ['schedule_id' => (int) $schedule->id, 'schedule_name' => $schedule->name,
                        'dependency_count' => count($dependencyRows), 'stored_dependencies' => array_map(static fn (array $edge): array => [
                            'predecessor_id' => (int) $edge['predecessor_task_id'], 'successor_id' => (int) $edge['successor_task_id'],
                            'type' => $edge['dependency_type'], 'lag_type' => $edge['lag_type'], 'lag_days' => $edge['lag_days'], 'lag_hours' => $edge['lag_hours'],
                            'validation_status' => $edge['validation_status']], $dependencyRows),
                        'schedule_version' => (string) $schedule->getRawOriginal('updated_at'), 'graph_version' => AssistantSourceReferenceIdentity::key([$taskRows, $dependencyRows])];
                }
            }
            $payload = ['project' => ['id' => $projectId, 'name' => $project->name], 'as_of_date' => today()->toDateString(), 'focus' => $focus, 'schedules' => $results];
            $version = AssistantSourceReferenceIdentity::key(['schema' => 1, 'organization_id' => $organizationId, 'actor_id' => (int) $actor->id, 'payload' => $payload]);
            $ref = ['entity_type' => self::TYPE, 'entity_id' => $projectId, 'project_id' => $projectId, 'organization_id' => $organizationId,
                'actor_id' => (int) $actor->id, 'impact_focus' => $focus, 'source_version' => $version, 'fetched_at' => now()->toIso8601String()];
            return ['status' => 'partial', 'source_refs' => [$ref], 'impact_arguments' => $arguments, 'data' => $payload,
                'server_formatted_answer' => self::format($payload)];
        }, true);
    }

    public function matchesReference(User $actor, int $organizationId, array $reference): bool
    {
        if (($reference['entity_type'] ?? null) !== self::TYPE || ($reference['organization_id'] ?? null) !== $organizationId
            || ($reference['actor_id'] ?? null) !== (int) $actor->id || ($reference['project_id'] ?? null) !== ($reference['entity_id'] ?? null)
            || ! is_int($reference['entity_id'] ?? null) || ! is_string($reference['source_version'] ?? null)) { return false; }
        try { return $this->read($actor, $organizationId, ['project_id' => $reference['entity_id'], 'focus' => $reference['impact_focus'] ?? null])['source_refs'][0]['source_version'] === $reference['source_version']; }
        catch (\Throwable) { return false; }
    }

    public function verifiedAnswer(array $result, User $actor, int $organizationId): ?array
    {
        $ref = $result['source_refs'][0] ?? null;
        if (! is_array($ref) || ! $this->matchesReference($actor, $organizationId, $ref)) { return null; }
        $current = $this->read($actor, $organizationId, ['project_id' => $ref['entity_id'], 'focus' => $ref['impact_focus']]);
        if ($current['source_refs'][0]['source_version'] !== $ref['source_version']) { return null; }
        return ['text' => $current['server_formatted_answer'], 'source_refs' => $current['source_refs'], 'validation_status' => 'partial', 'replaced' => true, 'needs_clarification' => false];
    }

    private static function format(array $payload): string
    {
        $lines = [trans_message('ai_assistant_decisions.schedule_scope', ['project' => self::text($payload['project']['name']), 'date' => $payload['as_of_date']])];
        if ($payload['schedules'] === []) { $lines[] = trans_message('ai_assistant_decisions.no_active_schedule'); }
        foreach ($payload['schedules'] as $schedule) {
            $lines[] = "\n**".self::text((string) $schedule['schedule_name']).'**';
            if ($payload['focus'] === 'dependencies' && ($schedule['reason'] ?? null) !== 'limit') {
                foreach (array_slice($schedule['stored_dependencies'], 0, 20) as $edge) {
                    $lines[] = trans_message('ai_assistant_decisions.edge', ['pred' => $edge['predecessor_id'], 'succ' => $edge['successor_id'],
                        'type' => $edge['type'], 'days' => $edge['lag_days'], 'hours' => $edge['lag_hours'], 'unit' => $edge['lag_type']]);
                }
                $lines[] = trans_message('ai_assistant_decisions.graph_window', ['shown' => min(20, count($schedule['stored_dependencies'])), 'total' => count($schedule['stored_dependencies'])]);
                if (! $schedule['available']) { $lines[] = trans_message('ai_assistant_decisions.schedule_'.$schedule['reason']); }
                if ($schedule['dependency_count'] === 0) { $lines[] = trans_message('ai_assistant_decisions.no_dependencies'); }
                continue;
            }
            if (! $schedule['available']) { $lines[] = trans_message('ai_assistant_decisions.schedule_'.$schedule['reason']); continue; }
            $lines[] = trans_message('ai_assistant_decisions.graph', ['tasks' => $schedule['task_count'], 'dependencies' => $schedule['dependency_count']]);
            if ($schedule['dependency_count'] === 0) { $lines[] = trans_message('ai_assistant_decisions.no_dependencies'); }
            if ($payload['focus'] === 'critical_path') {
                if ($schedule['dependency_count'] > 0) {
                    $lines[] = trans_message('ai_assistant_decisions.critical_basis', ['date' => $schedule['baseline_network_finish'], 'total' => count($schedule['critical_tasks'])]);
                    foreach (array_slice($schedule['critical_tasks'], 0, 20) as $task) { $lines[] = '- #'.$task['id'].' '.self::text($task['name']); }
                    $lines[] = trans_message('ai_assistant_decisions.graph_window', ['shown' => min(20, count($schedule['critical_tasks'])), 'total' => count($schedule['critical_tasks'])]);
                }
                continue;
            }
            $candidates = $schedule['candidates'];
            if ($candidates === []) { $lines[] = trans_message('ai_assistant_decisions.no_overdue'); continue; }
            if ($schedule['dependency_count'] > 0) { $lines[] = trans_message('ai_assistant_decisions.impact_basis', ['date' => $schedule['baseline_network_finish']]); }
            foreach (array_slice($candidates, 0, 3) as $task) {
                $lines[] = '- '.self::text($task['name']).' — '.trans_message('ai_assistant_decisions.task_delay', ['deadline' => $task['deadline'], 'days' => $task['overdue_days']]);
                if ($schedule['dependency_count'] > 0) { $lines[] = trans_message('ai_assistant_decisions.task_impact', ['days' => $task['finish_shift_days'], 'successors' => implode(', ', $task['direct_successors']) ?: '—']); }
                $lines[] = trans_message($task['unfinished_predecessors'] === [] ? 'ai_assistant_decisions.no_blocking_predecessor' : 'ai_assistant_decisions.blocking_predecessors',
                    ['ids' => implode(', ', $task['unfinished_predecessors'])]);
            }
            $lines[] = trans_message('ai_assistant_decisions.schedule_action', ['shown' => min(3, count($candidates)), 'total' => count($candidates)]);
        }
        $lines[] = trans_message('ai_assistant_decisions.schedule_basis');
        return implode("\n", $lines);
    }

    private static function text(string $value): string
    {
        return str_replace(['*', '_', '[', ']', '`'], ['\\*', '\\_', '\\[', '\\]', '\\`'], htmlspecialchars(mb_substr(preg_replace('/[\p{Cc}\p{Cf}]/u', ' ', $value) ?? '', 0, 255), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
    }
}
