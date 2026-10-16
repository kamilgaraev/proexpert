<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Sessions;

use App\BusinessModules\Addons\EstimateGeneration\Application\Generation\BuildMostEstimateDraft;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\UniversalEvaluationBuilder;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\EstimateGenerationEvent;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\EstimateGenerationWorkflow;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\StaleEstimateGenerationState;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\EvidenceAwarePipelineBaseInputVersionResolver;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineContext;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateGenerationPackagePersistenceService;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateValidationService;
use App\BusinessModules\Addons\EstimateGeneration\Services\Quality\DraftReadinessProjector;
use App\Models\User;
use Illuminate\Database\Connection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Reuses current facts and the selected normative sources; no model matching or auditing is invoked. */
final readonly class RecalculateLegacyEstimateDraft
{
    public function __construct(private Connection $database, private EstimateGenerationActionAuthorization $authorization,
        private ProjectModelRepository $models, private EvidenceAwarePipelineBaseInputVersionResolver $versions,
        private EvaluationInputFingerprint $inputs, private SaveEvaluationRevision $revisions,
        private EvaluationDraftArithmeticRecalculator $arithmetic, private EstimateGenerationPackagePersistenceService $packages,
        private EstimateValidationService $validation, private DraftReadinessProjector $readiness, private EstimateGenerationWorkflow $workflow,
        private UniversalEvaluationBuilder $results = new UniversalEvaluationBuilder, private BuildMostEstimateDraft $artifacts = new BuildMostEstimateDraft) {}

    public function handle(User $actor, int $projectId, int $sessionId, int $stateVersion, string $requestId): array
    {
        if (! Str::isUuid($requestId) || $stateVersion < 0) {
            throw ValidationException::withMessages(['request_id' => 'Укажите идентификатор пересчёта и версию состояния.']);
        }

        return $this->database->transaction(function () use ($actor, $projectId, $sessionId, $stateVersion, $requestId): array {
            $session = EstimateGenerationSession::query()->where('organization_id', $actor->current_organization_id)
                ->where('project_id', $projectId)->whereKey($sessionId)->lockForUpdate()->firstOrFail();
            $this->authorization->authorize($actor, $session, 'estimate_generation.review');
            $payload = $session->input_payload ?? [];
            $receipts = $payload['evaluation_recalculation_receipts'] ?? [];
            $fingerprint = hash('sha256', CanonicalPipelineJson::encode([$actor::class, (int) $actor->id, $stateVersion, $requestId]));
            if (isset($receipts[$requestId])) {
                if (! hash_equals($receipts[$requestId]['fingerprint'], $fingerprint)) {
                    throw ValidationException::withMessages(['request_id' => 'Идентификатор уже использован для другого пересчёта.']);
                }
                $receipt = $receipts[$requestId]['result'];
                $row = $this->database->table('estimate_generation_evaluation_revisions')->where('organization_id', $session->organization_id)
                    ->where('project_id', $projectId)->where('session_id', $sessionId)->where('public_id', $receipt['revision_id'])->firstOrFail();

                return [...$receipt, 'result' => json_decode($row->result, true, 64, JSON_THROW_ON_ERROR)];
            }
            if ((int) $session->state_version !== $stateVersion || ! in_array($session->status->value, ['estimate_review_required', 'ready_to_apply'], true)) {
                throw new StaleEstimateGenerationState($sessionId, $stateVersion);
            }
            $draft = $session->draft_payload ?? [];
            if (($draft['generation_contract'] ?? null) !== BuildMostEstimateDraft::CONTRACT || ! $this->artifacts->verifyArtifact($draft)
                || count($receipts) >= 10000) {
                throw ValidationException::withMessages(['evaluation' => 'Нужен сохранённый состав сметы с действующей подписью.']);
            }
            $scope = [(int) $session->organization_id, $projectId, $sessionId];
            $capture = $this->models->snapshotForPlanning(...[...$scope, 10001]);
            $snapshot = $capture['snapshot'];
            if (count($snapshot->facts) > 10000) {
                throw ValidationException::withMessages(['evaluation' => 'Разделите состав: предел фактов сессии превышен.']);
            }
            $decisions = $this->models->decisionsForSelectedFacts(...[...$scope, array_column($snapshot->facts, 'id')]);
            $hash = $this->inputs->fromSession($session);
            $version = $this->versions->fromSession($session);
            $context = new PipelineContext($sessionId, $scope[0], $projectId, $stateVersion, $version, $session->status->value,
                generationAttemptId: $requestId, baseInputVersion: $version);
            $reviewCandidates = new \App\BusinessModules\Addons\EstimateGeneration\Application\Generation\OrdinaryDraftReviewCandidates;
            $candidateReview = $reviewCandidates->pending($draft);
            if ($candidateReview) {
                $draft = $reviewCandidates->restore($draft);
                foreach ($draft['local_estimates'] ?? [] as $estimateIndex => $estimate) {
                    foreach ($estimate['sections'] ?? [] as $sectionIndex => $section) {
                        foreach ($section['work_items'] ?? [] as $itemIndex => $item) {
                            if (($item['item_type'] ?? null) === 'quantity_review' && isset($payload['quantity_selections'][$item['key']])) {
                                $draft['local_estimates'][$estimateIndex]['sections'][$sectionIndex]['work_items'][$itemIndex]['item_type'] = 'priced_work';
                            }
                        }
                    }
                }
            }
            $draft = $this->arithmetic->recalculate($draft, $context, $snapshot, $decisions, $actor, $session);
            $draft['source_input_version'] = $version;
            $draft['input_snapshot_hash'] = $capture['token'];
            $draft['scope_identity'] = ['organization_id' => $scope[0], 'project_id' => $projectId, 'session_id' => $sessionId,
                'source_version' => $version, 'source_versions' => array_values(array_unique(array_map(static fn ($fact): string => $fact->sourceVersion, $snapshot->facts)))];
            if ($candidateReview) {
                $draft = $this->artifacts->build($reviewCandidates->prepareRebuild($draft));
            }
            if (! BuildMostEstimateDraft::isCandidateOnly($draft)) {
                $this->packages->syncFromDraft($session, $draft, $version);
                $draft = app(\App\BusinessModules\Addons\EstimateGeneration\Application\Apply\FinalizedPackageDraftProjector::class)->project($session, $draft);
            }
            $draft = $this->readiness->project($this->validation->validate($candidateReview ? $draft : $this->artifacts->build($draft)));
            $draft['evaluation_result'] = $this->results->build($candidateReview ? $reviewCandidates->evaluationView($draft) : $draft, $snapshot, $decisions);
            $draft = $this->artifacts->seal($draft);
            $verified = $this->models->snapshotForPlanning(...[...$scope, 10001]);
            if (! hash_equals($capture['token'], $verified['token'])) {
                throw ValidationException::withMessages(['evaluation' => 'Исходные факты изменились во время пересчёта.']);
            }
            $revision = $this->revisions->save($actor, ...[...$scope, $stateVersion, $requestId, $hash, $draft['evaluation_result'], 'arithmetic_recalculation']);
            $result = [...$revision, 'operation_id' => $requestId, 'state_version' => $stateVersion + 1, 'cost_units' => '0', 'result' => $draft['evaluation_result']];
            $receipt = $result;
            unset($receipt['result']);
            $receipts[$requestId] = ['fingerprint' => $fingerprint, 'result' => $receipt];
            $payload['evaluation_recalculation_receipts'] = $receipts;
            $this->workflow->transition($session, EstimateGenerationEvent::EvaluationRecalculated, ['input_payload' => $payload, 'draft_payload' => $draft,
                'analysis_payload' => [...($session->analysis_payload ?? []), 'evaluation_revision' => $revision], 'last_error' => null]);

            return $result;
        }, 3);
    }
}
