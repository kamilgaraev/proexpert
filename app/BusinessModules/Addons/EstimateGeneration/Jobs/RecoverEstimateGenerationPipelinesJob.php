<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Jobs;

use App\BusinessModules\Addons\EstimateGeneration\Application\Generation\RecoverEstimateGenerationPipelines;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class RecoverEstimateGenerationPipelinesJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 60;

    private const QUEUE = 'estimate-generation-recovery';

    public function __construct()
    {
        $this->onConnection(GenerateEstimateDraftJob::CONNECTION);
        $this->onQueue(self::QUEUE);
    }

    public function handle(RecoverEstimateGenerationPipelines $recovery): void
    {
        $recovery->handle();
    }
}
