<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services;

final class AssistantProductionScheduleLocationScope
{
    private const NUMBER = '\d+(?:-(?:й|я|е|м|го|му|ый|ом))?';

    private const NUMBERS = self::NUMBER.'(?:\s*(?:[,—–-]|и)\s*'.self::NUMBER.'){0,4}';

    private const UNIT = '(?:этаж(?:а|е|у|и|ей|ам|ами|ах|ом)?|корпус(?:а|е|у|ы|ов|ам|ами|ах|ом)?)';

    private const FILTER = '(?:(?:на|в|во|по|с|со|для)\s+)?(?:'.self::NUMBERS.'\s+'.self::UNIT.'|'.self::UNIT.'\s+(?:№\s*)?'.self::NUMBERS.')(?![\pL\pN])';

    public static function matches(string $query): bool
    {
        return preg_match('/\b'.self::FILTER.'/iu', $query) === 1;
    }

    public static function withoutSuffix(string $name): string
    {
        $parts = preg_split('/\s+(?='.self::FILTER.')/iu', $name, 2);
        $head = trim((string) ($parts[0] ?? $name));

        return count($parts) > 1 ? (preg_replace('/[\s,;:—–\-]+$/u', '', $head) ?? $head) : $head;
    }
}
