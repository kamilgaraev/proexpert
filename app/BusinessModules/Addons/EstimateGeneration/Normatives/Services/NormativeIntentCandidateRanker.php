<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Normatives\Services;

final readonly class NormativeIntentCandidateRanker
{
    /** @param list<object> $candidates @param non-empty-list<array{search_text: string, unit: string, code?: string|null}> $intents @return list<object>|null */
    public function select(array $candidates, array $intents): ?array
    {
        $selected = [];
        foreach ($intents as $intent) {
            $ranked = [];
            foreach ($candidates as $candidate) {
                $score = $this->score($candidate, $intent);
                if ($score !== null) {
                    $ranked[] = [$score, (int) $candidate->id, $candidate];
                }
            }
            usort($ranked, static fn (array $left, array $right): int => [$left[0], $left[1]] <=> [$right[0], $right[1]]);
            if ($ranked === []) {
                return null;
            }
            foreach (array_slice($ranked, 0, 16) as $row) {
                $selected[(int) $row[2]->id] = $row[2];
            }
        }
        if (count($selected) > 128) {
            return null;
        }
        ksort($selected, SORT_NUMERIC);

        return array_values($selected);
    }

    /** @param array{search_text: string, unit: string, code?: string|null} $intent */
    private function score(object $candidate, array $intent): ?int
    {
        $unit = mb_strtolower((string) ($candidate->canonical_unit ?: $candidate->unit));
        if ($unit !== mb_strtolower($intent['unit'])) {
            return null;
        }
        $name = mb_strtolower((string) $candidate->name);
        $code = mb_strtolower((string) $candidate->code);
        $search = mb_strtolower($intent['search_text']);
        $requestedCode = mb_strtolower((string) ($intent['code'] ?? ''));
        if ($requestedCode !== '' && $code === $requestedCode) {
            return 0;
        }
        if ($name === $search) {
            return 1;
        }
        $tokens = array_values(array_filter(preg_split('/[^\pL\pN.-]+/u', $search) ?: [], static fn (string $token): bool => mb_strlen($token) >= 3));
        $matches = count(array_filter($tokens, static fn (string $token): bool => str_contains($name, $token)));

        return $matches > 0 ? 100 - min(99, $matches) : null;
    }
}
