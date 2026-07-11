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

        self::assertSafeVersion($inputVersion, 'input');
    }

    private static function assertSafeVersion(string $version, string $name): void
    {
        if (trim($version) === '' || preg_match('/[\x00-\x1F\x7F]/', $version) === 1) {
            throw new InvalidArgumentException("Pipeline {$name} version must be a non-empty safe string.");
        }
    }
}
