<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Normatives\Services;

final readonly class NormativeSearchQueryBuilder
{
    public function build(string $searchText): string
    {
        preg_match_all('/[\p{L}\p{N}.-]+/u', mb_strtolower($searchText), $matches);
        $tokens = array_slice(array_values(array_unique(array_filter(
            $matches[0] ?? [],
            static fn (string $token): bool => mb_strlen(trim($token, '.- ')) >= 3,
        ))), 0, 10);

        if ($tokens === []) {
            return mb_substr(trim($searchText), 0, 1000);
        }

        return implode(' OR ', array_map(
            static fn (string $token): string => '"'.trim($token, '.- ').'"',
            $tokens,
        ));
    }
}
