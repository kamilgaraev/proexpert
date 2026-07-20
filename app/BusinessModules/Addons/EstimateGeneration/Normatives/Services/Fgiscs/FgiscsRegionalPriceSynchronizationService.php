<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\Fgiscs;

use App\BusinessModules\Addons\EstimateGeneration\Normatives\Enums\RegionalPriceStatus;

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
        $this->assertComponentSucceeded($workerSalary, 'worker_salary', $workerSalary);
        $buildingResources = $this->buildingResourceService->syncTatarstan(
            bucket: $bucket,
            periodId: $periodId,
            force: $force,
            withSplitForm: $withSplitForm,
            progress: $progress,
        );
        $this->assertComponentSucceeded($buildingResources, 'building_resources', $workerSalary, $buildingResources);
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
            $this->assertComponentSucceeded($final, 'worker_salary', $final, $buildingResources, $final);
        }

        if (($final['status'] ?? null) !== RegionalPriceStatus::ACTIVE->value) {
            throw $this->failure(
                FgiscsRegionalPriceSynchronizationException::FINAL_VERSION_NOT_ACTIVE,
                $workerSalary,
                $buildingResources,
                $final,
            );
        }

        return array_merge($final, [
            'worker_salary_result' => $workerSalary,
            'building_resources_result' => $buildingResources,
        ]);
    }

    /** @param array<string, mixed> $result */
    private function assertComponentSucceeded(
        array $result,
        string $component,
        ?array $workerSalary = null,
        ?array $buildingResources = null,
        ?array $final = null,
    ): void {
        if (in_array($result['status'] ?? null, [
            RegionalPriceStatus::FAILED->value,
            RegionalPriceStatus::UNAVAILABLE->value,
        ], true)) {
            throw $this->failure(
                $component === 'worker_salary'
                    ? FgiscsRegionalPriceSynchronizationException::WORKER_COMPONENT_FAILED
                    : FgiscsRegionalPriceSynchronizationException::BUILDING_COMPONENT_FAILED,
                $workerSalary,
                $buildingResources,
                $final,
            );
        }
    }

    /**
     * @param  array<string, mixed>|null  $workerSalary
     * @param  array<string, mixed>|null  $buildingResources
     * @param  array<string, mixed>|null  $final
     */
    private function failure(
        string $failureCode,
        ?array $workerSalary,
        ?array $buildingResources,
        ?array $final,
    ): FgiscsRegionalPriceSynchronizationException {
        return new FgiscsRegionalPriceSynchronizationException(
            failureCode: $failureCode,
            workerStatus: $this->status($workerSalary),
            workerVersionId: $this->versionId($workerSalary),
            buildingStatus: $this->status($buildingResources),
            buildingVersionId: $this->versionId($buildingResources),
            finalStatus: $this->status($final),
            finalVersionId: $this->versionId($final),
        );
    }

    /** @param array<string, mixed>|null $result */
    private function status(?array $result): ?string
    {
        $status = $result['status'] ?? null;

        return is_string($status) && in_array($status, array_column(RegionalPriceStatus::cases(), 'value'), true)
            ? $status
            : null;
    }

    /** @param array<string, mixed>|null $result */
    private function versionId(?array $result): ?int
    {
        $versionId = $result['version_id'] ?? null;

        return is_int($versionId) && $versionId > 0 ? $versionId : null;
    }
}
