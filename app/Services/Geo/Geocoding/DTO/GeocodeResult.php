<?php

namespace App\Services\Geo\Geocoding\DTO;

class GeocodeResult
{
    public function __construct(
        public readonly float $latitude,
        public readonly float $longitude,
        public readonly string $formattedAddress,
        public readonly ?string $country = null,
        public readonly ?string $region = null,
        public readonly ?string $city = null,
        public readonly ?string $district = null,
        public readonly ?string $street = null,
        public readonly ?string $house = null,
        public readonly ?string $postalCode = null,
        public readonly float $confidence = 1.0,
        public readonly ?string $provider = null,
        public readonly ?array $rawData = null,
    ) {}

    /**
     * Convert to array
     */
    public function toArray(): array
    {
        return [
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'formatted_address' => $this->formattedAddress,
            'country' => $this->country,
            'region' => $this->region,
            'city' => $this->city,
            'district' => $this->district,
            'street' => $this->street,
            'house' => $this->house,
            'postal_code' => $this->postalCode,
            'confidence' => $this->confidence,
            'provider' => $this->provider,
        ];
    }

    /**
     * Check if result meets minimum confidence threshold
     */
    public function meetsConfidenceThreshold(float $threshold = 0.5): bool
    {
        return $this->confidence >= $threshold;
    }
}

