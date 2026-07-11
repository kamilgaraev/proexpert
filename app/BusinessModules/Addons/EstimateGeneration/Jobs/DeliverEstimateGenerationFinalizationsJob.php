<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Jobs;

use App\BusinessModules\Addons\EstimateGeneration\Pipeline\DeliverFinalization;
use DateTimeImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class DeliverEstimateGenerationFinalizationsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct()
    {
        $this->onConnection(GenerateEstimateDraftJob::CONNECTION);
        $this->onQueue(GenerateEstimateDraftJob::QUEUE);
    }

    public function handle(DeliverFinalization $delivery): void
    {
        for ($handled = 0; $handled < 100 && $delivery->one(new DateTimeImmutable); $handled++) {
        }
    }
}
