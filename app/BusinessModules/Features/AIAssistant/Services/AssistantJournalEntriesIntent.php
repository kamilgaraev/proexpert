<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services;

final class AssistantJournalEntriesIntent
{
    private const QUOTED = '(?:«([^»]+)»|“([^”]+)”|"([^"]+)")';

    public static function hasIntent(string $query): bool
    {
        $query = self::semanticQuery($query);

        return preg_match('/запис[а-яё]*.*журнал|журнал[а-яё]*.*запис/iu', $query) === 1
            && preg_match('/покаж|перечисл|список|все\s+запис|содержани[ея]|текст/iu', $query) === 1;
    }

    public static function matches(string $query): bool
    {
        $query = self::semanticQuery($query);
        if (AssistantRequestProjectScope::organizationWide($query)
            || preg_match_all('/\bжурнал(?:а|е|у|ом)?\b/iu', $query) !== 1) {
            return false;
        }
        if (preg_match('/начиная\s+с\s+([0-9]+)/iu', $query, $page) === 1
            && filter_var($page[1], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 10000]]) === false) {
            return false;
        }

        return self::hasIntent($query)
            && preg_match('/создай|измени|удали|подпиши|согласуй|отправь|добавь|исправь|выгруз|сформир|excel|xlsx|csv|pdf|файл|плат[её]ж|договор|смет|стоим|бюджет|остатк|материал|техник|охран|безопасност|график|сотрудник|персонал|rfi|сдач|комплект|поставк|закуп|при[её]мк|расход|затрат|средств|наряд|автор|кто|объ[её]м|об[ъь][её]м|количеств|погод|бригад|рабочи|примечан|\\bакт(?:ы|ов|а|ам|ами|ах)?\\b/iu', $query) !== 1
            && preg_match('/только|кроме|исключая|не\s+показывай|за\s+|между|(?:до|по)\s+\d|с\s+\d{1,4}(?!\d)|сегодня|вчера|(?:утвержд[её]нн|согласованн|отклон[её]нн|чернов|подписанн|несогласованн)[а-яё]*\s+запис|статус(?:ом|е)\s+(?:утверж|соглас|отклон|чернов|на\s+соглас|draft|approved|submitted|rejected)|(?<!включая\s)\bожидающ[а-яё]*\s+согласован|журналов|(?:№|#)\s*[^\s]+\s+и\s*(?:№|#)/iu', preg_replace('/начиная\s+с\s+[0-9]+/iu', '', $query) ?? $query) !== 1;
    }

    public static function arguments(int $projectId, string $query): array
    {
        $query = AssistantNamedProjectReadScope::semanticQuery($query);
        $number = null;
        $name = null;
        preg_match_all('/журнал(?:а|е|у|ом)?\s*(?:(?:№|номер(?:ом|а)?|#)\s*([\pL\pN._\/-]+))?\s*'.self::QUOTED.'/iu', $query, $names, PREG_SET_ORDER);
        if (count($names) === 1) {
            $number = ($names[0][1] ?? '') !== '' ? $names[0][1] : null;
            foreach (array_slice($names[0], 2) as $value) {
                if ($value !== '') {
                    $name = trim($value);
                    break;
                }
            }
        } else {
            preg_match_all('/журнал(?:а|е|у|ом)?\s*(?:№|номер(?:ом|а)?|#)\s*([\pL\pN._\/-]+)/iu', $query, $numbers);
            if (count($numbers[1]) === 1 && $names === []) {
                $number = $numbers[1][0];
            }
        }

        $number = $number === null ? null : rtrim($number, '.');
        $offset = 0;
        if (preg_match('/начиная\s+с\s+([0-9]+)/iu', $query, $page) === 1
            && filter_var($page[1], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 10000]]) !== false) {
            $offset = (int) $page[1] - 1;
        }

        return ['project_id' => $projectId, 'journal_name' => $name, 'journal_number' => $number, 'offset' => $offset];
    }

    private static function semanticQuery(string $query): string
    {
        $query = AssistantNamedProjectReadScope::semanticQuery($query);

        return preg_replace('/(журнал(?:а|е|у|ом)?\s*(?:(?:№|номер(?:ом|а)?|#)\s*[\pL\pN._\/-]+)?\s*)'.self::QUOTED.'/iu', '$1', $query) ?? $query;
    }
}
