<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Normatives\Services;

use App\BusinessModules\Addons\EstimateGeneration\Normatives\Enums\EstimateImportStatus;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Enums\EstimateSourceType;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Models\EstimateDatasetVersion;

class NormativeContextPinResolver
{
    public function resolve(array $regionalContext): array
    {
        $version = $regionalContext['normative_dataset_version'] ?? null;
        $date = $this->date($regionalContext);
        if (! is_string($version) || $version === '' || $date === null) {
            return ['status' => 'review_required', 'blocking_issues' => [! is_string($version) || $version === '' ? 'normative_dataset_not_pinned' : 'normative_applicability_date_not_pinned']];
        }
        $approved = $this->approved($version);

        return $approved
            ? ['status' => 'pinned', 'dataset_version' => $version, 'applicability_date' => $date, 'identity_version' => hash('sha256', $version.'|'.$date)]
            : ['status' => 'review_required', 'blocking_issues' => ['normative_dataset_not_approved']];
    }

    protected function approved(string $version): bool
    {
        return EstimateDatasetVersion::query()
            ->where('source_type', EstimateSourceType::FSNB_2022->value)
            ->where('status', EstimateImportStatus::PARSED->value)
            ->where('version_key', $version)
            ->exists();
    }

    private function date(array $context): ?string
    {
        foreach (['applicability_date', 'estimate_date', 'business_date'] as $key) {
            $value = $context[$key] ?? null;
            if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) === 1) {
                return $value;
            }
        }
        $year = $context['year'] ?? null;
        $quarter = $context['quarter'] ?? null;
        if (is_int($year) && is_int($quarter) && $year >= 2000 && $quarter >= 1 && $quarter <= 4) {
            return sprintf('%04d-%02d-01', $year, (($quarter - 1) * 3) + 1);
        }

        return null;
    }
}
