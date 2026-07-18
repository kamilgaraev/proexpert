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
    public function select(
        string $groupCode,
        int $regionalPriceVersionId,
        array $candidates,
        array $baseDatasetIds = [],
        string $normName = '',
        string $groupName = '',
    ): ?array {
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
        $targetAttributes = $this->hardAttributes($normName.' '.$groupName);
        if ($targetAttributes['diameter_conflict']) {
            return null;
        }
        $usesHardAttributes = $this->hasHardAttributes($targetAttributes);
        $regional = array_values(array_filter(
            $related,
            static fn (object $candidate): bool => (int) ($candidate->regional_price_version_id ?? 0) === $regionalPriceVersionId,
        ));
        if ($usesHardAttributes) {
            $regional = $this->filterByHardAttributes($regional, $targetAttributes);
        }
        $eligible = $regional;
        $policy = $usesHardAttributes
            ? 'regional_child_hard_attributes_median:v1'
            : 'regional_child_median:v1';
        if ($eligible === []) {
            $baseCandidates = array_values(array_filter(
                $related,
                static fn (object $candidate): bool => in_array((int) ($candidate->dataset_version_id ?? 0), $baseDatasetIds, true)
                    && ($candidate->regional_price_version_id ?? null) === null
                    && in_array((string) ($candidate->price_dataset_source_type ?? ''), ['fsbc', 'fsnb_2022'], true),
            ));
            if ($usesHardAttributes) {
                $baseCandidates = $this->filterByHardAttributes($baseCandidates, $targetAttributes);
            }
            $fsbcCandidates = array_values(array_filter(
                $baseCandidates,
                static fn (object $candidate): bool => ($candidate->price_dataset_source_type ?? null) === 'fsbc',
            ));
            $eligible = $fsbcCandidates !== [] ? $fsbcCandidates : $baseCandidates;
            $policy = match (true) {
                $usesHardAttributes && $fsbcCandidates !== [] => 'fsbc_base_child_hard_attributes_median:v1',
                $usesHardAttributes => 'fsnb_base_child_hard_attributes_median:v1',
                $fsbcCandidates !== [] => 'fsbc_base_child_median:v1',
                default => 'fsnb_base_child_median:v1',
            };
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

    /**
     * @param  list<object>  $candidates
     * @param  array{diameter: ?float, diameter_conflict: bool, material: ?string, purposes: list<string>}  $target
     * @return list<object>
     */
    private function filterByHardAttributes(array $candidates, array $target): array
    {
        return array_values(array_filter($candidates, function (object $candidate) use ($target): bool {
            $candidateAttributes = $this->hardAttributes((string) ($candidate->price_resource_name ?? ''));
            if ($candidateAttributes['diameter_conflict']
                || ($target['diameter'] !== null && $candidateAttributes['diameter'] !== $target['diameter'])) {
                return false;
            }
            if ($target['material'] !== null && $candidateAttributes['material'] !== $target['material']) {
                return false;
            }

            return $target['purposes'] === []
                || $candidateAttributes['purposes'] === []
                || array_diff($candidateAttributes['purposes'], $target['purposes']) === [];
        }));
    }

    /** @return array{diameter: ?float, diameter_conflict: bool, material: ?string, purposes: list<string>} */
    private function hardAttributes(string $source): array
    {
        $text = mb_strtolower(str_replace('ё', 'е', trim($source)));
        preg_match_all(
            '/(?:диаметр\w*|условн\w*\s+проход\w*|\bду\b|\bdn\b|[dд]\s*=|[ø⌀])\s*[:=]?\s*(\d{1,4}(?:[.,]\d+)?)/u',
            $text,
            $matches,
        );
        $diameters = array_values(array_unique(array_map(
            static fn (string $value): float => (float) str_replace(',', '.', $value),
            $matches[1] ?? [],
        ), SORT_REGULAR));
        $diameter = count($diameters) === 1 ? $diameters[0] : null;
        $diameterConflict = count($diameters) > 1;
        $material = match (true) {
            preg_match('/полипропилен/u', $text) === 1 => 'polypropylene',
            preg_match('/(?:\bпнд\b|\bhdpe\b|полиэтилен)/u', $text) === 1 => 'polyethylene',
            preg_match('/(?:\bпвх\b|поливинилхлорид)/u', $text) === 1 => 'pvc',
            preg_match('/(?:сталь\w*|стальн\w*|\bвгп\b)/u', $text) === 1 => 'steel',
            preg_match('/чугун/u', $text) === 1 => 'cast_iron',
            preg_match('/медн\w*/u', $text) === 1 => 'copper',
            preg_match('/асбестоцемент|хризотилцемент/u', $text) === 1 => 'asbestos_cement',
            default => null,
        };
        $purposeText = str_replace('водогазопровод', 'вгп', $text);
        $purposePatterns = [
            'sewerage' => '/(?:канализац|водоотвед)/u',
            'water_supply' => '/(?:водоснаб|водопровод)/u',
            'heating' => '/(?:отоплен|теплоснаб)/u',
            'gas' => '/газопровод/u',
            'stormwater' => '/(?:ливнев|дожд)/u',
            'drainage' => '/дренаж/u',
            'fire' => '/(?:противопожар|пожар)/u',
            'cable' => '/кабел/u',
        ];
        $purposes = [];
        foreach ($purposePatterns as $purpose => $pattern) {
            if (preg_match($pattern, $purposeText) === 1) {
                $purposes[] = $purpose;
            }
        }

        return [
            'diameter' => $diameter,
            'diameter_conflict' => $diameterConflict,
            'material' => $material,
            'purposes' => $purposes,
        ];
    }

    /** @param array{diameter: ?float, diameter_conflict: bool, material: ?string, purposes: list<string>} $attributes */
    private function hasHardAttributes(array $attributes): bool
    {
        return $attributes['diameter'] !== null
            || $attributes['material'] !== null
            || $attributes['purposes'] !== [];
    }
}
