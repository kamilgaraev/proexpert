<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Normatives\Services;

final readonly class AbstractResourceSemanticPriceSelector
{
    /** @return array{material: string, polarity: ?string, diameter: int}|null */
    public function queryHints(string $normName, string $groupName): ?array
    {
        $attributes = $this->attributes($normName.' '.$groupName);
        if (! $this->isStrongTarget($attributes)) {
            return null;
        }

        return [
            'material' => $attributes['material'],
            'polarity' => $attributes['polarity'],
            'diameter' => $attributes['diameter'],
        ];
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
    ): ?array {
        $target = $this->attributes($normName.' '.$groupName);
        if ($regionalPriceVersionId <= 0 || $requiredUnit === '' || ! $this->isStrongTarget($target)) {
            return null;
        }

        $eligible = array_values(array_filter(
            $candidates,
            function (object $candidate) use ($target, $requiredUnit, $regionalPriceVersionId): bool {
                $price = $candidate->base_price ?? null;
                if ((int) ($candidate->price_id ?? 0) <= 0
                    || (int) ($candidate->regional_price_version_id ?? 0) !== $regionalPriceVersionId
                    || ! is_numeric($price)
                    || (float) $price <= 0
                    || ! hash_equals($requiredUnit, (string) ($candidate->price_unit ?? ''))) {
                    return false;
                }

                $candidateAttributes = $this->attributes((string) ($candidate->price_resource_name ?? ''));

                return $this->hardAttributesMatch($target, $candidateAttributes);
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
            'policy' => 'regional_semantic_pipe_hard_attributes_median:v1',
        ];
    }

    /**
     * @return array{family: ?string, material: ?string, polarity: ?string, diameter: ?int, purposes: list<string>}
     */
    private function attributes(string $source): array
    {
        $text = mb_strtolower(str_replace('ё', 'е', trim($source)));
        $family = str_contains($text, 'труб') ? 'pipe' : null;
        $material = match (true) {
            preg_match('/(?:\bпнд\b|\bhdpe\b|полиэтилен\w*[^.]{0,40}высок\w*\s+плотност)/u', $text) === 1 => 'hdpe',
            preg_match('/(?:сталь\w*|\bвгп\b|водогазопровод)/u', $text) === 1 => 'steel',
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
        $purposeText = str_replace('водогазопровод', 'вгп', $text);
        $purposeMarkers = [
            'sewerage' => '/(?:канализац|водоотвед)/u',
            'water_supply' => '/(?:водоснаб|водопровод)/u',
            'heating' => '/(?:отоплен|теплоснаб)/u',
            'gas' => '/газопровод/u',
            'cable' => '/кабел/u',
            'stormwater' => '/(?:ливнев|дожд)/u',
            'drainage' => '/дренаж/u',
            'fire' => '/(?:противопожар|пожар)/u',
        ];
        $purposes = [];
        foreach ($purposeMarkers as $purpose => $pattern) {
            if (preg_match($pattern, $purposeText) === 1) {
                $purposes[] = $purpose;
            }
        }

        return compact('family', 'material', 'polarity', 'diameter', 'purposes');
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

    /** @param array{family: ?string, material: ?string, polarity: ?string, diameter: ?int, purposes: list<string>} $attributes */
    private function isStrongTarget(array $attributes): bool
    {
        return $attributes['family'] === 'pipe'
            && in_array($attributes['material'], ['steel', 'hdpe'], true)
            && $attributes['diameter'] !== null
            && ($attributes['material'] !== 'steel' || $attributes['polarity'] !== null);
    }

    /**
     * @param  array{family: ?string, material: ?string, polarity: ?string, diameter: ?int, purposes: list<string>}  $target
     * @param  array{family: ?string, material: ?string, polarity: ?string, diameter: ?int, purposes: list<string>}  $candidate
     */
    private function hardAttributesMatch(array $target, array $candidate): bool
    {
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
}
