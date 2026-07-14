<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Jobs;

use App\BusinessModules\Addons\EstimateGeneration\Observability\AiBudgetGuard;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;

final class ReconcileAiBudgetReservationsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct()
    {
        $this->onConnection(RecoverEstimateGenerationUnitsJob::CONNECTION);
        $this->onQueue(RecoverEstimateGenerationUnitsJob::QUEUE);
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('estimate-generation:reconcile-ai-budgets'))->expireAfter(120)];
    }

    public function handle(AiBudgetGuard $budgets): void
    {
        $limit = max(1, min(1000, (int) config('estimate-generation.ai_budget.reconciliation_batch', 100)));
        $budgets->reconcileExpired($limit);
    }
}
