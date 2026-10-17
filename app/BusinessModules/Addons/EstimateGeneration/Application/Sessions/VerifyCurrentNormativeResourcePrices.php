<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Sessions;

use App\BusinessModules\Addons\EstimateGeneration\Normatives\Models\EstimateResourcePrice;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;
use App\BusinessModules\Addons\EstimateGeneration\Pricing\MissingRegionalPrice;
use App\BusinessModules\Addons\EstimateGeneration\Pricing\ResolveRegionalPrice;
use App\BusinessModules\Addons\EstimateGeneration\Pricing\ResolveUnitConversion;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

final class VerifyCurrentNormativeResourcePrices
{
    public function verify(array $items, array $regional): bool
    {
        $ids = $conversions = [];
        foreach ($items as $item) {
            foreach ($this->resources($item) as $resource) {
                $id = (int) ($resource['price_id'] ?? $resource['normative_ref']['price_id'] ?? 0);
                if ($id > 0) {
                    $ids[$id] = $id;
                }
                [$from, $to, $version] = $this->conversionIdentity($resource, $regional);
                if ($from !== $to) {
                    $conversions[CanonicalPipelineJson::encode([$from, $to, $version])] = [$from, $to, $version];
                }
            }
        }
        $prices = [];
        foreach (array_chunk(array_values($ids), 1000) as $chunk) {
            foreach (EstimateResourcePrice::query()->with(['datasetVersion', 'regionalPriceVersion'])->whereIn('id', $chunk)->get() as $price) {
                $prices[(int) $price->id] = $price;
            }
        }
        $conversionRows = [];
        foreach (array_chunk(array_values($conversions), 500) as $chunk) {
            $rows = DB::table('estimate_generation_unit_conversions')->where('is_active', true)->where(function ($query) use ($chunk): void {
                foreach ($chunk as [$from, $to, $version]) {
                    $query->orWhere(fn ($query) => $query->where('from_unit', $from)->where('to_unit', $to)->where('version', $version));
                }
            })->get();
            foreach ($rows as $row) {
                $key = CanonicalPipelineJson::encode([$row->from_unit, $row->to_unit, (int) $row->version]);
                $conversionRows[$key][] = (array) $row;
            }
        }
        $resolver = new ResolveRegionalPrice(static fn (int $id): ?EstimateResourcePrice => $prices[$id] ?? null);
        $units = new ResolveUnitConversion(static fn (string $from, string $to, int $version): array => $conversionRows[CanonicalPipelineJson::encode([$from, $to, $version])] ?? []);
        try {
            foreach ($items as $item) {
                $resources = $this->resources($item);
                $evidence = $item['price_snapshot']['coefficients']['resource_evidence'] ?? null;
                if (! is_array($evidence) || ! array_is_list($evidence) || $resources === [] || count($resources) !== count($evidence)) {
                    return false;
                }
                foreach ($resources as $index => $resource) {
                    $conversion = $units->handle(...$this->conversionIdentity($resource, $regional));
                    if ($conversion !== null) {
                        $reference = $resource['normative_ref'] ?? [];
                        if (($reference['unit_conversion_id'] ?? null) !== $conversion->id
                            || ($reference['unit_conversion_fingerprint'] ?? null) !== $conversion->fingerprint
                            || ! BigDecimal::of($reference['unit_conversion_factor'] ?? '-1')->isEqualTo($conversion->factor)) {
                            return false;
                        }
                    }
                    $current = $resolver->handle($resource, $regional)->toArray();
                    $expected = $evidence[$index];
                    if (! is_array($expected)) {
                        return false;
                    }
                    unset($current['captured_at'], $expected['captured_at']);
                    if (CanonicalPipelineJson::encode($current) !== CanonicalPipelineJson::encode($expected)) {
                        return false;
                    }
                }
            }
        } catch (MissingRegionalPrice|\Brick\Math\Exception\MathException) {
            return false;
        }

        return true;
    }

    private function resources(array $item): array
    {
        return array_merge(...array_map(static fn (string $group): array => $item[$group] ?? [],
            ['materials', 'labor', 'machinery', 'other_resources']));
    }

    private function conversionIdentity(array $resource, array $regional): array
    {
        $from = trim((string) ($resource['unit'] ?? ''));

        return [$from, trim((string) ($resource['price_unit'] ?? $resource['pricing']['unit'] ?? $from)),
            (int) ($resource['unit_conversion_version'] ?? $regional['unit_conversion_version'] ?? 1)];
    }
}
