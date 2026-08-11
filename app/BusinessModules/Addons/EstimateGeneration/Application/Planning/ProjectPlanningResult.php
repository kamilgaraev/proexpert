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
        public string $status = 'current',
    ) {
        if (! in_array($status, ['current', 'unresolved', 'stale'], true)) {
            throw new InvalidArgumentException('Project planning status is invalid.');
        }
        foreach ($recommendations as $recommendation) {
            if (! $recommendation instanceof TechnologyRecommendation) {
                throw new InvalidArgumentException('Project planning recommendation is invalid.');
            }
        }
    }

    public function isReadyForCompleteness(): bool
    {
        return $this->status === 'current';
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
            $this->status,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
    }
}
