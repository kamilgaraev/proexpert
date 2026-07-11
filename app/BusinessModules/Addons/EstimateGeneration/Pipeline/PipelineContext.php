<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Pipeline;

use InvalidArgumentException;

final readonly class PipelineContext
{
    public function __construct(
        public int $sessionId,
        public int $organizationId,
        public int $projectId,
        public int $stateVersion,
        public string $inputVersion,
    ) {
        if ($sessionId <= 0 || $organizationId <= 0 || $projectId <= 0) {
            throw new InvalidArgumentException('Pipeline identity values must be positive.');
        }

        if ($stateVersion < 0) {
            throw new InvalidArgumentException('Pipeline state version cannot be negative.');
        }

        PipelineVersionValidator::assertValid($inputVersion, 'input');
    }
}
