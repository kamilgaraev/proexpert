<?php

namespace App\Services\Geo;

class ClusterService
{
    /**
     * Cluster features using radius-based algorithm
     * 
     * @param array $features GeoJSON features
     * @param int $zoom Zoom level
     * @param int $radiusPixels Cluster radius in pixels (default: 50px)
     * @return array Clustered features
     */
    public function cluster(array $features, int $zoom, int $radiusPixels = 50): array
    {
        if (empty($features)) {
            return [];
        }

        // Calculate radius in meters for this zoom level
        $radiusMeters = GeoUtils::getClusterRadius($zoom, $radiusPixels);

        // Initialize clusters
        $clusters = [];
        $processed = [];

        foreach ($features as $index => $feature) {
            if (isset($processed[$index])) {
                continue;
            }

            $coords = $feature['geometry']['coordinates'];
            $lon = $coords[0];
            $lat = $coords[1];

            // Find all nearby features within radius
            $nearbyFeatures = [$feature];
            $nearbyIndices = [$index];

            foreach ($features as $compareIndex => $compareFeature) {
                if ($compareIndex === $index || isset($processed[$compareIndex])) {
                    continue;
                }

                $compareCoords = $compareFeature['geometry']['coordinates'];
                $compareLon = $compareCoords[0];
                $compareLat = $compareCoords[1];

                $distance = GeoUtils::distance($lat, $lon, $compareLat, $compareLon);

                if ($distance <= $radiusMeters) {
                    $nearbyFeatures[] = $compareFeature;
                    $nearbyIndices[] = $compareIndex;
                }
            }

            // Mark as processed
            foreach ($nearbyIndices as $idx) {
                $processed[$idx] = true;
            }

            // Create cluster or single feature
            if (count($nearbyFeatures) === 1) {
                // Single feature, add as-is
                $clusters[] = $feature;
            } else {
                // Multiple features, create cluster
                $clusters[] = $this->createCluster($nearbyFeatures);
            }
        }

        return $clusters;
    }

    /**
     * Create a cluster feature from multiple features
     * 
     * @param array $features
     * @return array GeoJSON cluster feature
     */
    private function createCluster(array $features): array
    {
        $count = count($features);

        // Calculate centroid
        $sumLat = 0;
        $sumLon = 0;
        $projectIds = [];
        $healthCounts = [
            'critical' => 0,
            'warning' => 0,
            'good' => 0,
        ];
        $totalBudget = 0;

        foreach ($features as $feature) {
            $coords = $feature['geometry']['coordinates'];
            $sumLon += $coords[0];
            $sumLat += $coords[1];

            $props = $feature['properties'];
            $projectIds[] = $props['id'];
            
            $health = $props['health'] ?? 'good';
            if (isset($healthCounts[$health])) {
                $healthCounts[$health]++;
            }

            $totalBudget += $props['budget'] ?? 0;
        }

        $centroidLon = $sumLon / $count;
        $centroidLat = $sumLat / $count;

        // Determine cluster color based on worst health status
        $statusColor = 'green';
        if ($healthCounts['critical'] > 0) {
            $statusColor = 'red';
        } elseif ($healthCounts['warning'] > 0) {
            $statusColor = 'yellow';
        }

        return [
            'type' => 'Feature',
            'geometry' => [
                'type' => 'Point',
                'coordinates' => [$centroidLon, $centroidLat],
            ],
            'properties' => [
                'cluster' => true,
                'point_count' => $count,
                'project_ids' => $projectIds,
                'status_color' => $statusColor,
                'total_budget' => $totalBudget,
                'health_summary' => [
                    'critical' => $healthCounts['critical'],
                    'warning' => $healthCounts['warning'],
                    'good' => $healthCounts['good'],
                ],
            ],
        ];
    }

    /**
     * Expand cluster to get individual features
     * This would be called when user clicks on a cluster
     * 
     * @param array $projectIds
     * @return array
     */
    public function expandCluster(array $projectIds): array
    {
        // This would fetch the full project data for the IDs in the cluster
        // Implementation depends on how you want to handle expansion
        // For now, just return the IDs
        return [
            'type' => 'cluster_expansion',
            'project_ids' => $projectIds,
            'count' => count($projectIds),
        ];
    }

    /**
     * Get optimal cluster radius for zoom level
     * 
     * @param int $zoom
     * @return float Radius in meters
     */
    public function getClusterRadius(int $zoom): float
    {
        return GeoUtils::getClusterRadius($zoom);
    }

    /**
     * Advanced clustering using grid-based approach (faster for large datasets)
     * 
     * @param array $features
     * @param int $zoom
     * @return array
     */
    public function gridCluster(array $features, int $zoom): array
    {
        if (empty($features)) {
            return [];
        }

        // Calculate grid size based on zoom
        // Grid size represents degrees per cell
        $gridSize = 360 / pow(2, $zoom + 2); // Smaller grid than tile size

        $grid = [];

        // Assign features to grid cells
        foreach ($features as $feature) {
            $coords = $feature['geometry']['coordinates'];
            $lon = $coords[0];
            $lat = $coords[1];

            $cellX = floor($lon / $gridSize);
            $cellY = floor($lat / $gridSize);
            $key = "{$cellX}:{$cellY}";

            if (!isset($grid[$key])) {
                $grid[$key] = [];
            }

            $grid[$key][] = $feature;
        }

        // Convert grid cells to clusters or single features
        $result = [];

        foreach ($grid as $cellFeatures) {
            if (count($cellFeatures) === 1) {
                $result[] = $cellFeatures[0];
            } else {
                $result[] = $this->createCluster($cellFeatures);
            }
        }

        return $result;
    }

    /**
     * Supercluster-like hierarchical clustering
     * Pre-calculates clusters at different zoom levels
     * 
     * @param array $features All features
     * @param int $minZoom Minimum zoom level
     * @param int $maxZoom Maximum zoom level
     * @return array Cluster tree indexed by zoom level
     */
    public function buildClusterTree(array $features, int $minZoom = 0, int $maxZoom = 20): array
    {
        $tree = [];

        for ($zoom = $maxZoom; $zoom >= $minZoom; $zoom--) {
            $tree[$zoom] = $this->gridCluster($features, $zoom);
        }

        return $tree;
    }
}

