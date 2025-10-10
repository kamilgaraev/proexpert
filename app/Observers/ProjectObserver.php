<?php

namespace App\Observers;

use App\Models\Project;
use App\Services\GeocodingService;
use Illuminate\Support\Facades\Log;

class ProjectObserver
{
    private GeocodingService $geocodingService;

    public function __construct(GeocodingService $geocodingService)
    {
        $this->geocodingService = $geocodingService;
    }

    public function creating(Project $project): void
    {
        $this->geocodeIfNeeded($project);
    }

    public function updating(Project $project): void
    {
        if ($project->isDirty('address')) {
            $project->latitude = null;
            $project->longitude = null;
            $project->geocoded_at = null;

            $this->geocodeIfNeeded($project);
        }
    }

    private function geocodeIfNeeded(Project $project): void
    {
        if (!$project->address || ($project->latitude && $project->longitude)) {
            return;
        }

        try {
            $result = $this->geocodingService->geocode($project->address);

            if ($result) {
                $project->latitude = $result['latitude'];
                $project->longitude = $result['longitude'];
                $project->geocoded_at = now();

                Log::info("Project geocoded successfully", [
                    'project_id' => $project->id,
                    'address' => $project->address,
                    'coordinates' => [
                        'lat' => $result['latitude'],
                        'lng' => $result['longitude'],
                    ],
                ]);
            }
        } catch (\Exception $e) {
            Log::warning("Failed to geocode project address", [
                'project_id' => $project->id,
                'address' => $project->address,
                'error' => $e->getMessage(),
            ]);
        }
    }
}

