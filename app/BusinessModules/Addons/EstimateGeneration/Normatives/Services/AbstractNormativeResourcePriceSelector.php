<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Normatives\Services;

final readonly class AbstractNormativeResourcePriceSelector
{
    /**
     * @param  list<object>  $candidates
     * @param  list<int>  $baseDatasetIds
     * @return array{row: object, candidates_count: int, policy: string}|null
     */
    public function select(string $groupCode, int $regionalPriceVersionId, array $candidates, array $baseDatasetIds = []): ?array
    {
        if (preg_match('/^\d{2}\.\d\.\d{2}\.\d{2}$/D', $groupCode) !== 1 || $regionalPriceVersionId <= 0) {
            return null;
        }

        $related = array_values(array_filter(
            $candidates,
            static function (object $candidate) use ($groupCode): bool {
                $price = $candidate->base_price ?? null;

                return preg_match(
                    '/^'.preg_quote($groupCode, '/').'-\d{4}$/D',
                    trim((string) ($candidate->price_resource_code ?? '')),
                ) === 1
                    && is_numeric($price)
                    && (float) $price > 0
                    && (int) ($candidate->price_id ?? 0) > 0;
            },
        ));
        $regional = array_values(array_filter(
            $related,
            static fn (object $candidate): bool => (int) ($candidate->regional_price_version_id ?? 0) === $regionalPriceVersionId,
        ));
        $eligible = $regional;
        $policy = 'regional_child_median:v1';
        if ($eligible === []) {
            $baseCandidates = array_values(array_filter(
                $related,
                static fn (object $candidate): bool => in_array((int) ($candidate->dataset_version_id ?? 0), $baseDatasetIds, true)
                    && ($candidate->regional_price_version_id ?? null) === null
                    && in_array((string) ($candidate->price_dataset_source_type ?? ''), ['fsbc', 'fsnb_2022'], true),
            ));
            $fsbcCandidates = array_values(array_filter(
                $baseCandidates,
                static fn (object $candidate): bool => ($candidate->price_dataset_source_type ?? null) === 'fsbc',
            ));
            $eligible = $fsbcCandidates !== [] ? $fsbcCandidates : $baseCandidates;
            $policy = $fsbcCandidates !== [] ? 'fsbc_base_child_median:v1' : 'fsnb_base_child_median:v1';
        }
        if ($eligible === []) {
            return null;
        }

        usort($eligible, static function (object $left, object $right): int {
            $byPrice = (float) $left->base_price <=> (float) $right->base_price;
            if ($byPrice !== 0) {
                return $byPrice;
            }
            $byCode = strcmp((string) $left->price_resource_code, (string) $right->price_resource_code);

            return $byCode !== 0 ? $byCode : (int) $left->price_id <=> (int) $right->price_id;
        });

        return [
            'row' => $eligible[intdiv(count($eligible) - 1, 2)],
            'candidates_count' => count($eligible),
            'policy' => $policy,
        ];
    }
}
