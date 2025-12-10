<?php

namespace App\Services\Geo\Geocoding\Contracts;

use App\Services\Geo\Geocoding\DTO\GeocodeResult;

interface GeocodeProviderInterface
{
    /**
     * Get provider name
     */
    public function getName(): string;

    /**
     * Get provider priority (lower is higher priority)
     */
    public function getPriority(): int;

    /**
     * Check if provider is enabled
     */
    public function isEnabled(): bool;

    /**
     * Geocode an address string
     *
     * @param string $address
     * @return GeocodeResult|null
     */
    public function geocode(string $address): ?GeocodeResult;

    /**
     * Reverse geocode coordinates to address
     *
     * @param float $latitude
     * @param float $longitude
     * @return GeocodeResult|null
     */
    public function reverse(float $latitude, float $longitude): ?GeocodeResult;
}

