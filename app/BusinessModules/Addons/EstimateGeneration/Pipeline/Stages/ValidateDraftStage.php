<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Pipeline\Stages;

use App\BusinessModules\Addons\EstimateGeneration\Analysis\Audit\ApplyComposerCorrectionCycle;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Audit\EstimateAuditInputFactory;
use App\BusinessModules\Addons\EstimateGeneration\Application\Generation\BuildMostEstimateDraft;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\UniversalDraftProjector;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\UniversalEvaluationBuilder;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\UniversalReviewSummaryProjector;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\LeaseAwarePipelineStage;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineContext;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineStageResult;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\ProcessingStage;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\RenewsPipelineLease;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateValidationService;
use App\BusinessModules\Addons\EstimateGeneration\Services\Quality\DraftReadinessProjector;

final readonly class ValidateDraftStage implements LeaseAwarePipelineStage
{
    use RenewsPipelineLease;

    public function __construct(
        private EstimateValidationService $validation,
        private DraftReadinessProjector $readiness,
        private StageResultFactory $results,
        private ApplyComposerCorrectionCycle $auditCycles,
        private EstimateAuditInputFactory $auditInputs,
        private BuildMostEstimateDraft $artifacts = new BuildMostEstimateDraft,
        private UniversalEvaluationBuilder $evaluations = new UniversalEvaluationBuilder,
        private ?ProjectModelRepository $models = null,
        private UniversalReviewSummaryProjector $universalReviews = new UniversalReviewSummaryProjector,
    ) {}

    public function stage(): ProcessingStage
    {
        return ProcessingStage::ValidateDraft;
    }

    public function execute(PipelineContext $context): PipelineStageResult
    {
        $input = $context->priorOutputs->payload(ProcessingStage::BuildDraft);
        if (($input['draft']['generation_contract'] ?? null) === UniversalDraftProjector::CONTRACT) {
            return $this->validateUniversal($context, $input['draft']);
        }
        $draft = $this->validation->validate($input['draft']);
        $draft = $this->readiness->project($draft);
        $audit = $this->auditCycles->apply($this->auditInputs->capture(
            $context->organizationId,
            $context->projectId,
            $context->sessionId,
            $draft,
            new \App\BusinessModules\Addons\EstimateGeneration\Observability\AiSessionWireScope($context->stateVersion, $context->generationAttemptId, $context->creditOperationId),
        ));
        $draft = $this->readiness->project($this->validation->validate($audit['draft']));
        $draft = $this->artifacts->seal($draft);
        $blockingCodes = array_column((array) ($draft['readiness_summary']['blocking_issues'] ?? []), 'code');
        $requiresAuditReview = ($audit['audit']['status'] ?? null) === 'review_required';

        return $this->results->make($context, $this->stage(), [
            'draft' => $draft,
            'requires_review' => $blockingCodes !== [] || $requiresAuditReview,
        ]);
    }

    private function validateUniversal(PipelineContext $context, array $draft): PipelineStageResult
    {
        $hasWork = false;
        foreach ($draft['local_estimates'] ?? [] as $estimate) {
            foreach ($estimate['sections'] ?? [] as $section) {
                foreach ($section['work_items'] ?? [] as $item) {
                    if (! in_array($item['item_type'] ?? 'priced_work', ['operation', 'resource_note', 'review_note', 'quantity_review'], true)) {
                        $hasWork = true;
                    }
                }
            }
        }
        if (! $hasWork && ($draft['estimate_composition']['request_info'] ?? []) !== []) {
            // No audit/model call and no fabricated zero estimate for unanswered source questions.
            $draft['local_estimates'] = [];
            $draft['source_info_only'] = true;
            $draft['evaluation_result'] = null;
            $draft['is_complete'] = false;
            $draft['stage6_status'] = 'source_info_required';
            $draft = $this->artifacts->seal($this->universalReviews->project($draft));

            return $this->results->make($context, $this->stage(), ['draft' => $draft, 'requires_review' => true]);
        }
        if ($this->models === null) {
            throw new \DomainException('universal_project_snapshot_unavailable');
        }
        $audit = $this->auditCycles->apply($this->auditInputs->capture($context->organizationId, $context->projectId, $context->sessionId,
            $draft, new \App\BusinessModules\Addons\EstimateGeneration\Observability\AiSessionWireScope($context->stateVersion, $context->generationAttemptId, $context->creditOperationId)));
        $draft = $audit['draft'];
        $snapshot = $this->models->snapshot($context->organizationId, $context->projectId, $context->sessionId);
        $decisions = $this->models->decisionsForSelectedFacts($context->organizationId, $context->projectId, $context->sessionId, array_column($snapshot->facts, 'id'));
        $draft['evaluation_result'] = $this->evaluations->build($draft, $snapshot, $decisions);
        $draft['is_complete'] = false;
        $draft['stage6_status'] = 'scenario_confirmation_required';
        $draft = $this->universalReviews->project($draft);
        $draft = $this->artifacts->seal($draft);

        return $this->results->make($context, $this->stage(), ['draft' => $draft, 'requires_review' => true]);
    }
}
