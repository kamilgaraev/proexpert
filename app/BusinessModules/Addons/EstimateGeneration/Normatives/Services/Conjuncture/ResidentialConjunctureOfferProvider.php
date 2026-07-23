<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\Conjuncture;

use DateTimeImmutable;
use DateTimeZone;

final class ResidentialConjunctureOfferProvider
{
    public const MAX_OFFER_AGE_DAYS = 45;

    private const SCHEMA_VERSION = 'project_material_conjuncture:v1';

    /** @var array<string, mixed> */
    private array $config;

    private DateTimeImmutable $asOf;

    /** @param array<string, mixed>|null $config */
    public function __construct(?array $config = null, ?DateTimeImmutable $asOf = null)
    {
        $this->config = $config ?? (array) config('estimate_generation_project_material_conjuncture', []);
        $this->asOf = $asOf ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    /**
     * @return array{
     *   schema_version:string,analysis_key:string,resource_code:string,resource_name:string,unit:string,
     *   currency:string,region_code:string,observed_at:string,median_price:float,eligible_offers:list<array<string,mixed>>,
     *   rejected_offers:list<array<string,mixed>>,eligibility:array<string,mixed>
     * }|null
     */
    public function resolve(string $analysisKey, string $resourceCode, string $unit, string $regionCode): ?array
    {
        $analyses = is_array($this->config['analyses'] ?? null) ? $this->config['analyses'] : [];
        $definition = is_array($analyses[$analysisKey] ?? null) ? $analyses[$analysisKey] : null;

        if ($definition === null
            || trim((string) ($definition['resource_code'] ?? '')) !== $resourceCode
            || trim((string) ($definition['unit'] ?? '')) !== $unit) {
            return null;
        }

        $requiredMarkers = $this->markers($definition['required_name_markers'] ?? []);
        $forbiddenMarkers = $this->markers($definition['forbidden_name_markers'] ?? []);
        $maxAgeDays = max(1, (int) ($this->config['max_offer_age_days'] ?? self::MAX_OFFER_AGE_DAYS));
        $offers = is_array($definition['offers'] ?? null) ? $definition['offers'] : [];
        $eligible = [];
        $rejected = [];

        foreach ($offers as $offer) {
            if (! is_array($offer)) {
                continue;
            }

            $reason = $this->rejectionReason(
                $offer,
                $regionCode,
                $unit,
                $requiredMarkers,
                $forbiddenMarkers,
                $maxAgeDays,
            );
            $normalized = $this->normalizedOffer($offer);

            if ($reason === null) {
                $eligible[] = $normalized;
            } else {
                $rejected[] = [...$normalized, 'rejection_reason' => $reason];
            }
        }

        $hosts = array_values(array_unique(array_map(
            static fn (array $offer): string => (string) parse_url((string) $offer['url'], PHP_URL_HOST),
            $eligible,
        )));
        if (count($eligible) < 3 || count(array_filter($hosts)) < 3) {
            return null;
        }

        usort($eligible, static fn (array $left, array $right): int => $left['price'] <=> $right['price']);
        $prices = array_column($eligible, 'price');
        $middle = intdiv(count($prices), 2);
        $median = count($prices) % 2 === 1
            ? (float) $prices[$middle]
            : round(((float) $prices[$middle - 1] + (float) $prices[$middle]) / 2, 2);
        $observedAt = max(array_column($eligible, 'observed_at'));

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'analysis_key' => $analysisKey,
            'resource_code' => $resourceCode,
            'resource_name' => trim((string) ($definition['resource_name'] ?? $resourceCode)),
            'unit' => $unit,
            'currency' => 'RUB',
            'region_code' => $regionCode,
            'observed_at' => $observedAt,
            'median_price' => $median,
            'eligible_offers' => $eligible,
            'rejected_offers' => $rejected,
            'eligibility' => [
                'minimum_offers' => 3,
                'minimum_distinct_hosts' => 3,
                'max_offer_age_days' => $maxAgeDays,
                'required_name_markers' => $requiredMarkers,
                'forbidden_name_markers' => $forbiddenMarkers,
            ],
        ];
    }

    /** @param mixed $markers @return list<string> */
    private function markers(mixed $markers): array
    {
        return array_values(array_filter(array_map(
            static fn (mixed $marker): string => mb_strtolower(trim((string) $marker)),
            is_array($markers) ? $markers : [],
        )));
    }

    /**
     * @param  array<string, mixed>  $offer
     * @param  list<string>  $requiredMarkers
     * @param  list<string>  $forbiddenMarkers
     */
    private function rejectionReason(
        array $offer,
        string $regionCode,
        string $unit,
        array $requiredMarkers,
        array $forbiddenMarkers,
        int $maxAgeDays,
    ): ?string {
        $url = trim((string) ($offer['url'] ?? ''));
        if (filter_var($url, FILTER_VALIDATE_URL) === false || parse_url($url, PHP_URL_SCHEME) !== 'https') {
            return 'invalid_url';
        }
        if (trim((string) ($offer['region_code'] ?? '')) !== $regionCode) {
            return 'region_mismatch';
        }
        if (trim((string) ($offer['unit'] ?? '')) !== $unit || ($offer['currency'] ?? null) !== 'RUB') {
            return 'unit_or_currency_mismatch';
        }
        if (! is_numeric($offer['price'] ?? null) || (float) $offer['price'] <= 0) {
            return 'price_invalid';
        }

        $observedAt = DateTimeImmutable::createFromFormat('!Y-m-d', trim((string) ($offer['observed_at'] ?? '')));
        if (! $observedAt instanceof DateTimeImmutable || $observedAt > $this->asOf
            || $observedAt < $this->asOf->modify('-'.$maxAgeDays.' days')->setTime(0, 0)) {
            return 'observation_stale_or_invalid';
        }

        $name = mb_strtolower(str_replace('ё', 'е', trim((string) ($offer['product_name'] ?? ''))));
        if ($name === '' || array_filter(
            $requiredMarkers,
            static fn (string $marker): bool => ! str_contains($name, str_replace('ё', 'е', $marker)),
        ) !== []) {
            return 'product_identity_missing';
        }
        if (array_filter(
            $forbiddenMarkers,
            static fn (string $marker): bool => str_contains($name, str_replace('ё', 'е', $marker)),
        ) !== []) {
            return 'forbidden_product_identity';
        }

        return null;
    }

    /** @param array<string, mixed> $offer @return array<string, mixed> */
    private function normalizedOffer(array $offer): array
    {
        return [
            'supplier' => trim((string) ($offer['supplier'] ?? '')),
            'url' => trim((string) ($offer['url'] ?? '')),
            'region_code' => trim((string) ($offer['region_code'] ?? '')),
            'observed_at' => trim((string) ($offer['observed_at'] ?? '')),
            'product_name' => trim((string) ($offer['product_name'] ?? '')),
            'unit' => trim((string) ($offer['unit'] ?? '')),
            'currency' => trim((string) ($offer['currency'] ?? '')),
            'price' => is_numeric($offer['price'] ?? null) ? (float) $offer['price'] : null,
        ];
    }
}
