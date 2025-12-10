<?php

namespace App\Services\Geo;

class GeoUtils
{
    /**
     * Convert Web Mercator tile coordinates to lat/lng bounds
     * 
     * @param int $x Tile X coordinate
     * @param int $y Tile Y coordinate
     * @param int $zoom Zoom level
     * @return array ['north' => float, 'south' => float, 'east' => float, 'west' => float]
     */
    public static function tileToBounds(int $x, int $y, int $zoom): array
    {
        $n = pow(2, $zoom);
        
        $west = ($x / $n) * 360.0 - 180.0;
        $east = (($x + 1) / $n) * 360.0 - 180.0;
        
        $north = self::tile2lat($y, $zoom);
        $south = self::tile2lat($y + 1, $zoom);
        
        return [
            'north' => $north,
            'south' => $south,
            'east' => $east,
            'west' => $west,
        ];
    }

    /**
     * Convert tile Y coordinate to latitude
     */
    private static function tile2lat(int $y, int $zoom): float
    {
        $n = pow(2, $zoom);
        $latRad = atan(sinh(pi() * (1 - 2 * $y / $n)));
        return rad2deg($latRad);
    }

    /**
     * Convert latitude to tile Y coordinate
     */
    public static function lat2tile(float $lat, int $zoom): int
    {
        $latRad = deg2rad($lat);
        $n = pow(2, $zoom);
        return (int) floor($n * (1 - log(tan($latRad) + 1 / cos($latRad)) / pi()) / 2);
    }

    /**
     * Convert longitude to tile X coordinate
     */
    public static function lon2tile(float $lon, int $zoom): int
    {
        $n = pow(2, $zoom);
        return (int) floor(($lon + 180.0) / 360.0 * $n);
    }

    /**
     * Calculate distance between two points in meters (Haversine formula)
     * 
     * @param float $lat1 Latitude of point 1
     * @param float $lon1 Longitude of point 1
     * @param float $lat2 Latitude of point 2
     * @param float $lon2 Longitude of point 2
     * @return float Distance in meters
     */
    public static function distance(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadius = 6371000; // meters
        
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        
        $a = sin($dLat / 2) * sin($dLat / 2) +
            cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
            sin($dLon / 2) * sin($dLon / 2);
        
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        
        return $earthRadius * $c;
    }

    /**
     * Calculate distance in pixels at given zoom level
     * Approximate conversion: meters to pixels at equator
     * 
     * @param float $meters Distance in meters
     * @param int $zoom Zoom level
     * @param float $latitude Latitude (for more accurate calculation)
     * @return float Distance in pixels
     */
    public static function metersToPixels(float $meters, int $zoom, float $latitude = 0): float
    {
        // Meters per pixel at zoom level 0
        $metersPerPixel = 156543.03392 * cos(deg2rad($latitude)) / pow(2, $zoom);
        return $meters / $metersPerPixel;
    }

    /**
     * Calculate radius in meters for clustering at given zoom level
     * Uses adaptive radius: larger at lower zoom, smaller at higher zoom
     * 
     * @param int $zoom Zoom level
     * @param int $baseRadius Base radius in pixels (default: 50px)
     * @return float Radius in meters
     */
    public static function getClusterRadius(int $zoom, int $baseRadius = 50): float
    {
        // At zoom 0, ~40,000 km per tile
        // At zoom 10, ~40 km per tile
        // At zoom 20, ~40 m per tile
        return 40075000 / pow(2, $zoom) * ($baseRadius / 256);
    }

    /**
     * Check if point is within bounds
     * 
     * @param float $lat
     * @param float $lon
     * @param array $bounds ['north', 'south', 'east', 'west']
     * @return bool
     */
    public static function isPointInBounds(float $lat, float $lon, array $bounds): bool
    {
        return $lat >= $bounds['south'] 
            && $lat <= $bounds['north']
            && $lon >= $bounds['west']
            && $lon <= $bounds['east'];
    }

    /**
     * Clamp latitude to valid range
     */
    public static function clampLat(float $lat): float
    {
        return max(-85.0511, min(85.0511, $lat));
    }

    /**
     * Normalize longitude to -180..180 range
     */
    public static function normalizeLon(float $lon): float
    {
        while ($lon > 180) {
            $lon -= 360;
        }
        while ($lon < -180) {
            $lon += 360;
        }
        return $lon;
    }

    /**
     * Create GeoJSON Point feature
     */
    public static function createPointFeature(float $lat, float $lon, array $properties = []): array
    {
        return [
            'type' => 'Feature',
            'geometry' => [
                'type' => 'Point',
                'coordinates' => [$lon, $lat], // GeoJSON uses [lng, lat] order
            ],
            'properties' => $properties,
        ];
    }

    /**
     * Create GeoJSON FeatureCollection
     */
    public static function createFeatureCollection(array $features): array
    {
        return [
            'type' => 'FeatureCollection',
            'features' => $features,
        ];
    }
}

