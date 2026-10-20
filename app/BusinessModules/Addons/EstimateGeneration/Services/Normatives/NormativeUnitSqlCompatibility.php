<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services\Normatives;

final class NormativeUnitSqlCompatibility
{
    /** @return array{sql: string, bindings: array<int, string>}|null */
    public static function predicate(string $workUnit): ?array
    {
        $pattern = NormativeUnitNormalizer::compatibleSearchPattern($workUnit);
        if ($pattern === null) {
            return null;
        }
        $expression = self::normalize("COALESCE(unit, '')");
        $expression = "REGEXP_REPLACE($expression, '^тыс[.]?[[:space:]]+', '')";
        $expression = "REGEXP_REPLACE($expression, '^[0-9]+([,.][0-9]+)?[[:space:]]*', '')";
        $expression = self::normalize($expression);
        foreach ([' ', '.', ','] as $character) {
            $expression = "REPLACE($expression, '$character', '')";
        }

        return ['sql' => $expression.' ~ ?', 'bindings' => [$pattern]];
    }

    private static function normalize(string $expression): string
    {
        $expression = "LOWER(BTRIM($expression, ' ' || CHR(9) || CHR(10) || CHR(13) || CHR(11)))";
        foreach (NormativeUnitNormalizer::normalizationReplacements() as $from => $to) {
            $from = str_replace("'", "''", $from);
            $to = str_replace("'", "''", $to);
            $expression = "REPLACE($expression, '$from', '$to')";
        }

        return $expression;
    }
}
