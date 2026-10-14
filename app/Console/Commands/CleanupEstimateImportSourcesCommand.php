<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\BusinessModules\Features\BudgetEstimates\Services\Import\EstimateImportService;
use App\Models\ImportSession;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

final class CleanupEstimateImportSourcesCommand extends Command
{
    protected $signature = 'estimates:cleanup-import-sources {--limit=100}';

    protected $description = 'Recover interrupted imports and delete inactive expired source files while preserving history.';

    public function handle(EstimateImportService $imports): int
    {
        $limit = max(1, min(1000, (int) $this->option('limit')));
        $timestamp = now()->timestamp;
        ImportSession::query()->where(function (Builder $query) use ($timestamp): void {
            $query->where(function (Builder $queued) use ($timestamp): void {
                $queued->where('status', 'queued');
                $this->expiredDeadline($queued, 'queued_deadline_at', $timestamp);
            })->orWhere(function (Builder $worker) use ($timestamp): void {
                $worker->whereIn('status', ['parsing', 'processing', 'enriching']);
                $this->expiredDeadline($worker, 'worker_deadline_at', $timestamp);
            })->orWhere(function (Builder $detecting) use ($timestamp): void {
                $detecting->where('status', 'detecting')->where(function (Builder $phase): void {
                    $phase->where('stats->detection_phase', '!=', 'completed')->orWhereNull('stats->detection_phase');
                });
                $this->expiredDeadline($detecting, 'detection_deadline_at', $timestamp);
            });
        })
            ->orderBy('created_at')->limit($limit)->get()
            ->each(static fn (ImportSession $session) => $imports->recoverInterruptedSession($session));
        $this->info((string) $imports->cleanupInactiveSources($limit));

        return Command::SUCCESS;
    }

    private function expiredDeadline(Builder $query, string $field, int $timestamp): void
    {
        $query->whereRaw(
            "CASE WHEN (stats->>'{$field}') ~ '^[[:space:]]*[+-]?([0-9]+([.][0-9]*)?|[.][0-9]+)([eE][+-]?[0-9]{1,3})?[[:space:]]*$' THEN (stats->>'{$field}')::numeric END < ?",
            [$timestamp],
        );
    }
}
