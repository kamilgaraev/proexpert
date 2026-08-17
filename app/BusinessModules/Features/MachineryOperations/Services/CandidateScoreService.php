<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\MachineryOperations\Services;

use Illuminate\Support\Collection;

final class CandidateScoreService
{
    /**
     * @param  Collection<int, array<string, mixed>>  $candidates
     * @return Collection<int, array<string, mixed>>
     */
    public function score(Collection $candidates): Collection
    {
        $eligibleCosts = $candidates
            ->filter(static fn (array $candidate): bool => (bool) ($candidate['eligible'] ?? false))
            ->map(static fn (array $candidate): float => max(0.0, (float) ($candidate['operating_cost_per_hour'] ?? 0.0)));
        $minCost = $eligibleCosts->isEmpty() ? 0.0 : (float) $eligibleCosts->min();
        $maxCost = $eligibleCosts->isEmpty() ? 0.0 : (float) $eligibleCosts->max();

        return $candidates->map(function (array $candidate) use ($minCost, $maxCost): array {
            if (! ($candidate['eligible'] ?? false)) {
                return [
                    ...$candidate,
                    'score' => null,
                    'suitability' => 'excluded',
                    'suitability_label' => 'Не подходит',
                    'score_breakdown' => null,
                ];
            }

            $location = $this->locationPoints(
                (bool) ($candidate['same_project'] ?? false),
                isset($candidate['distance_km']) ? (float) $candidate['distance_km'] : null,
            );
            $cost = $this->costPoints((float) ($candidate['operating_cost_per_hour'] ?? 0.0), $minCost, $maxCost);
            $score = round(40.0 + $location['points'] + $cost['points'], 1);
            [$suitability, $suitabilityLabel] = match (true) {
                $score >= 80 => ['excellent', 'Отлично подходит'],
                $score >= 60 => ['good', 'Хорошо подходит'],
                default => ['acceptable', 'Подходит'],
            };

            return [
                ...$candidate,
                'score' => $score,
                'suitability' => $suitability,
                'suitability_label' => $suitabilityLabel,
                'score_breakdown' => [
                    'requirements' => ['points' => 40.0, 'max_points' => 40.0, 'label' => 'Требования соблюдены'],
                    'location' => $location,
                    'cost' => $cost,
                ],
            ];
        });
    }

    /** @return array{points: float, max_points: float, label: string} */
    private function locationPoints(bool $sameProject, ?float $distance): array
    {
        if ($sameProject) {
            return ['points' => 30.0, 'max_points' => 30.0, 'label' => 'Техника уже на этом проекте'];
        }
        if ($distance === null) {
            return ['points' => 15.0, 'max_points' => 30.0, 'label' => 'Геоданные не указаны'];
        }
        $points = round(max(0.0, 30.0 * (1.0 - min(max($distance, 0.0), 100.0) / 100.0)), 1);

        return ['points' => $points, 'max_points' => 30.0, 'label' => 'Расстояние '.round(max($distance, 0.0), 1).' км'];
    }

    /** @return array{points: float, max_points: float, label: string} */
    private function costPoints(float $cost, float $minCost, float $maxCost): array
    {
        if ($maxCost <= $minCost) {
            return ['points' => 30.0, 'max_points' => 30.0, 'label' => 'Стоимость сопоставима'];
        }
        $points = round(30.0 * (1.0 - (max(0.0, $cost) - $minCost) / ($maxCost - $minCost)), 1);
        $label = abs($cost - $minCost) < 0.0001 ? 'Минимальная стоимость' : 'Стоимость относительно кандидатов';

        return ['points' => max(0.0, min(30.0, $points)), 'max_points' => 30.0, 'label' => $label];
    }
}
