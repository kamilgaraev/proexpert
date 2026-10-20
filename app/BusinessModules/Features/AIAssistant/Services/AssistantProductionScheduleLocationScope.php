<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services;

final class AssistantProductionScheduleLocationScope
{
    private const NUMBER = '\d+(?:-(?:й|я|е|м|го|му|ый|ом))?';

    private const NUMBERS = self::NUMBER.'(?:\s*(?:[,—–-]|и|по|до)\s*'.self::NUMBER.'){0,4}';

    private const UNIT = '(?:этаж(?:а|е|у|и|ей|ам|ами|ах|ом)?|корпус(?:а|е|у|ы|ов|ам|ами|ах|ом)?)';

    private const PREFIX = '(?:на|в|во|по|с|со|для)';

    private const NEGATION = '(?:не\s+(?:(?:ограничивай(?:ся|тесь)|ограничиваться)(?:\s+только)?\s+)?|без\s+ограничени(?:й|я)\s+)';

    private const QUALIFIER = '(?!(?:не|без|'.self::PREFIX.')\b)[\pL\pN-]+';

    private const RANGE = '(?:с|со)\s+(?:'.self::QUALIFIER.'\s+){1,3}(?:по|до)\s+(?:'.self::QUALIFIER.'\s+){1,3}'.self::UNIT;

    private const FILTER = '(?:'.self::NEGATION.')?(?:'.self::RANGE.'|(?:'.self::PREFIX.'\s+)?(?:'.self::NUMBERS.'\s+'.self::UNIT.'|'.self::UNIT.'\s+(?:№\s*)?'.self::NUMBERS.')|'.self::PREFIX.'\s+(?:'.self::QUALIFIER.'\s+){0,3}'.self::UNIT.')(?![\pL\pN])';

    public static function matches(string $query): bool
    {
        preg_match_all('/\b'.self::FILTER.'/iu', $query, $filters);
        foreach ($filters[0] as $filter) {
            if (preg_match('/^'.self::NEGATION.'/iu', $filter) !== 1) {
                return true;
            }
        }

        return false;
    }

    public static function withoutSuffix(string $name): string
    {
        $parts = preg_split('/\s+(?='.self::FILTER.')/iu', $name, 2);
        $head = trim((string) ($parts[0] ?? $name));

        return count($parts) > 1 ? (preg_replace('/[\s,;:—–\-]+$/u', '', $head) ?? $head) : $head;
    }
}
