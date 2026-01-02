<?php

namespace App\Console\Commands;

use App\Jobs\GeocodeProjectJob;
use App\Models\Project;
use App\Services\Geo\Geocoding\GeocodeService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class GeocodeProjectsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'geocode:projects
                            {--organization= : Organization ID to geocode}
                            {--project= : Specific project ID to geocode}
                            {--status=pending : Geocoding status filter (pending, failed, all)}
                            {--limit= : Maximum number of projects to process}
                            {--queue : Use queue for background processing}
                            {--sync : Process synchronously (not recommended for large batches)}
                            {--force : Re-geocode even if already geocoded}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Geocode projects by their addresses using multiple providers (DaData, Yandex, Nominatim)';

    /**
     * Execute the console command.
     */
    public function handle(GeocodeService $geocodeService): int
    {
        $this->info('Starting geocoding process...');
        $this->newLine();
        
        // Check available providers
        $this->checkProviders();

        // Build query
        $query = Project::query()->whereNotNull('address');

        // Filter by organization
        if ($organizationId = $this->option('organization')) {
            $query->where('organization_id', $organizationId);
            $this->info("Filtering by organization ID: {$organizationId}");
        }

        // Filter by specific project
        if ($projectId = $this->option('project')) {
            $query->where('id', $projectId);
            $this->info("Processing single project ID: {$projectId}");
        }

        // Filter by status
        $status = $this->option('status');
        $force = $this->option('force');

        if (!$force) {
            if ($status === 'all') {
                // Process all projects with addresses
            } elseif ($status === 'pending') {
                $query->where(function ($q) {
                    $q->where('geocoding_status', 'pending')
                        ->orWhereNull('geocoding_status')
                        ->orWhere(function ($q2) {
                            $q2->whereNull('latitude')->whereNull('longitude');
                        });
                });
            } elseif ($status === 'failed') {
                $query->where('geocoding_status', 'failed');
            } else {
                $query->where('geocoding_status', $status);
            }
            $this->info("Geocoding status filter: {$status}");
        } else {
            $this->warn("Force mode: will re-geocode all projects");
        }

        // Apply limit
        if ($limit = $this->option('limit')) {
            $query->limit((int) $limit);
            $this->info("Limit: {$limit} projects");
        }

        $projects = $query->get();
        $total = $projects->count();

        if ($total === 0) {
            $this->warn('No projects found matching criteria.');
            return self::SUCCESS;
        }

        $this->info("Found {$total} projects to geocode");
        $this->newLine();

        // Confirm before proceeding
        if (!$this->option('sync') && !$this->option('queue')) {
            if (!$this->confirm("Process {$total} projects?", true)) {
                $this->info('Cancelled.');
                return self::SUCCESS;
            }
        }

        // Process projects
        $useQueue = $this->option('queue') || (!$this->option('sync') && $total > 10);
        $useSync = $this->option('sync');

        if ($useQueue && !$useSync) {
            $this->info('Using queue for background processing...');
            $this->processWithQueue($projects);
        } else {
            $this->info('Processing synchronously...');
            $this->processSync($projects, $geocodeService);
        }

        $this->newLine();
        $this->info('Geocoding process completed!');

        // Show statistics if organization specified
        if ($organizationId) {
            $this->newLine();
            $this->showStatistics($geocodeService, $organizationId);
        }

        return self::SUCCESS;
    }

    /**
     * Process projects using queue
     */
    private function processWithQueue($projects): void
    {
        $bar = $this->output->createProgressBar($projects->count());
        $bar->setFormat('verbose');
        $bar->start();

        $dispatched = 0;
        $chunkSize = config('geocoding.batch.chunk_size', 100);
        $delayBetweenChunks = config('geocoding.batch.delay_between_chunks', 1000) / 1000; // Convert to seconds

        foreach ($projects->chunk($chunkSize) as $chunk) {
            foreach ($chunk as $project) {
                GeocodeProjectJob::dispatch($project->id);
                $dispatched++;
                $bar->advance();
            }

            // Delay between chunks to respect rate limits
            if ($delayBetweenChunks > 0) {
                usleep((int)($delayBetweenChunks * 1000000));
            }
        }

        $bar->finish();
        $this->newLine();
        $this->info("Dispatched {$dispatched} geocoding jobs to queue");
    }

    /**
     * Process projects synchronously
     */
    private function processSync($projects, GeocodeService $geocodeService): void
    {
        $bar = $this->output->createProgressBar($projects->count());
        $bar->setFormat('verbose');
        $bar->start();

        $success = 0;
        $failed = 0;
        $skipped = 0;

        $rateLimit = config('geocoding.batch.rate_limit', 10);
        $delay = 1.0 / $rateLimit; // Delay between requests in seconds

        foreach ($projects as $project) {
            // Skip manual geocoded projects
            if ($project->geocoding_status === 'manual' && !$this->option('force')) {
                $skipped++;
                $bar->advance();
                continue;
            }

            try {
                $result = $geocodeService->geocodeAndSave($project);

                if ($result) {
                    $success++;
                } else {
                    $failed++;
                    // Show first few failures for debugging
                    if ($failed <= 3) {
                        $this->warn("\nFailed to geocode project {$project->id}: {$project->address}");
                    }
                }

                // Rate limiting
                if ($delay > 0) {
                    usleep((int)($delay * 1000000));
                }
            } catch (\Exception $e) {
                $failed++;
                $this->error("\nException geocoding project {$project->id}: {$e->getMessage()}");
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->newLine();

        // Summary
        $this->table(
            ['Status', 'Count'],
            [
                ['Success', $success],
                ['Failed', $failed],
                ['Skipped', $skipped],
                ['Total', $projects->count()],
            ]
        );
    }

    /**
     * Check available geocoding providers
     */
    private function checkProviders(): void
    {
        $this->info('Checking geocoding providers...');
        
        $providers = [
            'DaData' => [
                'enabled' => env('DADATA_ENABLED', true),
                'has_api_key' => !empty(env('DADATA_API_KEY')),
                'has_secret' => !empty(env('DADATA_SECRET_KEY')),
            ],
            'Yandex' => [
                'enabled' => env('YANDEX_GEOCODER_ENABLED', true),
                'has_api_key' => !empty(env('YANDEX_GEOCODER_KEY')),
            ],
            'Nominatim' => [
                'enabled' => env('NOMINATIM_ENABLED', true),
                'needs_key' => false,
            ],
        ];
        
        $available = [];
        foreach ($providers as $name => $config) {
            $isAvailable = $config['enabled'];
            if ($name === 'DaData') {
                $isAvailable = $isAvailable && $config['has_api_key'] && $config['has_secret'];
            } elseif ($name === 'Yandex') {
                $isAvailable = $isAvailable && $config['has_api_key'];
            }
            
            if ($isAvailable) {
                $available[] = $name;
                $this->info("  ✓ {$name} - available");
            } else {
                $reasons = [];
                if (!$config['enabled']) {
                    $reasons[] = 'disabled';
                }
                if (isset($config['has_api_key']) && !$config['has_api_key']) {
                    $reasons[] = 'no API key';
                }
                if (isset($config['has_secret']) && !$config['has_secret']) {
                    $reasons[] = 'no secret key';
                }
                $this->warn("  ✗ {$name} - unavailable (" . implode(', ', $reasons) . ")");
            }
        }
        
        $this->newLine();
        
        if (empty($available)) {
            $this->error('ERROR: No geocoding providers are available!');
            $this->error('Please configure at least one provider in .env file:');
            $this->newLine();
            $this->line('For Nominatim (free, no API key needed):');
            $this->line('  NOMINATIM_ENABLED=true');
            $this->newLine();
            $this->line('For DaData (recommended for Russian addresses):');
            $this->line('  DADATA_ENABLED=true');
            $this->line('  DADATA_API_KEY=your_api_key');
            $this->line('  DADATA_SECRET_KEY=your_secret_key');
            $this->newLine();
            $this->line('For Yandex:');
            $this->line('  YANDEX_GEOCODER_ENABLED=true');
            $this->line('  YANDEX_GEOCODER_KEY=your_api_key');
            $this->newLine();
            return;
        }
        
        $this->info('Available providers: ' . implode(', ', $available));
        $this->newLine();
    }

    /**
     * Show geocoding statistics
     */
    private function showStatistics(GeocodeService $geocodeService, int $organizationId): void
    {
        $stats = $geocodeService->getStatistics($organizationId);

        $this->info('Geocoding Statistics:');
        $this->table(
            ['Metric', 'Value'],
            [
                ['Total Projects', $stats['total']],
                ['Geocoded', $stats['geocoded']],
                ['Pending', $stats['pending']],
                ['Failed', $stats['failed']],
                ['Manual', $stats['manual']],
                ['Geocoded %', $stats['geocoded_percentage'] . '%'],
            ]
        );
    }
}
