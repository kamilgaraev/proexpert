<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services;

use App\BusinessModules\Features\AIAssistant\Services\Agent\AssistantPeriodResolver;

final readonly class AssistantRequiredProjectRead
{
    public static function fromPlan(array $plan): ?array
    {
        $query = (string) ($plan['request']['message'] ?? '');
        if (($read = \App\BusinessModules\Features\AIAssistant\Services\FinancialEvidence\AssistantEstimateCrossSearchIntent::unitPriceRead($query)) !== null) { return $read; }
        $scope = AssistantRequestProjectScope::fromPlan($plan);
        if (! $scope->requested || $scope->projectId === null) { return null; }
        if (($arguments = AssistantDomainSearchCountEvidence::overdueCountArguments($query, $scope->projectId)) !== null) {
            return ['name' => 'assistant_domain_search', 'arguments' => $arguments + ['limit' => 1, 'cursor' => null, 'fields' => null]];
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

    private static function simpleExpenseTotal(string $query): bool
    {
        $months = 'январ[ьяе]|феврал[ьяе]|март[ае]?|апрел[ьяе]|ма[йяе]|июн[ьяе]|июл[ьяе]|август[ае]?|сентябр[ьяе]|октябр[ьяе]|ноябр[ьяе]|декабр[ьяе]';
        $semantic = preg_replace('/(?<!\pL)за\s+(?:'.$months.')\s+\d{4}(?:\s+год[а-яё]*)?(?![\pL\pN])/iu', '', $query, 1, $periods);
        if ($semantic === null || $periods !== 1 || str_contains($semantic, ';')) { return false; }
        preg_match_all('/[\pL\pN_]+/u', mb_strtolower($semantic), $words);
        $allowed = explode(' ', 'покажи покажите показать скажи скажите подскажи подскажите посчитай рассчитай какие сколько всего расходы затрат затраты фактические фактических нашего наш текущего текущий выбранного выбранный этом этого проект проекта проекте проекту объект объекта объекте объекту на по у нас пожалуйста');

        return $words[0] !== [] && array_diff($words[0], $allowed) === [];
    }
}
