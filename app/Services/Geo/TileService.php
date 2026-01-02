<?php

namespace App\Services\Geo;

use App\Models\Project;
use App\Services\Analytics\EVMService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TileService
{
    private const CACHE_TTL = 900; // 15 minutes
    private const CACHE_PREFIX = 'map_tile:';

    public function __construct(
        private EVMService $evmService,
        private ClusterService $clusterService
    ) {}

    /**
     * Get map tile data
     * 
     * @param int $organizationId
     * @param int $z Zoom level
     * @param int $x Tile X coordinate
     * @param int $y Tile Y coordinate
     * @param array $options ['layer', 'filters']
     * @return array GeoJSON FeatureCollection
     */
    public function getTile(int $organizationId, int $z, int $x, int $y, array $options = []): array
    {
        $layer = $options['layer'] ?? 'projects';
        $filters = $options['filters'] ?? [];

        // Generate cache key
        $cacheKey = $this->getCacheKey($organizationId, $z, $x, $y, $layer, $filters);

        // Try cache first
        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            Log::debug('Tile cache hit', compact('organizationId', 'z', 'x', 'y', 'layer'));
            return $cached;
        }

        Log::debug('Tile cache miss, generating', compact('organizationId', 'z', 'x', 'y', 'layer'));

        // Calculate tile bounds
        $bounds = GeoUtils::tileToBounds($x, $y, $z);

        // Get projects in bounds
        $projects = $this->getProjectsInBounds($organizationId, $bounds, $filters);

        // Convert to features
        $features = $projects->map(function ($project) {
            return $this->projectToFeature($project);
        })->filter()->values()->all();

        // Apply clustering if zoom is low
        if ($z < 10 && count($features) > 5) {
            $features = $this->clusterService->cluster($features, $z);
        }

        $result = GeoUtils::createFeatureCollection($features);

        // Cache the result
        Cache::put($cacheKey, $result, self::CACHE_TTL);

        return $result;
    }

    /**
     * Get projects within bounds
     */
    private function getProjectsInBounds(int $organizationId, array $bounds, array $filters): \Illuminate\Database\Eloquent\Collection
    {
        $query = Project::where('organization_id', $organizationId)
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->where('latitude', '>=', $bounds['south'])
            ->where('latitude', '<=', $bounds['north'])
            ->where('longitude', '>=', $bounds['west'])
            ->where('longitude', '<=', $bounds['east']);

        // Apply filters
        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['health'])) {
            // Health filter requires joining with metrics or calculating on the fly
            // For now, we'll calculate after fetching
        }

        if (isset($filters['budget_min'])) {
            $query->where('budget_amount', '>=', $filters['budget_min']);
        }

        if (isset($filters['budget_max'])) {
            $query->where('budget_amount', '<=', $filters['budget_max']);
        }

        return $query->get();
    }

    /**
     * Convert project to GeoJSON feature
     */
    private function projectToFeature(Project $project): ?array
    {
        if (!$project->latitude || !$project->longitude) {
            return null;
        }

        // Get EVM metrics (cached)
        try {
            $metrics = $this->evmService->calculateMetrics($project);
        } catch (\Exception $e) {
            Log::error('Failed to calculate metrics for project', [
                'project_id' => $project->id,
                'error' => $e->getMessage(),
            ]);
            
            // Fallback metrics
            $metrics = [
                'spi' => 1.0,
                'cpi' => 1.0,
                'health' => 'unknown',
            ];
        }

        $statusColor = match ($metrics['health']) {
            'critical' => 'red',
            'warning' => 'yellow',
            'good' => 'green',
            default => 'gray',
        };

        return [
            'type' => 'Feature',
            'geometry' => [
                'type' => 'Point',
                'coordinates' => [(float) $project->longitude, (float) $project->latitude],
            ],
            'properties' => [
                'id' => $project->id,
                'name' => $project->name,
                'address' => $project->address,
                'status' => $project->status,
                'budget' => (float) $project->budget_amount,
                'spi' => $metrics['spi'],
                'cpi' => $metrics['cpi'],
                'health' => $metrics['health'],
                'status_color' => $statusColor,
                'start_date' => $project->start_date?->format('Y-m-d'),
                'end_date' => $project->end_date?->format('Y-m-d'),
            ],
        ];
    }

    /**
     * Generate cache key
     */
    private function getCacheKey(int $orgId, int $z, int $x, int $y, string $layer, array $filters): string
    {
        $filterHash = md5(json_encode($filters));
        return self::CACHE_PREFIX . "{$orgId}:{$z}:{$x}:{$y}:{$layer}:{$filterHash}";
    }

    /**
     * Invalidate all tiles cache for organization
     */
    public function invalidateTilesCache(int $organizationId): void
    {
        // In Redis, we could use key patterns, but Cache facade doesn't support it directly
        // For now, we'll just log it. In production, you'd want to use Redis SCAN or tags
        Log::info('Tiles cache invalidation requested', ['organization_id' => $organizationId]);
        
        // If using Redis directly:
        // Redis::del(Redis::keys(self::CACHE_PREFIX . "{$organizationId}:*"));
    }

    /**
     * Invalidate tiles cache for specific project
     */
    public function invalidateTilesForProject(Project $project): void
    {
        // Invalidate EVM cache first
        $this->evmService->invalidateCache($project->id);
        
        // Invalidate tiles that might contain this project
        // This is simplified - in production you'd calculate which tiles are affected
        $this->invalidateTilesCache($project->organization_id);
    }
}

