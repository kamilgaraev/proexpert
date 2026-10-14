<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services;

final class AssistantBimModelSelector
{
    public static function hasExplicitModelScope(string $question, ?string $selector): bool
    {
        $prefix = '(?:модел[а-яё]*|файл[а-яё]*|комплект[а-яё]*)\s+(?:(?:ifc|bim|с\s+названием|под\s+названием)\s+)*';
        if (preg_match('/'.$prefix.'[«"“\x27‘][^»"”\x27’]+[»"”\x27’]|(?<![\pL\pN])[\pL\pN][\pL\pN_.()\-]*\.ifc(?![\pL\pN])/iu', $question) === 1) {
            return true;
        }

        return $selector !== null && trim($selector) !== ''
            && preg_match('/'.$prefix.'[«"“\x27‘]?'.preg_quote(trim($selector), '/').'(?![\pL\pN])/iu', $question) === 1;
    }

    public static function withoutProjectAlias(?string $modelQuery, string $projectName, string $question): ?string
    {
        if ($modelQuery === null || trim($modelQuery) === '') {
            return $modelQuery;
        }
        $needle = str_replace('ё', 'е', mb_strtolower(trim($modelQuery)));
        $query = str_replace('ё', 'е', mb_strtolower($question));
        $literal = preg_quote($needle, '/');
        if (! self::matchesProjectQuestion($modelQuery, $projectName, $question)
            || preg_match('/\.ifc\b/iu', $question)
            || preg_match('/(?:модел[а-я]*|файл[а-я]*)\s+(?:(?:ifc|bim|с\s+названием|под\s+названием)\s+)*[«"“\x27‘]/u', $query)
            || preg_match('/(?:модел[а-я]*|файл[а-я]*)\s+(?:(?:ifc|bim|с\s+названием|под\s+названием)\s+)*[«"“\x27‘]?'.$literal.'/u', $query)) {
            return $modelQuery;
        }

        return null;
    }

    public static function matchesProjectQuestion(?string $selector, string $projectName, string $question): bool
    {
        return self::isProjectQuestion($selector, $question)
            && str_contains(str_replace('ё', 'е', mb_strtolower($projectName)), str_replace('ё', 'е', mb_strtolower(trim((string) $selector))));
    }

    public static function isProjectQuestion(?string $selector, string $question): bool
    {
        if ($selector === null || trim($selector) === '') {
            return false;
        }
        $literal = preg_quote(str_replace('ё', 'е', mb_strtolower(trim($selector))), '/');
        $query = str_replace('ё', 'е', mb_strtolower($question));

        return preg_match('/(?:проект[а-я]*|(?:по|на|в)\s+(?:строительн[а-я]*\s+)?объект[а-я]*(?:\s+(?:капитальн[а-я]*\s+)?строительств[а-я]*)?)\s+[«"“\x27‘]?'.$literal.'(?![\pL\pN])/u', $query) === 1;
    }

    public static function hasProjectScope(string $question): bool
    {
        return preg_match('/(?<![\pL\pN])(?:проект(?:е|у|а)?|(?:по|на|в)\s+(?:строительн[а-яё]*\s+)?объект(?:е|у|а)?)(?![\pL\pN])/iu', $question) === 1;
    }

    public static function namedProject(string $question): ?string
    {
        if (! preg_match('/(?<![\pL\pN])(?:проект(?:е|у|а)?|(?:по|на|в)\s+(?:строительн[а-яё]*\s+)?объект(?:е|у|а)?(?:\s+(?:капитальн[а-яё]*\s+)?строительств[а-яё]*)?)\s+(?:[«"“\x27‘]([^»"”\x27’]+)[»"”\x27’]|([^.!?,]{1,120}))/iu', $question, $match)) {
            return null;
        }
        if ($match[1] !== '') {
            return trim($match[1]);
        }
        if (preg_match('/(?<![\pL\pN])(?:наш|текущ|выбран|эт[ао]|данн)[а-яё]*\s+(?:проект|объект)/iu', $question)) {
            return null;
        }
        $name = trim((string) preg_split('/\s+(?:есть|сколько|покажи|посчитай|найди|проверь|какие|котор[а-яё]*|и)(?![\pL\pN])/iu', $match[2], 2)[0]);
        if ($name === '' || preg_match('/^(?:(?:текущ|выбран|эт[ао]|данн)[а-яё]*|есть|сколько|покажи|посчитай|найди|проверь|какие)(?![\pL\pN])/iu', $name)) {
            return null;
        }

        return $name;
    }
}
