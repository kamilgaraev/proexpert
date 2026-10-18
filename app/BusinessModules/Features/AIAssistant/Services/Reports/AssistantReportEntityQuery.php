<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Reports;

final class AssistantReportEntityQuery
{
    public static function extract(string $message, string $type): ?string
    {
        $names = self::names($message, $type);

        return count($names) === 1 ? $names[0] : null;
    }

    public static function hasAmbiguousName(string $message, string $type): bool
    {
        return count(self::names($message, $type)) > 1;
    }

    private static function names(string $message, string $type): array
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
                $parts = preg_split('/\s+(?:за|с|по|для|на|от|проекта|объекта|склада|сотрудника|подрядчика)\b|[,;\n]/iu', $tail, 2);
                $name = preg_replace('/^[\s\x{0022}\x{0027}«»“”.:]+|[\s\x{0022}\x{0027}«»“”.:]+$/u', '', (string) ($parts[0] ?? '')) ?? '';
                if (preg_match('/^(?:(?:за|с|по|для|на|от|этот|этого|весь|всем|все|сегодня|проекта|объекта|склада|подрядчика|сотрудника)\b|текущ|выполн)/iu', $name) === 1) {
                    continue;
                }
            }
            if ($name !== '') {
                $names[mb_strtolower($name)] = $name;
            }
        }

        return array_values($names);
    }
}
