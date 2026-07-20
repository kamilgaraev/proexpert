<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\Fgiscs;

use App\BusinessModules\Addons\EstimateGeneration\Normatives\Enums\RegionalPriceStatus;
use RuntimeException;

class FgiscsRegionalPriceSynchronizationService
{
    public function __construct(
        private readonly FgiscsRegionalPriceUpdateService $workerSalaryService,
        private readonly FgiscsBuildingResourcePriceUpdateService $buildingResourceService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function syncTatarstan(
        string $bucket,
        ?int $periodId = null,
        bool $force = false,
        bool $withSplitForm = true,
        ?callable $progress = null,
    ): array {
        $workerSalary = $this->workerSalaryService->syncTatarstan(
            bucket: $bucket,
            periodId: $periodId,
            latestOnly: true,
            force: $force,
            progress: $progress,
        );
        $this->assertComponentSucceeded($workerSalary, 'worker_salary');
        $buildingResources = $this->buildingResourceService->syncTatarstan(
            bucket: $bucket,
            periodId: $periodId,
            force: $force,
            withSplitForm: $withSplitForm,
            progress: $progress,
        );
        $this->assertComponentSucceeded($buildingResources, 'building_resources');
        $final = $buildingResources;

        if (($buildingResources['status'] ?? null) !== RegionalPriceStatus::ACTIVE->value
            && ($buildingResources['version_id'] ?? null) !== ($workerSalary['version_id'] ?? null)) {
            $final = $this->workerSalaryService->syncTatarstan(
                bucket: $bucket,
                periodId: $periodId,
                latestOnly: true,
                force: false,
                progress: $progress,
            );
            $this->assertComponentSucceeded($final, 'worker_salary');
        }

        if (($final['status'] ?? null) !== RegionalPriceStatus::ACTIVE->value) {
            throw new RuntimeException(sprintf(
                'Regional price synchronization did not activate a complete version: %s.',
                $this->resultSummary($final),
            ));
        }

        return array_merge($final, [
            'worker_salary_result' => $workerSalary,
            'building_resources_result' => $buildingResources,
        ]);
    }

    /** @param array<string, mixed> $result */
    private function assertComponentSucceeded(array $result, string $component): void
    {
        if (in_array($result['status'] ?? null, [
            RegionalPriceStatus::FAILED->value,
            RegionalPriceStatus::UNAVAILABLE->value,
        ], true)) {
            throw new RuntimeException(sprintf(
                'Regional price component synchronization failed: %s (%s).',
                $component,
                $this->resultSummary($result),
            ));
        }
    }

    /** @param array<string, mixed> $result */
    private function resultSummary(array $result): string
    {
        $metadata = is_array($result['metadata'] ?? null) ? $result['metadata'] : [];
        $parts = [
            'status='.(string) ($result['status'] ?? 'unknown'),
        ];

        foreach (['version_id', 'version_key', 'failure_code', 'reason'] as $key) {
            if (isset($result[$key]) && $result[$key] !== '') {
                $parts[] = $key.'='.(string) $result[$key];
            }
        }

        if (isset($metadata['building_resources_failure_code'])) {
            $parts[] = 'failure_code='.(string) $metadata['building_resources_failure_code'];
        }

        return implode(', ', $parts);
    }
}
