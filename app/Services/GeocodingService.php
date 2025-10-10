<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class GeocodingService
{
    private string $provider;
    private int $timeout = 10;
    private int $retryTimes = 2;
    private int $cacheTtl = 86400;

    public function __construct(string $provider = 'nominatim')
    {
        $this->provider = $provider;
    }

    public function geocode(string $address): ?array
    {
        if (empty(trim($address))) {
            return null;
        }

        $cacheKey = "geocode:" . md5($address);

        return Cache::remember($cacheKey, $this->cacheTtl, function () use ($address) {
            return $this->fetchCoordinates($address);
        });
    }

    private function fetchCoordinates(string $address): ?array
    {
        try {
            return match ($this->provider) {
                'nominatim' => $this->geocodeWithNominatim($address),
                'dadata' => $this->geocodeWithDaData($address),
                default => $this->geocodeWithNominatim($address),
            };
        } catch (\Exception $e) {
            Log::warning("Geocoding failed for address: {$address}", [
                'error' => $e->getMessage(),
                'provider' => $this->provider,
            ]);
            return null;
        }
    }

    private function geocodeWithNominatim(string $address): ?array
    {
        $response = Http::timeout($this->timeout)
            ->retry($this->retryTimes, 1000)
            ->withHeaders([
                'User-Agent' => 'ProHelper/1.0 (Laravel Application)',
            ])
            ->get('https://nominatim.openstreetmap.org/search', [
                'q' => $address,
                'format' => 'json',
                'limit' => 1,
                'addressdetails' => 1,
            ]);

        if ($response->successful() && $response->json()) {
            $data = $response->json();
            if (!empty($data) && isset($data[0]['lat'], $data[0]['lon'])) {
                return [
                    'latitude' => (float) $data[0]['lat'],
                    'longitude' => (float) $data[0]['lon'],
                    'formatted_address' => $data[0]['display_name'] ?? $address,
                ];
            }
        }

        return null;
    }

    private function geocodeWithDaData(string $address): ?array
    {
        $apiKey = config('services.dadata.api_key');
        $secretKey = config('services.dadata.secret_key');

        if (!$apiKey || !$secretKey) {
            Log::warning('DaData API keys not configured');
            return $this->geocodeWithNominatim($address);
        }

        $response = Http::timeout($this->timeout)
            ->retry($this->retryTimes, 1000)
            ->withHeaders([
                'Authorization' => "Token {$apiKey}",
                'X-Secret' => $secretKey,
                'Content-Type' => 'application/json',
            ])
            ->post('https://cleaner.dadata.ru/api/v1/clean/address', [
                $address
            ]);

        if ($response->successful() && $response->json()) {
            $data = $response->json();
            if (!empty($data) && isset($data[0]['geo_lat'], $data[0]['geo_lon'])) {
                return [
                    'latitude' => (float) $data[0]['geo_lat'],
                    'longitude' => (float) $data[0]['geo_lon'],
                    'formatted_address' => $data[0]['result'] ?? $address,
                ];
            }
        }

        return null;
    }

    public function reverseGeocode(float $latitude, float $longitude): ?array
    {
        $cacheKey = "reverse_geocode:{$latitude}:{$longitude}";

        return Cache::remember($cacheKey, $this->cacheTtl, function () use ($latitude, $longitude) {
            return $this->fetchAddress($latitude, $longitude);
        });
    }

    private function fetchAddress(float $latitude, float $longitude): ?array
    {
        try {
            $response = Http::timeout($this->timeout)
                ->retry($this->retryTimes, 1000)
                ->withHeaders([
                    'User-Agent' => 'ProHelper/1.0 (Laravel Application)',
                ])
                ->get('https://nominatim.openstreetmap.org/reverse', [
                    'lat' => $latitude,
                    'lon' => $longitude,
                    'format' => 'json',
                    'addressdetails' => 1,
                ]);

            if ($response->successful() && $response->json()) {
                $data = $response->json();
                if (isset($data['display_name'])) {
                    return [
                        'address' => $data['display_name'],
                        'details' => $data['address'] ?? [],
                    ];
                }
            }
        } catch (\Exception $e) {
            Log::warning("Reverse geocoding failed for coordinates: {$latitude}, {$longitude}", [
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    public function calculateMapCenter(array $coordinates): array
    {
        if (empty($coordinates)) {
            return [
                'lat' => 55.7558,
                'lng' => 37.6173,
            ];
        }

        $count = count($coordinates);
        $sumLat = 0;
        $sumLng = 0;

        foreach ($coordinates as $coord) {
            $sumLat += $coord['lat'];
            $sumLng += $coord['lng'];
        }

        return [
            'lat' => round($sumLat / $count, 6),
            'lng' => round($sumLng / $count, 6),
        ];
    }

    public function calculateMapZoom(array $coordinates): int
    {
        if (count($coordinates) < 2) {
            return 12;
        }

        $latitudes = array_column($coordinates, 'lat');
        $longitudes = array_column($coordinates, 'lng');

        $latDiff = max($latitudes) - min($latitudes);
        $lngDiff = max($longitudes) - min($longitudes);

        $maxDiff = max($latDiff, $lngDiff);

        if ($maxDiff > 10) return 5;
        if ($maxDiff > 5) return 6;
        if ($maxDiff > 2) return 7;
        if ($maxDiff > 1) return 8;
        if ($maxDiff > 0.5) return 9;
        if ($maxDiff > 0.25) return 10;
        if ($maxDiff > 0.1) return 11;
        if ($maxDiff > 0.05) return 12;
        if ($maxDiff > 0.025) return 13;
        if ($maxDiff > 0.01) return 14;

        return 15;
    }
}

