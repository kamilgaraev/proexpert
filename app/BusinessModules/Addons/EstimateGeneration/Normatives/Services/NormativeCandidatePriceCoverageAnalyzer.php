<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Normatives\Services;

final class NormativeCandidatePriceCoverageAnalyzer
{
    /**
     * @param  array<int, array{estimate_norm_id: int, resource_code: ?string, unit: ?string}>  $resources
     * @param  array<int, array{resource_code: string, unit: ?string}>  $prices
     * @param  array<int, array{from_unit: string, to_unit: string}>  $conversions
     * @return array<int, array{
     *     positive_resources: int,
     *     priced_resources: int,
     *     unpriced_resources: int,
     *     reasons: array{missing_resource_code: int, absent_from_selected_sources: int, unit_mismatch: int}
     * }>
     */
    public function analyze(array $resources, array $prices, array $conversions): array
    {
        $pricesByCode = [];
        foreach ($prices as $price) {
            $pricesByCode[$price['resource_code']][] = $price['unit'];
        }
        $conversionPairs = [];
        foreach ($conversions as $conversion) {
            $conversionPairs[$conversion['from_unit']."\0".$conversion['to_unit']] = true;
        }

        $coverage = [];
        foreach ($resources as $resource) {
            $normId = $resource['estimate_norm_id'];
            $coverage[$normId] ??= [
                'positive_resources' => 0,
                'priced_resources' => 0,
                'unpriced_resources' => 0,
                'reasons' => [
                    'missing_resource_code' => 0,
                    'absent_from_selected_sources' => 0,
                    'unit_mismatch' => 0,
                ],
            ];
            $coverage[$normId]['positive_resources']++;

            $code = trim((string) $resource['resource_code']);
            if ($code === '') {
                $coverage[$normId]['unpriced_resources']++;
                $coverage[$normId]['reasons']['missing_resource_code']++;

                continue;
            }

            $candidateUnits = $pricesByCode[$code] ?? [];
            if ($candidateUnits === []) {
                $coverage[$normId]['unpriced_resources']++;
                $coverage[$normId]['reasons']['absent_from_selected_sources']++;

                continue;
            }

            $resourceUnit = $resource['unit'];
            $compatible = false;
            foreach ($candidateUnits as $priceUnit) {
                if ($this->unitsCompatible($resourceUnit, $priceUnit, $conversionPairs)) {
                    $compatible = true;

                    break;
                }
            }
            if ($compatible) {
                $coverage[$normId]['priced_resources']++;

                continue;
            }

            $coverage[$normId]['unpriced_resources']++;
            $coverage[$normId]['reasons']['unit_mismatch']++;
        }

        return $coverage;
    }

    /** @param array<string, true> $conversionPairs */
    private function unitsCompatible(?string $resourceUnit, ?string $priceUnit, array $conversionPairs): bool
    {
        if ($resourceUnit === $priceUnit || $this->normalizeUnit($resourceUnit) === $this->normalizeUnit($priceUnit)) {
            return true;
        }

        return isset($conversionPairs[(string) $resourceUnit."\0".(string) $priceUnit]);
    }

    private function normalizeUnit(?string $unit): string
    {
        return preg_replace('/[\s.,\-]+/u', '', mb_strtolower(trim((string) $unit))) ?? '';
    }
}
