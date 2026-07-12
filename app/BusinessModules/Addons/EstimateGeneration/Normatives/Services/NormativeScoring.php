<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Normatives\Services;

final class NormativeScoring
{
    public const VERSION = 'normative-combined-v1';

    /** @param list<array{id: string, lexical: float, semantic: ?float}> $scores */
    public function rank(array $scores): array
    {
        if ($scores === []) {
            return [];
        }
        $lexicalMax = max(1.0, ...array_column($scores, 'lexical'));
        $semanticValues = array_values(array_filter(array_column($scores, 'semantic'), static fn (mixed $score): bool => $score !== null));
        $semanticMax = $semanticValues === [] ? 1.0 : max(1.0, ...$semanticValues);
        foreach ($scores as &$score) {
            $lexical = min(1.0, max(0.0, $score['lexical'] / $lexicalMax));
            $semantic = $score['semantic'] === null ? null : min(1.0, max(0.0, $score['semantic'] / $semanticMax));
            $score['combined'] = round((0.4 * $lexical) + (0.6 * ($semantic ?? 0.0)), 8);
        }
        unset($score);
        usort($scores, static fn (array $left, array $right): int => $right['combined'] <=> $left['combined'] ?: strcmp($left['id'], $right['id']));

        return $scores;
    }
}
