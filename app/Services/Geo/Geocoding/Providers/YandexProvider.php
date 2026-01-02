<?php

namespace App\Services\Geo\Geocoding\Providers;

use App\Services\Geo\Geocoding\Contracts\GeocodeProviderInterface;
use App\Services\Geo\Geocoding\DTO\GeocodeResult;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class YandexProvider implements GeocodeProviderInterface
{
    private array $config;

    public function __construct()
    {
        $this->config = config('geocoding.providers.yandex');
    }

    public function getName(): string
    {
        return 'yandex';
    }

    public function getPriority(): int
    {
        return $this->config['priority'] ?? 2;
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
            $response = Http::timeout($this->config['timeout'] ?? 5)
                ->get($this->config['url'], [
                    'apikey' => $this->config['api_key'],
                    'geocode' => $address,
                    'format' => 'json',
                    'results' => 1,
                ]);

            if (!$response->successful()) {
                Log::warning('Yandex geocoding failed', [
                    'address' => $address,
                    'status' => $response->status(),
                    'response' => $response->body(),
                    'has_api_key' => !empty($this->config['api_key']),
                ]);
                return null;
            }

            $data = $response->json();
            
            $geoObject = $data['response']['GeoObjectCollection']['featureMember'][0]['GeoObject'] ?? null;
            
            if (!$geoObject) {
                $foundCount = $data['response']['GeoObjectCollection']['metaDataProperty']['GeocoderResponseMetaData']['found'] ?? 0;
                Log::info('Yandex returned empty result', [
                    'address' => $address,
                    'found_count' => $foundCount,
                ]);
                return null;
            }

            // Parse coordinates (format: "longitude latitude")
            $pos = explode(' ', $geoObject['Point']['pos']);
            $longitude = (float) $pos[0];
            $latitude = (float) $pos[1];

            $components = $this->parseComponents($geoObject['metaDataProperty']['GeocoderMetaData']['Address']['Components'] ?? []);
            
            $confidence = $this->calculateConfidence($geoObject);

            return new GeocodeResult(
                latitude: $latitude,
                longitude: $longitude,
                formattedAddress: $geoObject['metaDataProperty']['GeocoderMetaData']['text'] ?? $address,
                country: $components['country'] ?? null,
                region: $components['region'] ?? null,
                city: $components['locality'] ?? null,
                district: $components['district'] ?? null,
                street: $components['street'] ?? null,
                house: $components['house'] ?? null,
                postalCode: $geoObject['metaDataProperty']['GeocoderMetaData']['Address']['postal_code'] ?? null,
                confidence: $confidence,
                provider: $this->getName(),
                rawData: $geoObject,
            );
        } catch (\Exception $e) {
            Log::error('Yandex geocoding exception', [
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

        try {
            $response = Http::timeout($this->config['timeout'] ?? 5)
                ->get($this->config['url'], [
                    'apikey' => $this->config['api_key'],
                    'geocode' => "{$longitude},{$latitude}",
                    'format' => 'json',
                    'results' => 1,
                ]);

            if (!$response->successful()) {
                return null;
            }

            $data = $response->json();
            $geoObject = $data['response']['GeoObjectCollection']['featureMember'][0]['GeoObject'] ?? null;
            
            if (!$geoObject) {
                return null;
            }

            $components = $this->parseComponents($geoObject['metaDataProperty']['GeocoderMetaData']['Address']['Components'] ?? []);

            return new GeocodeResult(
                latitude: $latitude,
                longitude: $longitude,
                formattedAddress: $geoObject['metaDataProperty']['GeocoderMetaData']['text'] ?? '',
                country: $components['country'] ?? null,
                region: $components['region'] ?? null,
                city: $components['locality'] ?? null,
                district: $components['district'] ?? null,
                street: $components['street'] ?? null,
                house: $components['house'] ?? null,
                postalCode: $geoObject['metaDataProperty']['GeocoderMetaData']['Address']['postal_code'] ?? null,
                confidence: 1.0,
                provider: $this->getName(),
                rawData: $geoObject,
            );
        } catch (\Exception $e) {
            Log::error('Yandex reverse geocoding exception', [
                'latitude' => $latitude,
                'longitude' => $longitude,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    private function parseComponents(array $components): array
    {
        $parsed = [];
        
        foreach ($components as $component) {
            $kind = $component['kind'] ?? null;
            $name = $component['name'] ?? null;
            
            if (!$kind || !$name) {
                continue;
            }
            
            match ($kind) {
                'country' => $parsed['country'] = $name,
                'province' => $parsed['region'] = $name,
                'locality' => $parsed['locality'] = $name,
                'district' => $parsed['district'] = $name,
                'street' => $parsed['street'] = $name,
                'house' => $parsed['house'] = $name,
                default => null,
            };
        }
        
        return $parsed;
    }

    private function calculateConfidence(array $geoObject): float
    {
        $precision = $geoObject['metaDataProperty']['GeocoderMetaData']['precision'] ?? 'other';
        
        return match ($precision) {
            'exact' => 1.0,
            'number', 'near' => 0.9,
            'range' => 0.8,
            'street' => 0.7,
            'other' => 0.5,
            default => 0.3,
        };
    }
}

