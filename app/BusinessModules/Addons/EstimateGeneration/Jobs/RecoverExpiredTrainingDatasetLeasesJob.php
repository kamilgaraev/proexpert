<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Jobs;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationTrainingDataset;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

final class RecoverExpiredTrainingDatasetLeasesJob implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public function handle(): void
    {
        EstimateGenerationTrainingDataset::query()
            ->where('status', EstimateGenerationTrainingDataset::STATUS_PROCESSING)
            ->where('processing_lease_expires_at', '<=', now())
            ->orderBy('id')->limit(100)->get()->each(function (EstimateGenerationTrainingDataset $dataset): void {
                $recovered = EstimateGenerationTrainingDataset::query()->whereKey($dataset->id)
                    ->where('status', EstimateGenerationTrainingDataset::STATUS_PROCESSING)
                    ->where('processing_token', $dataset->processing_token)
                    ->where('processing_lease_expires_at', '<=', now())->update([
                        'status' => EstimateGenerationTrainingDataset::STATUS_DRAFT,
                        'processing_token' => null, 'processing_lease_expires_at' => null,
                        'error_message' => 'training_dataset_processing_lease_expired',
                    ]);
                if ($recovered === 1) {
                    try {
                        ProcessEstimateGenerationTrainingDatasetJob::dispatch((int) $dataset->id);
                    } catch (\Throwable $exception) {
                        EstimateGenerationTrainingDataset::query()->whereKey($dataset->id)
                            ->where('status', EstimateGenerationTrainingDataset::STATUS_DRAFT)
                            ->update([
                                'status' => EstimateGenerationTrainingDataset::STATUS_PROCESSING,
                                'processing_token' => $dataset->processing_token,
                                'processing_lease_expires_at' => now()->subSecond(),
                            ]);
                        throw $exception;
                    }
                }
            });

        EstimateGenerationTrainingDataset::query()
            ->where('status', EstimateGenerationTrainingDataset::STATUS_DRAFT)
            ->whereNotNull('queued_at')->orderBy('id')->limit(100)->pluck('id')
            ->each(static fn (int $datasetId) => ProcessEstimateGenerationTrainingDatasetJob::dispatch($datasetId));
    }
}
