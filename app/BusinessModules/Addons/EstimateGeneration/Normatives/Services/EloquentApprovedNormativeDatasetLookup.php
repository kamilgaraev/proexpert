<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Normatives\Services;

use App\BusinessModules\Addons\EstimateGeneration\Normatives\Enums\EstimateImportStatus;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Enums\EstimateSourceType;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Models\EstimateDatasetVersion;

final class EloquentApprovedNormativeDatasetLookup implements ApprovedNormativeDatasetLookup
{
    public function approved(string $version): bool
    {
        return EstimateDatasetVersion::query()->where('source_type', EstimateSourceType::FSNB_2022->value)
            ->where('status', EstimateImportStatus::PARSED->value)->where('version_key', $version)->exists();
    }
}
