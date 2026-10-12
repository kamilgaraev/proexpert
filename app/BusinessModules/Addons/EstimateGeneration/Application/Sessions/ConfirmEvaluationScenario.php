<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Sessions;

use App\BusinessModules\Addons\EstimateGeneration\Application\Generation\BuildMostEstimateDraft;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\SelectedEvaluationScenario;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\UniversalDraftProjector;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\UniversalEvaluationBuilder;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\StaleEstimateGenerationState;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineContext;
use App\Models\User;
use Illuminate\Database\Connection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** A free, explicit choice of a current revision. Ordinary publication has its own proof and price gates. */
final readonly class ConfirmEvaluationScenario
{
    public function __construct(private Connection $database, private EstimateGenerationActionAuthorization $authorization,
        private EvaluationInputFingerprint $inputs, private ProjectModelRepository $models,
        private EvaluationDraftArithmeticRecalculator $arithmetic, private UserEvaluationChoiceWriter $choices,
        private MaterializeSelectedEvaluationQuantities $quantities, private SaveEvaluationRevision $revisions,
        private \App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\EstimateGenerationWorkflow $workflow,
        private SelectedEvaluationScenario $scenarios = new SelectedEvaluationScenario,
        private UniversalEvaluationBuilder $builder = new UniversalEvaluationBuilder,
        private BuildMostEstimateDraft $artifacts = new BuildMostEstimateDraft) {}

    public function handle(User $actor, int $projectId, int $sessionId, array $input): array
    {
        if (! Str::isUuid($input['request_id'] ?? '') || ! Str::isUuid($input['revision_id'] ?? '')
            || ! is_int($input['state_version'] ?? null) || $input['state_version'] < 0
            || ! is_string($input['content_hash'] ?? null) || preg_match('/\A[a-f0-9]{64}\z/', $input['content_hash']) !== 1
            || ! in_array($input['scenario_id'] ?? null, ['minimum', 'base', 'maximum'], true)
            || ! is_bool($input['assumptions_accepted'] ?? null)) {
            throw ValidationException::withMessages(['scenario' => 'Выберите сценарий текущей ревизии и явно подтвердите его допущения.']);
        }

        return $this->database->transaction(function () use ($actor, $projectId, $sessionId, $input): array {
            $session = EstimateGenerationSession::query()->where('organization_id', $actor->current_organization_id)
                ->where('project_id', $projectId)->whereKey($sessionId)->lockForUpdate()->firstOrFail();
            $this->authorization->authorize($actor, $session, 'estimate_generation.review');
            if (! config('estimate-generation.universal_enabled', false) || ($session->input_payload['evaluation_mode'] ?? null) !== 'universal') {
                throw ValidationException::withMessages(['scenario' => 'Универсальный процесс недоступен для этой сессии.']);
            }
            $payload = $session->input_payload ?? [];
            $receipts = $payload['scenario_confirmation_receipts'] ?? [];
            $fingerprint = hash('sha256', CanonicalPipelineJson::encode([$actor->id, $input]));
            if (isset($receipts[$input['request_id']])) {
                if (! hash_equals($receipts[$input['request_id']]['fingerprint'], $fingerprint)) {
                    throw ValidationException::withMessages(['request_id' => 'Идентификатор уже относится к другому выбору сценария.']);
                }

                return $receipts[$input['request_id']]['result'];
            }
            if ($session->state_version !== $input['state_version'] || $session->status->isTerminal()
                || in_array($session->status->value, ['generating', 'processing_documents', 'applying', 'failed'], true)) {
                throw new StaleEstimateGenerationState($sessionId, $input['state_version']);
            }
            if (count($receipts) >= 10000) {
                throw ValidationException::withMessages(['scenario' => 'Предел решений в этой сессии превышен.']);
            }
            $revision = $this->database->table('estimate_generation_evaluation_revisions')->where('organization_id', $session->organization_id)
                ->where('project_id', $projectId)->where('session_id', $sessionId)->orderByDesc('revision')->firstOrFail();
            $inputHash = $this->inputs->fromSession($session);
            if ($revision->public_id !== strtolower($input['revision_id']) || ! hash_equals($revision->content_hash, $input['content_hash'])
                || ! hash_equals($revision->input_hash, $inputHash)) {
                throw ValidationException::withMessages(['scenario' => 'Оценка изменилась. Пересчитайте её перед подтверждением.']);
            }
            try {
                $selected = $this->scenarios->select(json_decode($revision->result, true, 64, JSON_THROW_ON_ERROR), $input['scenario_id']);
            } catch (\InvalidArgumentException) {
                throw ValidationException::withMessages(['scenario' => 'Для выбранного сценария подтвердите состав, объёмы, цены и условия расчёта.']);
            }
            if ($selected['assumptions'] !== [] && ! $input['assumptions_accepted']) {
                throw ValidationException::withMessages(['assumptions_accepted' => 'Подтвердите показанные допущения выбранного сценария.']);
            }
            $draft = $session->draft_payload ?? [];
            if (($draft['generation_contract'] ?? null) !== UniversalDraftProjector::CONTRACT || ! $this->artifacts->verifyArtifact($draft)) {
                throw ValidationException::withMessages(['scenario' => 'Исходный состав оценки изменился. Пересчитайте оценку.']);
            }
            $scope = [(int) $session->organization_id, $projectId, $sessionId];
            $snapshot = $this->models->snapshotForPlanning(...[...$scope, 10001])['snapshot'];
            if (count($snapshot->facts) > 10000) {
                throw ValidationException::withMessages(['scenario' => 'Разделите проект: предел фактов превышен.']);
            }
            $decisions = $this->models->decisionsForSelectedFacts(...[...$scope, array_column($snapshot->facts, 'id')]);
            $version = 'sha256:'.$inputHash;
            $context = new PipelineContext($sessionId, $scope[0], $projectId, $session->state_version, $version, $session->status->value,
                generationAttemptId: $input['request_id'], baseInputVersion: $version);
            $fresh = $this->arithmetic->recalculate($draft, $context, $snapshot, $decisions, $actor, $session);
            try {
                $freshSelected = $this->scenarios->select($this->builder->build($fresh, $snapshot, $decisions), $input['scenario_id']);
            } catch (\InvalidArgumentException) {
                throw ValidationException::withMessages(['scenario' => 'Основания выбранного сценария больше не актуальны. Пересчитайте оценку.']);
            }
            if (! hash_equals($selected['scenario_hash'], $freshSelected['scenario_hash'])) {
                throw ValidationException::withMessages(['scenario' => 'Объём или источник цены изменился. Подтвердите новую ревизию после пересчёта.']);
            }
            $sourceRevisionId = $revision->public_id;
            $sourceContentHash = $revision->content_hash;
            if ($this->quantities->materialize($actor, $session, $selected, $input['request_id'], $sourceRevisionId)) {
                $originalPositions = array_column($selected['scenario']['positions'], null, 'key');
                foreach ($fresh['local_estimates'] ?? [] as $estimateIndex => $estimate) {
                    foreach ($estimate['sections'] ?? [] as $sectionIndex => $section) {
                        foreach ($section['work_items'] ?? [] as $itemIndex => $item) {
                            $entityId = $originalPositions[$item['key']]['entity_id'] ?? null;
                            if (is_string($entityId)) {
                                // A quantity ledger anchor is not the physical entity whose work scope the user accepted.
                                $fresh['local_estimates'][$estimateIndex]['sections'][$sectionIndex]['work_items'][$itemIndex]['metadata']['scope_entity_id'] = $entityId;
                            }
                        }
                    }
                }
                $inputHash = $this->inputs->fromSession($session);
                $version = 'sha256:'.$inputHash;
                $snapshot = $this->models->snapshotForPlanning(...[...$scope, 10001])['snapshot'];
                if (count($snapshot->facts) > 10000) {
                    throw ValidationException::withMessages(['scenario' => 'Разделите проект: предел фактов превышен.']);
                }
                $decisions = $this->models->decisionsForSelectedFacts(...[...$scope, array_column($snapshot->facts, 'id')]);
                $context = new PipelineContext($sessionId, $scope[0], $projectId, $session->state_version, $version, $session->status->value,
                    generationAttemptId: $input['request_id'], baseInputVersion: $version);
                $fresh = $this->arithmetic->recalculate($fresh, $context, $snapshot, $decisions, $actor, $session);
                $evaluation = $this->builder->build($fresh, $snapshot, $decisions);
                try {
                    $materialized = $this->scenarios->select($evaluation, $input['scenario_id']);
                } catch (\InvalidArgumentException) {
                    throw ValidationException::withMessages(['scenario' => 'Подтверждённые объёмы не прошли проверку состава или цен. Изменение не сохранено.']);
                }
                if (! hash_equals($selected['scenario_hash'], $materialized['scenario_hash'])) {
                    throw ValidationException::withMessages(['scenario' => 'Пересчёт изменил выбранные объёмы или деньги. Проверьте новую оценку.']);
                }
                $saved = $this->revisions->save($actor, ...[...$scope, $session->state_version, $input['request_id'], $inputHash, $evaluation, 'arithmetic_recalculation']);
                $revision = (object) ['public_id' => $saved['revision_id'], 'content_hash' => $saved['content_hash']];
                $fresh['source_input_version'] = $version;
                $fresh['evaluation_result'] = $evaluation;
                $fresh['stage6_status'] = 'selected_scenario_confirmed';
                $session->update(['draft_payload' => $this->artifacts->seal((new \App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\UniversalReviewSummaryProjector)->project($fresh)),
                    'analysis_payload' => [...($session->analysis_payload ?? []), 'evaluation_revision' => $saved]]);
                $payload = $session->input_payload;
            }
            $choice = ['answer_kind' => 'selected_scenario', 'revision_id' => $revision->public_id, 'content_hash' => $revision->content_hash,
                'input_hash' => $inputHash, 'scenario_id' => $input['scenario_id'], 'scenario_hash' => $selected['scenario_hash'],
                'selected_scope' => $selected['selected_scope'], 'assumptions_accepted' => $input['assumptions_accepted'],
                'source_revision_id' => $sourceRevisionId, 'source_content_hash' => $sourceContentHash];
            $proof = $this->choices->write($actor, $session, 'selected-scenario', 'evaluation_scenario', $choice,
                'Пользователь подтвердил конкретный сценарий, состав, объёмы, цены и показанные допущения.', $input['request_id']);
            $payload['confirmed_evaluation'] = [...$choice, ...$proof];
            if (isset($payload['planning_review']['limitations'])) {
                $payload['planning_review']['limitations'] = array_values(array_diff($payload['planning_review']['limitations'], ['planning_requires_paid_approval']));
            }
            $result = [...$proof, 'request_id' => $input['request_id'], 'revision_id' => $sourceRevisionId, 'result_revision_id' => $revision->public_id,
                'scenario_id' => $input['scenario_id'], 'state_version' => $session->state_version + 1, 'cost_units' => '0', 'recalculation_required' => false];
            $receipts[$input['request_id']] = ['fingerprint' => $fingerprint, 'result' => $result];
            $payload['scenario_confirmation_receipts'] = $receipts;
            $this->workflow->transition($session, \App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\EstimateGenerationEvent::EvaluationConfirmed,
                ['input_payload' => $payload, 'processing_stage' => 'ready_to_apply', 'processing_progress' => 100]);

            return $result;
        }, 3);
    }
}
