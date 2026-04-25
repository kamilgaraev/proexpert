<?php

namespace App\Services\Geo\Geocoding\Providers;

use App\Services\Geo\Geocoding\Contracts\GeocodeProviderInterface;
use App\Services\Geo\Geocoding\DTO\GeocodeResult;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class DaDataProvider implements GeocodeProviderInterface
{
    private array $config;

    public function __construct()
    {
        $this->config = config('geocoding.providers.dadata');
    }

    public function getName(): string
    {
        return 'dadata';
    }

    public function getPriority(): int
    {
        return $this->config['priority'] ?? 1;
    }

    public function isEnabled(): bool
    {
        return ($this->config['enabled'] ?? false)
            && !empty($this->config['api_key']);
    }

    public function geocode(string $address): ?GeocodeResult
    {
        if (!$this->isEnabled()) {
            return null;
        }

        try {
            return $this->geocodeWithCleanApi($address)
                ?? $this->geocodeWithSuggestApi($address);
        } catch (\Exception $e) {
            Log::error('DaData geocoding exception', [
                'address' => $address,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function geocodeWithCleanApi(string $address): ?GeocodeResult
    {
        if (empty($this->config['clean_url']) || empty($this->config['secret_key'])) {
            return null;
        }

        $headers = $this->baseHeaders();
        $headers['X-Secret'] = $this->config['secret_key'];

        $response = Http::withHeaders($headers)
            ->timeout($this->config['timeout'] ?? 5)
            ->asJson()
            ->post($this->config['clean_url'], [$address]);

        if (!$response->successful()) {
            Log::warning('DaData clean geocoding failed', [
                'address' => $address,
                'status' => $response->status(),
                'response' => $response->body(),
            ]);

            return null;
        }

        $data = $response->json();

        if (empty($data) || !isset($data[0]) || !is_array($data[0])) {
            Log::info('DaData clean returned empty result', [
                'address' => $address,
                'data' => $data,
            ]);

            return null;
        }

        return $this->makeResult($data[0], $address);
    }

    private function geocodeWithSuggestApi(string $address): ?GeocodeResult
    {
        if (empty($this->config['url'])) {
            return null;
        }

        $response = Http::withHeaders($this->baseHeaders())
            ->timeout($this->config['timeout'] ?? 5)
            ->asJson()
            ->post($this->config['url'], [
                'query' => $address,
                'count' => 1,
            ]);

        if (!$response->successful()) {
            Log::warning('DaData suggest geocoding failed', [
                'address' => $address,
                'status' => $response->status(),
                'response' => $response->body(),
            ]);

            return null;
        }

        $suggestion = $response->json('suggestions.0');

        if (!is_array($suggestion)) {
            Log::info('DaData suggest returned empty result', [
                'address' => $address,
            ]);

            return null;
        }

        $data = $suggestion['data'] ?? null;
        if (!is_array($data)) {
            return null;
        }

        $data['result'] = $suggestion['unrestricted_value'] ?? $suggestion['value'] ?? $address;

        return $this->makeResult($data, $address);
    }

    private function baseHeaders(): array
    {
        return [
            'Authorization' => 'Token ' . $this->config['api_key'],
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];
    }

    private function makeResult(array $result, string $address): ?GeocodeResult
    {
        if (empty($result['geo_lat']) || empty($result['geo_lon'])) {
            return null;
        }

        return new GeocodeResult(
            latitude: (float) $result['geo_lat'],
            longitude: (float) $result['geo_lon'],
            formattedAddress: $result['result'] ?? $address,
            country: $result['country'] ?? null,
            region: $result['region_with_type'] ?? $result['region'] ?? null,
            city: $result['city'] ?? $result['settlement'] ?? null,
            district: $result['city_district'] ?? null,
            street: $result['street_with_type'] ?? $result['street'] ?? null,
            house: $result['house'] ?? null,
            postalCode: $result['postal_code'] ?? null,
            confidence: $this->calculateConfidence($result),
            provider: $this->getName(),
            rawData: $result,
        );
    }

    public function reverse(float $latitude, float $longitude): ?GeocodeResult
    {
        return null;
    }

    private function calculateConfidence(array $result): float
    {
        $qcGeo = $result['qc_geo'] ?? null;

        return match ((string) $qcGeo) {
            '0' => 1.0,
            '1' => 0.9,
            '2' => 0.7,
            '3' => 0.5,
            '4' => 0.3,
            default => 0.1,
        };
    }
}
