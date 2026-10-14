<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Sessions;

use App\BusinessModules\Addons\EstimateGeneration\Application\Generation\BuildMostEstimateDraft;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\UniversalDraftProjector;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\UniversalEvaluationBuilder;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\UniversalReviewSummaryProjector;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\EstimateGenerationEvent;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\EstimateGenerationWorkflow;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\StaleEstimateGenerationState;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineContext;
use App\Models\User;
use Illuminate\Database\Connection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final readonly class RecalculateUniversalEvaluation
{
    public function __construct(private Connection $database, private EstimateGenerationActionAuthorization $authorization,
        private ProjectModelRepository $models, private EvaluationInputFingerprint $inputs, private SaveEvaluationRevision $revisions,
        private EvaluationDraftArithmeticRecalculator $arithmetic, private EstimateGenerationWorkflow $workflow,
        private UniversalEvaluationBuilder $builder = new UniversalEvaluationBuilder,
        private UniversalReviewSummaryProjector $reviews = new UniversalReviewSummaryProjector,
        private BuildMostEstimateDraft $artifacts = new BuildMostEstimateDraft) {}

    public function handle(User $actor, int $projectId, int $sessionId, int $stateVersion, string $requestId): array
    {
        $mode = EstimateGenerationSession::query()->where('organization_id', $actor->current_organization_id)
            ->where('project_id', $projectId)->whereKey($sessionId)->value('input_payload');
        $mode = is_string($mode) ? json_decode($mode, true, 64, JSON_THROW_ON_ERROR) : $mode;
        if (($mode['evaluation_mode'] ?? null) !== 'universal') {
            return app(RecalculateLegacyEstimateDraft::class)->handle($actor, $projectId, $sessionId, $stateVersion, $requestId);
        }
        if (! Str::isUuid($requestId) || $stateVersion < 0) {
            throw ValidationException::withMessages(['request_id' => 'Укажите идентификатор запроса и версию состояния.']);
        }

        return $this->database->transaction(function () use ($actor, $projectId, $sessionId, $stateVersion, $requestId): array {
            $session = EstimateGenerationSession::query()->where('organization_id', $actor->current_organization_id)
                ->where('project_id', $projectId)->whereKey($sessionId)->lockForUpdate()->firstOrFail();
            $this->authorization->authorize($actor, $session, 'estimate_generation.review');
            if (! config('estimate-generation.universal_enabled', false) || ($session->input_payload['evaluation_mode'] ?? null) !== 'universal') {
                throw ValidationException::withMessages(['evaluation' => 'Универсальный процесс не включён для этой сессии.']);
            }
            $payload = $session->input_payload;
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
            if ($session->state_version !== $stateVersion || $session->status->isTerminal()
                || in_array($session->status->value, ['applying', 'generating', 'processing_documents', 'failed'], true)) {
                throw new StaleEstimateGenerationState($sessionId, $stateVersion);
            }
            if (count($receipts) >= 10000 || isset($payload['evaluation_decision_receipts'][$requestId])) {
                throw ValidationException::withMessages(['request_id' => 'Используйте новый идентификатор операции.']);
            }
            $draft = $session->draft_payload ?? [];
            if (($draft['generation_contract'] ?? null) !== UniversalDraftProjector::CONTRACT || ! $this->artifacts->verifyArtifact($draft)) {
                throw ValidationException::withMessages(['evaluation' => 'Сначала получите исходный состав оценки.']);
            }
            $scope = [(int) $session->organization_id, $projectId, $sessionId];
            $capture = $this->models->snapshotForPlanning(...[...$scope, 10001]);
            $snapshot = $capture['snapshot'];
            if (count($snapshot->facts) > 10000) {
                throw ValidationException::withMessages(['evaluation' => 'Разделите состав: предел фактов сессии превышен.']);
            }
            $decisions = $this->models->decisionsForSelectedFacts(...[...$scope, array_column($snapshot->facts, 'id')]);
            if (! (new \App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\EvaluationScenarioSourceBinding)
                ->current($payload['scenario_selections'] ?? null, $snapshot, $decisions)) {
                throw ValidationException::withMessages(['evaluation' => 'Исходные параметры сценариев изменились. Выберите актуальные параметры и сохраните сценарии заново.']);
            }
            $hash = $this->inputs->fromSession($session);
            $version = 'sha256:'.$hash;
            $context = new PipelineContext($sessionId, $scope[0], $projectId, $stateVersion, $version, $session->status->value,
                generationAttemptId: $requestId, baseInputVersion: $version);
            $draft['evaluation_policy'] = [...($draft['evaluation_policy'] ?? []),
                'selected_sections' => $payload['selected_sections'] ?? [], 'profile_id' => $payload['profile_id'] ?? 'construction',
                'construction_type' => $payload['construction_type'] ?? 'new_construction', 'price_policy' => $payload['price_policy'] ?? 'normative',
                'scope_selection' => $payload['scope_selection'] ?? null,
                'scenario_selections' => $payload['scenario_selections'] ?? null,
                'currency_selections' => $payload['currency_selections'] ?? [],
                'contingency_selection' => $payload['contingency_selection'] ?? null,
                'technology_selections' => $payload['technology_selections'] ?? [],
                'required_technology_revision' => $payload['technology_selection_revision'] ?? 0];
            $draft = $this->arithmetic->recalculate($draft, $context, $snapshot, $decisions, $actor, $session);
            $draft['source_input_version'] = $version;
            $draft['evaluation_result'] = $this->builder->build($draft, $snapshot, $decisions, $payload['technology_selections'] ?? []);
            $draft['is_complete'] = false;
            $draft['stage6_status'] = 'scenario_confirmation_required';
            $draft = $this->artifacts->seal($this->reviews->project($draft));
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
            unset($payload['confirmed_evaluation']);
            $this->workflow->transition($session, EstimateGenerationEvent::EvaluationRecalculated,
                ['input_payload' => $payload, 'draft_payload' => $draft,
                    'analysis_payload' => [...($session->analysis_payload ?? []), 'evaluation_revision' => $revision], 'last_error' => null]);

            return $result;
        }, 3);
    }
}
