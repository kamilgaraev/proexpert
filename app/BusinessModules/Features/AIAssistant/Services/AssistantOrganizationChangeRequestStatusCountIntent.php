<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services;

final class AssistantOrganizationChangeRequestStatusCountIntent
{
    public static function hasIntent(string $query): bool
    {
        return AssistantRequestProjectScope::organizationWide($query)
            && preg_match('/запрос[а-яё]*\s+на\s+изменени[а-яё]*/iu', $query) === 1;
    }

    public static function matches(string $query): bool
    {
        if (! self::hasIntent($query)
            || preg_match('/(?<![\pL\pN_])(?:посчитай(?:те)?|подсчитай(?:те)?|сосчитай(?:те)?|сколько|количество|число)(?![\pL\pN_])/iu', $query) !== 1
            || preg_match('/по\s+(?:текущим\s+)?статусам/iu', $query) !== 1) {
            return false;
        }
        $query = preg_replace('/не\s+ограничивай(?:ся|тесь)\s+(?:выбранным|текущим)\s+(?:проектом|объектом)/iu', '', $query) ?? $query;
        $query = preg_replace('/если\s+запросов\s+нет\s*,?\s*скажи(?:те)?\s+об\s+этом\s+прямо/iu', '', $query) ?? $query;
        $query = preg_replace('/включая\s+черновики|черновики\s+тоже\s+(?:посчитай(?:те)?|учти(?:те)?)/iu', '', $query) ?? $query;
        $terms = 'посчитай(?:те)?|подсчитай(?:те)?|сосчитай(?:те)?|сколько|количество|число|запрос[а-яё]*|на|изменени[а-яё]*|по|в|во|всей|нашей|организац[а-яё]*|текущим|статусам|пожалуйста';
        $remaining = preg_replace('/(?<![\pL\pN_])(?:'.$terms.')(?![\pL\pN_])/iu', '', $query) ?? $query;

        return preg_match('/^[\s.,;:!?()—–-]*$/uD', $remaining) === 1;
    }
}
