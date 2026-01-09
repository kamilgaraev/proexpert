<?php

namespace App\Services\Geo;

use App\Models\Project;
use App\Services\Analytics\EVMService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class HeatmapService
{
    private const CACHE_TTL = 600; // 10 minutes (reduced from 30)
    private const CACHE_PREFIX = 'heatmap:';
    private const MAX_POINTS = 1000; // Limit for performance
    private const ZONE_POINTS = 8; // Number of surrounding points per project

    public function __construct(
        private EVMService $evmService
    ) {}

    /**
     * Generate heatmap data for projects with heat zones
     * 
     * @param int $organizationId
     * @param string $metric budget|problems|activity
     * @param array|null $bounds ['north', 'south', 'east', 'west']
     * @param int $zoom Zoom level for adaptive radius
     * @param array $filters Additional filters (status, date_from, date_to)
     * @return array
     */
    public function generate(
        int $organizationId, 
        string $metric = 'budget', 
        ?array $bounds = null,
        int $zoom = 10,
        array $filters = []
    ): array {
        $cacheKey = $this->getCacheKey($organizationId, $metric, $bounds, $zoom, $filters);

        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            Log::debug('Heatmap cache hit', compact('organizationId', 'metric'));
            return $cached;
        }

        Log::debug('Heatmap cache miss, generating', compact('organizationId', 'metric', 'zoom'));

        $query = Project::where('organization_id', $organizationId)
            ->whereNotNull('latitude')
            ->whereNotNull('longitude');

        // Apply status filter
        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        // Apply date range filter
        if (!empty($filters['date_from'])) {
            $query->where('start_date', '>=', Carbon::parse($filters['date_from']));
        }
        if (!empty($filters['date_to'])) {
            $query->where('end_date', '<=', Carbon::parse($filters['date_to']));
        }

        // Apply bounds filter if provided
        if ($bounds) {
            $query->where('latitude', '>=', $bounds['south'])
                ->where('latitude', '<=', $bounds['north'])
                ->where('longitude', '>=', $bounds['west'])
                ->where('longitude', '<=', $bounds['east']);
        }

        $projects = $query->get();

        // Get max value for normalization
        $maxValue = $this->getMaxValue($projects, $metric);

        // Generate heatmap points with heat zones
        $points = $this->generateHeatZones($projects, $metric, $zoom, $maxValue);

        // Calculate statistics
        $stats = $this->calculateStats($points, $projects, $metric);

        $result = [
            'type' => 'heatmap',
            'metric' => $metric,
            'data' => $points,
            'stats' => $stats,
            'bounds' => $bounds,
            'zoom' => $zoom,
            'generated_at' => now()->toIso8601String(),
        ];

        Cache::put($cacheKey, $result, self::CACHE_TTL);

        return $result;
    }

    /**
     * Generate heat zones with central and surrounding points for each project
     */
    private function generateHeatZones($projects, string $metric, int $zoom, float $maxValue): array
    {
        $points = [];
        $totalPoints = 0;
        $maxPointsPerProject = self::ZONE_POINTS + 1; // Center + surrounding

        // Calculate adaptive radius based on zoom
        $radius = $this->calculateRadius($zoom);

        foreach ($projects as $project) {
            // Stop if we exceed max points limit
            if ($totalPoints + $maxPointsPerProject > self::MAX_POINTS) {
                Log::warning('Heatmap points limit reached', [
                    'organization_id' => $project->organization_id,
                    'total_points' => $totalPoints
                ]);
                break;
            }

            $baseIntensity = $this->calculateIntensity($project, $metric, $maxValue);

            if ($baseIntensity <= 0) {
                continue;
            }

            $value = $this->getProjectValue($project, $metric);

            // 1. Center point with maximum intensity
            $points[] = [
                'lat' => (float) $project->latitude,
                'lng' => (float) $project->longitude,
                'intensity' => $this->enhanceContrast($baseIntensity, 1.0),
                'value' => $value,
                'zone' => 'center',
                'project_id' => $project->id,
            ];
            $totalPoints++;

            // 2. Surrounding points in 8 directions (N, NE, E, SE, S, SW, W, NW)
            for ($angle = 0; $angle < 360; $angle += 45) {
                $radians = deg2rad($angle);
                
                $points[] = [
                    'lat' => (float) $project->latitude + $radius * sin($radians),
                    'lng' => (float) $project->longitude + $radius * cos($radians),
                    'intensity' => $this->enhanceContrast($baseIntensity, 0.4), // 40% of center
                    'value' => $value,
                    'zone' => 'outer',
                    'project_id' => $project->id,
                ];
                $totalPoints++;
            }
        }

        return $points;
    }

    /**
     * Calculate adaptive radius based on zoom level
     */
    private function calculateRadius(int $zoom): float
    {
        // Lower zoom (1-5) = viewing large area = larger radius
        // Higher zoom (15-20) = viewing small area = smaller radius
        return match (true) {
            $zoom < 6 => 0.5,      // ~50km - viewing country/region
            $zoom < 10 => 0.1,     // ~10km - viewing city
            $zoom < 13 => 0.05,    // ~5km - viewing district
            default => 0.02,       // ~2km - viewing neighborhood
        };
    }

    /**
     * Get max value for a metric across all projects
     */
    private function getMaxValue($projects, string $metric): float
    {
        if ($metric === 'budget') {
            return (float) $projects->max('budget_amount') ?: 1.0;
        }

        if ($metric === 'activity') {
            return 1.0; // Activity is already normalized
        }

        if ($metric === 'problems') {
            return 1.0; // Problems intensity is already 0-1
        }

        return 1.0;
    }

    /**
     * Get project value for a specific metric
     */
    private function getProjectValue(Project $project, string $metric): float
    {
        return match ($metric) {
            'budget' => (float) $project->budget_amount,
            'problems' => $this->calculateProblemsIntensity($project),
            'activity' => $this->calculateActivityIntensity($project),
            default => 0.0,
        };
    }

    /**
     * Calculate intensity for a project based on metric
     */
    private function calculateIntensity(Project $project, string $metric, float $maxValue): float
    {
        return match ($metric) {
            'budget' => $this->calculateBudgetIntensity($project, $maxValue),
            'problems' => $this->calculateProblemsIntensity($project),
            'activity' => $this->calculateActivityIntensity($project),
            default => 0.5,
        };
    }

    /**
     * Enhance contrast using power function
     * Makes bright points brighter and dim points dimmer
     */
    private function enhanceContrast(float $intensity, float $zoneMultiplier = 1.0): float
    {
        // Apply zone multiplier (1.0 for center, 0.4 for outer)
        $intensity *= $zoneMultiplier;

        // Apply power function to enhance contrast
        // Power < 1 makes bright values brighter, dim values dimmer
        $intensity = pow($intensity, 0.7);

        // Ensure minimum visibility
        $intensity = max($intensity, 0.1);

        return min($intensity, 1.0);
    }

    /**
     * Calculate budget-based intensity using improved logarithmic scale
     */
    private function calculateBudgetIntensity(Project $project, float $maxBudget): float
    {
        $budget = (float) $project->budget_amount;

        if ($budget <= 0 || $maxBudget <= 0) {
            return 0.0;
        }

        // Use logarithmic scale: log10(value + 1) / log10(max + 1)
        // +1 to handle zero values safely
        $intensity = log10($budget + 1) / log10($maxBudget + 1);

        return max(0.0, min(1.0, $intensity));
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
     * Based on recent completed works (last 7 days)
     */
    private function calculateActivityIntensity(Project $project): float
    {
        $intensity = 0.0;
        $now = now();

        // Check if project is currently active
        $isActive = $project->start_date && $project->end_date
            && $now->between($project->start_date, $project->end_date);

        if (!$isActive) {
            return 0.0; // Inactive projects have no activity
        }

        // Count completed works in last 7 days
        $recentWorksCount = $project->completedWorks()
            ->where('completion_date', '>=', $now->copy()->subDays(7))
            ->count();

        // Normalize by 20 works (high activity threshold)
        $intensity = min($recentWorksCount / 20, 1.0);

        // Boost if updated recently
        if ($project->updated_at && $project->updated_at->diffInHours($now) <= 24) {
            $intensity = min($intensity + 0.2, 1.0);
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
     * Calculate statistics for heatmap
     */
    private function calculateStats(array $points, $projects, string $metric): array
    {
        if (empty($points)) {
            return [
                'total_points' => 0,
                'total_projects' => 0,
                'max_intensity' => 0,
                'min_intensity' => 0,
                'metric_range' => ['min' => 0, 'max' => 0],
            ];
        }

        $intensities = array_column($points, 'intensity');
        $values = array_filter(array_column($points, 'value'));

        return [
            'total_points' => count($points),
            'total_projects' => $projects->count(),
            'max_intensity' => round(max($intensities), 2),
            'min_intensity' => round(min($intensities), 2),
            'metric_range' => [
                'min' => !empty($values) ? (float) min($values) : 0,
                'max' => !empty($values) ? (float) max($values) : 0,
            ],
        ];
    }

    /**
     * Generate cache key with filters
     */
    private function getCacheKey(int $orgId, string $metric, ?array $bounds, int $zoom, array $filters): string
    {
        $boundsHash = $bounds ? md5(json_encode($bounds)) : 'all';
        $filtersHash = !empty($filters) ? md5(json_encode($filters)) : 'none';
        return self::CACHE_PREFIX . "{$orgId}:{$metric}:{$zoom}:{$boundsHash}:{$filtersHash}";
    }

    /**
     * Invalidate heatmap cache
     */
    public function invalidateCache(int $organizationId): void
    {
        Log::info('Heatmap cache invalidation requested', ['organization_id' => $organizationId]);
    }
}

