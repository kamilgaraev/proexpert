<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Reports;

use App\BusinessModules\Features\AIAssistant\Services\AssistantOrganizationRecordVocabulary;
use App\BusinessModules\Features\AIAssistant\Services\AssistantProductionSchedulePeriod;

final class AssistantReportEntityQuery
{
    public static function extract(string $message, string $type, bool $preservePrepositions = false): ?string
    {
        $names = self::names($message, $type, $preservePrepositions);

        return count($names) === 1 ? $names[0] : null;
    }

    public static function hasAmbiguousName(string $message, string $type, bool $preservePrepositions = false): bool
    {
        return count(self::names($message, $type, $preservePrepositions)) > 1;
    }

    private static function names(string $message, string $type, bool $preservePrepositions = false): array
    {
        $noun = match ($type) {
            'project' => '(?:проект(?:а|у|ом|е)?|объект(?:а|у|ом|е)?)',
            'warehouse' => 'склад(?:а|у|ом|е)?',
            'contract' => 'договор(?:а|у|ом|е)?',
            'contractor' => '(?:подрядчик(?:а|у|ом|е)?|контрагент(?:а|у|ом|е)?)',
            'user' => '(?:сотрудник(?:а|у|ом|е)?|пользовател(?:ь|я|ю|ем|е))',
            default => '',
        };
        if ($noun === '') {
            return [];
        }
        preg_match_all('/\b'.$noun.'(?:\s*:\s*|\s+)/iu', $message, $mentions, PREG_OFFSET_CAPTURE);
        preg_match_all('/«[^»]*»|“[^”]*”|„[^“]*“|"[^"]*"/u', $message, $quotes, PREG_OFFSET_CAPTURE);
        $names = [];
        foreach ($mentions[0] as [$mention, $offset]) {
            foreach ($quotes[0] as [$quote, $quoteOffset]) {
                if ($offset > $quoteOffset && $offset < $quoteOffset + strlen($quote)) {
                    continue 2;
                }
            }
            $tail = trim(substr($message, $offset + strlen($mention)));
            if (preg_match('/^(?:«([^»]+)»|“([^”]+)”|„([^“]+)“|"([^"]+)")/u', $tail, $quoted) === 1) {
                $parts = array_values(array_filter(array_slice($quoted, 1), static fn (string $part): bool => trim($part) !== ''));
                $name = trim((string) ($parts[0] ?? ''));
            } else {
                $prefix = substr($message, 0, $offset);
                if ($type === 'project'
                    && preg_match('/(?<![\pL\pN])(?:в\s+разрезе|с\s+детализацией\s+по)\s*$/iu', $prefix) === 1
                    && self::isGroupingTail($tail)) {
                    continue;
                }
                if ($type === 'project' && preg_match('/^(?:и|или)\s+/iu', $tail) === 1
                    && ((preg_match('/(?<![\pL\pN])(?:колонк(?:ами|и|ах|ок)|столбц(?:ами|ы|ах|ов)|пол(?:ями|я|ях|ям|ей))\s*:?[^;.!?\r\n]*$/iu', $prefix) === 1
                            && preg_match('/(?<![\pL\pN])(?:по|для|в|во|на|из|с|со)\s*$/iu', $prefix) !== 1)
                        || preg_match('/(?<![\pL\pN])(?:сгруппиру[а-яё]*|группиру[а-яё]*|группировк[а-яё]*)\s+по\s*$/iu', $prefix) === 1)) {
                    continue;
                }
                if ($type === 'project' && $names !== []
                    && preg_match('/\b(?:с|со)\s+начала\s*$/iu', substr($message, 0, $offset)) === 1
                    && AssistantProductionSchedulePeriod::withoutSuffix('текущий '.$tail) === 'текущий') {
                    continue;
                }
                $parts = preg_split($preservePrepositions
                    ? '/\s+(?:проекта|объекта|склада|сотрудника|подрядчика)\b|\n/iu'
                    : '/\s+(?:за|с|по|для|на|от|проекта|объекта|склада|сотрудника|подрядчика)\b|[,;\n]/iu', $tail, 2);
                $name = preg_replace('/^[\s\x{0022}\x{0027}«»“”.:]+|[\s\x{0022}\x{0027}«»“”.:]+$/u', '', (string) ($parts[0] ?? '')) ?? '';
                if (! $preservePrepositions
                    && preg_match('/^(?:(?:за|с|по|для|на|от|сегодня|этот|этого|весь|всем|все|проекта|объекта|склада|подрядчика|сотрудника)\b|текущ|выполн)/iu', $name) === 1) {
                    continue;
                }
            }
            if ($name !== '') {
                $names[mb_strtolower($name)] = $name;
            }
        }

        return array_values($names);
    }

    private static function isGroupingTail(string $tail): bool
    {
        if (preg_match('/^(?:и|или)\s+(.+?)(?:[.;!?\r\n]|$)/isu', $tail, $match) !== 1) {
            return false;
        }
        $fields = preg_split('/\s*,\s*|\s+(?:и|или)\s+/iu', trim($match[1])) ?: [];
        if ($fields === [] || count($fields) > 5) {
            return false;
        }
        foreach ($fields as $field) {
            if (! AssistantOrganizationRecordVocabulary::matchesReportFieldInflection($field)) {
                return false;
            }
        }

        return true;
    }
}
