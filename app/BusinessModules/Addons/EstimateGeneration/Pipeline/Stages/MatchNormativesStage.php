<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Pipeline\Stages;

use App\BusinessModules\Addons\EstimateGeneration\Normatives\DTO\AcceptedNormativeDecisionData;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\NormativeMatchingWorkflow;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\NormativeMatchTelemetry;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\NormativeWorkIntentFactory;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\PinnedNormativeCandidateFactory;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\LeaseAwarePipelineStage;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineContext;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineLeaseHeartbeat;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineStageResult;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\ProcessingStage;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\RenewsPipelineLease;
use App\BusinessModules\Addons\EstimateGeneration\Services\ResourceAssemblyService;
use Illuminate\Support\Facades\Log;

final readonly class MatchNormativesStage implements LeaseAwarePipelineStage
{
    use RenewsPipelineLease;

    public function __construct(
        private ResourceAssemblyService $matcher,
        private NormativeMatchingWorkflow $workflow,
        private NormativeWorkIntentFactory $intentFactory,
        private StageResultFactory $results,
        private PinnedNormativeCandidateFactory $pinnedCandidates = new PinnedNormativeCandidateFactory,
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
        $pin = is_array($data['normative_context_pin'] ?? null) ? $data['normative_context_pin'] : [];
        $datasetVersion = $pin['dataset_version'] ?? null;
        $applicabilityDate = $pin['applicability_date'] ?? null;
        if ($this->canLog()) {
            Log::info('estimate_generation.normative_pin_loaded', [
                'session_id' => $context->sessionId,
                'project_id' => $context->projectId,
                'status' => $pin['status'] ?? 'missing',
                'blocking_issues' => is_array($pin['blocking_issues'] ?? null) ? $pin['blocking_issues'] : [],
                'catalog_candidates_count' => is_array($pin['catalog_candidates'] ?? null) ? count($pin['catalog_candidates']) : 0,
                'dataset_version_present' => is_string($datasetVersion) && $datasetVersion !== '',
                'applicability_date_present' => is_string($applicabilityDate) && $applicabilityDate !== '',
                'regional_version_present' => is_int($regionalContext['estimate_regional_price_version_id'] ?? null),
                'region_present' => is_int($regionalContext['region_id'] ?? null),
                'price_zone_present' => is_int($regionalContext['price_zone_id'] ?? null),
                'period_present' => is_int($regionalContext['period_id'] ?? null),
                'regional_version_key_present' => is_string($regionalContext['version_key'] ?? null),
                'normative_dataset_version_present' => is_string($regionalContext['normative_dataset_version'] ?? null),
            ]);
        }
        $rerankRequested = is_array($regionalContext) && ($regionalContext['normative_rerank_requested'] ?? false) === true;
        $telemetry = new NormativeMatchTelemetry;
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
                        'object_type' => $data['object_profile']['object_type'] ?? null,
                        'source_refs' => $section['source_refs'] ?? $localEstimate['source_refs'] ?? [],
                        'regional_context' => $regionalContext,
                        'applicability_date' => $applicabilityDate,
                        'progress_callback' => $heartbeat === null ? null : static fn () => self::renewLease($heartbeat),
                        'heartbeat_callback' => $heartbeat === null ? null : static fn () => self::renewLease($heartbeat),
                    ];
                    if (! $this->requiresNormative($workItem)) {
                        $data['local_estimates'][$localIndex]['sections'][$sectionIndex]['work_items'][$itemIndex] = $workItem;

                        continue;
                    }
                    $telemetry->required();
                    if (! is_string($datasetVersion) || $datasetVersion === '') {
                        $telemetry->blocked('dataset_not_pinned');
                        $data['local_estimates'][$localIndex]['sections'][$sectionIndex]['work_items'][$itemIndex] = $this->blocked($workItem, 'review_required', 'normative_dataset_not_pinned');

                        continue;
                    }
                    if (! is_string($applicabilityDate) || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $applicabilityDate) !== 1) {
                        $telemetry->blocked('applicability_date_not_pinned');
                        $data['local_estimates'][$localIndex]['sections'][$sectionIndex]['work_items'][$itemIndex] = $this->blocked($workItem, 'review_required', 'normative_applicability_date_not_pinned');

                        continue;
                    }
                    $intent = $this->intentFactory->intent($workItem, $decisionContext, $datasetVersion);
                    $decision = $this->intentFactory->decision($workItem, $decisionContext);
                    $catalogCandidates = is_array($pin['catalog_candidates'] ?? null) ? $pin['catalog_candidates'] : [];
                    $pinnedCandidates = $this->pinnedCandidates->forWorkItem(
                        $catalogCandidates,
                        $workItem,
                        $intent->normativeSections,
                    );
                    if ($pinnedCandidates === []) {
                        $telemetry->missingPinnedCandidate();
                        $data['local_estimates'][$localIndex]['sections'][$sectionIndex]['work_items'][$itemIndex] = $this->blocked($workItem, 'review_required', 'normative_not_found');

                        continue;
                    }
                    $telemetry->pinnedCandidatesFound(count($pinnedCandidates));
                    $result = $this->workflow->match($intent, $decision, $rerankRequested, $pinnedCandidates);
                    if (in_array($result->status, ['review_required', 'unavailable'], true)) {
                        $reasonCodes = [];
                        foreach ($result->candidateSet->rejected as $rejected) {
                            $reasonCodes = [...$reasonCodes, ...$rejected->reasonCodes];
                        }
                        $telemetry->rejected(array_values(array_unique($reasonCodes)));
                        $data['local_estimates'][$localIndex]['sections'][$sectionIndex]['work_items'][$itemIndex] = $this->blocked($workItem, $result->status, $result->blockingIssues[0] ?? 'normative_not_found');

                        continue;
                    }
                    $catalogCandidate = null;
                    foreach ($catalogCandidates as $candidate) {
                        if (is_array($candidate) && ($candidate['candidate_id'] ?? null) === $result->selectedCandidateId()) {
                            $catalogCandidate = $candidate;
                            break;
                        }
                    }
                    if ($catalogCandidate === null) {
                        $telemetry->blocked('catalog_content_not_pinned');
                        $data['local_estimates'][$localIndex]['sections'][$sectionIndex]['work_items'][$itemIndex] = $this->blocked($workItem, 'review_required', 'normative_catalog_content_not_pinned');

                        continue;
                    }
                    $regionalPin = is_array($pin['regional_context'] ?? null) ? $pin['regional_context'] : $regionalContext;
                    $enriched = $this->matcher->assembleFromDecision(
                        $workItem,
                        AcceptedNormativeDecisionData::fromWorkflowResult($result, $catalogCandidate),
                        $regionalPin,
                    );
                    $enriched['normative_retrieval'] = [
                        'status' => $result->status,
                        'dataset_version' => $datasetVersion,
                        'scoring_version' => $result->candidateSet->scoringVersion,
                        'lexical_algorithm_version' => $result->candidateSet->lexicalAlgorithmVersion,
                        'semantic_index_version' => $result->candidateSet->semanticIndexVersion,
                        'reranker_version' => $result->rerankResult?->schemaVersion,
                        'blocking_issues' => [],
                    ];
                    $telemetry->matched();
                    $data['local_estimates'][$localIndex]['sections'][$sectionIndex]['work_items'][$itemIndex] = $enriched;
                }
            }
        }

        if ($this->canLog()) {
            Log::info('estimate_generation.normative_match_outcomes', [
                'session_id' => $context->sessionId,
                'project_id' => $context->projectId,
                ...$telemetry->context(),
            ]);
        }

        return $this->results->make($context, $this->stage(), [
            'regional_context' => $regionalContext,
            'local_estimates' => $data['local_estimates'],
        ]);
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

    private function canLog(): bool
    {
        $application = Log::getFacadeApplication();

        return $application !== null && $application->bound('log') && $application->bound('config');
    }
}
