<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Jobs;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationTrainingDataset;
use App\BusinessModules\Addons\EstimateGeneration\Services\Training\EstimateGenerationTrainingDatasetService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

final class ProcessEstimateGenerationTrainingDatasetJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public const CONNECTION = 'redis_estimate_generation';

    public const QUEUE = 'estimate-generation';

    public int $tries = 2;

    public int $timeout = 1800;

    public function __construct(private readonly int $datasetId)
    {
        $this->onConnection(self::CONNECTION);
        $this->onQueue(self::QUEUE);
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping("estimate-generation-training-dataset-{$this->datasetId}"))
                ->releaseAfter(120)
                ->expireAfter($this->timeout + 300),
            (new WithoutOverlapping('estimate-generation-training:' . $this->rateLimitKey()))
                ->shared()
                ->releaseAfter(180)
                ->expireAfter($this->timeout + 300),
            new RateLimited('estimate-generation-training-datasets'),
        ];
    }

    public function rateLimitKey(): string
    {
        $organizationId = EstimateGenerationTrainingDataset::query()
            ->whereKey($this->datasetId)
            ->value('organization_id');

        return $organizationId !== null
            ? 'organization:' . (int) $organizationId
            : 'dataset:' . $this->datasetId;
    }

    public function handle(EstimateGenerationTrainingDatasetService $service): void
    {
        $dataset = EstimateGenerationTrainingDataset::query()->findOrFail($this->datasetId);
        $service->process($dataset);
    }
}
