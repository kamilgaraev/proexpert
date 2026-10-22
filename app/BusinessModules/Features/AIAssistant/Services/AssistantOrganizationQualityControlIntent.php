<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services;

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
        if (preg_match('/excel|xlsx|csv|pdf|файл|выгруз|создай|закрой|удали|измени|обнови|переназначь|отправь|стоим|бюджет|сумм|затрат|сгрупп|разбивк|по\s+кажд|только|лишь|исключительно|вчера|завтра|за\s+(?:\d|[а-яё]*\s*(?:недел|месяц|год))|\b\d{4}\b|\d{1,2}[.\/-]\d{1,2}[.\/-]\d{2,4}/iu', $query) === 1
            || preg_match('/(?:включ|учт|добав)[а-яё]*\s+(?:и\s+)?черновик|черновик[а-яё]*\s+(?:тоже|также)|не\s+исключ[а-яё]*\s+черновик/iu', $query) === 1) {
            return false;
        }
        $scope = preg_replace('/не\s+ограничивай(?:ся|тесь)\s+(?:выбранным|текущим)\s+(?:проектом|объектом)/iu', '', $query) ?? $query;
        if (preg_match('/(?<!\pL)(?:в|во|для|кроме|исключая|только)\s+(?:(?:выбранн|текущ)[а-яё]*\s+)?(?:проект|объект)[а-яё]*|(?:проект|объект)[а-яё]*\s+(?:[№#]?\s*\d|[«"])/iu', $scope) === 1) {
            return false;
        }
        $scope = preg_replace('/(?<!\pL)(?:по|во?)\s+вс(?:ем|ех)\s+(?:(?:нашим|наших|доступным|доступных)\s+)?(?:проект|объект)[а-яё]*/iu', '', $scope) ?? $scope;
        if (preg_match_all('/(?<!\pL)нужны\s+([^.!?]*)(?:[.!?]|$)/iu', $scope, $clauses, PREG_SET_ORDER) > 0) {
            foreach ($clauses as $clause) {
                if (! self::supportedColumns($clause[1])) {
                    return false;
                }
                $scope = str_replace($clause[0], '', $scope);
            }
        }
        $scope = preg_replace('/(?:отдельно\s+(?:отметь|выдели|покажи)|отметь|выдели)\s+критическ[а-яё]*(?:\s+(?:замечан|дефект)[а-яё]*)?(?=\s*(?:[.!?;,]|$))/iu', '', $scope) ?? $scope;

        return preg_match('/(?<!\pL)(?:проект|объект)[а-яё]*|критическ|существен|незначительн|ответствен|назначен|открыт|в\s+работе|в\s+статусе|не\s*просроч|не\s+раньше|отмени/iu', $scope) !== 1;
    }

    private static function supportedColumns(string $clause): bool
    {
        $column = '(?:id|ид|идентификатор(?:\s+(?:замечания|дефекта))?|проект|(?:название|наименование)(?:\s+(?:проекта|замечания|дефекта))?|статус|критичность|важность|(?:плановый\s+)?срок(?:\s+(?:устранения|исправления))?)';

        return preg_match('/^'.$column.'(?:(?:\s*,\s*|\s+и\s+)'.$column.')*$/iuD', trim($clause)) === 1;
    }
}
