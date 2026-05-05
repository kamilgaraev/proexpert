<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\BusinessModules\Features\BudgetEstimates\Services\Versioning\EstimateStableKeyService;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\EstimateSection;
use Illuminate\Console\Command;

class BackfillEstimateVersionStableKeysCommand extends Command
{
    protected $signature = 'estimates:backfill-stable-keys {--organization_id=} {--dry-run}';

    protected $description = 'Safely backfills stable keys for estimate sections and items.';

    public function handle(EstimateStableKeyService $stableKeyService): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $organizationId = $this->organizationId();

        $summary = [
            'estimates' => 0,
            'processed_estimates' => 0,
            'sections_without_keys' => 0,
            'items_without_keys' => 0,
        ];

        $this->query($organizationId)
            ->chunkById(100, function ($estimates) use (&$summary, $dryRun, $stableKeyService): void {
                foreach ($estimates as $estimate) {
                    $summary['estimates']++;

                    $missingSections = $estimate->sections
                        ->filter(fn (EstimateSection $section): bool => $this->isMissingStableKey($section->stable_key))
                        ->count();
                    $missingItems = $estimate->items
                        ->filter(fn (EstimateItem $item): bool => $this->isMissingStableKey($item->stable_key))
                        ->count();

                    $summary['sections_without_keys'] += $missingSections;
                    $summary['items_without_keys'] += $missingItems;

                    if ($dryRun) {
                        continue;
                    }

                    $stableKeyService->ensureKeys($estimate);
                    $summary['processed_estimates']++;
                }
            });

        $this->info($dryRun ? 'Dry-run completed. Database was not changed.' : 'Backfill completed.');
        $this->line('Estimates scanned: ' . $summary['estimates']);
        $this->line('Estimates processed: ' . $summary['processed_estimates']);
        $this->line('Sections without stable_key: ' . $summary['sections_without_keys']);
        $this->line('Items without stable_key: ' . $summary['items_without_keys']);

        return Command::SUCCESS;
    }

    private function query(?int $organizationId)
    {
        return Estimate::query()
            ->with([
                'sections',
                'items' => fn ($query) => $query->reorder('id'),
            ])
            ->when($organizationId !== null, fn ($query) => $query->where('organization_id', $organizationId));
    }

    private function organizationId(): ?int
    {
        $value = $this->option('organization_id');

        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    private function isMissingStableKey(mixed $value): bool
    {
        return $value === null || trim((string) $value) === '';
    }
}
