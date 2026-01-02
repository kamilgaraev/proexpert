<?php

namespace App\Services\Analytics;

use App\Models\Project;
use Illuminate\Support\Collection;

class MapService
{
    private EVMService $evmService;

    public function __construct(EVMService $evmService)
    {
        $this->evmService = $evmService;
    }

    /**
     * Get map data for organization
     * Supports basic grid-based clustering
     * 
     * @param int $organizationId
     * @param array $options [zoom, bounds]
     * @return array
     */
    public function getMapData(int $organizationId, array $options = []): array
    {
        $projects = Project::where('organization_id', $organizationId)
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->get();

        // If clustering is requested/needed based on zoom
        // For now, let's return all points with status calculated
        // Frontend clustering is often smoother, but user asked for backend support
        // We will implement a simple clustering if zoom is low (< 10)
        
        $zoom = $options['zoom'] ?? 12;
        $shouldCluster = $zoom < 10;

        $features = $projects->map(function ($project) {
            return $this->formatProjectFeature($project);
        });

        if ($shouldCluster) {
            return $this->clusterFeatures($features, $zoom);
        }

        return [
            'type' => 'FeatureCollection',
            'features' => $features->values()->toArray(),
        ];
    }

    private function formatProjectFeature(Project $project): array
    {
        // Calculate status color based on EVM
        // This could be optimized by batching, but for now we iterate
        $metrics = $this->evmService->calculateMetrics($project);
        
        $spi = $metrics['spi'];
        $cpi = $metrics['cpi'];
        
        $statusColor = 'green';
        $health = 'good';

        if ($spi < 0.8 || $cpi < 0.8) {
            $statusColor = 'red';
            $health = 'critical';
        } elseif ($spi < 0.95 || $cpi < 0.95) {
            $statusColor = 'yellow';
            $health = 'warning';
        }

        return [
            'type' => 'Feature',
            'geometry' => [
                'type' => 'Point',
                // ИСПРАВЛЕНИЕ: Координаты в БД перепутаны местами, меняем их для правильного формата GeoJSON
                'coordinates' => [(float) $project->latitude, (float) $project->longitude],
            ],
            'properties' => [
                'id' => $project->id,
                'name' => $project->name,
                'address' => $project->address,
                'spi' => $spi,
                'cpi' => $cpi,
                'status_color' => $statusColor,
                'health' => $health,
                'budget' => $project->budget_amount,
            ],
        ];
    }

    /**
     * Simple grid-based clustering
     */
    private function clusterFeatures(Collection $features, int $zoom): array
    {
        $gridSize = 360 / pow(2, $zoom); // Degrees per grid cell approximation
        
        $clusters = [];
        
        foreach ($features as $feature) {
            $lon = $feature['geometry']['coordinates'][0];
            $lat = $feature['geometry']['coordinates'][1];
            
            $gridX = floor($lon / $gridSize);
            $gridY = floor($lat / $gridSize);
            
            $key = "{$gridX}:{$gridY}";
            
            if (!isset($clusters[$key])) {
                $clusters[$key] = [
                    'type' => 'Feature',
                    'geometry' => [
                        'type' => 'Point',
                        'coordinates' => [$lon, $lat], // Centroid will be updated
                    ],
                    'properties' => [
                        'cluster' => true,
                        'point_count' => 0,
                        'features' => [], // Store IDs or summary
                        'sum_lon' => 0,
                        'sum_lat' => 0,
                    ],
                ];
            }
            
            $clusters[$key]['properties']['point_count']++;
            $clusters[$key]['properties']['sum_lon'] += $lon;
            $clusters[$key]['properties']['sum_lat'] += $lat;
            $clusters[$key]['properties']['features'][] = $feature;
        }

        // Finalize centroids
        $resultFeatures = [];
        foreach ($clusters as $cluster) {
            if ($cluster['properties']['point_count'] === 1) {
                // If only one point, return original feature
                $resultFeatures[] = $cluster['properties']['features'][0];
            } else {
                $count = $cluster['properties']['point_count'];
                $cluster['geometry']['coordinates'] = [
                    $cluster['properties']['sum_lon'] / $count,
                    $cluster['properties']['sum_lat'] / $count,
                ];
                
                // Remove temp properties to keep payload clean
                unset($cluster['properties']['sum_lon']);
                unset($cluster['properties']['sum_lat']);
                // Maybe simplified status for cluster? (e.g. if any red, cluster is red)
                $hasCritical = collect($cluster['properties']['features'])->contains(fn($f) => $f['properties']['health'] === 'critical');
                $cluster['properties']['status_color'] = $hasCritical ? 'red' : 'green';
                
                // Clear bulky features list from cluster property, keep just IDs if needed
                $cluster['properties']['project_ids'] = array_column(array_column($cluster['properties']['features'], 'properties'), 'id');
                unset($cluster['properties']['features']);

                $resultFeatures[] = $cluster;
            }
        }

        return [
            'type' => 'FeatureCollection',
            'features' => $resultFeatures,
        ];
    }
}

