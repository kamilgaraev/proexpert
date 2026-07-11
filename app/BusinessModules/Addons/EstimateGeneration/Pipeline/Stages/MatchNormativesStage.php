<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Pipeline\Stages;

use App\BusinessModules\Addons\EstimateGeneration\Pipeline\LeaseAwarePipelineStage;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineContext;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineLeaseHeartbeat;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineStageResult;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\ProcessingStage;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\RenewsPipelineLease;
use App\BusinessModules\Addons\EstimateGeneration\Services\ResourceAssemblyService;

final readonly class MatchNormativesStage implements LeaseAwarePipelineStage
{
    use RenewsPipelineLease;

    public function __construct(private ResourceAssemblyService $matcher, private StageResultFactory $results) {}

    public function stage(): ProcessingStage
    {
        return ProcessingStage::MatchNormatives;
    }

    public function execute(PipelineContext $context): PipelineStageResult
    {
        return $this->executeStage($context);
    }

    public function executeWithHeartbeat(PipelineContext $context, PipelineLeaseHeartbeat $heartbeat): PipelineStageResult
    {
        self::renewLease($heartbeat);
        $result = $this->executeStage($context, $heartbeat);
        self::renewLease($heartbeat);

        return $result;
    }

    private function executeStage(PipelineContext $context, ?PipelineLeaseHeartbeat $heartbeat = null): PipelineStageResult
    {
        $data = $context->priorOutputs->payload(ProcessingStage::PlanWorkItems);
        $regionalContext = $data['regional_context'] ?? [];
        foreach ($data['local_estimates'] as $localIndex => $localEstimate) {
            foreach ($localEstimate['sections'] as $sectionIndex => $section) {
                $data['local_estimates'][$localIndex]['sections'][$sectionIndex]['work_items'] = $this->matcher->enrich(
                    $section['work_items'],
                    [
                        'organization_id' => $context->organizationId,
                        'project_id' => $context->projectId,
                        'session_id' => $context->sessionId,
                        'checkpoint_claim_token' => $context->claimToken,
                        'input_version' => $context->priorOutputs->require(ProcessingStage::PlanWorkItems)->version,
                        'logical_attempt' => $context->stageAttempt,
                        'scope_type' => $localEstimate['scope_type'] ?? null,
                        'local_estimate_title' => $localEstimate['title'] ?? null,
                        'section_title' => $section['title'] ?? null,
                        'source_refs' => $section['source_refs'] ?? $localEstimate['source_refs'] ?? [],
                        'regional_context' => $regionalContext,
                        'progress_callback' => $heartbeat === null ? null : static fn () => self::renewLease($heartbeat),
                        'heartbeat_callback' => $heartbeat === null ? null : static fn () => self::renewLease($heartbeat),
                    ],
                );
            }
        }

        return $this->results->make($context, $this->stage(), ['local_estimates' => $data['local_estimates']]);
    }
}
