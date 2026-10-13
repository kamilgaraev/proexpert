<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services;

use App\BusinessModules\Features\AIAssistant\Services\Agent\AssistantPeriodResolver;

final readonly class AssistantRequiredProjectRead
{
    public static function decisionReader(string $toolName, string $query): ?string
    {
        $operational = \App\BusinessModules\Features\AIAssistant\Services\Operational\AssistantOperationalReaderRegistry::intent($query);
        if ($operational !== null) { return $operational['name'] === $toolName ? $operational['reader'] : null; }
        if (\App\BusinessModules\Features\AIAssistant\Services\Operational\AssistantOperationalReaderRegistry::hasIntent($query)) { return null; }
        if ($toolName !== 'get_project_handover_summary' && AssistantProjectHandoverReader::hasIntent($query)) { return null; }
        return match ($toolName) {
            'get_project_finance_overview' => AssistantProjectFinanceOverview::matches($query) ? AssistantProjectFinanceOverview::class : null,
            'analyze_project_schedule_impact' => AssistantProjectScheduleImpactReader::matches($query) ? AssistantProjectScheduleImpactReader::class : null,
            'get_project_workforce_summary' => AssistantProjectWorkforceReader::matches($query) ? AssistantProjectWorkforceReader::class : null,
            'get_project_payment_readiness' => AssistantProjectPaymentReader::matches($query) ? AssistantProjectPaymentReader::class : null,
            'get_project_machinery_summary' => AssistantProjectMachineryReader::matches($query) ? AssistantProjectMachineryReader::class : null,
            'get_project_handover_summary' => AssistantProjectHandoverReader::matches($query) ? AssistantProjectHandoverReader::class : null,
            'get_project_contract_roster' => AssistantProjectContractRosterReader::matches($query) ? AssistantProjectContractRosterReader::class : null,
            'get_document_readiness' => \App\BusinessModules\Features\AIAssistant\Services\Documents\AssistantDocumentReadinessReader::matches($query)
                ? \App\BusinessModules\Features\AIAssistant\Services\Documents\AssistantDocumentReadinessReader::class : null,
            default => null,
        };
    }

    public static function fromPlan(array $plan): ?array
    {
        $query = (string) ($plan['request']['message'] ?? '');
        if (\App\BusinessModules\Features\AIAssistant\Services\Operational\AssistantOperationalReaderRegistry::hasIntent($query)
            && \App\BusinessModules\Features\AIAssistant\Services\Operational\AssistantOperationalReaderRegistry::intent($query) === null) { return null; }
        if (\App\BusinessModules\Features\AIAssistant\Services\Operational\AssistantOperationalReaderRegistry::intent($query) === null
            && AssistantProjectHandoverReader::hasIntent($query) && ! AssistantProjectHandoverReader::matches($query)) { return null; }
        if (($arguments = \App\BusinessModules\Features\AIAssistant\Services\Documents\AssistantDocumentRequestIntent::readyListArguments($query)) !== null) {
            return ['name' => 'search_assistant_documents', 'arguments' => $arguments];
        }
        if (($read = \App\BusinessModules\Features\AIAssistant\Services\FinancialEvidence\AssistantEstimateCrossSearchIntent::unitPriceRead($query)) !== null) { return $read; }
        $scope = AssistantRequestProjectScope::fromPlan($plan);
        $operational = \App\BusinessModules\Features\AIAssistant\Services\Operational\AssistantOperationalReaderRegistry::intent($query);
        if (($operational['name'] ?? null) === 'get_project_tender_decisions'
            && ($scope->projectId !== null || AssistantRequestProjectScope::organizationWide($query))) {
            return ['name' => $operational['name'], 'arguments' => ['project_id' => $scope->projectId]];
        }
        if ($operational !== null && $scope->projectId !== null) { return ['name' => $operational['name'], 'arguments' => ['project_id' => $scope->projectId]]; }
        if (\App\BusinessModules\Features\AIAssistant\Services\Documents\AssistantDocumentReadinessReader::matches($query)
            && (! $scope->requested || $scope->projectId !== null)) {
            return ['name' => 'get_document_readiness', 'arguments' => ['project_id' => $scope->requested ? $scope->projectId : null]];
        }
        if ($scope->projectId !== null && AssistantProjectScheduleImpactReader::matches($query)
            && ($scope->requested || preg_match('/общий\s+срок\s+проекта\s*[?.!]|из\s+(?:перечисленных|просроченных)\s+задач/iu', $query) === 1)) {
            return ['name' => 'analyze_project_schedule_impact', 'arguments' => ['project_id' => $scope->projectId, 'focus' => AssistantProjectScheduleImpactReader::focus($query)]];
        }
        if (! $scope->requested || $scope->projectId === null) { return null; }
        if (AssistantProjectHandoverReader::matches($query)) {
            return ['name' => 'get_project_handover_summary', 'arguments' => ['project_id' => $scope->projectId]];
        }
        if (AssistantProjectContractRosterReader::matches($query)) {
            return ['name' => 'get_project_contract_roster', 'arguments' => ['project_id' => $scope->projectId]];
        }
        if (AssistantProjectMachineryReader::matches($query)) {
            return ['name' => 'get_project_machinery_summary', 'arguments' => ['project_id' => $scope->projectId]];
        }
        if (AssistantProjectPaymentReader::matches($query)) {
            return ['name' => 'get_project_payment_readiness', 'arguments' => ['project_id' => $scope->projectId]];
        }
        if (AssistantProjectWorkforceReader::matches($query)) {
            $period = (new AssistantPeriodResolver)->resolve($query);
            if ($period?->dateFrom !== null && $period->dateTo !== null) {
                return ['name' => 'get_project_workforce_summary', 'arguments' => ['project_id' => $scope->projectId,
                    'period_start' => $period->dateFrom, 'period_end' => $period->dateTo]];
            }
        }
        if (AssistantProjectFinanceOverview::matches($query)) {
            $period = (new AssistantPeriodResolver)->resolve($query);
            if ($period?->dateFrom !== null && $period->dateTo !== null) {
                return ['name' => 'get_project_finance_overview', 'arguments' => ['project_id' => $scope->projectId,
                    'period_start' => $period->dateFrom, 'period_end' => $period->dateTo, 'currency' => null]];
            }
        }
        if (AssistantProjectAttentionIntent::matches($query)) {
            return ['name' => 'get_project_attention', 'arguments' => ['project_id' => $scope->projectId, 'focus' => AssistantProjectAttentionIntent::focus($query), 'limit' => 3]];
        }
        if (self::simpleProjectBudget($query)) {
            return ['name' => 'assistant_domain_read', 'arguments' => ['domain' => 'projects', 'entity_type' => 'project',
                'id' => $scope->projectId, 'fields' => ['name', 'budget_amount'],
                'projection' => null, 'projection_offset' => 0, 'projection_limit' => 20]];
        }
        if (($arguments = AssistantDomainSearchCountEvidence::overdueCountArguments($query, $scope->projectId)) !== null) {
            return ['name' => 'assistant_domain_search', 'arguments' => $arguments + ['limit' => 1, 'cursor' => null, 'fields' => null]];
        }
        if (($arguments = self::projectListArguments($query, $scope->projectId)) !== null) {
            return ['name' => 'assistant_domain_search', 'arguments' => $arguments];
        }
        if (self::simpleProjectStock($query)) {
            return ['name' => 'get_project_material_stock', 'arguments' => ['project_id' => $scope->projectId, 'offset' => 0, 'limit' => 20]];
        }
        if (! AssistantFactIntentClassifier::isProjectExpenseRequest($query) || ! self::simpleExpenseTotal($query)) {
            return null;
        }
        $period = (new AssistantPeriodResolver)->resolve($query);
        if ($period?->dateFrom === null || $period->dateTo === null) { return null; }

        return ['name' => 'get_live_project_financial_evidence', 'arguments' => ['project_id' => $scope->projectId,
            'period_start' => $period->dateFrom, 'period_end' => $period->dateTo, 'currency' => null]];
    }

    public static function toolChoice(array $plan, array $tools): ?array
    {
        $read = self::fromPlan($plan);
        if ($read === null) { return null; }
        foreach ($tools as $tool) {
            if (($tool['function']['name'] ?? $tool['name'] ?? null) === $read['name']) {
                return ['type' => 'function', 'function' => ['name' => $read['name']]];
            }
        }

        return null;
    }

    public static function bindArguments(array $plan, string $toolName, array $arguments): array
    {
        $read = self::fromPlan($plan);
        if (($read['name'] ?? null) !== $toolName) { return $arguments; }
        $bound = $read['arguments'];
        if (($toolName === 'search_estimate_positions'
            || $toolName === 'assistant_domain_search' && ($bound['predicate'] ?? null) !== 'overdue')
            && is_string($arguments['cursor'] ?? null)) { $bound['cursor'] = $arguments['cursor']; }
        if ($toolName === 'get_project_material_stock' && is_int($arguments['offset'] ?? null)) { $bound['offset'] = $arguments['offset']; }
        if ($toolName === 'search_assistant_documents' && is_int($arguments['metadata_offset'] ?? null)) { $bound['metadata_offset'] = $arguments['metadata_offset']; }
        return $bound;
    }

    private static function simpleExpenseTotal(string $query): bool
    {
        $months = 'январ[ьяе]|феврал[ьяе]|март[ае]?|апрел[ьяе]|ма[йяе]|июн[ьяе]|июл[ьяе]|август[ае]?|сентябр[ьяе]|октябр[ьяе]|ноябр[ьяе]|декабр[ьяе]';
        $semantic = preg_replace('/(?<!\pL)за\s+(?:'.$months.')\s+\d{4}(?:\s+год[а-яё]*)?(?![\pL\pN])/iu', '', $query, 1, $periods);
        if ($semantic === null || $periods !== 1 || str_contains($semantic, ';')) { return false; }
        preg_match_all('/[\pL\pN_]+/u', mb_strtolower($semantic), $words);
        $allowed = explode(' ', 'покажи покажите показать скажи скажите подскажи подскажите посчитай рассчитай какие сколько всего расходы затрат затраты фактические фактических нашего наш текущего текущий выбранного выбранный этом этого проект проекта проекте проекту объект объекта объекте объекту на по у нас пожалуйста');

        return $words[0] !== [] && array_diff($words[0], $allowed) === [];
    }

    public static function projectListArguments(string $query, int $projectId): ?array
    {
        preg_match_all('/[\pL\pN_]+/u', mb_strtolower($query), $words);
        $allowed = explode(' ', 'что какие есть покажи покажите перечисли список все ещё еще уже ждём ждем ожидаем от поставщиков поставки ожидаемые работы приняли принятые принято незакрытые закрытые не замечания дефекты по качеству на в у нас нашем нашего текущем текущего выбранном этом этого проекте проекта объекте объекта пожалуйста');
        if ($words[0] === [] || array_diff($words[0], $allowed) !== []) { return null; }
        $target = match (true) {
            preg_match('/работ[а-яё]*.*(?:приняли|принят[а-яё]*)|принят[а-яё]*\s+работ/iu', $query) === 1 => ['works', 'completed_work', 'confirmed'],
            preg_match('/(?:жд[её]м|ожидаем).*поставщик|ожидаем[а-яё]*\s+поставк/iu', $query) === 1 => ['warehouse', 'project_material_delivery', 'supplier_waiting'],
            preg_match('/(?:незакрыт[а-яё]*|не\s+закрыт[а-яё]*)\s+(?:замечан[а-яё]*|дефект[а-яё]*)/iu', $query) === 1 => ['quality', 'quality_defect', 'unresolved'],
            default => null,
        };
        if ($target === null || $projectId < 1) { return null; }
        if ($target[2] !== 'unresolved' && preg_match('/(?<!\pL)не(?!\pL)/iu', $query) === 1) { return null; }
        return ['domain' => $target[0], 'entity_type' => $target[1], 'predicate' => $target[2], 'project_id' => $projectId,
            'query' => '', 'limit' => 20, 'cursor' => null, 'fields' => null];
    }

    private static function simpleProjectStock(string $query): bool
    {
        if (preg_match('/склад|остат/iu', $query) !== 1) { return false; }
        preg_match_all('/[\pL\pN_]+/u', mb_strtolower($query), $words);
        $allowed = explode(' ', 'что какие сколько сейчас есть осталось остатки материалы покажи покажите на в у нас складе склад текущего текущем выбранного нашем нашего этом этого объекте объекта проекте проекта и пожалуйста');
        return $words[0] !== [] && array_diff($words[0], $allowed) === [];
    }

    private static function simpleProjectBudget(string $query): bool
    {
        if (preg_match('/(?<!\pL)бюджет[а-яё]*(?!\pL)/iu', $query) !== 1) { return false; }
        preg_match_all('/[\pL\pN_]+/u', mb_strtolower($query), $words);
        $allowed = explode(' ', 'какой какая каков какова сколько бюджет сумма суммы в валюте валюта у нас нашего нашем текущего текущем выбранного выбранном этого этом проекте проекта объекте объекта покажи покажите пожалуйста');
        return $words[0] !== [] && array_diff($words[0], $allowed) === [];
    }
}
