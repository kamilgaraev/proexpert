<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services;

use App\BusinessModules\Features\AIAssistant\Services\RequestUnderstanding\AssistantMutationIntent;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

final readonly class AssistantProjectMachineryReader
{
    public const TYPE = 'live_project_machinery_projection';
    public function __construct(private AssistantDataAccessPolicy $access, private AssistantMachineryProjectScope $scope) {}
    public static function matches(string $query): bool
    {
        return preg_match('/техник[а-яё]*|машин[а-яё]*|механизм[а-яё]*/iu', $query) === 1
            && preg_match('/закреплен|закрепл[её]н|назначен|назначени|свободн|простаива|ремонт|обслуживан/iu', $query) === 1
            && ! AssistantMutationIntent::containsRequest($query)
            && ! AssistantMutationIntent::containsFollowUpCommand($query)
            && preg_match('/(?<!\pL)(?:сделай(?:те)?|сделать|переведи(?:те)?|перевести|освободи(?:те)?|освободить)(?!\pL)/iu', $query) !== 1
            && preg_match('/назначь|создай|отправь|спиши|измени|удали|бюджет|расход|потрач|затрат|сумм|стоим|сч[её]т|плат[её]ж|оплат|документ|смет|поставк|договор|табел|замечан|дефект|качест|зависимост|критическ|сотрудник|бим|ifc|bim/iu', $query) !== 1;
    }
    public function read(User $actor, int $organizationId, array $arguments): array
    {
        $projectId = $arguments['project_id'] ?? null;
        if (! is_int($projectId) || $projectId < 1 || array_keys($arguments) !== ['project_id']) { throw new \InvalidArgumentException('invalid_machinery_scope'); }
        return $this->access->withCurrentChecks($actor, $organizationId, function () use ($actor, $organizationId, $projectId): array {
            if (! $this->access->canReadDomain($actor, $organizationId, 'assistant') || ! $this->access->canReadDomain($actor, $organizationId, 'machinery')
                || ! $this->access->canReadEntity($actor, $organizationId, 'project', $projectId)) { throw new AuthorizationException; }
            $project = $this->access->entityQuery($actor, $organizationId, 'project')?->whereKey($projectId)->first(['id', 'name']);
            $assetQuery = $this->access->entityQuery($actor, $organizationId, 'machinery_asset');
            if ($project === null || $assetQuery === null) { throw new AuthorizationException; }
            $this->scope->apply($assetQuery, $actor, $organizationId, $projectId);
            $assets = $assetQuery->orderBy('id')->limit(1001)->select(['id', 'name', 'asset_code', 'status', 'organization_asset_id', 'current_project_id', 'updated_at'])->get();
            $sections = []; $rows = [];
            foreach ([
                'machinery_assignment' => ['id', 'asset_id', 'status', 'planned_start_at', 'planned_end_at', 'updated_at'],
                'machinery_downtime' => ['id', 'asset_id', 'reason', 'started_at', 'ended_at', 'updated_at'],
                'machinery_maintenance_order' => ['id', 'asset_id', 'title', 'status', 'planned_at', 'updated_at'],
            ] as $type => $columns) {
                $query = $this->access->entityQuery($actor, $organizationId, $type);
                if ($query === null) { $sections[$type] = ['available' => false]; continue; }
                $query->where('project_id', $projectId);
                if ($type === 'machinery_assignment') { $query->where('status', 'active')->where('planned_start_at', '<=', now())->where(static fn ($q) => $q->whereNull('planned_end_at')->orWhere('planned_end_at', '>', now())); }
                elseif ($type === 'machinery_downtime') { $query->where('started_at', '<=', now())->whereNull('ended_at'); }
                else { $query->whereIn('status', ['open', 'in_progress']); }
                $records = $query->orderBy('id')->limit(5001)->select($columns)->get()->map(static fn ($record): array => $record->getAttributes())->all();
                $sections[$type] = ['available' => count($records) <= 5000, 'total' => count($records) <= 5000 ? count($records) : null, 'examples' => array_slice($records, 0, 3)];
                $rows[$type] = $records;
            }
            $assetRows = $assets->map(static fn ($asset): array => $asset->getAttributes())->all();
            $historyQuery = $assets->count() <= 1000 ? $this->access->entityQuery($actor, $organizationId, 'machinery_assignment') : null;
            $history = $historyQuery?->where('project_id', $projectId)->whereIn('asset_id', array_column(array_slice($assetRows, 0, 20), 'id'))
                ->orderByDesc('planned_start_at')->orderByDesc('id')->limit(21)
                ->get(['id', 'asset_id', 'status', 'planned_start_at', 'planned_end_at', 'actual_start_at', 'actual_end_at', 'updated_at'])
                ->map(static fn ($record): array => $record->getAttributes())->all();
            $canonical = $this->access->entityQuery($actor, $organizationId, 'organization_asset')?->whereKey($assets->pluck('organization_asset_id')->filter()->all())
                ->select(['id', 'technical_status', 'lifecycle_status', 'current_project_id', 'updated_at'])->get()->map(static fn ($record): array => $record->getAttributes())->all() ?? [];
            $payload = ['project' => ['id' => $projectId, 'name' => $project->name], 'as_of_date' => today()->toDateString(), 'assets_available' => $assets->count() <= 1000,
                'asset_count' => $assets->count() <= 1000 ? $assets->count() : null, 'assets' => array_slice($assetRows, 0, 20), 'canonical_states' => $canonical, 'sections' => $sections,
                'assignment_history' => ['available' => $history !== null, 'complete' => $history !== null && count($history) <= 20, 'items' => array_slice($history ?? [], 0, 20)]];
            $version = AssistantSourceReferenceIdentity::key(['schema' => 2, 'organization_id' => $organizationId, 'actor_id' => (int) $actor->id, 'payload' => $payload, 'assets' => $assetRows, 'rows' => $rows]);
            $ref = ['entity_type' => self::TYPE, 'entity_id' => $projectId, 'project_id' => $projectId, 'organization_id' => $organizationId, 'actor_id' => (int) $actor->id,
                'source_version' => $version, 'fetched_at' => now()->toIso8601String()];
            return ['status' => 'partial', 'source_refs' => [$ref], 'data' => $payload, 'server_formatted_answer' => self::format($payload)];
        }, true);
    }
    public function matchesReference(User $actor, int $organizationId, array $ref): bool
    {
        if (($ref['entity_type'] ?? null) !== self::TYPE || ($ref['organization_id'] ?? null) !== $organizationId || ($ref['actor_id'] ?? null) !== (int) $actor->id
            || ($ref['project_id'] ?? null) !== ($ref['entity_id'] ?? null) || ! is_int($ref['entity_id'] ?? null) || ! is_string($ref['source_version'] ?? null)) { return false; }
        try { return $this->read($actor, $organizationId, ['project_id' => $ref['entity_id']])['source_refs'][0]['source_version'] === $ref['source_version']; }
        catch (\Throwable) { return false; }
    }
    public function verifiedAnswer(array $result, User $actor, int $organizationId): ?array
    {
        $ref = $result['source_refs'][0] ?? null;
        if (! is_array($ref) || ! $this->matchesReference($actor, $organizationId, $ref)) { return null; }
        $current = $this->read($actor, $organizationId, ['project_id' => $ref['entity_id']]);
        if ($current['source_refs'][0]['source_version'] !== $ref['source_version']) { return null; }
        return ['text' => $current['server_formatted_answer'], 'source_refs' => $current['source_refs'], 'validation_status' => 'partial', 'replaced' => true, 'needs_clarification' => false];
    }
    private static function format(array $data): string
    {
        $lines = [trans_message('ai_assistant_machinery.scope', ['project' => self::text($data['project']['name']), 'date' => $data['as_of_date']])];
        $lines[] = trans_message($data['assets_available'] ? 'ai_assistant_machinery.assets' : 'ai_assistant_machinery.limit', ['total' => $data['asset_count'], 'shown' => count($data['assets'])]);
        $canonical = array_column($data['canonical_states'], null, 'id');
        if ($data['assets_available']) {
            foreach ($data['assets'] as $asset) {
                $status = in_array($asset['status'], ['available', 'assigned', 'in_operation', 'under_maintenance', 'out_of_service', 'archived'], true) ? $asset['status'] : 'unknown';
                $technical = $canonical[$asset['organization_asset_id']]['technical_status'] ?? 'unknown';
                if (! in_array($technical, ['serviceable', 'restricted', 'maintenance', 'unavailable'], true)) { $technical = 'unknown'; }
                $lines[] = '- #'.$asset['id'].' '.self::text($asset['name']).' ('.self::text($asset['asset_code']).'): '
                    .trans_message('ai_assistant_machinery.status_'.$status).'; '.trans_message('ai_assistant_machinery.technical_'.$technical);
            }
        }
        foreach ($data['sections'] as $type => $section) {
            $lines[] = trans_message($section['available'] ? 'ai_assistant_machinery.'.$type : 'ai_assistant_machinery.unavailable', ['count' => $section['total'] ?? null]);
            if (! $section['available']) { continue; }
            foreach ($section['examples'] as $example) {
                $lines[] = '- #'.$example['id'].' ('.trans_message('ai_assistant_machinery.asset_id', ['id' => $example['asset_id']]).') '.self::text((string) ($example['title'] ?? $example['reason'] ?? ''));
            }
        }
        $history = $data['assignment_history'];
        if ($history['available']) {
            $lines[] = trans_message('ai_assistant_machinery.assignment_history', ['shown' => count($history['items'])]);
            foreach ($history['items'] as $assignment) {
                $status = in_array($assignment['status'], ['active', 'completed', 'cancelled', 'planned'], true) ? $assignment['status'] : 'unknown';
                $lines[] = trans_message('ai_assistant_machinery.assignment_row', [
                    'id' => $assignment['id'], 'asset' => $assignment['asset_id'], 'status' => trans_message('ai_assistant_machinery.assignment_'.$status),
                    'start' => self::date($assignment['planned_start_at']), 'end' => self::date($assignment['planned_end_at']),
                    'actual_start' => self::date($assignment['actual_start_at']), 'actual_end' => self::date($assignment['actual_end_at']),
                ]);
            }
            if (! $history['complete']) { $lines[] = trans_message('ai_assistant_machinery.assignment_history_partial'); }
        } else { $lines[] = trans_message('ai_assistant_machinery.unavailable'); }
        $lines[] = trans_message('ai_assistant_machinery.assignment_basis');
        $lines[] = trans_message('ai_assistant_machinery.action');
        $lines[] = trans_message('ai_assistant_machinery.basis');
        return implode("\n", $lines);
    }
    private static function date(?string $value): string
    {
        return $value === null ? trans_message('ai_assistant_machinery.date_unknown') : self::text($value);
    }
    private static function text(string $value): string
    {
        return str_replace(['*', '_', '[', ']', '`'], ['\\*', '\\_', '\\[', '\\]', '\\`'], htmlspecialchars(mb_substr(preg_replace('/[\p{Cc}\p{Cf}]/u', ' ', $value) ?? '', 0, 255), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
    }
}
