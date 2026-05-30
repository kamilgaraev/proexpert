<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services\Normatives;

class NormativeCandidatePresenter
{
    /**
     * @param array<string, mixed> $candidate
     * @return array<string, mixed>
     */
    public function present(array $candidate): array
    {
        $resources = is_array($candidate['resources'] ?? null) ? $candidate['resources'] : [];

        return [
            'key' => $candidate['key'] ?? null,
            'norm_id' => $candidate['norm_id'] ?? null,
            'code' => $candidate['code'] ?? null,
            'name' => $candidate['name'] ?? null,
            'unit' => $candidate['unit'] ?? null,
            'collection' => $candidate['collection'] ?? null,
            'section' => $candidate['section'] ?? null,
            'confidence' => round((float) ($candidate['confidence'] ?? 0), 4),
            'score' => round((float) ($candidate['score'] ?? 0), 4),
            'resources_count' => $this->resourcesCount($resources),
            'priced_resources_count' => $this->pricedResourcesCount($resources),
            'match_reasons' => array_values($candidate['match_reasons'] ?? []),
            'warnings' => array_values($candidate['warnings'] ?? []),
            'work_composition' => $this->normalizeComposition($candidate['work_composition'] ?? []),
            'learning_positive_count' => (int) ($candidate['learning_positive_count'] ?? 0),
            'learning_negative_count' => (int) ($candidate['learning_negative_count'] ?? 0),
            'learning_score' => round((float) ($candidate['learning_score'] ?? 0), 2),
            'learning_sources' => array_values($candidate['learning_sources'] ?? []),
        ];
    }

    /**
     * @param array<string, mixed> $resources
     */
    private function resourcesCount(array $resources): int
    {
        return count($resources['materials'] ?? [])
            + count($resources['machinery'] ?? [])
            + count($resources['labor'] ?? [])
            + count($resources['other'] ?? []);
    }

    /**
     * @param array<string, mixed> $resources
     */
    private function pricedResourcesCount(array $resources): int
    {
        $count = 0;

        foreach ($resources as $group) {
            foreach ($group as $resource) {
                if (($resource['price_source'] ?? null) !== null) {
                    $count++;
                }
            }
        }

        return $count;
    }

    /**
     * @param mixed $composition
     * @return array<int, string>
     */
    private function normalizeComposition(mixed $composition): array
    {
        if (!is_array($composition)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn (mixed $item): string => trim((string) $item), $composition),
            static fn (string $item): bool => $item !== ''
        ));
    }
}
