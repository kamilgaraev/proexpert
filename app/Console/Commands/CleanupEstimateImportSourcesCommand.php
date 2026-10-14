<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\BusinessModules\Features\BudgetEstimates\Services\Import\EstimateImportService;
use App\Models\ImportSession;
use Illuminate\Console\Command;

final class CleanupEstimateImportSourcesCommand extends Command
{
    protected $signature = 'estimates:cleanup-import-sources {--limit=100}';

    protected $description = 'Recover interrupted imports and delete inactive expired source files while preserving history.';

    public function handle(EstimateImportService $imports): int
    {
        $limit = max(1, min(1000, (int) $this->option('limit')));
        ImportSession::query()->whereIn('status', ['queued', 'parsing', 'processing', 'enriching', 'detecting'])
            ->orderBy('created_at')->limit($limit)->get()
            ->each(static fn (ImportSession $session) => $imports->recoverInterruptedSession($session));
        $this->info((string) $imports->cleanupInactiveSources($limit));

        return Command::SUCCESS;
    }
}
