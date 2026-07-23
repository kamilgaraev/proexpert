<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Normatives\Services;

final readonly class AbstractResourceSemanticPriceSelector
{
    /** @return array{family: string, material: string, polarity: ?string, diameter: ?int, diameter_max: ?int, thickness: ?float, window_leaf_count: ?int, window_area_max: ?float, window_operable: ?bool, duct_component: ?string}|null */
    public function queryHints(string $normName, string $groupName): ?array
    {
        $attributes = $this->targetAttributes($normName, $groupName);
        if (! $this->isStrongTarget($attributes)) {
            return null;
        }

        $hints = [
            'material' => $attributes['material'],
            'polarity' => $attributes['polarity'],
            'diameter' => $attributes['diameter'],
        ];
        if (in_array($attributes['family'], ['gutter_pipe', 'gutter_fitting'], true)) {
            $hints['family'] = $attributes['family'];
        } elseif (in_array($attributes['family'], ['window_block', 'duct', 'tile'], true)) {
            $hints['family'] = $attributes['family'];
            $hints['diameter_max'] = $attributes['diameter_max'];
            $hints['thickness'] = $attributes['thickness'];
            $hints['window_leaf_count'] = $attributes['window_leaf_count'];
            $hints['window_area_max'] = $attributes['window_area_max'];
            $hints['window_operable'] = $attributes['window_operable'];
            $hints['duct_component'] = $attributes['duct_component'];
        }

        return $hints;
    }

    /**
     * @param  list<object>  $candidates
     * @return array{row: object, candidates_count: int, policy: string}|null
     */
    public function select(
        string $normName,
        string $groupName,
        string $requiredUnit,
        int $regionalPriceVersionId,
        array $candidates,
        array $baseDatasetIds = [],
    ): ?array {
        $target = $this->targetAttributes($normName, $groupName);
        if ($regionalPriceVersionId <= 0 || $requiredUnit === '' || ! $this->isStrongTarget($target)) {
            return null;
        }

        $compatible = array_values(array_filter(
            $candidates,
            function (object $candidate) use ($target, $requiredUnit): bool {
                $price = $candidate->base_price ?? null;
                if ((int) ($candidate->price_id ?? 0) <= 0
                    || ! is_numeric($price)
                    || (float) $price <= 0
                    || ! hash_equals($requiredUnit, (string) ($candidate->price_unit ?? ''))) {
                    return false;
                }

                $candidateAttributes = $this->attributes((string) ($candidate->price_resource_name ?? ''));

                return $this->hardAttributesMatch($target, $candidateAttributes);
            },
        ));
        $regional = array_values(array_filter(
            $compatible,
            static fn (object $candidate): bool => (int) ($candidate->regional_price_version_id ?? 0) === $regionalPriceVersionId,
        ));
        $eligible = $regional;
        $source = 'regional';
        if ($eligible === []) {
            $base = array_values(array_filter(
                $compatible,
                static fn (object $candidate): bool => in_array((int) ($candidate->dataset_version_id ?? 0), $baseDatasetIds, true)
                    && ($candidate->regional_price_version_id ?? null) === null
                    && in_array((string) ($candidate->price_dataset_source_type ?? ''), ['fsbc', 'fsnb_2022'], true),
            ));
            $fsbc = array_values(array_filter(
                $base,
                static fn (object $candidate): bool => ($candidate->price_dataset_source_type ?? null) === 'fsbc',
            ));
            $eligible = $fsbc !== [] ? $fsbc : $base;
            $source = $fsbc !== [] ? 'fsbc' : 'fsnb';
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

        $policy = match (true) {
            $source === 'regional' && in_array($target['family'], ['gutter_pipe', 'gutter_fitting'], true) => 'regional_semantic_metal_gutter_family_median:v1',
            $source === 'regional' && $target['family'] === 'pipe' => 'regional_semantic_pipe_hard_attributes_median:v1',
            default => $source.'_semantic_hard_attributes_median:v4',
        };

        return [
            'row' => $eligible[intdiv(count($eligible) - 1, 2)],
            'candidates_count' => count($eligible),
            'policy' => $policy,
        ];
    }

    /**
     * @return array{family: ?string, material: ?string, polarity: ?string, diameter: ?int, diameter_max: ?int, thickness: ?float, window_leaf_count: ?int, window_area_max: ?float, window_operable: ?bool, duct_component: ?string, purposes: list<string>}
     */
    private function targetAttributes(string $normName, string $groupName): array
    {
        $attributes = $this->attributes($normName.' '.$groupName);
        $normalizedNorm = $this->normalize($normName);
        $normalizedGroup = $this->normalize($groupName);
        if (! str_contains($normalizedNorm, 'водосточ') || ! str_contains($normalizedNorm, 'металлич')) {
            return $attributes;
        }

        $family = match (true) {
            preg_match('/^\s*труб[\p{L}]*/u', $normalizedGroup) === 1
                && str_contains($normalizedGroup, 'водосточ') => 'gutter_pipe',
            str_contains($normalizedGroup, 'издел') && str_contains($normalizedGroup, 'водосточ') => 'gutter_fitting',
            default => null,
        };
        if ($family === null) {
            return $attributes;
        }

        return [
            ...$attributes,
            'family' => $family,
            'material' => 'metal',
            'purposes' => array_values(array_unique([...$attributes['purposes'], 'stormwater'])),
        ];
    }

    /**
     * @return array{family: ?string, material: ?string, polarity: ?string, diameter: ?int, diameter_max: ?int, thickness: ?float, window_leaf_count: ?int, window_area_max: ?float, window_operable: ?bool, duct_component: ?string, purposes: list<string>}
     */
    private function attributes(string $source): array
    {
        $text = $this->normalize($source);
        $isGutter = str_contains($text, 'водосточ');
        $family = match (true) {
            str_contains($text, 'воздуховод') => 'duct',
            preg_match('/окон\w*\s+блок|блок\w*\s+окон/u', $text) === 1 => 'window_block',
            str_contains($text, 'плит') => 'tile',
            $isGutter && preg_match('/^\s*труб[\p{L}]*/u', $text) === 1 => 'gutter_pipe',
            $isGutter && preg_match('/^\s*(?:колен|ворон|соединител|тройник|хомут|угол|заглуш|муфт|слив|кронштейн|держател|наконечник|паук|ограничител)/u', $text) === 1 => 'gutter_fitting',
            str_contains($text, 'труб') => 'pipe',
            default => null,
        };
        $material = match (true) {
            str_contains($text, 'керамич') => 'ceramic',
            str_contains($text, 'графит') => 'graphite',
            preg_match('/(?:\bпнд\b|\bhdpe\b|полиэтилен\w*[^.]{0,40}высок\w*\s+плотност)/u', $text) === 1 => 'hdpe',
            preg_match('/(?:стал\w*|\bвгп\b|водогазопровод)/u', $text) === 1 => 'steel',
            preg_match('/(?:металлич\w*|чугун\w*)/u', $text) === 1 => 'metal',
            preg_match('/(?:\bпвх\b|поливинилхлорид)/u', $text) === 1 => 'pvc',
            default => null,
        };
        $polarity = null;
        if ($material === 'steel') {
            $polarity = match (true) {
                preg_match('/(?:неоцинк|черн\w*\s+сталь|сталь\w*\s+черн)/u', $text) === 1 => 'non_galvanized',
                str_contains($text, 'оцинк') => 'galvanized',
                default => null,
            };
        }
        $diameter = $this->diameter($text);
        $diameterMax = null;
        if (preg_match('/диаметр\w*\s*(?:не\s+более|до)\s*(\d{1,4})/u', $text, $diameterMaxMatch) === 1) {
            $diameterMax = (int) $diameterMaxMatch[1];
        }
        $thickness = null;
        if (preg_match('/толщин\w*\s*[:=]?\s*(\d{1,2}(?:[.,]\d+)?)/u', $text, $thicknessMatch) === 1) {
            $thickness = (float) str_replace(',', '.', $thicknessMatch[1]);
        }
        $windowLeafCount = match (true) {
            preg_match('/(?:одно|1)[-\s]?створчат/u', $text) === 1 => 1,
            preg_match('/(?:дву(?:х)?|2)[-\s]?створчат/u', $text) === 1 => 2,
            preg_match('/(?:тр[её]х|3)[-\s]?створчат/u', $text) === 1 => 3,
            default => null,
        };
        $windowAreaMax = null;
        if (preg_match('/площад\w*[^\d]{0,30}(?:до|не более)\s*(\d+(?:[.,]\d+)?)\s*м2/u', $text, $windowAreaMaxMatch) === 1) {
            $windowAreaMax = (float) str_replace(',', '.', $windowAreaMaxMatch[1]);
        } elseif (preg_match('/площад\w*[^\d]{0,30}от\s*\d+(?:[.,]\d+)?\s*до\s*(\d+(?:[.,]\d+)?)\s*м2/u', $text, $windowAreaMaxMatch) === 1) {
            $windowAreaMax = (float) str_replace(',', '.', $windowAreaMaxMatch[1]);
        }
        $windowOperable = preg_match('/(?:поворот|откид)/u', $text) === 1
            ? true
            : (str_contains($text, 'глух') ? false : null);
        $ductComponent = $family === 'duct'
            ? (preg_match('/(?:издел\w*\s+фасон|фасонн\w*\s+издел)/u', $text) === 1 ? 'fitting' : 'straight')
            : null;
        $purposeText = str_replace('водогазопровод', 'вгп', $text);
        $purposeMarkers = [
            'sewerage' => '/(?:канализац|водоотвед)/u',
            'water_supply' => '/(?:водоснаб|водопровод)/u',
            'heating' => '/(?:отоплен|теплоснаб)/u',
            'gas' => '/газопровод/u',
            'cable' => '/кабел/u',
            'stormwater' => '/(?:ливнев|дожд|водосточ)/u',
            'drainage' => '/дренаж/u',
            'fire' => '/(?:противопожар|пожар)/u',
        ];
        $purposes = [];
        foreach ($purposeMarkers as $purpose => $pattern) {
            if (preg_match($pattern, $purposeText) === 1) {
                $purposes[] = $purpose;
            }
        }

        return [
            'family' => $family,
            'material' => $material,
            'polarity' => $polarity,
            'diameter' => $diameter,
            'diameter_max' => $diameterMax,
            'thickness' => $thickness,
            'window_leaf_count' => $windowLeafCount,
            'window_area_max' => $windowAreaMax,
            'window_operable' => $windowOperable,
            'duct_component' => $ductComponent,
            'purposes' => $purposes,
        ];
    }

    private function diameter(string $text): ?int
    {
        $patterns = [
            '/(?:диаметр\w*|\bду|\bdn|[dд]\s*=|[ø⌀])\s*[:=]?\s*(\d{1,3})(?:[.,]\d+)?/u',
            '/\b(\d{1,3})\s*[xх×]\s*\d+(?:[.,]\d+)?\s*мм\b/u',
            '/\b(\d{1,3})(?:[.,]\d+)?\s*мм\b/u',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $matches) === 1) {
                $diameter = (int) $matches[1];

                return $diameter > 0 ? $diameter : null;
            }
        }

        return null;
    }

    /** @param array{family: ?string, material: ?string, polarity: ?string, diameter: ?int, diameter_max: ?int, thickness: ?float, window_leaf_count: ?int, window_area_max: ?float, window_operable: ?bool, duct_component: ?string, purposes: list<string>} $attributes */
    private function isStrongTarget(array $attributes): bool
    {
        if (in_array($attributes['family'], ['gutter_pipe', 'gutter_fitting'], true)) {
            return $attributes['material'] === 'metal'
                && in_array('stormwater', $attributes['purposes'], true);
        }

        if ($attributes['family'] === 'window_block') {
            return $attributes['material'] === 'pvc';
        }

        if ($attributes['family'] === 'duct') {
            return $attributes['material'] === 'steel'
                && $attributes['polarity'] === 'galvanized'
                && $attributes['thickness'] !== null
                && ($attributes['diameter_max'] !== null || $attributes['diameter'] !== null);
        }

        if ($attributes['family'] === 'tile') {
            return $attributes['material'] === 'ceramic';
        }

        return $attributes['family'] === 'pipe'
            && in_array($attributes['material'], ['steel', 'hdpe'], true)
            && $attributes['diameter'] !== null
            && ($attributes['material'] !== 'steel' || $attributes['polarity'] !== null);
    }

    /**
     * @param  array{family: ?string, material: ?string, polarity: ?string, diameter: ?int, diameter_max: ?int, thickness: ?float, window_leaf_count: ?int, window_area_max: ?float, window_operable: ?bool, duct_component: ?string, purposes: list<string>}  $target
     * @param  array{family: ?string, material: ?string, polarity: ?string, diameter: ?int, diameter_max: ?int, thickness: ?float, window_leaf_count: ?int, window_area_max: ?float, window_operable: ?bool, duct_component: ?string, purposes: list<string>}  $candidate
     */
    private function hardAttributesMatch(array $target, array $candidate): bool
    {
        if (in_array($target['family'], ['gutter_pipe', 'gutter_fitting'], true)) {
            return $candidate['family'] === $target['family']
                && in_array($candidate['material'], ['metal', 'steel'], true)
                && in_array('stormwater', $candidate['purposes'], true)
                && ($target['diameter'] === null || $candidate['diameter'] === $target['diameter']);
        }

        if ($target['family'] === 'window_block') {
            return $candidate['family'] === 'window_block'
                && $candidate['material'] === 'pvc'
                && ($target['window_leaf_count'] === null || $candidate['window_leaf_count'] === $target['window_leaf_count'])
                && ($target['window_operable'] === null || $candidate['window_operable'] === $target['window_operable'])
                && ($target['window_area_max'] === null
                    || $candidate['window_area_max'] === null
                    || $candidate['window_area_max'] <= $target['window_area_max']);
        }

        if ($target['family'] === 'duct') {
            $candidateLimit = $candidate['diameter_max'] ?? $candidate['diameter'];
            $targetLimit = $target['diameter_max'] ?? $target['diameter'];

            return $candidate['family'] === 'duct'
                && $candidate['duct_component'] === 'straight'
                && $candidate['material'] === 'steel'
                && $candidate['polarity'] === 'galvanized'
                && $candidate['thickness'] === $target['thickness']
                && $candidateLimit !== null
                && $targetLimit !== null
                && $candidateLimit <= $targetLimit;
        }

        if ($target['family'] === 'tile') {
            return $candidate['family'] === 'tile' && $candidate['material'] === 'ceramic';
        }

        if ($candidate['family'] !== $target['family']
            || $candidate['material'] !== $target['material']
            || $candidate['diameter'] !== $target['diameter']
            || ($target['material'] === 'steel' && $candidate['polarity'] !== $target['polarity'])) {
            return false;
        }

        if ($target['material'] === 'hdpe') {
            $positivePurposes = ['sewerage', 'water_supply', 'heating'];
            $requiredPurposes = array_values(array_intersect($target['purposes'], $positivePurposes));
            if ($requiredPurposes === [] || array_diff($requiredPurposes, $candidate['purposes']) !== []) {
                return false;
            }
        }

        return array_diff($candidate['purposes'], $target['purposes']) === [];
    }

    private function normalize(string $source): string
    {
        return mb_strtolower(str_replace('ё', 'е', trim($source)));
    }
}
