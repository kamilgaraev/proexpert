<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Operational;

use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\AIAssistant\Services\AssistantSourceReferenceIdentity;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;

final readonly class AssistantTenderReader
{
    public const TYPE = 'live_tender_decisions_projection';

    public function __construct(private AssistantDataAccessPolicy $access) {}

    public static function matches(string $query): bool
    {
        return preg_match('/тендер/iu', $query) === 1 && preg_match('/решени|срок|просроч|подач/iu', $query) === 1
            && preg_match('/создай|удали|отправь|измени|участвуй|оплат|смет|договор|сотрудник|заявк|сумм|стоим|цен/iu', $query) !== 1;
    }

    public function read(User $actor, int $organizationId, array $arguments): array
    {
        if (array_keys($arguments) !== ['project_id'] || ($arguments['project_id'] !== null && (! is_int($arguments['project_id']) || $arguments['project_id'] < 1))) {
            throw new \InvalidArgumentException('invalid_tender_scope');
        }
        return $this->access->withCurrentChecks($actor, $organizationId, function () use ($actor, $organizationId, $arguments): array {
            if (! $this->access->canReadDomain($actor, $organizationId, 'assistant') || ! $this->access->canReadDomain($actor, $organizationId, 'tenders')) { throw new AuthorizationException; }
            $projectId = $arguments['project_id'];
            $query = $this->access->entityQuery($actor, $organizationId, 'tender');
            if ($query === null) { throw new AuthorizationException; }
            $project = null;
            if ($projectId !== null) {
                if (! $this->access->canReadEntity($actor, $organizationId, 'project', $projectId)
                    || ! $this->access->applyEntityProjectScope($actor, $organizationId, 'tender', $query, $projectId)) { throw new AuthorizationException; }
                $project = $this->access->entityQuery($actor, $organizationId, 'project')?->whereKey($projectId)->value('name');
                if (! is_string($project)) { throw new AuthorizationException; }
            }
            $asOf = now();
            $pending = (clone $query)->where('status', 'go_no_go')->count();
            $active = (clone $query)->whereNotIn('status', ['won', 'lost', 'cancelled']);
            $notSubmitted = (clone $active)->whereNull('submitted_at');
            $overdue = (clone $notSubmitted)->where('submission_deadline_at', '<', $asOf)->count();
            $soon = (clone $notSubmitted)->whereBetween('submission_deadline_at', [$asOf, $asOf->copy()->addDays(7)])->count();
            $examples = (clone $active)->where(static fn (Builder $q) => $q->where('status', 'go_no_go')
                ->orWhere(static fn (Builder $q) => $q->whereNull('submitted_at')->where('submission_deadline_at', '<=', $asOf->copy()->addDays(7))))
                ->orderByRaw('submission_deadline_at IS NULL')->orderBy('submission_deadline_at')->orderBy('id')->limit(20)
                ->select(['id', 'number', 'title', 'status', 'project_id', 'submission_deadline_at', 'owner_user_id'])
                ->with('owner:id,name')->get()->map(static fn ($row): array => ['id' => (string) $row->id, 'number' => $row->number, 'title' => $row->title,
                    'decision_required' => $row->status === 'go_no_go', 'project_id' => $row->project_id,
                    'submission_deadline' => $row->submission_deadline_at?->toIso8601String(), 'owner' => $row->owner?->name])->all();
            $data = ['project_id' => $projectId, 'project_name' => $project, 'as_of_date' => $asOf->toDateString(),
                'total' => (clone $query)->count(), 'pending' => $pending, 'submission_overdue' => $overdue, 'submission_soon' => $soon, 'examples' => $examples];
            $version = AssistantSourceReferenceIdentity::key(['schema' => 1, 'type' => self::TYPE, 'organization_id' => $organizationId, 'actor_id' => (int) $actor->id, 'data' => $data]);
            $ref = ['entity_type' => self::TYPE, 'entity_id' => $organizationId, 'project_id' => $projectId, 'organization_id' => $organizationId,
                'actor_id' => (int) $actor->id, 'source_version' => $version, 'fetched_at' => now()->toIso8601String()];
            return ['status' => 'partial', 'source_refs' => [$ref], 'data' => $data, 'server_formatted_answer' => $this->format($data)];
        }, true);
    }

    public function matchesReference(User $actor, int $organizationId, array $ref): bool
    {
        if (($ref['entity_type'] ?? null) !== self::TYPE || ($ref['organization_id'] ?? null) !== $organizationId || ($ref['entity_id'] ?? null) !== $organizationId
            || ($ref['actor_id'] ?? null) !== (int) $actor->id || ! array_key_exists('project_id', $ref) || ! is_string($ref['source_version'] ?? null)) { return false; }
        try { return $this->read($actor, $organizationId, ['project_id' => $ref['project_id']])['source_refs'][0]['source_version'] === $ref['source_version']; }
        catch (\Throwable) { return false; }
    }

    public function verifiedAnswer(array $result, User $actor, int $organizationId): ?array
    {
        $ref = $result['source_refs'][0] ?? null;
        if (! is_array($ref) || ! $this->matchesReference($actor, $organizationId, $ref)) { return null; }
        $current = $this->read($actor, $organizationId, ['project_id' => $ref['project_id']]);
        if ($current['source_refs'][0]['source_version'] !== $ref['source_version']) { return null; }
        return ['text' => $current['server_formatted_answer'], 'source_refs' => $current['source_refs'], 'validation_status' => 'partial', 'replaced' => true, 'needs_clarification' => false];
    }

    private function format(array $data): string
    {
        $scope = $data['project_id'] === null ? trans_message('ai_assistant_operational.tender_organization') : self::text($data['project_name']);
        $lines = [trans_message('ai_assistant_operational.tender_scope', ['scope' => $scope, 'date' => $data['as_of_date']]),
            trans_message('ai_assistant_operational.tender_counts', ['total' => $data['total'], 'pending' => $data['pending'], 'overdue' => $data['submission_overdue'], 'soon' => $data['submission_soon']])];
        foreach ($data['examples'] as $row) {
            $lines[] = '- '.self::text((string) $row['number']).' '.self::text($row['title']).'; '.trans_message('ai_assistant_operational.tender_entry', [
                'owner' => self::text($row['owner'] ?? trans_message('ai_assistant_operational.owner_unknown')),
                'date' => $row['submission_deadline'] ?? trans_message('ai_assistant_operational.not_recorded'),
                'decision' => trans_message('ai_assistant_operational.tender_decision_'.($row['decision_required'] ? 'pending' : 'other'))]);
        }
        $lines[] = trans_message('ai_assistant_operational.tender_basis');
        $lines[] = trans_message('ai_assistant_operational.examples_basis');
        return implode("\n", $lines);
    }

    private static function text(string $value): string
    {
        return str_replace(['*', '_', '[', ']', '`'], ['\\*', '\\_', '\\[', '\\]', '\\`'], htmlspecialchars(mb_substr(preg_replace('/[\p{Cc}\p{Cf}]/u', ' ', $value) ?? '', 0, 255), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
    }
}
