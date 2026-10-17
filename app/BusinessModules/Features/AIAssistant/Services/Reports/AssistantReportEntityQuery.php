<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Reports;

final class AssistantReportEntityQuery
{
    public static function extract(string $message, string $type): ?string
    {
        $noun = match ($type) {
            'project' => '(?:проект(?:а|у|ом|е)?|объект(?:а|у|ом|е)?)',
            'warehouse' => 'склад(?:а|у|ом|е)?',
            'contract' => 'договор(?:а|у|ом|е)?',
            'contractor' => '(?:подрядчик(?:а|у|ом|е)?|контрагент(?:а|у|ом|е)?)',
            'user' => '(?:сотрудник(?:а|у|ом|е)?|пользовател(?:ь|я|ю|ем|е))',
            default => '',
        };
        if ($noun === '' || preg_match('/\b'.$noun.'\s+(.+?)$/iu', $message, $matches) !== 1) {
            return null;
        }
        if (preg_match('/^(?:«([^»]+)»|“([^”]+)”|"([^"]+)")/u', trim($matches[1]), $quoted) === 1) {
            foreach (array_slice($quoted, 1) as $name) {
                if (trim($name) !== '') {
                    return trim($name);
                }
            }
        }
        $parts = preg_split('/\s+(?:за|с|по|для|на|от|проекта|объекта|склада|сотрудника|подрядчика)\b|[,;\n]/iu', $matches[1], 2);
        $name = trim((string) ($parts[0] ?? ''), " \t\r\n\"'«»“”.:");
        if ($name === '' || preg_match('/^(?:(?:за|с|по|для|на|от|этот|этого|весь|всем|все|сегодня|проекта|объекта|склада|подрядчика|сотрудника)\b|текущ|выполн)/iu', $name) === 1) {
            return null;
        }

        return $name;
    }
}
