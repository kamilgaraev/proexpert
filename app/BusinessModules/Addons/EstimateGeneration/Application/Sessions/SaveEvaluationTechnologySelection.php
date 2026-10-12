<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Sessions;

use App\BusinessModules\Addons\EstimateGeneration\Domain\Decisions\ActorContext;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\StaleEstimateGenerationState;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;
use App\BusinessModules\Addons\EstimateGeneration\Planning\TechnologyRecommendation;
use App\BusinessModules\Addons\EstimateGeneration\Planning\TechnologyRecommendationDecisionService;
use App\Models\User;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final readonly class SaveEvaluationTechnologySelection
{
    public function __construct(private EstimateGenerationActionAuthorization $authorization, private ConnectionInterface $database,
        private ProjectModelRepository $models, private TechnologyRecommendationDecisionService $technologies) {}

    public function handle(User $actor, int $projectId, int $sessionId, array $input): array
    {
        $reselection = array_key_exists('current_fact_id', $input) || array_key_exists('current_decision_id', $input);
        if (! is_string($input['request_id'] ?? null) || ! Str::isUuid($input['request_id'])
            || ! is_int($input['state_version'] ?? null) || $input['state_version'] < 0
            || (! $reselection && (! is_int($input['planning_run_id'] ?? null) || $input['planning_run_id'] < 1
                || ! is_string($input['decision_key'] ?? null) || mb_strlen($input['decision_key']) > 160))
            || ($reselection && (! is_string($input['current_fact_id'] ?? null) || mb_strlen($input['current_fact_id']) > 191
                || ! is_string($input['current_decision_id'] ?? null) || mb_strlen($input['current_decision_id']) > 191))
            || ! is_string($input['system_id'] ?? null) || mb_strlen($input['system_id']) > 160
            || in_array($input['system_id'], ['other', 'leave_unresolved'], true)
            || ! is_string($input['reason'] ?? null) || trim($input['reason']) === '' || mb_strlen($input['reason']) > 1000) {
            throw ValidationException::withMessages(['technology' => trans_message('estimate_evaluation.technology_request_invalid')]);
        }

        return $this->database->transaction(function () use ($actor, $projectId, $sessionId, $input, $reselection): array {
            $session = EstimateGenerationSession::query()->where('organization_id', $actor->current_organization_id)
                ->where('project_id', $projectId)->whereKey($sessionId)->lockForUpdate()->firstOrFail();
            $this->authorization->authorize($actor, $session, 'estimate_generation.review');
            if (! config('estimate-generation.universal_enabled', false) || ($session->input_payload['evaluation_mode'] ?? null) !== 'universal') {
                throw ValidationException::withMessages(['evaluation' => trans_message('estimate_evaluation.disabled')]);
            }
            $payload = $session->input_payload;
            $receipts = $payload['evaluation_technology_receipts'] ?? [];
            $fingerprint = hash('sha256', CanonicalPipelineJson::encode($input));
            if (isset($receipts[$input['request_id']])) {
                $existing = $receipts[$input['request_id']];
                if ($existing['actor_id'] !== (int) $actor->id || ! hash_equals($existing['fingerprint'], $fingerprint)) {
                    throw ValidationException::withMessages(['request_id' => trans_message('estimate_evaluation.technology_receipt_conflict')]);
                }

                return $existing['result'];
            }
            if ($session->state_version !== $input['state_version'] || $session->status->isTerminal()
                || in_array($session->status->value, ['applying', 'generating', 'processing_documents', 'failed'], true)) {
                throw new StaleEstimateGenerationState($sessionId, $input['state_version']);
            }
            if (count($receipts) >= 10000) {
                throw ValidationException::withMessages(['technology' => trans_message('estimate_evaluation.technology_request_invalid')]);
            }
            $scope = [(int) $session->organization_id, $projectId, $sessionId];
            $current = $reselection ? null : $this->models->currentTechnologyRecommendations(...$scope);
            $recommendation = null;
            foreach ($current['recommendations'] ?? [] as $candidate) {
                if ($candidate instanceof TechnologyRecommendation && $candidate->decisionKey === $input['decision_key']) {
                    $recommendation = $candidate;
                    break;
                }
            }
            $target = $reselection ? $this->models->fact(...[...$scope, $input['current_fact_id']])
                : ($recommendation === null ? null : $this->models->fact(...[...$scope, $recommendation->targetFactId]));
            if ($target === null || (! $reselection && ! str_starts_with($input['decision_key'], 'roof_covering_system.'))
                || ($reselection && (! is_array($target->value)
                    || ($payload['technology_selections']['roofing'][$target->entityId] ?? null) !== ($target->value['system_id'] ?? null)))) {
                throw ValidationException::withMessages(['technology' => trans_message('estimate_evaluation.technology_changed')]);
            }
            try {
                $context = new ActorContext($scope[0], $projectId, (int) $actor->id, 'evaluation-technology:'.$input['request_id'], $target->sourceVersion);
                $decision = $reselection
                    ? $this->technologies->reselect($actor, $session, $context, $input['current_fact_id'], $input['current_decision_id'], $input['system_id'], $input['reason'])
                    : $this->technologies->respond($actor, $session, $context, $input['planning_run_id'], $input['decision_key'], $input['system_id'], null, $input['reason']);
            } catch (InvalidArgumentException) {
                throw ValidationException::withMessages(['technology' => trans_message('estimate_evaluation.technology_changed')]);
            }
            if ($decision === null || $decision->selectedFactId === null) {
                throw ValidationException::withMessages(['technology' => trans_message('estimate_evaluation.technology_changed')]);
            }
            $payload['technology_selections']['roofing'][$target->entityId] = $input['system_id'];
            $payload['technology_selection_revision'] = ($payload['technology_selection_revision'] ?? 0) + 1;
            unset($payload['confirmed_evaluation']);
            $result = ['operation_id' => $input['request_id'], 'state_version' => $session->state_version + 1, 'cost_units' => '0',
                'fact_id' => $decision->selectedFactId, 'decision_id' => $decision->id, 'recalculation_required' => true,
                'composition_refresh_required' => true, 'section' => 'roofing', 'entity_id' => $target->entityId, 'system_id' => $input['system_id']];
            $receipts[$input['request_id']] = ['fingerprint' => $fingerprint, 'actor_id' => (int) $actor->id, 'result' => $result];
            $payload['evaluation_technology_receipts'] = $receipts;
            $session->update(['input_payload' => $payload, 'state_version' => $result['state_version']]);

            return $result;
        }, 3);
    }
}
