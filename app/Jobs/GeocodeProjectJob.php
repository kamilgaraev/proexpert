<?php

namespace App\Jobs;

use App\Models\Project;
use App\Services\Geo\Geocoding\GeocodeService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class GeocodeProjectJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 30;
    public int $backoff = 60;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public int $projectId
    ) {
        $this->onQueue(config('geocoding.queue.queue_name', 'geocoding'));
    }

    /**
     * Execute the job.
     */
    public function handle(GeocodeService $geocodeService): void
    {
        $project = Project::find($this->projectId);

        if (!$project) {
            Log::warning('Project not found for geocoding', ['project_id' => $this->projectId]);
            return;
        }

        // Skip if already geocoded with manual status
        if ($project->geocoding_status === 'manual') {
            Log::info('Skipping manually geocoded project', ['project_id' => $this->projectId]);
            return;
        }

        // Skip if no address
        if (empty($project->address)) {
            Log::warning('Project has no address for geocoding', ['project_id' => $this->projectId]);
            $geocodeService->markGeocodingFailed($project, 'No address provided');
            return;
        }

        Log::info('Starting geocoding job for project', [
            'project_id' => $this->projectId,
            'address' => $project->address,
            'attempt' => $this->attempts(),
        ]);

        $success = $geocodeService->geocodeAndSave($project);

        if ($success) {
            Log::info('Geocoding job completed successfully', ['project_id' => $this->projectId]);
        } else {
            Log::warning('Geocoding job failed', [
                'project_id' => $this->projectId,
                'attempt' => $this->attempts(),
            ]);

            // Will retry automatically if attempts < tries
            if ($this->attempts() >= $this->tries) {
                Log::error('Geocoding job exhausted all retries', ['project_id' => $this->projectId]);
            }
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('Geocoding job failed with exception', [
            'project_id' => $this->projectId,
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);

        $project = Project::find($this->projectId);
        if ($project) {
            $geocodeService = app(GeocodeService::class);
            $geocodeService->markGeocodingFailed(
                $project,
                'Job failed: ' . $exception->getMessage()
            );
        }
    }
}

