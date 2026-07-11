<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Generation;

use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\AdvanceEstimateGeneration;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\EstimateGenerationMutationPolicy;
use App\BusinessModules\Addons\EstimateGeneration\Jobs\GenerateEstimateDraftJob;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Observability\FailureExecutionSnapshot;
use Illuminate\Support\Str;

final class RebuildGeneratedSection
{
    public function __construct(
        private EstimateGenerationMutationPolicy $policy,
        private AdvanceEstimateGeneration $advance,
    ) {}

    public function handle(EstimateGenerationSession $session, int $expectedVersion, string $sectionKey): EstimateGenerationSession
    {
        $this->policy->review($session, $expectedVersion);
        $session = $this->advance->update($session, [$session->status], [
            'input_payload' => [...($session->input_payload ?? []), 'rebuild_section_key' => $sectionKey],
        ]);
        $attemptId = (string) Str::uuid();
        $session = $this->advance->generationStarted($session, $attemptId);
        GenerateEstimateDraftJob::dispatch(
            (int) $session->getKey(),
            (int) $session->state_version,
            $attemptId,
            FailureExecutionSnapshot::capture($session, 'rebuild_generated_section', $attemptId),
        )->onQueue(GenerateEstimateDraftJob::QUEUE)->afterCommit();

        return $session;
    }
}
