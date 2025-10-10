<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Project;
use App\Services\GeocodingService;
use Illuminate\Support\Facades\DB;

class GeocodeProjectsCommand extends Command
{
    protected $signature = 'projects:geocode 
                            {--force : Force re-geocode all projects even if they already have coordinates}
                            {--organization= : Geocode projects only for specific organization ID}
                            {--limit= : Limit the number of projects to geocode}
                            {--delay=1 : Delay in seconds between geocoding requests}';

    protected $description = 'Geocode project addresses to get latitude and longitude coordinates';

    private GeocodingService $geocodingService;

    public function __construct(GeocodingService $geocodingService)
    {
        parent::__construct();
        $this->geocodingService = $geocodingService;
    }

    public function handle(): int
    {
        $force = $this->option('force');
        $organizationId = $this->option('organization');
        $limit = $this->option('limit');
        $delay = (int) $this->option('delay');

        $query = Project::whereNotNull('address');

        if (!$force) {
            $query->where(function ($q) {
                $q->whereNull('latitude')
                  ->orWhereNull('longitude');
            });
        }

        if ($organizationId) {
            $query->where('organization_id', $organizationId);
        }

        if ($limit) {
            $query->limit((int) $limit);
        }

        $projects = $query->get();
        $total = $projects->count();

        if ($total === 0) {
            $this->info('No projects to geocode.');
            return self::SUCCESS;
        }

        $this->info("Found {$total} project(s) to geocode.");

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $successCount = 0;
        $failedCount = 0;
        $skippedCount = 0;

        foreach ($projects as $project) {
            if (!$force && $project->latitude && $project->longitude) {
                $skippedCount++;
                $bar->advance();
                continue;
            }

            try {
                $result = $this->geocodingService->geocode($project->address);

                if ($result) {
                    DB::table('projects')
                        ->where('id', $project->id)
                        ->update([
                            'latitude' => $result['latitude'],
                            'longitude' => $result['longitude'],
                            'geocoded_at' => now(),
                        ]);

                    $successCount++;
                    
                    if ($this->option('verbose')) {
                        $this->newLine();
                        $this->info("✓ Project #{$project->id}: {$result['latitude']}, {$result['longitude']}");
                    }
                } else {
                    $failedCount++;
                    $this->newLine();
                    $this->warn("Failed to geocode project #{$project->id}: {$project->name}");
                    $this->line("  Address: {$project->address}");
                }
            } catch (\Exception $e) {
                $failedCount++;
                $this->newLine();
                $this->error("Error geocoding project #{$project->id}: {$e->getMessage()}");
                $this->line("  Address: {$project->address}");
            }

            $bar->advance();

            if ($delay > 0 && $successCount < $total) {
                sleep($delay);
            }
        }

        $bar->finish();
        $this->newLine(2);

        $this->info("Geocoding completed!");
        $this->table(
            ['Status', 'Count'],
            [
                ['Success', $successCount],
                ['Failed', $failedCount],
                ['Skipped', $skippedCount],
                ['Total', $total],
            ]
        );

        return self::SUCCESS;
    }
}
