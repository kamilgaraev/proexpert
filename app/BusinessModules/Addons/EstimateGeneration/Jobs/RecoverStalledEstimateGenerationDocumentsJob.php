<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Jobs;

use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\RecoverStalledEstimateGenerationDocuments;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;

final class RecoverStalledEstimateGenerationDocumentsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct()
    {
        $this->onConnection(RecoverEstimateGenerationUnitsJob::CONNECTION);
        $this->onQueue(RecoverEstimateGenerationUnitsJob::QUEUE);
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('estimate-generation:recover-stalled-documents'))->expireAfter(180)];
    }

    public function handle(RecoverStalledEstimateGenerationDocuments $recovery): void
    {
        $recovery->handle();
    }
}
