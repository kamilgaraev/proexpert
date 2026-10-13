<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services;

use App\BusinessModules\Features\HandoverAcceptance\Models\AcceptanceScope;
use App\BusinessModules\Features\HandoverAcceptance\Services\HandoverAcceptanceGate;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

final readonly class AssistantProjectHandoverReader
{
    public const TYPE = 'live_project_handover_projection';

    public function __construct(private AssistantDataAccessPolicy $access, private HandoverAcceptanceGate $gate) {}

    public static function matches(string $query): bool
    {
        return self::hasIntent($query)
            && preg_match('/прими|принять\s+зон|передай|создай|измени|удали|отправь|подпиши|оплат|оплач|плат[её]ж|сч[её]т|деньг|сумм|бюджет|расход|затрат|стоим|выручк|прибыл|марж|смет|договор|контрагент|подрядчик|поставк|закуп|склад|материал|табел|сотрудник|журнал|безопасност|техник|бим|ifc|bim|график|критическ|перескаж|содержани/iu', $query) !== 1;
    }

    public static function hasIntent(string $query): bool
    {
        return preg_match('/при[её]мк|передач[а-яё]*\s+(?:наш|объект|зон)|зон[а-яё]*.*принят|handover/iu', $query) === 1;
    }

    public function read(User $actor, int $organizationId, array $arguments): array
    {
        $projectId = $arguments['project_id'] ?? null;
        if (! is_int($projectId) || $projectId < 1 || array_keys($arguments) !== ['project_id']) { throw new \InvalidArgumentException('invalid_handover_scope'); }
        return $this->access->withCurrentChecks($actor, $organizationId, function () use ($actor, $organizationId, $projectId): array {
            if (! $this->access->canReadDomain($actor, $organizationId, 'assistant')
                || ! $this->access->canReadDomain($actor, $organizationId, 'handover_acceptance')
                || ! $this->access->canReadEntity($actor, $organizationId, 'project', $projectId)) { throw new AuthorizationException; }
            $project = $this->access->entityQuery($actor, $organizationId, 'project')?->whereKey($projectId)->first(['id', 'name']);
            $query = $this->access->entityQuery($actor, $organizationId, 'acceptance_scope');
            if ($project === null || $query === null) { throw new AuthorizationException; }
            $query->where('project_id', $projectId);
            $counts = (clone $query)->select('status')->selectRaw('COUNT(*) AS total')->groupBy('status')->orderBy('status')->get()
                ->mapWithKeys(static fn ($row): array => [(string) $row->status => (int) $row->getAttribute('total')])->all();
            $counts = array_replace(array_fill_keys(['planned', 'in_progress', 'findings_open', 'ready_for_reinspection', 'accepted', 'handed_over', 'reopened', 'rejected'], 0), $counts);
            $scopes = (clone $query)->orderByRaw("CASE WHEN status IN ('in_progress', 'findings_open', 'ready_for_reinspection', 'reopened', 'rejected') THEN 0 WHEN status = 'planned' THEN 1 WHEN status = 'accepted' THEN 2 ELSE 3 END")
                ->orderBy('id')->limit(20)->select(['id', 'organization_id', 'project_id', 'title', 'status', 'planned_acceptance_date'])->get();
            $examples = [];
            foreach ($scopes as $scope) {
                if (! $scope instanceof AcceptanceScope) { throw new AuthorizationException; }
                $example = $scope->only(['id', 'title', 'status', 'planned_acceptance_date']);
                $example['readiness_available'] = $this->bounded($scope);
                if ($example['readiness_available']) {
                    $acceptance = $this->gate->evaluate($scope);
                    $handover = $this->gate->evaluate($scope, true);
                    $example['acceptance_ready'] = (bool) $acceptance['ready'];
                    $example['handover_ready'] = (bool) $handover['ready'];
                    $example['acceptance_blockers'] = self::codes($acceptance['blockers']);
                    $example['handover_blockers'] = self::codes($handover['blockers']);
                }
                $examples[] = $example;
            }
            $payload = ['project' => ['id' => $projectId, 'name' => $project->name], 'as_of_date' => today()->toDateString(),
                'total' => array_sum($counts), 'status_counts' => $counts, 'examples' => $examples];
            $version = AssistantSourceReferenceIdentity::key(['schema' => 1, 'organization_id' => $organizationId, 'actor_id' => (int) $actor->id, 'payload' => $payload]);
            $ref = ['entity_type' => self::TYPE, 'entity_id' => $projectId, 'project_id' => $projectId, 'organization_id' => $organizationId,
                'actor_id' => (int) $actor->id, 'source_version' => $version, 'fetched_at' => now()->toIso8601String()];
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

    private function bounded(AcceptanceScope $scope): bool
    {
        foreach (['checklists', 'findings', 'workReworks', 'workQuantities'] as $relation) {
            if ($scope->{$relation}()->count() > 500) { return false; }
        }
        $checklists = $scope->checklists()->select('id')->withCount('items')->get();
        if ($checklists->sum('items_count') > 500) { return false; }
        $package = $scope->handoverPackage()->first(['id', 'executive_document_set_id']);
        if ($package !== null && $package->documents()->count() > 500) { return false; }
        $set = $package?->executiveDocumentSet;
        if ($set !== null) {
            $size = $set->requirements()->whereNull('superseded_at')->selectRaw("COUNT(*) AS total,
                COALESCE(SUM(octet_length(to_jsonb(executive_document_requirements)::text)), 0) AS bytes,
                COALESCE(SUM(CASE WHEN jsonb_typeof(evidence::jsonb) = 'array' THEN jsonb_array_length(evidence::jsonb) ELSE 0 END), 0) AS links,
                COALESCE(SUM(CASE WHEN evidence IS NOT NULL AND jsonb_typeof(evidence::jsonb) <> 'array' THEN 1 ELSE 0 END), 0) AS malformed")->first();
            if ($size === null || (int) $size->getAttribute('total') > 500 || (int) $size->getAttribute('links') > 500
                || (int) $size->getAttribute('bytes') > 262144 || (int) $size->getAttribute('malformed') > 0) { return false; }
        }
        return true;
    }

    private static function codes(array $blockers): array
    {
        $counts = [];
        foreach ($blockers as $blocker) {
            $code = (string) ($blocker['code'] ?? 'unknown');
            $counts[$code] = ($counts[$code] ?? 0) + 1;
        }
        ksort($counts);
        return $counts;
    }

    private static function format(array $data): string
    {
        $lines = [trans_message('ai_assistant_handover.scope', ['project' => self::text($data['project']['name']), 'date' => $data['as_of_date']]),
            trans_message('ai_assistant_handover.total', ['total' => $data['total'], 'shown' => count($data['examples'])])];
        foreach ($data['status_counts'] as $status => $count) { $lines[] = '- '.self::status($status).': '.$count; }
        foreach ($data['examples'] as $example) {
            $lines[] = "\n- #".$example['id'].' '.self::text($example['title']).': '.self::status($example['status']);
            if (! $example['readiness_available']) { $lines[] = trans_message('ai_assistant_handover.limit'); continue; }
            $lines[] = trans_message('ai_assistant_handover.readiness', ['acceptance' => trans_message('ai_assistant_handover.'.($example['acceptance_ready'] ? 'ready' : 'blocked')),
                'handover' => trans_message('ai_assistant_handover.'.($example['handover_ready'] ? 'ready' : 'blocked'))]);
            foreach (array_unique(array_keys($example['acceptance_blockers'] + $example['handover_blockers'])) as $code) {
                $key = 'ai_assistant_handover.blocker_'.$code;
                $message = trans_message($key);
                if ($message === $key) { $message = trans_message('ai_assistant_handover.blocker_unknown'); }
                $lines[] = trans_message('ai_assistant_handover.blocker', ['message' => $message,
                    'acceptance' => $example['acceptance_blockers'][$code] ?? 0, 'handover' => $example['handover_blockers'][$code] ?? 0]);
            }
            $action = match ($example['status']) {
                'handed_over' => 'done', 'planned', 'reopened', 'rejected' => 'start',
                'accepted' => $example['handover_ready'] ? 'handover' : 'unblock_handover',
                'findings_open' => 'findings', 'in_progress', 'ready_for_reinspection' => $example['acceptance_ready'] ? 'accept' : 'unblock_acceptance',
                default => 'unknown',
            };
            $lines[] = trans_message('ai_assistant_handover.action_'.$action);
        }
        $lines[] = trans_message('ai_assistant_handover.basis');
        return implode("\n", $lines);
    }

    private static function status(string $status): string
    {
        return trans_message('ai_assistant_handover.status_'.(in_array($status, ['planned', 'in_progress', 'findings_open', 'ready_for_reinspection', 'accepted', 'handed_over', 'reopened', 'rejected'], true) ? $status : 'unknown'));
    }

    private static function text(string $value): string
    {
        return str_replace(['*', '_', '[', ']', '`'], ['\\*', '\\_', '\\[', '\\]', '\\`'], htmlspecialchars(mb_substr(preg_replace('/[\p{Cc}\p{Cf}]/u', ' ', $value) ?? '', 0, 255), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
    }
}
