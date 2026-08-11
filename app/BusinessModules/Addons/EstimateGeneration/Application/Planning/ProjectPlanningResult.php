<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Planning;

use App\BusinessModules\Addons\EstimateGeneration\Planning\TechnologyRecommendation;
use InvalidArgumentException;

final readonly class ProjectPlanningResult
{
    public function __construct(
        public string $sourceVersion,
        public string $inputFingerprint,
        public string $catalogVersion,
        public string $catalogHash,
        public array $recommendations,
        public array $limitations,
    ) {
        foreach ($recommendations as $recommendation) {
            if (! $recommendation instanceof TechnologyRecommendation) {
                throw new InvalidArgumentException('Project planning recommendation is invalid.');
            }
        }
    }

    public function fingerprint(): string
    {
        return hash('sha256', json_encode([
            $this->sourceVersion,
            $this->inputFingerprint,
            $this->catalogVersion,
            $this->catalogHash,
            $this->recommendations,
            $this->limitations,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
    }
}
