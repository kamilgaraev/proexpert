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

    public int $tries = 0;

    public int $maxExceptions = 8;

    /** @var array<int, int> */
    public array $backoff = [30, 60, 120, 240, 480];

    public int $timeout = 1800;

    public readonly \DateTimeImmutable $retryDeadline;

    public function __construct(public readonly int $datasetId, ?\DateTimeImmutable $retryDeadline = null)
    {
        $this->retryDeadline = $retryDeadline ?? new \DateTimeImmutable('+24 hours');
        $this->onConnection(self::CONNECTION);
        $this->onQueue(self::QUEUE);
    }

    public function uniqueId(): string
    {
        return (string) $this->datasetId;
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
            (new WithoutOverlapping('estimate-generation-training:'.$this->rateLimitKey()))
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
            ? 'organization:'.(int) $organizationId
            : 'dataset:'.$this->datasetId;
    }

    public function handle(EstimateGenerationTrainingDatasetService $service): void
    {
        $dataset = EstimateGenerationTrainingDataset::query()->findOrFail($this->datasetId);
        $service->process($dataset, (string) \Illuminate\Support\Str::uuid());
    }

    public function retryUntil(): \DateTimeInterface
    {
        return $this->retryDeadline;
    }
}
