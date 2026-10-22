<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Reports;

use App\BusinessModules\Features\AIAssistant\Services\AssistantExtendedDomainRegistry;
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
                if ($type === 'project' && self::isColonReportColumn($message, $offset, $mention, $noun)) {
                    continue;
                }
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

    private static function isColonReportColumn(string $message, int $offset, string $mention, string $noun): bool
    {
        if (preg_match('/^'.$noun.'\s+$/iu', $mention) !== 1) {
            return false;
        }
        $prefix = substr($message, 0, $offset);
        if (preg_match('/:[^:;.!?\r\n]*$/u', $prefix, $match, PREG_OFFSET_CAPTURE) !== 1) {
            return false;
        }
        $colon = $match[0][1];
        if (preg_match('/(?<![\pL\pN])(?:CSV|XLSX|PDF|Excel|отч[её]т[а-яё]*|реестр[а-яё]*|сводк[а-яё]*|таблиц[а-яё]*|выгрузк[а-яё]*)(?![\pL\pN])/iu', substr($message, 0, $colon)) !== 1) {
            return false;
        }
        $sentences = preg_split('/[;.!?\r\n]/u', substr($message, $colon + 1), 2) ?: [];
        $declaration = $sentences[0] ?? '';
        $fields = preg_split('/\s*,\s*|\s+(?:и|или)\s+/iu', trim($declaration)) ?: [];
        if (count($fields) < 3 || count($fields) > 30) {
            return false;
        }
        $labels = ['Проект', 'Объект', 'Компания', 'Название сделки', 'Ожидаемая дата закрытия'];
        if (app()->bound('translator')) {
            $translated = app('translator')->get('ai_assistant_facts.fields', [], 'ru');
            $labels = [...$labels, ...array_values(is_array($translated) ? $translated : []), ...array_values(AssistantExtendedDomainRegistry::values('fieldLabels'))];
        }
        $labels = array_map(static fn (string $label): string => mb_strtolower(trim($label)), array_values(array_filter($labels, is_string(...))));
        foreach ($fields as $field) {
            if (! in_array(mb_strtolower(trim($field)), $labels, true)
                && ! AssistantOrganizationRecordVocabulary::matchesReportFieldInflection($field)) {
                return false;
            }
        }

        return true;
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
