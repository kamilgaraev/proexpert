<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\Fgiscs;

use App\BusinessModules\Addons\EstimateGeneration\Normatives\Enums\RegionalPriceStatus;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Models\EstimateRegionalPriceVersion;

class RegionalPriceImportLifecycleService
{
    public function __construct(
        private readonly RegionalPriceQualityService $qualityService,
        private readonly RegionalPriceActivationService $activationService,
    ) {}

    /**
     * @return array{ready:bool,activated:bool,quality:array<string,mixed>|null,activation_id:int|null}
     */
    public function finalize(
        EstimateRegionalPriceVersion $version,
        bool $activationRequested,
        bool $buildingResourcesRequired,
    ): array {
        $metadata = array_merge($version->metadata ?? [], [
            'activation_requested' => $activationRequested || (bool) ($version->metadata['activation_requested'] ?? false),
            'building_resources_required' => $buildingResourcesRequired,
        ]);
        $workerSalaryImported = (bool) ($metadata['worker_salary_imported'] ?? false);
        $buildingResourcesImported = (bool) ($metadata['building_resources_imported'] ?? false);
        $ready = $workerSalaryImported && (! $buildingResourcesRequired || $buildingResourcesImported);

        if (! $ready) {
            $version->update([
                'status' => $workerSalaryImported ? RegionalPriceStatus::CHECKED->value : RegionalPriceStatus::PARSED->value,
                'metadata' => array_merge($metadata, [
                    'import_lifecycle' => [
                        'ready' => false,
                        'waiting_for' => $workerSalaryImported ? ['building_resources'] : ['worker_salary'],
                    ],
                ]),
            ]);

            return ['ready' => false, 'activated' => false, 'quality' => null, 'activation_id' => null];
        }

        $quality = $this->qualityService->checkCompleteVersion($version, $buildingResourcesRequired);

        if (! $quality['passed']) {
            $version->update([
                'status' => RegionalPriceStatus::FAILED->value,
                'errors_count' => max((int) $version->errors_count, count($quality['errors'])),
                'metadata' => array_merge($metadata, [
                    'complete_quality' => $quality,
                    'import_lifecycle' => ['ready' => false, 'waiting_for' => []],
                ]),
            ]);

            return ['ready' => false, 'activated' => false, 'quality' => $quality, 'activation_id' => null];
        }

        $version->update([
            'status' => RegionalPriceStatus::CHECKED->value,
            'errors_count' => 0,
            'metadata' => array_merge($metadata, [
                'complete_quality' => $quality,
                'import_lifecycle' => ['ready' => true, 'waiting_for' => []],
            ]),
        ]);

        $shouldActivate = (bool) $metadata['activation_requested'];
        $activation = $shouldActivate ? $this->activationService->activate($version) : null;

        return [
            'ready' => true,
            'activated' => $activation !== null,
            'quality' => $quality,
            'activation_id' => $activation?->id,
        ];
    }
}
