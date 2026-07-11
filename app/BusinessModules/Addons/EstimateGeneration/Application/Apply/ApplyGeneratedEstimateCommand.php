<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Apply;

final readonly class ApplyGeneratedEstimateCommand
{
    public function __construct(
        public int $sessionId,
        public int $organizationId,
        public int $projectId,
        public int $expectedStateVersion,
        public ?string $name = null,
        public ?string $type = null,
        public ?string $estimateDate = null,
    ) {}
}
