<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Pipeline\Stages;

use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\NormativeMatchingWorkflow;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\NormativeWorkIntentFactory;
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

    public function __construct(
        private ResourceAssemblyService $matcher,
        private NormativeMatchingWorkflow $workflow,
        private NormativeWorkIntentFactory $intentFactory,
        private StageResultFactory $results,
    ) {}

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
        $datasetVersion = is_array($regionalContext) ? ($regionalContext['normative_dataset_version'] ?? null) : null;
        $applicabilityDate = is_array($regionalContext) ? ($regionalContext['applicability_date'] ?? null) : null;
        $rerankRequested = is_array($regionalContext) && ($regionalContext['normative_rerank_requested'] ?? false) === true;
        foreach ($data['local_estimates'] as $localIndex => $localEstimate) {
            foreach ($localEstimate['sections'] as $sectionIndex => $section) {
                foreach ($section['work_items'] as $itemIndex => $workItem) {
                    $decisionContext = [
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
                        'applicability_date' => $applicabilityDate,
                        'progress_callback' => $heartbeat === null ? null : static fn () => self::renewLease($heartbeat),
                        'heartbeat_callback' => $heartbeat === null ? null : static fn () => self::renewLease($heartbeat),
                    ];
                    if (! $this->requiresNormative($workItem)) {
                        $data['local_estimates'][$localIndex]['sections'][$sectionIndex]['work_items'][$itemIndex] = $this->matcher->enrich([$workItem], $decisionContext)[0];

                        continue;
                    }
                    if (! is_string($datasetVersion) || $datasetVersion === '') {
                        $data['local_estimates'][$localIndex]['sections'][$sectionIndex]['work_items'][$itemIndex] = $this->blocked($workItem, 'review_required', 'normative_dataset_not_pinned');

                        continue;
                    }
                    if (! is_string($applicabilityDate) || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $applicabilityDate) !== 1) {
                        $data['local_estimates'][$localIndex]['sections'][$sectionIndex]['work_items'][$itemIndex] = $this->blocked($workItem, 'review_required', 'normative_applicability_date_not_pinned');

                        continue;
                    }
                    $intent = $this->intentFactory->intent($workItem, $decisionContext, $datasetVersion);
                    $decision = $this->intentFactory->decision($workItem, $decisionContext);
                    $result = $this->workflow->match($intent, $decision, $rerankRequested);
                    if (in_array($result->status, ['review_required', 'unavailable'], true)) {
                        $data['local_estimates'][$localIndex]['sections'][$sectionIndex]['work_items'][$itemIndex] = $this->blocked($workItem, $result->status, $result->blockingIssues[0] ?? 'normative_not_found');

                        continue;
                    }
                    $selected = $result->selectedCandidateId();
                    $enriched = $this->matcher->enrich([$workItem], [...$decisionContext, 'selected_norm_id' => $selected, 'normative_dataset_version' => $datasetVersion]);
                    $enriched[0]['normative_retrieval'] = [
                        'status' => $result->status,
                        'dataset_version' => $datasetVersion,
                        'scoring_version' => $result->candidateSet->lexicalAlgorithmVersion,
                        'reranker_version' => $result->rerankResult?->schemaVersion,
                        'blocking_issues' => [],
                    ];
                    $data['local_estimates'][$localIndex]['sections'][$sectionIndex]['work_items'][$itemIndex] = $enriched[0];
                }
            }
        }

        return $this->results->make($context, $this->stage(), ['local_estimates' => $data['local_estimates']]);
    }

    private function blocked(array $workItem, string $status, string $issue): array
    {
        return [...$workItem,
            'pricing_status' => 'not_calculated', 'pricing_blocker' => $issue,
            'normative_retrieval' => ['status' => $status, 'blocking_issues' => [$issue]],
            'validation_flags' => array_values(array_unique([...(is_array($workItem['validation_flags'] ?? null) ? $workItem['validation_flags'] : []), 'requires_normative_review', $issue])),
        ];
    }

    private function requiresNormative(array $workItem): bool
    {
        return ($workItem['skip_normative_matching'] ?? false) !== true
            && ! in_array((string) ($workItem['item_type'] ?? 'priced_work'), ['operation', 'resource_note', 'review_note', 'quantity_review'], true);
    }
}
