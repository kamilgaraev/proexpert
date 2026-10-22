<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services;

use App\BusinessModules\Features\AIAssistant\Services\RequestUnderstanding\AssistantMutationIntent;
use App\BusinessModules\Features\AIAssistant\Services\RequestUnderstanding\AssistantTemporalIntent;

final class AssistantOrganizationQualityControlIntent
{
    public static function matches(string $query): bool
    {
        $query = preg_replace('/^\s*нужны\s+(?=(?:все\s+)?(?:незакрыт[а-яё]*\s+)?просроч[а-яё]*\s+(?:замечан|дефект))/iu', 'Покажи ', $query) ?? $query;
        $query = preg_replace('/(?<!\pL)(?:только|лишь|исключительно)\s+(?=(?:незакрыт[а-яё]*\s+)?просроч[а-яё]*(?!\pL))/iu', '', $query) ?? $query;
        $scopeQuery = preg_replace('/(?<!\pL)(по\s+всем\s+)доступным\s+((?:проект|объект)ам)(?!\pL)/iu', '$1$2', $query) ?? $query;
        if (! AssistantRequestProjectScope::organizationWide($scopeQuery)
            || preg_match('/качеств/iu', $query) !== 1
            || preg_match('/замечан|дефект/iu', $query) !== 1
            || preg_match('/просроч|(?:раньше|до)\s+сегодняшн[а-яё]*\s+дня/iu', $query) !== 1) {
            return false;
        }
        $periodQuery = preg_replace('/(?:раньше|до)\s+сегодняшн[а-яё]*\s+дня/iu', '', $query) ?? $query;
        if (AssistantTemporalIntent::hasExplicitWindow($periodQuery) || AssistantTemporalIntent::hasNonCurrentScope($periodQuery)) {
            return false;
        }
        if (AssistantMutationIntent::containsRequest($query)) {
            return false;
        }
        $scope = preg_replace('/не\s+ограничивай(?:ся|тесь)\s+(?:выбранным|текущим)\s+(?:проектом|объектом)/iu', '', $query) ?? $query;
        if (preg_match('/(?<!\pL)(?:в|во|для|кроме|исключая|только)\s+(?:(?:выбранн|текущ)[а-яё]*\s+)?(?:проект|объект)[а-яё]*|(?:проект|объект)[а-яё]*\s+(?:[№#]?\s*\d|[«"])/iu', $scope) === 1) {
            return false;
        }
        $scope = preg_replace('/(?<!\pL)(?:по|во?)\s+вс(?:ем|ех)\s+(?:(?:нашим|наших|доступным|доступных)\s+)?(?:проект|объект)[а-яё]*/iu', '', $scope) ?? $scope;
        $excludedStatus = '(?:черновик[а-яё]*|устран[её]нн[а-яё]*|отмен[её]нн[а-яё]*)';
        $statusList = $excludedStatus.'(?:(?:\s*,\s*|\s+и\s+)'.$excludedStatus.')*';
        $scope = preg_replace('/(?<!\pL)кроме\s+'.$statusList.'(?=\s*(?:[.!?;]|$))/iu', '', $scope) ?? $scope;
        $scope = preg_replace('/(?:^|(?<=[.!?;]))\s*(?:'.$statusList.'\s+исключи(?:те)?|исключи(?:те)?\s+'.$statusList.')(?=\s*(?:[.!?;]|$))/iu', '', $scope) ?? $scope;
        if (preg_match_all('/(?<!\pL)нужны\s+([^.!?]*)(?:[.!?]|$)/iu', $scope, $clauses, PREG_SET_ORDER) > 0) {
            foreach ($clauses as $clause) {
                if (! self::supportedColumns($clause[1])) {
                    return false;
                }
                $scope = str_replace($clause[0], '', $scope);
            }
        }
        $scope = preg_replace('/(?:отдельно\s+(?:отметь|выдели|покажи)|отметь|выдели)\s+критическ[а-яё]*(?:\s+(?:замечан|дефект)[а-яё]*)?(?=\s*(?:[.!?;,]|$))/iu', '', $scope) ?? $scope;

        $readTerms = 'пожалуйста|прошу|покажи(?:те)?|перечисли(?:те)?|выведи(?:те)?|отобрази(?:те)?|назови(?:те)?|дай(?:те)?|какие|есть|нужн[ао]?|спис(?:ок|ка|ку|ке|ком|ки|ков)|сводк[а-яё]*|по|во?|со?|до|на|для|раньше|все|всех|всем|всей|нашей|организац[а-яё]*|незакрыт[а-яё]*|просроч[а-яё]*|замечан[а-яё]*|дефект[а-яё]*|качеств[а-яё]*|у|котор[а-яё]*|срок[а-яё]*|устранения|исправления|сегодняшн[а-яё]*|сегодня|дня|состоянию';
        $remaining = preg_replace('/(?<![\pL\pN_])(?:'.$readTerms.')(?![\pL\pN_])/iu', '', $scope) ?? $scope;

        return preg_match('/^[\s.,;:!?()—–-]*$/uD', $remaining) === 1;
    }

    private static function supportedColumns(string $clause): bool
    {
        $column = '(?:id|ид|идентификатор(?:\s+(?:замечания|дефекта))?|проект|(?:название|наименование)(?:\s+(?:проекта|замечания|дефекта))?|статус|критичность|важность|(?:плановый\s+)?срок(?:\s+(?:устранения|исправления))?)';

        return preg_match('/^'.$column.'(?:(?:\s*,\s*|\s+и\s+)'.$column.')*$/iuD', trim($clause)) === 1;
    }
}
