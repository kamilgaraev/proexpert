<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Jobs;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationTrainingDataset;
use App\BusinessModules\Addons\EstimateGeneration\Services\Training\EstimateGenerationTrainingDatasetService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

final class ProcessEstimateGenerationTrainingDatasetJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    public int $timeout = 1800;

    public function __construct(private readonly int $datasetId)
    {
        $this->onConnection('redis_estimate_generation');
        $this->onQueue('estimate-generation');
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping("estimate-generation-training-dataset-{$this->datasetId}"))
                ->expireAfter($this->timeout + 300),
        ];
    }

    public function handle(EstimateGenerationTrainingDatasetService $service): void
    {
        $dataset = EstimateGenerationTrainingDataset::query()->findOrFail($this->datasetId);
        $service->process($dataset);
    }
}
