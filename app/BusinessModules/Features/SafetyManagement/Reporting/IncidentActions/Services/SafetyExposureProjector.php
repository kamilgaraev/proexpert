<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\SafetyManagement\Reporting\IncidentActions\Services;

use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use App\BusinessModules\Features\SafetyManagement\Reporting\IncidentActions\Models\SafetyExposureDay;
use App\BusinessModules\Features\SafetyManagement\Reporting\IncidentActions\Models\SafetySite;
use DateTimeInterface;
use DomainException;

final readonly class SafetyExposureProjector
{
    public function project(
        int $organizationId,
        int $projectId,
        int $siteId,
        DateTimeInterface $date,
        string $exposureHours,
        int $personShifts,
        string $sourceCode,
        string $sourceWatermark,
        bool $complete,
    ): SafetyExposureDay {
        $siteExists = SafetySite::query()
            ->whereKey($siteId)
            ->where('organization_id', $organizationId)
            ->where('project_id', $projectId)
            ->where('is_active', true)
            ->exists();
        if (! $siteExists || preg_match('/^(0|[1-9][0-9]*)(?:\.[0-9]{1,4})?$/D', $exposureHours) !== 1 || $personShifts < 0) {
            throw new DomainException('REPORT_SOURCE_UNAVAILABLE');
        }

        $payload = [
            'complete' => $complete,
            'exposure_date' => $date->format('Y-m-d'),
            'exposure_hours' => $exposureHours,
            'organization_id' => $organizationId,
            'person_shifts' => $personShifts,
            'project_id' => $projectId,
            'safety_site_id' => $siteId,
            'source_code' => $sourceCode,
            'source_watermark' => $sourceWatermark,
        ];

        return SafetyExposureDay::query()->updateOrCreate(
            [
                'organization_id' => $organizationId,
                'safety_site_id' => $siteId,
                'exposure_date' => $date->format('Y-m-d'),
            ],
            $payload + [
                'source_hash' => hash('sha256', CanonicalJson::encode($payload)),
                'projected_at' => now(),
            ],
        );
    }
}
