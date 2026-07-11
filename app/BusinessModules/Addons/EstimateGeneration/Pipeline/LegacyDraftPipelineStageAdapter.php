<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Pipeline;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateGenerationOrchestrator;
use RuntimeException;

final readonly class LegacyDraftPipelineStageAdapter implements PipelineStage
{
    public function __construct(private EstimateGenerationOrchestrator $orchestrator) {}

    public function stage(): ProcessingStage
    {
        return ProcessingStage::BuildDraft;
    }

    public function execute(PipelineContext $context): PipelineStageResult
    {
        $session = EstimateGenerationSession::query()->find($context->sessionId);
        if (! $session instanceof EstimateGenerationSession) {
            throw new RuntimeException('Estimate generation session is unavailable.');
        }

        $generated = $this->orchestrator->generate($session);

        return new PipelineStageResult(
            stage: ProcessingStage::BuildDraft,
            outputVersion: 'session-state-'.$generated->state_version,
            metrics: ['state_version' => (int) $generated->state_version],
        );
    }
}
