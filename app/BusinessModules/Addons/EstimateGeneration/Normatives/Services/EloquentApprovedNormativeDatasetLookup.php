<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Normatives\Services;

use App\BusinessModules\Addons\EstimateGeneration\Normatives\Enums\EstimateImportStatus;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Enums\EstimateSourceType;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Models\EstimateDatasetVersion;

final class EloquentApprovedNormativeDatasetLookup implements ApprovedNormativeDatasetLookup
{
    public function latestApprovedVersion(): ?string
    {
        $version = EstimateDatasetVersion::query()
            ->where('source_type', EstimateSourceType::FSNB_2022->value)
            ->where('status', EstimateImportStatus::PARSED->value)
            ->whereNotNull('finished_at')
            ->where('rows_imported', '>', 0)
            ->where('errors_count', 0)
            ->orderByDesc('finished_at')
            ->orderByDesc('id')
            ->value('version_key');

        return is_string($version) && $version !== '' ? $version : null;
    }
}
