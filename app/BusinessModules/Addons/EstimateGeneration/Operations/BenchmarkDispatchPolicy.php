<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Operations;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationTrainingDataset;

final class BenchmarkDispatchPolicy
{
    public static function allows(string $datasetKind, string $status, bool $confirmedAcceptance, bool $canRunAcceptance): bool
    {
        if ($status !== EstimateGenerationTrainingDataset::STATUS_APPROVED
            || ! in_array($datasetKind, EstimateGenerationTrainingDataset::TYPES, true)) {
            return false;
        }

        return $datasetKind !== EstimateGenerationTrainingDataset::TYPE_ACCEPTANCE
            || ($confirmedAcceptance && $canRunAcceptance);
    }
}
