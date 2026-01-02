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
            $headers = [
                'Authorization' => 'Token ' . $this->config['api_key'],
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ];
            
            // DaData requires X-Secret header for authentication
            if (!empty($this->config['secret_key'])) {
                $headers['X-Secret'] = $this->config['secret_key'];
            }
            
            $response = Http::withHeaders($headers)
            ->timeout($this->config['timeout'] ?? 5)
            ->post($this->config['clean_url'], [
                [$address]
            ]);

            if (!$response->successful()) {
                Log::warning('DaData geocoding failed', [
                    'address' => $address,
                    'status' => $response->status(),
                    'response' => $response->body(),
                ]);
                return null;
            }

            $data = $response->json();
            
            if (empty($data) || !isset($data[0])) {
                return null;
            }

            $result = $data[0];

            // DaData возвращает координаты в geo_lat и geo_lon
            if (empty($result['geo_lat']) || empty($result['geo_lon'])) {
                return null;
            }

            $confidence = $this->calculateConfidence($result);

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
                confidence: $confidence,
                provider: $this->getName(),
                rawData: $result,
            );
        } catch (\Exception $e) {
            Log::error('DaData geocoding exception', [
                'address' => $address,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    public function reverse(float $latitude, float $longitude): ?GeocodeResult
    {
        // DaData doesn't have a direct reverse geocoding endpoint
        // We would need to use their geolocate service which is separate
        return null;
    }

    /**
     * Calculate confidence score based on DaData's QC codes
     */
    private function calculateConfidence(array $result): float
    {
        $qcGeo = $result['qc_geo'] ?? null;
        
        // QC коды DaData:
        // 0 - Точные координаты
        // 1 - Ближайший дом
        // 2 - Улица
        // 3 - Населенный пункт
        // 4 - Город
        // 5 - Координаты не определены
        
        return match ($qcGeo) {
            '0' => 1.0,  // Exact coordinates
            '1' => 0.9,  // Nearest house
            '2' => 0.7,  // Street level
            '3' => 0.5,  // Settlement level
            '4' => 0.3,  // City level
            default => 0.1, // Unknown or not determined
        };
    }
}

