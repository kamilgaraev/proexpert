<?php

namespace App\Services\Geo;

use App\Models\Project;
use App\Services\Analytics\EVMService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class HeatmapService
{
    private const CACHE_TTL = 1800; // 30 minutes
    private const CACHE_PREFIX = 'heatmap:';

    public function __construct(
        private EVMService $evmService
    ) {}

    /**
     * Generate heatmap data for projects
     * 
     * @param int $organizationId
     * @param string $metric budget|problems|activity
     * @param array $bounds ['north', 'south', 'east', 'west']
     * @return array
     */
    public function generate(int $organizationId, string $metric = 'budget', ?array $bounds = null): array
    {
        $cacheKey = $this->getCacheKey($organizationId, $metric, $bounds);

        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            Log::debug('Heatmap cache hit', compact('organizationId', 'metric'));
            return $cached;
        }

        Log::debug('Heatmap cache miss, generating', compact('organizationId', 'metric'));

        $query = Project::where('organization_id', $organizationId)
            ->whereNotNull('latitude')
            ->whereNotNull('longitude');

        // Apply bounds filter if provided
        if ($bounds) {
            $query->where('latitude', '>=', $bounds['south'])
                ->where('latitude', '<=', $bounds['north'])
                ->where('longitude', '>=', $bounds['west'])
                ->where('longitude', '<=', $bounds['east']);
        }

        $projects = $query->get();

        // Generate heatmap points based on metric
        $points = $this->generateHeatmapPoints($projects, $metric);

        $result = [
            'type' => 'heatmap',
            'metric' => $metric,
            'data' => $points,
            'bounds' => $bounds,
            'generated_at' => now()->toIso8601String(),
        ];

        Cache::put($cacheKey, $result, self::CACHE_TTL);

        return $result;
    }

    /**
     * Generate heatmap points from projects
     */
    private function generateHeatmapPoints($projects, string $metric): array
    {
        $points = [];

        foreach ($projects as $project) {
            $intensity = $this->calculateIntensity($project, $metric);

            if ($intensity > 0) {
                $points[] = [
                    'lat' => (float) $project->latitude,
                    'lng' => (float) $project->longitude,
                    'intensity' => $intensity,
                    'project_id' => $project->id,
                    'project_name' => $project->name,
                ];
            }
        }

        return $points;
    }

    /**
     * Calculate intensity for a project based on metric
     */
    private function calculateIntensity(Project $project, string $metric): float
    {
        return match ($metric) {
            'budget' => $this->calculateBudgetIntensity($project),
            'problems' => $this->calculateProblemsIntensity($project),
            'activity' => $this->calculateActivityIntensity($project),
            default => 0.5,
        };
    }

    /**
     * Calculate budget-based intensity (normalized 0-1)
     */
    private function calculateBudgetIntensity(Project $project): float
    {
        $budget = (float) $project->budget_amount;

        // Normalize budget to 0-1 scale
        // Using logarithmic scale for better visualization
        if ($budget <= 0) {
            return 0.0;
        }

        // Assuming typical project budgets range from 100k to 100M
        $minBudget = 100000; // 100k
        $maxBudget = 100000000; // 100M

        $normalized = (log($budget) - log($minBudget)) / (log($maxBudget) - log($minBudget));

        return max(0.0, min(1.0, $normalized));
    }

    /**
     * Calculate problems-based intensity
     * Based on EVM metrics (low SPI/CPI = high intensity)
     */
    private function calculateProblemsIntensity(Project $project): float
    {
        try {
            $metrics = $this->evmService->calculateMetrics($project);

            $spi = $metrics['spi'];
            $cpi = $metrics['cpi'];

            // Lower performance index = higher problem intensity
            // Perfect performance (SPI=1, CPI=1) = 0 intensity
            // Poor performance (SPI=0.5, CPI=0.5) = 1 intensity

            $spiProblem = max(0, 1 - $spi); // 0 if SPI=1, 0.5 if SPI=0.5
            $cpiProblem = max(0, 1 - $cpi);

            // Average of both problems
            $intensity = ($spiProblem + $cpiProblem) / 2;

            return max(0.0, min(1.0, $intensity));
        } catch (\Exception $e) {
            Log::error('Failed to calculate problems intensity', [
                'project_id' => $project->id,
                'error' => $e->getMessage(),
            ]);
            return 0.0;
        }
    }

    /**
     * Calculate activity-based intensity
     * Based on recent activity, project phase, etc.
     */
    private function calculateActivityIntensity(Project $project): float
    {
        $intensity = 0.0;

        // Check if project is currently active
        $now = now();
        $isActive = $project->start_date && $project->end_date
            && $now->between($project->start_date, $project->end_date);

        if ($isActive) {
            $intensity += 0.5;
        }

        // Check recent activity (updated within last week)
        if ($project->updated_at && $project->updated_at->diffInDays($now) <= 7) {
            $intensity += 0.3;
        }

        // Check if has active contracts
        $hasActiveContracts = $project->contracts()->where('status', 'active')->exists();
        if ($hasActiveContracts) {
            $intensity += 0.2;
        }

        return max(0.0, min(1.0, $intensity));
    }

    /**
     * Generate contour-based heatmap (density map)
     * Groups nearby projects into density zones
     */
    public function generateDensityMap(int $organizationId, ?array $bounds = null): array
    {
        $query = Project::where('organization_id', $organizationId)
            ->whereNotNull('latitude')
            ->whereNotNull('longitude');

        if ($bounds) {
            $query->where('latitude', '>=', $bounds['south'])
                ->where('latitude', '<=', $bounds['north'])
                ->where('longitude', '>=', $bounds['west'])
                ->where('longitude', '<=', $bounds['east']);
        }

        $projects = $query->get();

        // Create density grid
        $gridSize = 0.1; // degrees (approximately 11km at equator)
        $grid = [];

        foreach ($projects as $project) {
            $cellX = floor($project->longitude / $gridSize);
            $cellY = floor($project->latitude / $gridSize);
            $key = "{$cellX}:{$cellY}";

            if (!isset($grid[$key])) {
                $grid[$key] = [
                    'count' => 0,
                    'total_budget' => 0,
                    'lat' => $cellY * $gridSize + $gridSize / 2,
                    'lng' => $cellX * $gridSize + $gridSize / 2,
                ];
            }

            $grid[$key]['count']++;
            $grid[$key]['total_budget'] += $project->budget_amount;
        }

        // Convert to heatmap points
        $maxCount = max(array_column($grid, 'count')) ?: 1;
        $points = [];

        foreach ($grid as $cell) {
            $points[] = [
                'lat' => $cell['lat'],
                'lng' => $cell['lng'],
                'intensity' => $cell['count'] / $maxCount,
                'count' => $cell['count'],
                'total_budget' => $cell['total_budget'],
            ];
        }

        return [
            'type' => 'density_map',
            'data' => $points,
            'max_count' => $maxCount,
        ];
    }

    /**
     * Generate cache key
     */
    private function getCacheKey(int $orgId, string $metric, ?array $bounds): string
    {
        $boundsHash = $bounds ? md5(json_encode($bounds)) : 'all';
        return self::CACHE_PREFIX . "{$orgId}:{$metric}:{$boundsHash}";
    }

    /**
     * Invalidate heatmap cache
     */
    public function invalidateCache(int $organizationId): void
    {
        Log::info('Heatmap cache invalidation requested', ['organization_id' => $organizationId]);
    }
}

