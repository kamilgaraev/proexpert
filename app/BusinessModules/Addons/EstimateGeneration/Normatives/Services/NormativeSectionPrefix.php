<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Normatives\Services;

final class NormativeSectionPrefix
{
    public static function matches(string $candidate, string $preferred): bool
    {
        $candidateParts = self::parts($candidate);
        $preferredParts = self::parts($preferred);

        return $preferredParts !== []
            && count($candidateParts) >= count($preferredParts)
            && array_slice($candidateParts, 0, count($preferredParts)) === $preferredParts;
    }

    public static function postgresPattern(string $preferred): ?string
    {
        $parts = self::parts($preferred);

        return $parts === [] ? null : '^[^0-9]*'.implode('[^0-9]+', array_map(static fn (int $part): string => '0*'.$part, $parts)).'([^0-9]|$)';
    }

    private static function parts(string $section): array
    {
        return array_values(array_map('intval', array_filter(
            preg_split('/\D+/', $section) ?: [],
            static fn (string $part): bool => $part !== '',
        )));
    }
}
