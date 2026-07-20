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
        $targetAttributes = $this->targetHardAttributes($normName, $groupName);
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
            ? 'regional_child_hard_attributes_median:v2'
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
                $usesHardAttributes && $fsbcCandidates !== [] => 'fsbc_base_child_hard_attributes_median:v2',
                $usesHardAttributes => 'fsnb_base_child_hard_attributes_median:v2',
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
     * @param  array{diameter: ?float, diameter_max: ?float, thickness: ?float, diameter_conflict: bool, material: ?string, polarity: ?string, purposes: list<string>}  $target
     * @return list<object>
     */
    private function filterByHardAttributes(array $candidates, array $target): array
    {
        return array_values(array_filter($candidates, function (object $candidate) use ($target): bool {
            $candidateAttributes = $this->hardAttributes((string) ($candidate->price_resource_name ?? ''));
            if ($candidateAttributes['diameter_conflict']) {
                return false;
            }
            if ($target['diameter'] !== null
                && $candidateAttributes['diameter'] !== $target['diameter']
                && ! $this->diameterRangeContains(
                    (string) ($candidate->price_resource_name ?? ''),
                    $target['diameter'],
                )) {
                return false;
            }
            if ($target['diameter_max'] !== null) {
                $candidateLimit = $candidateAttributes['diameter_max'] ?? $candidateAttributes['diameter'];
                if ($candidateLimit === null || $candidateLimit > $target['diameter_max']) {
                    return false;
                }
            }
            if ($target['thickness'] !== null && $candidateAttributes['thickness'] !== $target['thickness']) {
                return false;
            }
            if ($target['material'] !== null && $candidateAttributes['material'] !== $target['material']) {
                return false;
            }
            if ($target['polarity'] !== null && $candidateAttributes['polarity'] !== $target['polarity']) {
                return false;
            }

            return $target['purposes'] === []
                || $candidateAttributes['purposes'] === []
                || array_diff($candidateAttributes['purposes'], $target['purposes']) === [];
        }));
    }

    /** @return array{diameter: ?float, diameter_max: ?float, thickness: ?float, diameter_conflict: bool, material: ?string, polarity: ?string, purposes: list<string>} */
    private function targetHardAttributes(string $normName, string $groupName): array
    {
        $groupAttributes = $this->hardAttributes($groupName);
        $normalizedGroup = mb_strtolower(str_replace('ё', 'е', trim($groupName)));
        $isPipeGroup = preg_match('/\bтруб(?:а|ы|опровод)/u', $normalizedGroup) === 1
            && preg_match('/хомут|креплен/u', $normalizedGroup) !== 1;
        $isClampGroup = preg_match('/хомут|креплен/u', $normalizedGroup) === 1;
        $normalizedNorm = mb_strtolower(str_replace('ё', 'е', trim($normName)));
        if ($isClampGroup) {
            $normAttributes = $this->hardAttributes($normName);
            $diameters = array_values(array_unique(array_filter([
                $groupAttributes['diameter'],
                $normAttributes['diameter'],
            ], static fn (?float $value): bool => $value !== null), SORT_REGULAR));

            return [
                ...$groupAttributes,
                'diameter' => count($diameters) === 1 ? $diameters[0] : null,
                'diameter_conflict' => $groupAttributes['diameter_conflict']
                    || $normAttributes['diameter_conflict']
                    || count($diameters) > 1,
                'purposes' => array_values(array_unique([
                    ...$groupAttributes['purposes'],
                    ...$normAttributes['purposes'],
                ])),
            ];
        }
        $inheritsNormAttributes = $isPipeGroup
            || preg_match('/оконн\w*\s+блок|блок\w*\s+окон/u', $normalizedGroup.' '.$normalizedNorm) === 1
            || str_contains($normalizedGroup.' '.$normalizedNorm, 'воздуховод');
        if (! $inheritsNormAttributes) {
            return $groupAttributes;
        }

        $normAttributes = $this->hardAttributes($normName);
        $diameters = array_values(array_unique(array_filter([
            $groupAttributes['diameter'],
            $normAttributes['diameter'],
        ], static fn (?float $value): bool => $value !== null), SORT_REGULAR));
        $diameterMaxes = array_values(array_unique(array_filter([
            $groupAttributes['diameter_max'],
            $normAttributes['diameter_max'],
        ], static fn (?float $value): bool => $value !== null), SORT_REGULAR));
        $thicknesses = array_values(array_unique(array_filter([
            $groupAttributes['thickness'],
            $normAttributes['thickness'],
        ], static fn (?float $value): bool => $value !== null), SORT_REGULAR));
        $materials = array_values(array_unique(array_filter([
            $groupAttributes['material'],
            $normAttributes['material'],
        ], static fn (?string $value): bool => $value !== null), SORT_STRING));
        $polarities = array_values(array_unique(array_filter([
            $groupAttributes['polarity'],
            $normAttributes['polarity'],
        ], static fn (?string $value): bool => $value !== null), SORT_STRING));

        return [
            'diameter' => count($diameters) === 1 ? $diameters[0] : null,
            'diameter_max' => count($diameterMaxes) === 1 ? $diameterMaxes[0] : null,
            'thickness' => count($thicknesses) === 1 ? $thicknesses[0] : null,
            'diameter_conflict' => $groupAttributes['diameter_conflict']
                || $normAttributes['diameter_conflict']
                || count($diameters) > 1
                || count($diameterMaxes) > 1
                || count($thicknesses) > 1
                || count($materials) > 1
                || count($polarities) > 1,
            'material' => count($materials) === 1 ? $materials[0] : null,
            'polarity' => count($polarities) === 1 ? $polarities[0] : null,
            'purposes' => array_values(array_unique([
                ...$groupAttributes['purposes'],
                ...$normAttributes['purposes'],
            ])),
        ];
    }

    /** @return array{diameter: ?float, diameter_max: ?float, thickness: ?float, diameter_conflict: bool, material: ?string, polarity: ?string, purposes: list<string>} */
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
        preg_match_all(
            '/диаметр\w*\s*(?:не\s+более|до)\s*(\d{1,4}(?:[.,]\d+)?)/u',
            $text,
            $diameterMaxMatches,
        );
        $diameterMaxes = array_values(array_unique(array_map(
            static fn (string $value): float => (float) str_replace(',', '.', $value),
            $diameterMaxMatches[1] ?? [],
        ), SORT_REGULAR));
        preg_match_all(
            '/толщин\w*\s*[:=]?\s*(\d{1,2}(?:[.,]\d+)?)/u',
            $text,
            $thicknessMatches,
        );
        $thicknesses = array_values(array_unique(array_map(
            static fn (string $value): float => (float) str_replace(',', '.', $value),
            $thicknessMatches[1] ?? [],
        ), SORT_REGULAR));
        $diameterConflict = count($diameters) > 1 || count($diameterMaxes) > 1 || count($thicknesses) > 1;
        $material = match (true) {
            preg_match('/дерев\w*[-\s]?алюмини|дерево-алюмини/u', $text) === 1 => 'wood_aluminum',
            preg_match('/полипропилен/u', $text) === 1 => 'polypropylene',
            preg_match('/(?:\bпнд\b|\bhdpe\b|полиэтилен)/u', $text) === 1 => 'polyethylene',
            preg_match('/(?:\bпвх\b|поливинилхлорид|пластиков)/u', $text) === 1 => 'pvc',
            preg_match('/оцинкован/u', $text) === 1 && preg_match('/стал\w*/u', $text) === 1 => 'steel',
            preg_match('/алюмини/u', $text) === 1 => 'aluminum',
            preg_match('/деревян|древес/u', $text) === 1 => 'wood',
            preg_match('/(?:стал\w*|\bвгп\b)/u', $text) === 1 => 'steel',
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
            'diameter_max' => count($diameterMaxes) === 1 ? $diameterMaxes[0] : null,
            'thickness' => count($thicknesses) === 1 ? $thicknesses[0] : null,
            'diameter_conflict' => $diameterConflict,
            'material' => $material,
            'polarity' => preg_match('/оцинкован/u', $text) === 1 ? 'galvanized' : null,
            'purposes' => $purposes,
        ];
    }

    /** @param array{diameter: ?float, diameter_max: ?float, thickness: ?float, diameter_conflict: bool, material: ?string, polarity: ?string, purposes: list<string>} $attributes */
    private function hasHardAttributes(array $attributes): bool
    {
        return $attributes['diameter'] !== null
            || $attributes['diameter_max'] !== null
            || $attributes['thickness'] !== null
            || $attributes['material'] !== null
            || $attributes['polarity'] !== null
            || $attributes['purposes'] !== [];
    }

    private function diameterRangeContains(string $source, float $diameter): bool
    {
        $text = mb_strtolower(str_replace('ё', 'е', trim($source)));
        if (preg_match(
            '/диаметр\w*\s*(?:от\s*)?(\d{1,4}(?:[.,]\d+)?)\s*(?:-|–|—|до)\s*(\d{1,4}(?:[.,]\d+)?)/u',
            $text,
            $matches,
        ) !== 1) {
            return false;
        }

        $minimum = (float) str_replace(',', '.', $matches[1]);
        $maximum = (float) str_replace(',', '.', $matches[2]);

        return $minimum <= $diameter && $diameter <= $maximum;
    }
}
