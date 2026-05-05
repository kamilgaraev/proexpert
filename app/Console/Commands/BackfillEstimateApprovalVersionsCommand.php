<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\BusinessModules\Features\BudgetEstimates\Services\EstimateVersioningService;
use App\Models\Estimate;
use App\Models\EstimateVersion;
use Illuminate\Console\Command;
use InvalidArgumentException;

class BackfillEstimateApprovalVersionsCommand extends Command
{
    protected $signature = 'estimates:backfill-approval-versions {--organization_id=} {--dry-run}';

    protected $description = 'Safely backfills approval snapshots for approved estimates.';

    public function handle(EstimateVersioningService $versioningService): int
    {
        $dryRun = (bool) $this->option('dry-run');

        try {
            $organizationId = $this->organizationId();
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return Command::FAILURE;
        }

        $summary = [
            'candidates' => 0,
            'processed_estimates' => 0,
            'created_versions' => 0,
            'existing_approval_snapshots' => 0,
            'existing_approval_snapshots_with_hash' => 0,
        ];

        $this->query($organizationId)
            ->chunkById(100, function ($estimates) use (&$summary, $dryRun, $versioningService): void {
                $estimateIds = $estimates->pluck('id')->all();
                $existingSnapshots = $this->approvalSnapshotEstimateIds($estimateIds, false);
                $existingSnapshotsWithHash = $this->approvalSnapshotEstimateIds($estimateIds, true);

                $summary['existing_approval_snapshots'] += count($existingSnapshots);
                $summary['existing_approval_snapshots_with_hash'] += count($existingSnapshotsWithHash);

                foreach ($estimates as $estimate) {
                    $summary['candidates']++;

                    if ($dryRun) {
                        continue;
                    }

                    $version = $versioningService->createApprovalSnapshot(
                        $estimate,
                        (int) $estimate->approved_by_user_id
                    );

                    $summary['processed_estimates']++;

                    if ($version->wasRecentlyCreated) {
                        $summary['created_versions']++;
                    }
                }
            });

        $this->info($dryRun ? 'Dry-run completed. Database was not changed.' : 'Backfill completed.');
        $this->line('Approved estimates with approver: ' . $summary['candidates']);
        $this->line('Estimates processed: ' . $summary['processed_estimates']);
        $this->line('Approval versions created: ' . $summary['created_versions']);
        $this->line('Estimates with approval snapshot: ' . $summary['existing_approval_snapshots']);
        $this->line('Estimates with approval snapshot hash: ' . $summary['existing_approval_snapshots_with_hash']);

        return Command::SUCCESS;
    }

    private function query(?int $organizationId)
    {
        return Estimate::query()
            ->where('status', 'approved')
            ->whereNotNull('approved_by_user_id')
            ->when($organizationId !== null, fn ($query) => $query->where('organization_id', $organizationId));
    }

    private function organizationId(): ?int
    {
        $value = $this->option('organization_id');

        if ($value === null) {
            return null;
        }

        $value = is_scalar($value) ? (string) $value : '';

        if (preg_match('/^[1-9][0-9]*$/', $value) !== 1) {
            throw new InvalidArgumentException('The --organization_id option must be a positive integer.');
        }

        return (int) $value;
    }

    private function approvalSnapshotEstimateIds(array $estimateIds, bool $withHash): array
    {
        if ($estimateIds === []) {
            return [];
        }

        return EstimateVersion::query()
            ->whereIn('estimate_id', $estimateIds)
            ->where('snapshot_type', 'approval')
            ->when($withHash, fn ($query) => $query->whereNotNull('snapshot_hash'))
            ->distinct()
            ->pluck('estimate_id')
            ->all();
    }
}
