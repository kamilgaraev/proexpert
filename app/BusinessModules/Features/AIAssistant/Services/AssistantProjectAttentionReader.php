<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;

final readonly class AssistantProjectAttentionReader
{
    public const TYPE = 'live_project_attention_projection';

    public function __construct(private AssistantDataAccessPolicy $access) {}

    public function read(User $actor, int $organizationId, array $filters): array
    {
        $projectId = $filters['project_id'] ?? null;
        $focus = $filters['focus'] ?? 'all';
        $limit = $filters['limit'] ?? 3;
        if (! is_int($projectId) || $projectId < 1 || ! in_array($focus, ['all', 'schedule', 'quality', 'procurement'], true)
            || ! is_int($limit) || $limit < 1 || $limit > 20
            || array_diff(array_keys($filters), ['project_id', 'focus', 'limit']) !== []) { throw new \InvalidArgumentException('invalid_project_attention_scope'); }

        return $this->access->withCurrentChecks($actor, $organizationId, function () use ($actor, $organizationId, $projectId, $focus, $limit): array {
            if (! $this->access->canReadDomain($actor, $organizationId, 'assistant')
                || ! $this->access->canReadEntity($actor, $organizationId, 'project', $projectId)) { throw new AuthorizationException; }
            $project = $this->access->entityQuery($actor, $organizationId, 'project')?->whereKey($projectId)->select(['projects.id', 'projects.name'])->first();
            if ($project === null) { throw new AuthorizationException; }
            $groups = [];
            $coverage = [];
            foreach (self::definitions() as $kind => $definition) {
                if ($focus !== 'all' && $definition['domain'] !== $focus) { continue; }
                $query = $this->query($actor, $organizationId, $projectId, $kind, $definition);
                $coverage[$kind] = ['available' => $query !== null, 'label' => trans_message('ai_assistant_attention.'.$kind)];
                if ($query === null) { continue; }
                $table = $query->getModel()->getTable();
                $count = (clone $query)->reorder()->count();
                $coverage[$kind]['total'] = $count;
                if ($count === 0) { continue; }
                $columns = array_values(array_unique(['id', $definition['title'], 'status', $definition['owner'], 'updated_at', ...$definition['extra']]));
                $query->reorder();
                if ($definition['deadline'] !== null) { $query->orderByRaw($table.'.'.$definition['deadline'].' ASC NULLS LAST'); }
                $models = $query->orderBy($table.'.id')->limit($limit)->select(array_map(static fn (string $column): string => $table.'.'.$column, $columns))->get();
                $ownerIds = $models->pluck($definition['owner'])->filter()->unique()->all();
                $owners = User::query()->whereKey($ownerIds)->whereHas('organizations', static fn (Builder $query): Builder => $query->whereKey($organizationId)->where('organization_user.is_active', true))->get(['id', 'name', 'is_active'])->keyBy('id');
                $items = [];
                foreach ($models as $model) {
                    $assigned = $model->getRawOriginal($definition['owner']);
                    $owner = $assigned === null ? null : $owners->get($assigned);
                    $items[] = ['entity_type' => $definition['type'], 'id' => (int) $model->id,
                        'title' => (string) $model->getRawOriginal($definition['title']), 'status' => (string) $model->getRawOriginal('status'),
                        'deadline' => $definition['deadline'] === null ? null : $model->getRawOriginal($definition['deadline']),
                        'owner_name' => $owner?->name, 'owner_state' => $assigned === null ? 'unassigned' : ($owner === null ? 'unavailable' : ($owner->is_active ? 'active' : 'inactive')),
                        'source_version' => (string) $model->getRawOriginal('updated_at')];
                }
                $groups[] = ['kind' => $kind, 'total' => $count, 'items' => $items, 'has_more' => $count > count($items),
                    'title' => trans_message('ai_assistant_attention.'.$kind), 'next_action' => trans_message('ai_assistant_attention.'.$kind.'_action')];
            }
            $filters = ['project_id' => $projectId, 'focus' => $focus, 'limit' => $limit];
            $payload = ['project' => ['id' => $projectId, 'name' => $project->name], 'as_of_date' => today()->toDateString(),
                'groups' => $groups, 'coverage' => $coverage];
            $version = AssistantSourceReferenceIdentity::key(['schema' => 1, 'organization_id' => $organizationId,
                'actor_id' => (int) $actor->id, 'filters' => $filters, 'payload' => $payload]);
            $ref = ['source_type' => self::TYPE, 'entity_type' => self::TYPE, 'entity_id' => $projectId, 'project_id' => $projectId,
                'organization_id' => $organizationId, 'actor_id' => (int) $actor->id, 'attention_filters' => $filters,
                'source_version' => $version, 'fetched_at' => now()->toIso8601String()];
            return ['status' => 'success', 'attention_evidence' => ['version' => $version], 'source_refs' => [$ref],
                'fetched_at' => $ref['fetched_at'], 'data' => $payload, 'server_formatted_answer' => self::format($payload, $focus)];
        }, true);
    }

    private static function definitions(): array
    {
        return [
            'critical_quality' => ['domain' => 'quality', 'type' => 'quality_defect', 'title' => 'title', 'owner' => 'assigned_to', 'deadline' => 'due_date', 'extra' => ['severity', 'due_date']],
            'overdue_schedule' => ['domain' => 'schedule', 'type' => 'schedule_task', 'title' => 'name', 'owner' => 'assigned_user_id', 'deadline' => 'planned_end_date', 'extra' => ['planned_end_date', 'progress_percent']],
            'overdue_quality' => ['domain' => 'quality', 'type' => 'quality_defect', 'title' => 'title', 'owner' => 'assigned_to', 'deadline' => 'due_date', 'extra' => ['severity', 'due_date']],
            'pending_procurement' => ['domain' => 'procurement', 'type' => 'purchase_request', 'title' => 'request_number', 'owner' => 'assigned_to', 'deadline' => null, 'extra' => ['site_request_id']],
        ];
    }

    private function query(User $actor, int $organizationId, int $projectId, string $kind, array $definition): ?Builder
    {
        if (! $this->access->canReadDomain($actor, $organizationId, $definition['domain'])) { return null; }
        $query = $this->access->entityQuery($actor, $organizationId, $definition['type']);
        if ($query === null) { return null; }
        $table = $query->getModel()->getTable();
        if ($kind === 'overdue_schedule') {
            $parents = $this->access->entityQuery($actor, $organizationId, 'schedule');
            if ($parents === null) { return null; }
            $query->whereIn($table.'.schedule_id', $parents->where('project_id', $projectId)->select('project_schedules.id'));
            AssistantDomainPredicate::apply($query, 'overdue');
        } elseif ($kind === 'pending_procurement') {
            $parents = $this->access->entityQuery($actor, $organizationId, 'site_request');
            if ($parents === null) { return null; }
            $query->whereIn($table.'.site_request_id', $parents->where('project_id', $projectId)->select('site_requests.id'))->where($table.'.status', 'pending');
        } else {
            $query->where($table.'.project_id', $projectId)->whereNotIn($table.'.status', ['resolved', 'cancelled']);
            if ($kind === 'critical_quality') { $query->where($table.'.severity', 'critical'); }
            else { $query->where($table.'.severity', '!=', 'critical')->whereDate($table.'.due_date', '<', today()); }
        }
        return $query;
    }

    public function matchesReference(User $actor, int $organizationId, array $reference): bool
    {
        if (($reference['entity_type'] ?? null) !== self::TYPE || ($reference['organization_id'] ?? null) !== $organizationId
            || ($reference['actor_id'] ?? null) !== (int) $actor->id || ! is_array($reference['attention_filters'] ?? null)
            || ($reference['attention_filters']['project_id'] ?? null) !== ($reference['entity_id'] ?? null)
            || ($reference['project_id'] ?? null) !== ($reference['entity_id'] ?? null) || ! is_string($reference['source_version'] ?? null)) { return false; }
        try {
            return ($this->read($actor, $organizationId, $reference['attention_filters'])['attention_evidence']['version'] ?? null) === $reference['source_version'];
        } catch (\Throwable) { return false; }
    }

    public function verifiedAnswer(array $result, User $actor, int $organizationId): ?array
    {
        $reference = $result['source_refs'][0] ?? null;
        if (! is_array($reference) || ! $this->matchesReference($actor, $organizationId, $reference)) { return null; }
        $current = $this->read($actor, $organizationId, $reference['attention_filters']);
        if (($current['attention_evidence']['version'] ?? null) !== $reference['source_version']) { return null; }
        $partial = array_filter($current['data']['coverage'], static fn (array $entry): bool => ! $entry['available']) !== [];
        return ['text' => $current['server_formatted_answer'], 'validation_status' => $partial ? 'partial' : 'verified',
            'source_refs' => $current['source_refs'], 'replaced' => true, 'needs_clarification' => false];
    }

    private static function format(array $payload, string $focus): string
    {
        $lines = [trans_message('ai_assistant_attention.scope', ['project' => self::text($payload['project']['name']), 'date' => $payload['as_of_date']])];
        $checked = array_column(array_values(array_filter($payload['coverage'], static fn (array $entry): bool => $entry['available'])), 'label');
        if ($checked !== []) { $lines[] = trans_message('ai_assistant_attention.checked', ['sections' => implode(', ', $checked)]); }
        $groups = $focus === 'all' ? array_slice($payload['groups'], 0, 3) : $payload['groups'];
        foreach ($groups as $index => $group) {
            $lines[] = "\n".($index + 1).'. **'.$group['title'].'** — '.$group['total'];
            foreach ($group['items'] as $item) {
                $owner = $item['owner_name'] === null ? trans_message('ai_assistant_attention.owner_'.$item['owner_state']) : self::text($item['owner_name']);
                if ($item['owner_state'] === 'inactive') { $owner .= ' ('.trans_message('ai_assistant_attention.owner_inactive').')'; }
                $line = '- '.self::text($item['title']).'. '.trans_message('ai_assistant_attention.owner', ['owner' => $owner]);
                if ($item['deadline'] !== null) { $line .= ' '.trans_message('ai_assistant_attention.deadline', ['date' => substr((string) $item['deadline'], 0, 10)]); }
                $lines[] = $line;
            }
            if ($group['has_more']) { $lines[] = trans_message('ai_assistant_attention.window', ['shown' => count($group['items']), 'total' => $group['total']]); }
            $lines[] = trans_message('ai_assistant_attention.action', ['action' => $group['next_action']]);
        }
        if ($groups === []) {
            $available = array_filter($payload['coverage'], static fn (array $entry): bool => $entry['available']);
            $lines[] = trans_message($available === [] ? 'ai_assistant_attention.insufficient' : 'ai_assistant_attention.empty');
        }
        $unavailable = array_column(array_values(array_filter($payload['coverage'], static fn (array $entry): bool => ! $entry['available'])), 'label');
        if ($unavailable !== []) { $lines[] = trans_message('ai_assistant_attention.unavailable', ['sections' => implode(', ', $unavailable)]); }
        $lines[] = trans_message('ai_assistant_attention.basis');
        return implode("\n", $lines);
    }

    private static function text(string $value): string
    {
        $value = mb_substr(trim(preg_replace('/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]+/u', ' ', $value) ?? ''), 0, 255);
        return str_replace(['&', '<', '>', '\\', '*', '_', '[', ']', '`'], ['&amp;', '&lt;', '&gt;', '\\\\', '\\*', '\\_', '\\[', '\\]', '\\`'], $value);
    }
}
