<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\BuildingModel;

use InvalidArgumentException;

final readonly class BuildingModelOperationContext
{
    public function __construct(
        public int $organizationId,
        public int $projectId,
        public int $sessionId,
        public string $inputVersion,
    ) {
        if ($organizationId < 1 || $projectId < 1 || $sessionId < 1) {
            throw new InvalidArgumentException('Building model scope identifiers must be positive.');
        }
        if (preg_match('/^sha256:[a-f0-9]{64}$/', $inputVersion) !== 1) {
            throw new InvalidArgumentException('Building model input version is invalid.');
        }
    }
}
