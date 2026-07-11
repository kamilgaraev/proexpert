<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Generation;

use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\EstimateGenerationMutationPolicy;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\SessionActionResult;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateGenerationOrchestrator;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\DocumentGenerationReadinessService;

final class AnalyzeEstimateGenerationSession
{
    public function __construct(
        private EstimateGenerationMutationPolicy $policy,
        private DocumentGenerationReadinessService $readiness,
        private EstimateGenerationOrchestrator $orchestrator,
    ) {}

    public function handle(EstimateGenerationSession $session, int $expectedVersion): SessionActionResult
    {
        $this->policy->analyze($session, $expectedVersion);
        $readiness = $this->readiness->evaluate($session->load('documents'));
        if (! $readiness['can_analyze']) {
            return new SessionActionResult($session, false, $readiness['blocking_message_key'] ?? 'estimate_generation.documents_processing', 409, ['documents_summary' => $readiness['summary']]);
        }
        if (! $this->hasInput($session, $readiness['summary'])) {
            return new SessionActionResult($session, false, 'estimate_generation.input_required', 422, ['documents_summary' => $readiness['summary']]);
        }

        return new SessionActionResult($this->orchestrator->analyze($session), true, 'estimate_generation.analysis_completed', 200);
    }

    /** @param array<string, mixed> $summary */
    private function hasInput(EstimateGenerationSession $session, array $summary): bool
    {
        return trim((string) ($session->input_payload['description'] ?? '')) !== ''
            || (int) ($summary['ready_count'] ?? 0) > 0;
    }
}
