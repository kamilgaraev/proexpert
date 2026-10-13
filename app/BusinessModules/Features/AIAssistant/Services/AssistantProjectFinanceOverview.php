<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services;

use App\BusinessModules\Features\AIAssistant\Services\FinancialEvidence\AssistantLiveProjectFinanceReader;
use App\Models\Organization;
use App\Models\User;

final readonly class AssistantProjectFinanceOverview
{
    public function __construct(private AssistantDomainReadService $domains, private AssistantLiveProjectFinanceReader $finance,
        private AssistantDataAccessPolicy $access, private AssistantSourceReferenceGuard $sources) {}

    public static function matches(string $query): bool
    {
        return preg_match('/бюджет/iu', $query) === 1
            && preg_match('/потрач|затрат|расход|перерасход/iu', $query) === 1
            && preg_match('/создай|измени|назначь|одобри|оплати|удали|задач|график|замечан|дефект|качеств|поставк|заявк|табел|сотрудник|документ|ifc|bim|бим/iu', $query) !== 1;
    }

    public function read(User $actor, Organization $organization, array $arguments): array
    {
        return $this->access->withCurrentChecks($actor, (int) $organization->id, function () use ($actor, $organization, $arguments): array {
            $actuals = $this->finance->read($actor, $organization, $arguments);
            $budget = $this->domains->execute('read', ['domain' => 'projects', 'entity_type' => 'project', 'id' => $arguments['project_id'],
                'fields' => ['name', 'budget_amount']], $actor, (int) $organization->id);
            $fields = $budget['results'][0]['fields'] ?? [];
            $amount = $fields['budget_amount'] ?? null;
            $budgetKnown = is_string($amount) && preg_match('/^\d+(?:\.\d+)?$/D', $amount) === 1;
            $lines = [trans_message('ai_assistant_decisions.finance_scope', ['project' => self::text((string) ($fields['name'] ?? ''))])];
            $lines[] = trans_message($budgetKnown ? 'ai_assistant_decisions.budget' : 'ai_assistant_decisions.budget_unavailable', ['amount' => $amount]);
            $lines[] = is_string($actuals['server_formatted_answer'] ?? null) ? $actuals['server_formatted_answer'] : trans_message('ai_assistant_decisions.actuals_unavailable');
            $lines[] = trans_message('ai_assistant_decisions.overrun_unknown');
            return ['status' => 'partial', 'validation_status' => 'partial', 'source_covered' => ($actuals['source_covered'] ?? false),
                'overview_arguments' => $arguments, 'source_refs' => [...($budget['source_refs'] ?? []), ...($actuals['source_refs'] ?? [])],
                'server_formatted_answer' => implode("\n\n", $lines), 'budget_available' => $budgetKnown,
                'data' => ['project_budget' => $budgetKnown ? $amount : null, 'actuals' => $actuals['financial_evidence'] ?? [], 'actuals_reason' => $actuals['reason'] ?? null]];
        }, true);
    }

    public function verifiedAnswer(array $result, User $actor, int $organizationId): ?array
    {
        if (! is_array($result['overview_arguments'] ?? null) || ($result['source_refs'] ?? []) === []
            || ! $this->sources->fresh($actor, $organizationId, $result['source_refs'])) { return null; }
        $organization = new Organization;
        $organization->id = $organizationId;
        $current = $this->read($actor, $organization, $result['overview_arguments']);
        if (($current['data']['project_budget'] ?? null) !== ($result['data']['project_budget'] ?? null)
            || ($current['budget_available'] ?? null) !== ($result['budget_available'] ?? null)) { return null; }
        return ['text' => $current['server_formatted_answer'], 'source_refs' => $current['source_refs'], 'validation_status' => 'partial',
            'replaced' => true, 'needs_clarification' => false];
    }

    private static function text(string $value): string
    {
        return htmlspecialchars(mb_substr(preg_replace('/[\p{Cc}\p{Cf}]/u', ' ', $value) ?? '', 0, 255), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
