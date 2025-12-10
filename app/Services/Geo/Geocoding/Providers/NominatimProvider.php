<?php

namespace App\Services\Geo\Geocoding\Providers;

use App\Services\Geo\Geocoding\Contracts\GeocodeProviderInterface;
use App\Services\Geo\Geocoding\DTO\GeocodeResult;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class NominatimProvider implements GeocodeProviderInterface
{
    private array $config;
    private string $rateLimitKey = 'nominatim_last_request';

    public function __construct()
    {
        $this->config = config('geocoding.providers.nominatim');
    }

    public function getName(): string
    {
        return 'nominatim';
    }

    public function getPriority(): int
    {
        return $this->config['priority'] ?? 3;
    }

    public function isEnabled(): bool
    {
        return $this->config['enabled'] ?? true;
    }

    public function geocode(string $address): ?GeocodeResult
    {
        if (!$this->isEnabled()) {
            return null;
        }

        // Respect rate limit (max 1 request per second)
        $this->respectRateLimit();

        try {
            $response = Http::withHeaders([
                'User-Agent' => $this->config['user_agent'] ?? 'ProHelper',
            ])
            ->timeout($this->config['timeout'] ?? 10)
            ->get($this->config['url'], [
                'q' => $address,
                'format' => 'json',
                'addressdetails' => 1,
                'limit' => 1,
            ]);

            if (!$response->successful()) {
                Log::warning('Nominatim geocoding failed', [
                    'address' => $address,
                    'status' => $response->status(),
                ]);
                return null;
            }

            $data = $response->json();
            
            if (empty($data) || !isset($data[0])) {
                return null;
            }

            $result = $data[0];
            $addressParts = $result['address'] ?? [];

            return new GeocodeResult(
                latitude: (float) $result['lat'],
                longitude: (float) $result['lon'],
                formattedAddress: $result['display_name'] ?? $address,
                country: $addressParts['country'] ?? null,
                region: $addressParts['state'] ?? $addressParts['region'] ?? null,
                city: $addressParts['city'] ?? $addressParts['town'] ?? $addressParts['village'] ?? null,
                district: $addressParts['suburb'] ?? $addressParts['district'] ?? null,
                street: $addressParts['road'] ?? null,
                house: $addressParts['house_number'] ?? null,
                postalCode: $addressParts['postcode'] ?? null,
                confidence: $this->calculateConfidence($result),
                provider: $this->getName(),
                rawData: $result,
            );
        } catch (\Exception $e) {
            Log::error('Nominatim geocoding exception', [
                'address' => $address,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    public function reverse(float $latitude, float $longitude): ?GeocodeResult
    {
        if (!$this->isEnabled()) {
            return null;
        }

        $this->respectRateLimit();

        try {
            $response = Http::withHeaders([
                'User-Agent' => $this->config['user_agent'] ?? 'ProHelper',
            ])
            ->timeout($this->config['timeout'] ?? 10)
            ->get('https://nominatim.openstreetmap.org/reverse', [
                'lat' => $latitude,
                'lon' => $longitude,
                'format' => 'json',
                'addressdetails' => 1,
            ]);

            if (!$response->successful()) {
                return null;
            }

            $result = $response->json();
            $addressParts = $result['address'] ?? [];

            return new GeocodeResult(
                latitude: $latitude,
                longitude: $longitude,
                formattedAddress: $result['display_name'] ?? '',
                country: $addressParts['country'] ?? null,
                region: $addressParts['state'] ?? $addressParts['region'] ?? null,
                city: $addressParts['city'] ?? $addressParts['town'] ?? $addressParts['village'] ?? null,
                district: $addressParts['suburb'] ?? $addressParts['district'] ?? null,
                street: $addressParts['road'] ?? null,
                house: $addressParts['house_number'] ?? null,
                postalCode: $addressParts['postcode'] ?? null,
                confidence: 1.0,
                provider: $this->getName(),
                rawData: $result,
            );
        } catch (\Exception $e) {
            Log::error('Nominatim reverse geocoding exception', [
                'latitude' => $latitude,
                'longitude' => $longitude,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    private function respectRateLimit(): void
    {
        $lastRequest = Cache::get($this->rateLimitKey, 0);
        $now = microtime(true);
        $elapsed = $now - $lastRequest;
        
        $rateLimit = $this->config['rate_limit'] ?? 1; // requests per second
        $minInterval = 1.0 / $rateLimit;
        
        if ($elapsed < $minInterval) {
            $sleepTime = (int) (($minInterval - $elapsed) * 1000000);
            usleep($sleepTime);
        }
        
        Cache::put($this->rateLimitKey, microtime(true), 60);
    }

    private function calculateConfidence(array $result): float
    {
        $type = $result['type'] ?? 'unknown';
        $importance = $result['importance'] ?? 0;
        
        // Base confidence on result type
        $baseConfidence = match ($type) {
            'house' => 1.0,
            'building' => 0.95,
            'residential' => 0.9,
            'road', 'street' => 0.7,
            'suburb', 'neighbourhood' => 0.6,
            'city', 'town', 'village' => 0.5,
            default => 0.3,
        };
        
        // Adjust by importance (0.0 - 1.0)
        return min(1.0, $baseConfidence * (1 + $importance));
    }
}

