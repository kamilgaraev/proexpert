<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services;

use App\Models\User;
use Brick\Math\BigDecimal;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;

final readonly class AssistantProjectWorkforceReader
{
    public const TYPE = 'live_project_workforce_projection';
    public function __construct(private AssistantDataAccessPolicy $access) {}

    public static function matches(string $query): bool
    {
        return preg_match('/табел|кто\s+работал|сколько\s+часов|уч[её]т.*врем/iu', $query) === 1
            && preg_match('/назначь|утверди|согласуй|создай|измени|удали|бюджет|расход|зарплат|оплат|потрач|сумм|стоим|затрат|смет|договор|документ|замечан|зависимост|график|дефект|поставк|закуп|ifc|bim|бим/iu', $query) !== 1;
    }

    public function read(User $actor, int $organizationId, array $arguments): array
    {
        $projectId = $arguments['project_id'] ?? null;
        $from = $arguments['period_start'] ?? null;
        $to = $arguments['period_end'] ?? null;
        if (! is_int($projectId) || $projectId < 1 || ! self::date($from) || ! self::date($to) || $to < $from
            || (new \DateTimeImmutable($from))->diff(new \DateTimeImmutable($to))->days > 366
            || array_diff(array_keys($arguments), ['project_id', 'period_start', 'period_end']) !== []) { throw new \InvalidArgumentException('invalid_workforce_scope'); }
        return $this->access->withCurrentChecks($actor, $organizationId, function () use ($actor, $organizationId, $arguments, $projectId, $from, $to): array {
            if (! $this->access->canReadDomain($actor, $organizationId, 'assistant') || ! $this->access->canReadEntity($actor, $organizationId, 'project', $projectId)) { throw new AuthorizationException; }
            $project = $this->access->entityQuery($actor, $organizationId, 'project')?->whereKey($projectId)->first(['id', 'name']);
            if ($project === null) { throw new AuthorizationException; }
            $sections = [];
            $sourceRows = [];
            foreach (['time_entry', 'production_labor_timesheet'] as $type) {
                $query = $this->access->entityQuery($actor, $organizationId, $type);
                if ($query === null) { $sections[$type] = ['available' => false, 'reason' => 'access']; continue; }
                $date = $type === 'time_entry' ? 'work_date' : 'shift_date';
                $columns = $type === 'time_entry' ? ['id', 'user_id', 'worker_name', 'hours_worked', 'status', 'work_date', 'updated_at']
                    : ['id', 'status', 'shift_date', 'updated_at'];
                $records = $query->where('project_id', $projectId)->whereBetween($date, [$from, $to])->orderBy('id')->limit(10001)->get($columns);
                if ($records->count() > 10000) { $sections[$type] = ['available' => false, 'reason' => 'limit']; continue; }
                $rows = $records->map(static fn ($record): array => $record->getAttributes())->all();
                $entryRows = $rows;
                if ($type !== 'time_entry') {
                    $entries = $this->access->entityQuery($actor, $organizationId, 'production_labor_timesheet_entry');
                    if ($entries === null) { $sections[$type] = ['available' => false, 'reason' => 'access']; continue; }
                    $entryRows = $entries->whereIn('timesheet_id', $records->modelKeys())->orderBy('id')->limit(10001)
                        ->get(['id', 'timesheet_id', 'user_id', 'employee_id', 'worker_name', 'hours', 'updated_at'])->map(static fn ($record): array => $record->getAttributes())->all();
                    if (count($entryRows) > 10000) { $sections[$type] = ['available' => false, 'reason' => 'limit']; continue; }
                }
                $owners = User::query()->whereKey(array_filter(array_column($entryRows, 'user_id')))
                    ->whereHas('organizations', static fn (Builder $query): Builder => $query->whereKey($organizationId)->where('organization_user.is_active', true))
                    ->where('is_active', true)->get(['id', 'name'])->keyBy('id');
                $employees = $this->access->entityQuery($actor, $organizationId, 'workforce_employee')?->whereKey(array_filter(array_column($entryRows, 'employee_id')))
                    ->get(['id', 'last_name', 'first_name', 'middle_name'])->keyBy('id');
                $hours = BigDecimal::zero(); $workers = []; $valid = true;
                foreach ($entryRows as &$row) {
                    $value = $row[$type === 'time_entry' ? 'hours_worked' : 'hours'];
                    if (! is_string($value) || preg_match('/^\d+(?:\.\d{1,2})?$/D', $value) !== 1) { $valid = false; break; }
                    $hours = $hours->plus($value);
                    $name = trim((string) ($row['worker_name'] ?? '')) ?: (string) ($owners->get($row['user_id'] ?? null)?->name
                        ?? $employees?->get($row['employee_id'] ?? null)?->full_name ?? '');
                    $row['display_name'] = $name;
                    if ($name !== '') { $workers[$name] = true; }
                }
                unset($row);
                if (! $valid) { $sections[$type] = ['available' => false, 'reason' => 'invalid_hours']; continue; }
                $byStatus = array_count_values(array_column($rows, 'status')); ksort($byStatus);
                $names = array_keys($workers); sort($names, SORT_STRING);
                $sections[$type] = ['available' => true, 'record_count' => count($rows), 'entry_count' => count($entryRows),
                    'unidentified_entries' => count(array_filter($entryRows, static fn (array $row): bool => $row['display_name'] === '')),
                    'hours_total' => (string) $hours->toScale(2), 'by_status' => $byStatus, 'worker_names_count' => count($names), 'worker_names' => array_slice($names, 0, 20)];
                $sourceRows[$type] = [$rows, $entryRows];
            }
            $payload = ['project' => ['id' => $projectId, 'name' => $project->name], 'period_start' => $from, 'period_end' => $to, 'sections' => $sections];
            $version = AssistantSourceReferenceIdentity::key(['schema' => 1, 'organization_id' => $organizationId, 'actor_id' => (int) $actor->id,
                'arguments' => $arguments, 'payload' => $payload, 'source_rows' => $sourceRows]);
            $ref = ['entity_type' => self::TYPE, 'entity_id' => $projectId, 'project_id' => $projectId, 'organization_id' => $organizationId,
                'actor_id' => (int) $actor->id, 'workforce_arguments' => $arguments, 'source_version' => $version, 'fetched_at' => now()->toIso8601String()];
            return ['status' => 'partial', 'source_refs' => [$ref], 'data' => $payload, 'server_formatted_answer' => self::format($payload)];
        }, true);
    }

    public function matchesReference(User $actor, int $organizationId, array $ref): bool
    {
        if (($ref['entity_type'] ?? null) !== self::TYPE || ($ref['organization_id'] ?? null) !== $organizationId || ($ref['actor_id'] ?? null) !== (int) $actor->id
            || ! is_array($ref['workforce_arguments'] ?? null) || ($ref['workforce_arguments']['project_id'] ?? null) !== ($ref['entity_id'] ?? null)
            || ($ref['project_id'] ?? null) !== ($ref['entity_id'] ?? null) || ! is_string($ref['source_version'] ?? null)) { return false; }
        try { return $this->read($actor, $organizationId, $ref['workforce_arguments'])['source_refs'][0]['source_version'] === $ref['source_version']; }
        catch (\Throwable) { return false; }
    }

    public function verifiedAnswer(array $result, User $actor, int $organizationId): ?array
    {
        $ref = $result['source_refs'][0] ?? null;
        if (! is_array($ref) || ! $this->matchesReference($actor, $organizationId, $ref)) { return null; }
        $current = $this->read($actor, $organizationId, $ref['workforce_arguments']);
        if ($current['source_refs'][0]['source_version'] !== $ref['source_version']) { return null; }
        return ['text' => $current['server_formatted_answer'], 'source_refs' => $current['source_refs'], 'validation_status' => 'partial', 'replaced' => true, 'needs_clarification' => false];
    }

    private static function format(array $payload): string
    {
        $lines = [trans_message('ai_assistant_decisions.workforce_scope', ['project' => self::text($payload['project']['name']), 'from' => $payload['period_start'], 'to' => $payload['period_end']])];
        foreach ($payload['sections'] as $type => $section) {
            $lines[] = "\n**".trans_message('ai_assistant_decisions.'.$type).'**';
            if (! $section['available']) { $lines[] = trans_message('ai_assistant_decisions.workforce_'.$section['reason']); continue; }
            $lines[] = trans_message('ai_assistant_decisions.workforce_totals', ['records' => $section['record_count'], 'entries' => $section['entry_count'], 'hours' => $section['hours_total']]);
            $lines[] = trans_message('ai_assistant_decisions.workforce_names', ['shown' => count($section['worker_names']), 'total' => $section['worker_names_count'],
                'names' => implode(', ', array_map(self::text(...), $section['worker_names'])) ?: '—']);
            if ($section['unidentified_entries'] > 0) { $lines[] = trans_message('ai_assistant_decisions.workforce_unidentified', ['count' => $section['unidentified_entries']]); }
            if ($type === 'time_entry') { $lines[] = trans_message('ai_assistant_decisions.time_pending', ['count' => $section['by_status']['submitted'] ?? 0]); }
            else { $lines[] = trans_message('ai_assistant_decisions.labor_submitted', ['count' => $section['by_status']['submitted'] ?? 0]); }
        }
        $lines[] = trans_message('ai_assistant_decisions.workforce_basis');
        return implode("\n", $lines);
    }

    private static function date(mixed $value): bool
    {
        if (! is_string($value)) { return false; }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date instanceof \DateTimeImmutable && $date->format('Y-m-d') === $value;
    }

    private static function text(string $value): string
    {
        return str_replace(['*', '_', '[', ']', '`'], ['\\*', '\\_', '\\[', '\\]', '\\`'], htmlspecialchars(mb_substr(preg_replace('/[\p{Cc}\p{Cf}]/u', ' ', $value) ?? '', 0, 255), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
    }
}
