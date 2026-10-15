<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services;

use App\BusinessModules\Features\AIAssistant\Services\RequestUnderstanding\AssistantMutationIntent;
use App\BusinessModules\Features\AIAssistant\Services\RequestUnderstanding\AssistantTemporalIntent;
use App\Models\Contract;
use App\Models\User;
use App\Services\Contract\ContractOrganizationListScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

final readonly class AssistantProjectContractRosterReader
{
    public const TYPE = 'live_project_contract_roster_projection';
    public function __construct(private AssistantDataAccessPolicy $access, private ContractOrganizationListScope $nativeScope) {}
    public static function matches(string $query): bool
    {
        $query = preg_replace('/(?<!\pL)документ[а-яё]*\s+без\s+(?:указанн[а-яё]*\s+)?дат[а-яё]*\s+окончания(?!\pL)/iu', 'договоры без даты окончания', $query) ?? $query;
        $terminalStatuses = '(?:заверш[её]н|расторгнут|приостановл|чернов|архив|закрыт)[а-яё]*';
        $statusQuery = preg_replace('/(?<!\pL)не\s*'.$terminalStatuses.'(?!\pL)/iu', '', $query) ?? $query;
        $detailQuery = preg_replace('/(?:по\s+состоянию\s+)?на\s+сегодня(?:шний\s+день)?/iu', '', $query) ?? $query;
        $hasActiveStatus = preg_match('/(?<!\pL)(?:действующ[а-яё]*|действу(?:ют|ет)|активн[а-яё]*)(?!\pL)/iu', $query) === 1;
        $hasEndDate = preg_match('/(?:срок[а-яё]*\s+окончан|без\s+срока\s+(?:окончан|действи)[а-яё]*|без\s+срока(?=\s*(?:$|[.!?;,]))|дат[а-яё]*\s+окончан)/iu', $query) === 1;
        $hasPluralContracts = preg_match('/(?<!\pL)договор(?:ы|ов|ам|ами|ах)(?!\pL)|(?:действующие|активные|какие)\s+договора(?!\pL)/iu', $query) === 1;
        return $hasPluralContracts && ($hasActiveStatus || $hasEndDate)
            && preg_match('/№|#|(?<!\pL)(?:id|номер)(?!\pL)/iu', $query) !== 1
            && preg_match('/(?<!\pL)(?:подрядчика|подрядчиком|контрагента|контрагентом|поставщика|поставщиком|заказчика|заказчиком|исполнителя|исполнителем)(?!\pL)|по\s+(?:подрядчик|контрагент|поставщик|заказчик|исполнител)[а-яё]*|сгруппир/iu', $query) !== 1
            && ! AssistantMutationIntent::containsRequest($query)
            && ! AssistantMutationIntent::containsFollowUpCommand($query)
            && ! AssistantMutationIntent::hasPresentationRequest($query)
            && ! AssistantTemporalIntent::hasExplicitWindow($query)
            && preg_match('/(?:позже|раньше)\s+вс(?:его|ех)|сам[а-яё]*\s+(?:поздн|ранн|дальн|дал[её]к|близк)|отсортир|по\s+убыван|в\s+обратн|максимальн|минимальн/iu', $query) !== 1
            && preg_match('/(?<!\pL)не\s*(?:действующ[а-яё]*|действу(?:ют|ет)|активн[а-яё]*)(?!\pL)/iu', $query) !== 1
            && preg_match('/аванс|гаранти|начал|написан|содержани|текст|услови|пункт/iu', $query) !== 1
            && preg_match('/исполнени[ея]|выполнени[ея]|обязательств/iu', $query) !== 1
            && preg_match('/(?<!\pL)'.$terminalStatuses.'(?!\pL)/iu', $statusQuery) !== 1
            && preg_match('/создай|измени|подпиши|заключи|расторгни|приостанови|закрой|архивируй|обнови|удали|отправь|бюджет|расход|затрат|потрач|выручк|прибыл|марж|рентабел|ден[еьё]|финанс|валют|цен[аы]|долг|задолж|остат|выплат|плат[её]ж|оплат|сумм|стоим|сч[её]т|табел|поставк|смет|дефект|замечан|техник|машин|механизм|документ|файл|график|задач|бим|ifc|bim/iu', $detailQuery) !== 1;
    }
    public function read(User $actor, int $organizationId, array $arguments): array
    {
        $projectId = $arguments['project_id'] ?? null;
        if (! is_int($projectId) || $projectId < 1 || array_keys($arguments) !== ['project_id']) { throw new \InvalidArgumentException('invalid_contract_roster_scope'); }
        return $this->access->withCurrentChecks($actor, $organizationId, function () use ($actor, $organizationId, $projectId): array {
            if (! $this->access->canReadDomain($actor, $organizationId, 'assistant') || ! $this->access->canReadDomain($actor, $organizationId, 'contracts')
                || ! $this->access->canReadEntity($actor, $organizationId, 'project', $projectId)) { throw new AuthorizationException; }
            $project = $this->access->entityQuery($actor, $organizationId, 'project')?->whereKey($projectId)->first(['id', 'name']);
            if ($project === null) { throw new AuthorizationException; }
            $query = Contract::query();
            $this->nativeScope->apply($query, $organizationId, ['status' => ['active']], static function (Builder $legacy) use ($organizationId): void {
                $legacy->where(static fn (Builder $parties): Builder => $parties->where('contracts.organization_id', $organizationId)
                    ->orWhereHas('contractor', static fn (Builder $counterparty): Builder => $counterparty->where('source_organization_id', $organizationId))
                    ->orWhereHas('parties', static fn (Builder $party): Builder => $party->where('linked_organization_id', $organizationId)));
            });
            $query->where(static fn (Builder $projects): Builder => $projects->where('contracts.project_id', $projectId)
                ->orWhereExists(static fn (QueryBuilder $pivot): QueryBuilder => $pivot->selectRaw('1')->from('contract_project')
                    ->whereColumn('contract_project.contract_id', 'contracts.id')->where('contract_project.project_id', $projectId)));
            $records = $query->orderBy('contracts.end_date')->orderBy('contracts.id')->limit(10001)->select([
                'contracts.id', 'contracts.organization_id', 'contracts.number', 'contracts.subject', 'contracts.status', 'contracts.start_date', 'contracts.end_date',
                'contracts.contractor_id', 'contracts.is_self_execution', 'contracts.updated_at'])->get();
            $counterparties = $this->access->entityQuery($actor, $organizationId, 'contractor')?->whereKey($records->where('organization_id', $organizationId)->pluck('contractor_id')->filter()->all())
                ->select(['id', 'name'])->get()->keyBy('id');
            $rows = [];
            foreach ($records as $record) {
                $own = (int) $record->organization_id === $organizationId;
                $name = $own ? $counterparties?->get($record->contractor_id)?->name : null;
                $rows[] = ['id' => (int) $record->id, 'number' => $record->getRawOriginal('number'), 'status' => $record->getRawOriginal('status'),
                    'start_date' => $record->getRawOriginal('start_date'), 'end_date' => $record->getRawOriginal('end_date'),
                    'counterparty' => $name, 'self_execution' => $own && (bool) $record->is_self_execution,
                    'shared' => ! $own, 'source_version' => $record->getRawOriginal('updated_at')];
            }
            $asOf = today()->toDateString(); $endingBy = today()->addDays(7)->toDateString(); $complete = count($rows) <= 10000;
            $payload = ['project' => ['id' => $projectId, 'name' => $project->name], 'as_of_date' => $asOf, 'complete' => $complete,
                'total' => count($rows) <= 10000 ? count($rows) : null, 'items' => array_slice($rows, 0, 20),
                'undated_items' => array_slice(array_values(array_filter($rows, static fn (array $row): bool => $row['end_date'] === null)), 0, 20),
                'ending_by_date' => $endingBy,
                'overdue_count' => $complete ? count(array_filter($rows, static fn (array $row): bool => $row['end_date'] !== null && substr($row['end_date'], 0, 10) < $asOf)) : null,
                'ending_soon_count' => $complete ? count(array_filter($rows, static fn (array $row): bool => $row['end_date'] !== null && substr($row['end_date'], 0, 10) >= $asOf && substr($row['end_date'], 0, 10) <= $endingBy)) : null,
                'missing_end_date_count' => $complete ? count(array_filter($rows, static fn (array $row): bool => $row['end_date'] === null)) : null];
            $version = AssistantSourceReferenceIdentity::key(['schema' => 1, 'organization_id' => $organizationId, 'actor_id' => (int) $actor->id, 'payload' => $payload, 'rows' => $rows]);
            $ref = ['entity_type' => self::TYPE, 'entity_id' => $projectId, 'project_id' => $projectId, 'organization_id' => $organizationId,
                'actor_id' => (int) $actor->id, 'source_version' => $version, 'fetched_at' => now()->toIso8601String()];
            return ['status' => 'success', 'source_refs' => [$ref], 'data' => $payload, 'server_formatted_answer' => self::format($payload)];
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
        return ['text' => $current['server_formatted_answer'], 'source_refs' => $current['source_refs'], 'validation_status' => 'verified', 'replaced' => true, 'needs_clarification' => false];
    }
    private static function format(array $data): string
    {
        $lines = [trans_message('ai_assistant_contract_roster.scope', ['project' => self::text($data['project']['name']), 'date' => $data['as_of_date']])];
        if (! $data['complete']) { return $lines[0]."\n".trans_message('ai_assistant_contract_roster.limit'); }
        $datedItems = array_values(array_filter($data['items'], static fn (array $item): bool => $item['end_date'] !== null));
        $lines[] = trans_message('ai_assistant_contract_roster.count', ['total' => $data['total'], 'shown' => count($datedItems) + count($data['undated_items'])]);
        $lines[] = trans_message('ai_assistant_contract_roster.deadlines', ['overdue' => $data['overdue_count'], 'soon' => $data['ending_soon_count'],
            'date' => $data['ending_by_date'], 'undated' => $data['missing_end_date_count']]);
        foreach (array_merge($datedItems, $data['undated_items']) as $index => $item) {
            if ($index === count($datedItems)) { $lines[] = trans_message('ai_assistant_contract_roster.undated_group', ['shown' => count($data['undated_items']), 'total' => $data['missing_end_date_count']]); }
            $name = $item['self_execution'] ? trans_message('ai_assistant_contract_roster.self_execution')
                : ($item['counterparty'] === null ? trans_message('ai_assistant_contract_roster.counterparty_unknown') : self::text($item['counterparty']));
            $end = $item['end_date'] === null ? trans_message('ai_assistant_contract_roster.no_date') : substr($item['end_date'], 0, 10);
            $lines[] = '- #'.$item['id'].' '.self::text((string) $item['number']).': '.trans_message('ai_assistant_contract_roster.row', ['counterparty' => $name, 'end' => $end]);
            if ($item['end_date'] !== null && substr($item['end_date'], 0, 10) < $data['as_of_date']) { $lines[] = trans_message('ai_assistant_contract_roster.overdue'); }
        }
        $lines[] = trans_message('ai_assistant_contract_roster.basis');
        $lines[] = trans_message('ai_assistant_contract_roster.renewal');
        return implode("\n", $lines);
    }
    private static function text(string $value): string
    {
        return str_replace(['*', '_', '[', ']', '`'], ['\\*', '\\_', '\\[', '\\]', '\\`'], htmlspecialchars(mb_substr(preg_replace('/[\p{Cc}\p{Cf}]/u', ' ', $value) ?? '', 0, 255), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
    }
}
