<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Operational;

use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\AIAssistant\Services\AssistantSourceReferenceIdentity;
use App\BusinessModules\Features\AIAssistant\Services\AssistantTextSearch;
use App\Models\Contract;
use App\Models\ContractOrganizationView;
use App\Models\User;
use App\Services\Contract\ContractSupplementaryDocumentService;
use App\Services\Project\UserProjectAccessService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

final readonly class AssistantContractSupplementaryReader
{
    public const TYPE = 'live_contract_supplementary_readiness_projection';
    public function __construct(private AssistantDataAccessPolicy $access, private ContractSupplementaryDocumentService $documents) {}

    public static function hasIntent(string $query): bool
    {
        return preg_match('/дополнительн[а-яё]*\s+(?:соглашен|документ)|допсоглашен/iu', $query) === 1;
    }

    public static function matches(string $query): bool
    {
        return self::hasIntent($query) && preg_match('/создай|удали|активируй|подпиши|измени|согласуй|оплат|бюджет|расход|смет|сумм|стоим|выручк|прибыл|марж|рентабел|затрат|плат[её]ж|сч[её]т|уведомлен|тендер|заявк|сотрудник|журнал|график|задач|бим|ifc|bim|охран|безопасност|исполнительн|наряд/iu', $query) !== 1
            && preg_match(AssistantProjectActReader::INTENT_PATTERN, $query) !== 1
            && ! AssistantOrganizationProjectsReader::hasIntent($query);
    }

    public static function contractNumber(string $query): ?string
    {
        if (preg_match('/договор[а-яё]*\s*(?:№\s*)?([\pL\d]+(?:[-–—][\pL\d]+){1,8})(?![\pL\d-])/iu', $query, $match) !== 1
            || preg_match('/\d/u', $match[1]) !== 1 || mb_strlen($match[1]) > 100) { return null; }
        return str_replace(['–', '—'], '-', $match[1]);
    }

    public static function projectQuery(string $query): ?string
    {
        if (preg_match('/(?:по|в|для)\s+(?:проект[а-яё]*|объект[а-яё]*)\s+(?:№\s*)?[«"“]([^»"”]{1,100})[»"”]/iu', $query, $match) === 1) { return trim($match[1]); }
        return preg_match('/(?:по|в|для)\s+(?:проект[а-яё]*|объект[а-яё]*)\s+(?:№\s*)?([\pL\d][\pL\d._ -]{0,99}?)(?=[?.!,;]|$)/iu', $query, $match) === 1 ? trim($match[1]) : null;
    }

    public function read(User $actor, int $organizationId, array $arguments): array
    {
        if (array_diff(array_keys($arguments), ['contract_number', 'project_id', 'project_query']) !== [] || count($arguments) !== 3
            || ! is_string($arguments['contract_number'] ?? null) || trim($arguments['contract_number']) === '' || mb_strlen($arguments['contract_number']) > 100
            || ! array_key_exists('project_id', $arguments) || ($arguments['project_id'] !== null && (! is_int($arguments['project_id']) || $arguments['project_id'] < 1))
            || ! array_key_exists('project_query', $arguments) || ($arguments['project_query'] !== null && (! is_string($arguments['project_query']) || trim($arguments['project_query']) === '' || mb_strlen($arguments['project_query']) > 100))) {
            throw new \InvalidArgumentException('invalid_supplementary_scope');
        }
        return $this->access->withCurrentChecks($actor, $organizationId, function () use ($actor, $organizationId, $arguments): array {
            if (! $this->access->canReadDomain($actor, $organizationId, 'assistant') || ! $this->access->canReadDomain($actor, $organizationId, 'contracts')
                || ! $this->access->canCurrentPermission($actor, $organizationId, 'contracts.view')) { throw new AuthorizationException; }
            $projectId = $arguments['project_id']; $projectName = null; $projectAvailable = true;
            if ($projectId !== null && ! $this->access->canReadEntity($actor, $organizationId, 'project', $projectId)) { throw new AuthorizationException; }
            if ($arguments['project_query'] !== null) {
                $projects = $this->access->entityQuery($actor, $organizationId, 'project');
                if ($projects === null) { throw new AuthorizationException; }
                if (preg_match('/^[1-9]\d{0,9}$/', $arguments['project_query']) === 1) {
                    $matches = $projects->whereKey((int) $arguments['project_query'])->select(['projects.id', 'projects.name'])->limit(2)->get();
                } else {
                    $matches = (clone $projects)->whereRaw('LOWER(projects.name) = ?', [mb_strtolower(trim($arguments['project_query']))])
                        ->select(['projects.id', 'projects.name'])->limit(2)->get();
                    if ($matches->isEmpty()) {
                        AssistantTextSearch::apply($projects, ['projects.name'], $arguments['project_query']);
                        $matches = $projects->select(['projects.id', 'projects.name'])->limit(2)->get();
                    }
                }
                $projectAvailable = $matches->count() === 1;
                $projectId = $projectAvailable ? (int) $matches->first()->id : null;
                $projectName = $projectAvailable ? (string) $matches->first()->name : null;
            }
            $accessible = app(UserProjectAccessService::class)->queryAccessibleProjects($actor, $organizationId)->select('projects.id');
            $views = ContractOrganizationView::query()->where('organization_id', $organizationId)->whereNull('access_revoked_at')->select('contract_id');
            $query = Contract::query()->where(static fn (Builder $q) => $q->where('contracts.organization_id', $organizationId)->orWhereIn('contracts.id', $views))
                ->where(static fn (Builder $q) => $q->whereNull('contracts.project_id')->orWhereIn('contracts.project_id', $accessible)
                    ->orWhereExists(static fn (QueryBuilder $pivot) => $pivot->selectRaw('1')->from('contract_project')
                        ->whereColumn('contract_project.contract_id', 'contracts.id')->whereIn('contract_project.project_id', $accessible)));
            if ($projectId !== null) {
                $query->where(static fn (Builder $q) => $q->where('contracts.project_id', $projectId)
                    ->orWhereExists(static fn (QueryBuilder $pivot) => $pivot->selectRaw('1')->from('contract_project')
                        ->whereColumn('contract_project.contract_id', 'contracts.id')->where('contract_project.project_id', $projectId)));
            }
            if (! $projectAvailable) { $query->whereRaw('1 = 0'); }
            $contracts = $query->whereRaw('LOWER(contracts.number) = ?', [mb_strtolower(trim($arguments['contract_number']))])->limit(2)
                ->select(['contracts.id', 'contracts.number', 'contracts.project_id'])->get();
            $data = ['as_of_date' => today()->toDateString(), 'contract_number' => $arguments['contract_number'], 'project_id' => $projectId, 'project_name' => $projectName,
                'available' => false, 'reason' => $projectAvailable ? 'not_unique' : 'project_not_unique'];
            if ($contracts->count() === 1) {
                $contract = $contracts->first(); $data['contract_number'] = $contract->number; $data['contract_id'] = (int) $contract->id;
                try { $data = array_replace($data, $this->documents->readinessFacts($actor, $organizationId, (int) $contract->id), ['available' => true, 'reason' => null]); }
                catch (ModelNotFoundException|AuthorizationException) { $data['reason'] = 'native_unavailable'; }
            }
            $version = AssistantSourceReferenceIdentity::key(['schema' => 1, 'type' => self::TYPE, 'organization_id' => $organizationId,
                'actor_id' => (int) $actor->id, 'arguments' => $arguments, 'data' => $data]);
            $ref = ['entity_type' => self::TYPE, 'entity_id' => $organizationId, 'project_id' => $projectId, 'organization_id' => $organizationId,
                'actor_id' => (int) $actor->id, 'arguments' => $arguments, 'source_version' => $version, 'fetched_at' => now()->toIso8601String()];
            return ['status' => 'partial', 'source_refs' => [$ref], 'data' => $data, 'server_formatted_answer' => $this->format($data)];
        }, true);
    }

    public function matchesReference(User $actor, int $organizationId, array $ref): bool
    {
        if (($ref['entity_type'] ?? null) !== self::TYPE || ($ref['entity_id'] ?? null) !== $organizationId || ($ref['organization_id'] ?? null) !== $organizationId
            || ($ref['actor_id'] ?? null) !== (int) $actor->id || ! is_array($ref['arguments'] ?? null) || ! is_string($ref['source_version'] ?? null)
            || ! array_key_exists('project_id', $ref)) { return false; }
        try { $current = $this->read($actor, $organizationId, $ref['arguments'])['source_refs'][0]; return $current['project_id'] === $ref['project_id'] && $current['source_version'] === $ref['source_version']; }
        catch (\Throwable) { return false; }
    }

    public function verifiedAnswer(array $result, User $actor, int $organizationId): ?array
    {
        $ref = $result['source_refs'][0] ?? null;
        if (! is_array($ref) || ! $this->matchesReference($actor, $organizationId, $ref)) { return null; }
        $current = $this->read($actor, $organizationId, $ref['arguments']);
        if ($current['source_refs'][0]['source_version'] !== $ref['source_version']) { return null; }
        return ['text' => $current['server_formatted_answer'], 'source_refs' => $current['source_refs'], 'validation_status' => 'partial', 'replaced' => true, 'needs_clarification' => false];
    }

    private function format(array $data): string
    {
        if (! $data['available']) { return trans_message('ai_assistant_operational.supplement_'.$data['reason'], ['number' => self::text($data['contract_number'])]); }
        $lines = [trans_message('ai_assistant_operational.supplement_counts', ['number' => self::text($data['contract_number']), 'total' => $data['total'], 'pending' => $data['pending'], 'applied' => $data['applied']])];
        foreach ($data['examples'] as $row) {
            $status = in_array($row['status'], ['draft', 'applied', 'cancelled'], true) ? $row['status'] : 'unknown';
            $activation = in_array($row['activation_status'], ['scheduled', 'failed', 'applied'], true) ? $row['activation_status'] : ($row['activation_status'] === null ? 'none' : 'unknown');
            $lines[] = '- #'.$row['id'].' '.self::text($row['number']).'; '.self::text($row['date']).'; '.trans_message('ai_assistant_operational.supplement_status_'.$status).'; '
                .trans_message('ai_assistant_operational.supplement_activation_'.$activation).'; '
                .trans_message('ai_assistant_operational.supplement_effective_date', ['date' => $row['effective_date'] ?? trans_message('ai_assistant_operational.not_recorded')]);
        }
        $lines[] = trans_message('ai_assistant_operational.supplement_basis');
        $lines[] = trans_message('ai_assistant_operational.examples_basis');
        return implode("\n", $lines);
    }

    private static function text(string $value): string
    {
        return str_replace(['*', '_', '[', ']', '`'], ['\\*', '\\_', '\\[', '\\]', '\\`'], htmlspecialchars(mb_substr(preg_replace('/[\p{Cc}\p{Cf}]/u', ' ', $value) ?? '', 0, 255), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
    }
}
