<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Generation;

use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\AdvanceEstimateGeneration;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\EstimateGenerationMutationPolicy;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateGenerationOrchestrator;
use Illuminate\Support\Str;

final class RebuildGeneratedSection
{
    public function __construct(
        private EstimateGenerationMutationPolicy $policy,
        private AdvanceEstimateGeneration $advance,
        private EstimateGenerationOrchestrator $orchestrator,
    ) {}

    public function handle(EstimateGenerationSession $session, int $expectedVersion, string $sectionKey): EstimateGenerationSession
    {
        $this->policy->review($session, $expectedVersion);
        $session = $this->advance->generationStarted($session, (string) Str::uuid());

        return $this->orchestrator->rebuildSection($session, $sectionKey);
    }
}
