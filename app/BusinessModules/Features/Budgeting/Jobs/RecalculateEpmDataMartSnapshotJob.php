<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Jobs;

use App\BusinessModules\Features\Budgeting\Services\EpmDataMartRecalculationCoordinator;
use App\BusinessModules\Features\Budgeting\Services\EpmDataMartRecalculationService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Throwable;

final class RecalculateEpmDataMartSnapshotJob implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $tries = 3;
    public int $timeout = 1800;
    public bool $failOnTimeout = true;
    public int $uniqueFor = 1800;

    public function __construct(
        public int $runId,
    ) {
        $this->onQueue($this->queueName());
    }

    public function handle(EpmDataMartRecalculationService $service): void
    {
        $service->recalculateRun($this->runId, false);
    }

    public function failed(Throwable $throwable): void
    {
        try {
            app(EpmDataMartRecalculationCoordinator::class)->markFailedById($this->runId, $throwable);
        } catch (Throwable $statusThrowable) {
            Log::warning('budgeting.epm_data_mart.failed_status_update_failed', [
                'run_id' => $this->runId,
                'exception_class' => $statusThrowable::class,
            ]);
        }
    }

    public function uniqueId(): string
    {
        return (string) $this->runId;
    }

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('epm-data-mart-run-' . $this->runId))
                ->releaseAfter(60)
                ->expireAfter($this->timeout + 60),
        ];
    }

    private function queueName(): string
    {
        $queue = config('budgeting.epm_data_mart.queue', 'epm-data-mart');

        return is_string($queue) && trim($queue) !== '' ? $queue : 'epm-data-mart';
    }
}
