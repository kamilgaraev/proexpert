<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Planning;

use App\BusinessModules\Addons\EstimateGeneration\Planning\CompletenessFinding;

final readonly class ProjectCompletenessProjectionResult
{
    public function __construct(
        public string $sourceVersion,
        public string $inputFingerprint,
        public string $catalogVersion,
        public string $catalogHash,
        public string $ruleCatalogVersion,
        public string $ruleCatalogHash,
        public array $findings,
        public array $limitations,
    ) {}

    public function fingerprint(): string
    {
        return hash('sha256', json_encode([
            $this->sourceVersion, $this->inputFingerprint, $this->catalogVersion, $this->catalogHash,
            $this->ruleCatalogVersion, $this->ruleCatalogHash,
            array_map(static fn (CompletenessFinding $finding): array => $finding->toArray(), $this->findings),
            $this->limitations,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }
}
