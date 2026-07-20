<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\Fgiscs;

use RuntimeException;

final class FgiscsRegionalPriceSynchronizationException extends RuntimeException
{
    public const WORKER_COMPONENT_FAILED = 'worker_component_failed';

    public const BUILDING_COMPONENT_FAILED = 'building_component_failed';

    public const FINAL_VERSION_NOT_ACTIVE = 'final_version_not_active';

    public function __construct(
        public readonly string $failureCode,
        public readonly ?string $workerStatus = null,
        public readonly ?int $workerVersionId = null,
        public readonly ?string $buildingStatus = null,
        public readonly ?int $buildingVersionId = null,
        public readonly ?string $finalStatus = null,
        public readonly ?int $finalVersionId = null,
    ) {
        parent::__construct($failureCode);
    }

    /** @return array<string, int|string|null> */
    public function safeContext(): array
    {
        return [
            'failure_code' => $this->failureCode,
            'worker_status' => $this->workerStatus,
            'worker_version_id' => $this->workerVersionId,
            'building_status' => $this->buildingStatus,
            'building_version_id' => $this->buildingVersionId,
            'final_status' => $this->finalStatus,
            'final_version_id' => $this->finalVersionId,
        ];
    }
}
