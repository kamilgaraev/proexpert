<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services\Training;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationTrainingDataset;
use DomainException;

final class TrainingDatasetTrustPolicy
{
    public function canTrain(EstimateGenerationTrainingDataset $dataset): bool
    {
        return $this->isApprovedDevelopment($dataset);
    }

    public function canTuneRules(EstimateGenerationTrainingDataset $dataset): bool
    {
        return $this->isApprovedDevelopment($dataset);
    }

    public function canBenchmark(EstimateGenerationTrainingDataset $dataset): bool
    {
        return $dataset->status === EstimateGenerationTrainingDataset::STATUS_APPROVED
            && in_array($dataset->dataset_type, EstimateGenerationTrainingDataset::TYPES, true);
    }

    public function assertCanProcess(EstimateGenerationTrainingDataset $dataset): void
    {
        if ($dataset->dataset_type !== EstimateGenerationTrainingDataset::TYPE_DEVELOPMENT) {
            throw new DomainException('training_dataset_not_eligible_for_learning');
        }

        if (! in_array($dataset->status, [
            EstimateGenerationTrainingDataset::STATUS_DRAFT,
            EstimateGenerationTrainingDataset::STATUS_PROCESSING,
        ], true)) {
            throw new DomainException('training_dataset_is_immutable');
        }
    }

    private function isApprovedDevelopment(EstimateGenerationTrainingDataset $dataset): bool
    {
        return $dataset->dataset_type === EstimateGenerationTrainingDataset::TYPE_DEVELOPMENT
            && $dataset->status === EstimateGenerationTrainingDataset::STATUS_APPROVED;
    }
}
