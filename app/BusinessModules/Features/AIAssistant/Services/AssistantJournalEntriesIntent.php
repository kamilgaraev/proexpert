<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services;

final class AssistantJournalEntriesIntent
{
    private const QUOTED = '(?:«([^»]+)»|“([^”]+)”|"([^"]+)")';

    private const JOURNAL = 'журнал(?:а|е|у|ом)?(?:\s+(?:производства\s+)?работ)?';

    private const ADJECTIVE_ENDING = '(?:ый|ий|ого|его|ому|ему|ом|ем|ым|им|ая|ую|ой|ей|ое|ее|ые|ие|ых|их|ыми|ими)';

    private const LIST_WORD = '/^(?:в|во|на|по|с|со|и|а|мне|пожалуйста|можешь|можете|хочу|увидеть|получить|покаж(?:и|ите)|показать|перечисл(?:и|ите)|дай(?:те)?|расскажи|прочитай|список|все|всех|всеми|всю|весь|(?:кажд|общ|текущ|выбранн|данн|доступн|полн|ожидающ)'.self::ADJECTIVE_ENDING.'|запис(?:ь|и|ей|ям|ями|ях)|журнал(?:а|е|у|ом)?|производства|работ(?:а|ы|у|е|ам|ами|ах|ой)?|(?:проект|объект)(?:а|у|ом|е)?|эт(?:от|ого|ому|ом|им|а|у|ой|о|и|их|ими)|наш(?:его|ему|ем|им|а|у|ей|е|и|их|ими)?|дат(?:а|ы|у|е|ам|ами)?|содержани[ея]|содержим(?:ое|ого|ом|ым)|текст(?:ы|а|ов)?|статус(?:ы|а|ов|ом|ами)?|согласования|нуж(?:ны|на|ен|но)|включая)$/iu';

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
        $selector = '(?:№|#|номер(?:ом|а)?)\s*[\pL\pN._\/-]+';
        $position = 'начиная\s+с\s+[0-9]+';
        if (! self::hasIntent($query) || preg_match_all('/'.$selector.'/iu', $query) > 1
            || preg_match_all('/'.$position.'/iu', $query) > 1) {
            return false;
        }
        $words = preg_split('/[^\pL\pN]+/u', preg_replace('/'.$selector.'|'.$position.'/iu', ' ', $query) ?? $query, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($words as $word) {
            if (preg_match(self::LIST_WORD, $word) !== 1) {
                return false;
            }
        }

        return $words !== [] && preg_match('/(?<!включая\s)\bожидающ[а-яё]*\s+согласован/iu', $query) !== 1;
    }

    public static function arguments(int $projectId, string $query): array
    {
        $query = AssistantNamedProjectReadScope::semanticQuery($query);
        $number = null;
        $name = null;
        preg_match_all('/'.self::JOURNAL.'\s*(?:(?:№|номер(?:ом|а)?|#)\s*([\pL\pN._\/-]+))?\s*'.self::QUOTED.'/iu', $query, $names, PREG_SET_ORDER);
        if (count($names) === 1) {
            $number = ($names[0][1] ?? '') !== '' ? $names[0][1] : null;
            foreach (array_slice($names[0], 2) as $value) {
                if ($value !== '') {
                    $name = trim($value);
                    break;
                }
            }
        } else {
            preg_match_all('/'.self::JOURNAL.'\s*(?:№|номер(?:ом|а)?|#)\s*([\pL\pN._\/-]+)/iu', $query, $numbers);
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

        $query = preg_replace('/('.self::JOURNAL.'\s*(?:(?:№|номер(?:ом|а)?|#)\s*[\pL\pN._\/-]+)?\s*)'.self::QUOTED.'/iu', '$1', $query) ?? $query;

        return preg_replace('/\s+/u', ' ', trim($query)) ?? $query;
    }
}
