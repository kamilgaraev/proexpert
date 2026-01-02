<?php

namespace App\Services\Geo\Geocoding;

use App\Models\Project;
use App\Models\ProjectAddress;
use App\Services\Geo\Geocoding\Contracts\GeocodeProviderInterface;
use App\Services\Geo\Geocoding\DTO\GeocodeResult;
use App\Services\Geo\Geocoding\Providers\DaDataProvider;
use App\Services\Geo\Geocoding\Providers\YandexProvider;
use App\Services\Geo\Geocoding\Providers\NominatimProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class GeocodeService
{
    /** @var GeocodeProviderInterface[] */
    private array $providers = [];
    private float $minConfidence;

    public function __construct()
    {
        $this->initializeProviders();
        $this->minConfidence = config('geocoding.min_confidence', 0.5);
    }

    /**
     * Initialize geocoding providers
     */
    private function initializeProviders(): void
    {
        $providers = [
            new DaDataProvider(),
            new YandexProvider(),
            new NominatimProvider(),
        ];

        // Sort by priority and filter enabled
        $this->providers = collect($providers)
            ->filter(fn($p) => $p->isEnabled())
            ->sortBy(fn($p) => $p->getPriority())
            ->values()
            ->all();
    }

    /**
     * Geocode a project by its address
     */
    public function geocodeProject(Project $project): ?GeocodeResult
    {
        if (empty($project->address)) {
            Log::warning('Cannot geocode project without address', ['project_id' => $project->id]);
            return null;
        }

        // Check cache first
        $cacheKey = $this->getCacheKey($project->address);
        $cached = Cache::get($cacheKey);
        
        if ($cached) {
            Log::info('Using cached geocoding result', ['project_id' => $project->id]);
            return $cached;
        }

        // Try each provider in order of priority
        $lowConfidenceResults = [];
        
        foreach ($this->providers as $provider) {
            try {
                Log::info('Attempting geocoding with provider', [
                    'project_id' => $project->id,
                    'provider' => $provider->getName(),
                    'address' => $project->address,
                ]);

                $result = $provider->geocode($project->address);

                if ($result && $result->meetsConfidenceThreshold($this->minConfidence)) {
                    // Cache the successful result
                    Cache::put($cacheKey, $result, config('geocoding.cache_ttl', 86400 * 30));
                    
                    // Log success
                    $this->logGeocodingAttempt($project->id, $provider->getName(), true, $result);
                    
                    Log::info('Successfully geocoded project', [
                        'project_id' => $project->id,
                        'provider' => $provider->getName(),
                        'confidence' => $result->confidence,
                    ]);

                    return $result;
                }

                if ($result) {
                    Log::warning('Geocoding result below confidence threshold', [
                        'project_id' => $project->id,
                        'provider' => $provider->getName(),
                        'confidence' => $result->confidence,
                        'threshold' => $this->minConfidence,
                        'coordinates' => [
                            'lat' => $result->latitude,
                            'lon' => $result->longitude,
                        ],
                        'formatted_address' => $result->formattedAddress,
                    ]);
                    
                    // Log the attempt even if confidence is low
                    $this->logGeocodingAttempt($project->id, $provider->getName(), false, $result, 
                        "Confidence {$result->confidence} below threshold {$this->minConfidence}");
                    
                    // Store low confidence results for fallback
                    $lowConfidenceResults[] = $result;
                } else {
                    // Log when provider returns null
                    $this->logGeocodingAttempt($project->id, $provider->getName(), false, null, 
                        'Provider returned null result');
                }
            } catch (\Exception $e) {
                Log::error('Geocoding provider exception', [
                    'project_id' => $project->id,
                    'provider' => $provider->getName(),
                    'error' => $e->getMessage(),
                ]);
                
                $this->logGeocodingAttempt($project->id, $provider->getName(), false, null, $e->getMessage());
            }
        }
        
        // If no high confidence results, use the best low confidence result
        if (!empty($lowConfidenceResults)) {
            // Sort by confidence descending
            usort($lowConfidenceResults, fn($a, $b) => $b->confidence <=> $a->confidence);
            $bestResult = $lowConfidenceResults[0];
            
            Log::info('Using best low confidence result as fallback', [
                'project_id' => $project->id,
                'provider' => $bestResult->provider,
                'confidence' => $bestResult->confidence,
                'coordinates' => [
                    'lat' => $bestResult->latitude,
                    'lon' => $bestResult->longitude,
                ],
            ]);
            
            return $bestResult;
        }

        Log::warning('All geocoding providers failed for project', [
            'project_id' => $project->id,
            'address' => $project->address,
        ]);

        return null;
    }

    /**
     * Save geocoding result to project
     */
    public function saveGeocodingResult(Project $project, GeocodeResult $result): ProjectAddress
    {
        DB::beginTransaction();
        
        try {
            // Update project coordinates and status
            $project->update([
                'latitude' => $result->latitude,
                'longitude' => $result->longitude,
                'geocoded_at' => now(),
                'geocoding_status' => 'geocoded',
            ]);

            // Create or update project_address record
            $projectAddress = ProjectAddress::updateOrCreate(
                ['project_id' => $project->id],
                [
                    'raw_address' => $project->address,
                    'country' => $result->country,
                    'region' => $result->region,
                    'city' => $result->city,
                    'district' => $result->district,
                    'street' => $result->street,
                    'house' => $result->house,
                    'postal_code' => $result->postalCode,
                    'latitude' => $result->latitude,
                    'longitude' => $result->longitude,
                    'geocoded_at' => now(),
                    'geocoding_provider' => $result->provider,
                    'geocoding_confidence' => $result->confidence,
                    'geocoding_error' => null,
                ]
            );

            DB::commit();

            Log::info('Saved geocoding result for project', [
                'project_id' => $project->id,
                'provider' => $result->provider,
            ]);

            return $projectAddress;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to save geocoding result', [
                'project_id' => $project->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Mark project as failed geocoding
     */
    public function markGeocodingFailed(Project $project, string $error): void
    {
        $project->update([
            'geocoding_status' => 'failed',
        ]);

        ProjectAddress::updateOrCreate(
            ['project_id' => $project->id],
            [
                'raw_address' => $project->address,
                'geocoding_error' => $error,
            ]
        );

        Log::warning('Marked project geocoding as failed', [
            'project_id' => $project->id,
            'error' => $error,
        ]);
    }

    /**
     * Geocode and save in one operation
     */
    public function geocodeAndSave(Project $project): bool
    {
        $result = $this->geocodeProject($project);

        if ($result) {
            $this->saveGeocodingResult($project, $result);
            return true;
        }

        $this->markGeocodingFailed($project, 'All providers failed or returned low confidence results');
        return false;
    }

    /**
     * Get geocoding statistics
     */
    public function getStatistics(int $organizationId): array
    {
        $total = Project::where('organization_id', $organizationId)->count();
        $geocoded = Project::where('organization_id', $organizationId)
            ->where('geocoding_status', 'geocoded')
            ->count();
        $pending = Project::where('organization_id', $organizationId)
            ->where('geocoding_status', 'pending')
            ->count();
        $failed = Project::where('organization_id', $organizationId)
            ->where('geocoding_status', 'failed')
            ->count();
        $manual = Project::where('organization_id', $organizationId)
            ->where('geocoding_status', 'manual')
            ->count();

        return [
            'total' => $total,
            'geocoded' => $geocoded,
            'pending' => $pending,
            'failed' => $failed,
            'manual' => $manual,
            'geocoded_percentage' => $total > 0 ? round(($geocoded / $total) * 100, 2) : 0,
        ];
    }

    /**
     * Get cache key for address
     */
    private function getCacheKey(string $address): string
    {
        return config('geocoding.cache_prefix', 'geocode:') . md5(strtolower(trim($address)));
    }

    /**
     * Log geocoding attempt
     */
    private function logGeocodingAttempt(
        int $projectId,
        string $provider,
        bool $success,
        ?GeocodeResult $result,
        ?string $error = null
    ): void {
        if (!config('geocoding.logging.enabled', true)) {
            return;
        }

        DB::table('geocoding_logs')->insert([
            'project_id' => $projectId,
            'provider' => $provider,
            'request' => config('geocoding.logging.log_requests', false) ? json_encode(['project_id' => $projectId]) : null,
            'response' => config('geocoding.logging.log_responses', false) && $result ? json_encode($result->toArray()) : null,
            'success' => $success,
            'error' => $error,
            'created_at' => now(),
        ]);
    }

    /**
     * Reverse geocode coordinates to address
     */
    public function reverseGeocode(float $latitude, float $longitude): ?GeocodeResult
    {
        foreach ($this->providers as $provider) {
            try {
                $result = $provider->reverse($latitude, $longitude);
                
                if ($result) {
                    return $result;
                }
            } catch (\Exception $e) {
                Log::error('Reverse geocoding exception', [
                    'provider' => $provider->getName(),
                    'latitude' => $latitude,
                    'longitude' => $longitude,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return null;
    }
}

