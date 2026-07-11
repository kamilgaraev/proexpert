<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Jobs;

use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DispatchDocumentProcessingUnits;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\RecoverExhaustedDocumentUnits;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;

final class RecoverEstimateGenerationUnitsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public function middleware(): array
    {
        return [(new WithoutOverlapping('estimate-generation:recover-units'))->expireAfter(180)];
    }

    public function handle(DispatchDocumentProcessingUnits $dispatcher, RecoverExhaustedDocumentUnits $exhausted): void
    {
        $dispatcher->recover();
        $exhausted->handle();
    }
}
