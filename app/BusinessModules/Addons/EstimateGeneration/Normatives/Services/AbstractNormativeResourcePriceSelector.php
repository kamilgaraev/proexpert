<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Normatives\Services;

final readonly class AbstractNormativeResourcePriceSelector
{
    /**
     * @param  list<object>  $candidates
     * @return array{row: object, candidates_count: int}|null
     */
    public function select(string $groupCode, int $regionalPriceVersionId, array $candidates): ?array
    {
        if (preg_match('/^\d{2}\.\d\.\d{2}\.\d{2}$/D', $groupCode) !== 1 || $regionalPriceVersionId <= 0) {
            return null;
        }

        $eligible = array_values(array_filter(
            $candidates,
            static function (object $candidate) use ($groupCode, $regionalPriceVersionId): bool {
                $price = $candidate->base_price ?? null;

                return (int) ($candidate->regional_price_version_id ?? 0) === $regionalPriceVersionId
                    && preg_match(
                        '/^'.preg_quote($groupCode, '/').'-\d{4}$/D',
                        trim((string) ($candidate->price_resource_code ?? '')),
                    ) === 1
                    && is_numeric($price)
                    && (float) $price > 0
                    && (int) ($candidate->price_id ?? 0) > 0;
            },
        ));
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
        ];
    }
}
