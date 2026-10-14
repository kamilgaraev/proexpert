<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Sessions;

use App\BusinessModules\Addons\EstimateGeneration\Application\Generation\BuildMostEstimateDraft;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\EvaluationScenarioSet;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\EvaluationScenarioSourceBinding;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\EvaluationWorkItemLocator;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\ParametricEvaluationScenarios;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\UniversalDraftProjector;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\StaleEstimateGenerationState;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;
use App\Models\User;
use Illuminate\Database\Connection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final readonly class SaveEvaluationScenarioSet
{
    public function __construct(private Connection $database, private EstimateGenerationActionAuthorization $authorization,
        private UserEvaluationChoiceWriter $choices, private ProjectModelRepository $models, private ParametricEvaluationScenarios $parameters,
        private EvaluationScenarioSet $scenarios = new EvaluationScenarioSet,
        private BuildMostEstimateDraft $artifacts = new BuildMostEstimateDraft) {}

    public function handle(User $actor, int $projectId, int $sessionId, array $input): array
    {
        if (! Str::isUuid($input['request_id'] ?? '') || ! is_int($input['state_version'] ?? null) || $input['state_version'] < 0
            || (isset($input['definitions']) === isset($input['parameter_cases']))
            || ! is_array($input['definitions'] ?? $input['parameter_cases'] ?? null)) {
            throw ValidationException::withMessages(['scenarios' => trans_message('estimate_evaluation.scenario_request_invalid')]);
        }

        return $this->database->transaction(function () use ($actor, $projectId, $sessionId, $input): array {
            $session = EstimateGenerationSession::query()->where('organization_id', $actor->current_organization_id)
                ->where('project_id', $projectId)->whereKey($sessionId)->lockForUpdate()->firstOrFail();
            $this->authorization->authorize($actor, $session, 'estimate_generation.review');
            if (! config('estimate-generation.universal_enabled', false) || ($session->input_payload['evaluation_mode'] ?? null) !== 'universal') {
                throw ValidationException::withMessages(['evaluation' => trans_message('estimate_evaluation.disabled')]);
            }
            $payload = $session->input_payload;
            $receipts = $payload['evaluation_scenario_receipts'] ?? [];
            $fingerprint = hash('sha256', CanonicalPipelineJson::encode($input));
            if (isset($receipts[$input['request_id']])) {
                $receipt = $receipts[$input['request_id']];
                if ($receipt['actor_id'] !== (int) $actor->id || ! hash_equals($receipt['fingerprint'], $fingerprint)) {
                    throw ValidationException::withMessages(['request_id' => trans_message('estimate_evaluation.scenario_receipt_conflict')]);
                }

                return $receipt['result'];
            }
            if ($session->state_version !== $input['state_version'] || $session->status->isTerminal()
                || in_array($session->status->value, ['applying', 'generating', 'processing_documents', 'failed'], true)) {
                throw new StaleEstimateGenerationState($sessionId, $input['state_version']);
            }
            $draft = $session->draft_payload ?? [];
            if (count($receipts) >= 10000 || ($draft['generation_contract'] ?? null) !== UniversalDraftProjector::CONTRACT
                || ! $this->artifacts->verifyArtifact($draft)) {
                throw ValidationException::withMessages(['scenarios' => trans_message('estimate_evaluation.scenario_draft_required')]);
            }
            $positions = $draft['evaluation_result']['scenarios'][0]['positions'] ?? [];
            if (! is_array($positions) || $positions === []) {
                throw ValidationException::withMessages(['scenarios' => trans_message('estimate_evaluation.scenario_draft_required')]);
            }
            $works = [];
            foreach ($positions as $position) {
                $works[$position['key']] = (new EvaluationWorkItemLocator)->find($draft, $payload, $position['key']);
            }
            $projection = null;
            try {
                if (isset($input['parameter_cases'])) {
                    $scope = [(int) $session->organization_id, $projectId, $sessionId];
                    $capture = $this->models->snapshotForPlanning(...[...$scope, 10001]);
                    $snapshot = $capture['snapshot'];
                    if (count($snapshot->facts) > 10000) {
                        throw new InvalidArgumentException('evaluation_scenario_fact_budget_exceeded');
                    }
                    $decisions = $this->models->decisionsForSelectedFacts(...[...$scope, array_column($snapshot->facts, 'id')]);
                    $projection = $this->parameters->build($input['parameter_cases'], $works, $positions, $snapshot, $capture['token'],
                        $this->models->currentTechnologyRecommendations(...$scope), $this->models->currentCompleteness(...$scope),
                        $decisions, (string) $actor->id);
                    $definitions = $projection['definitions'];
                    unset($projection['definitions']);
                    $projection['source_fingerprint'] = (new EvaluationScenarioSourceBinding)->fingerprint($snapshot, $decisions);
                    if (! hash_equals($capture['token'], $this->models->snapshotForPlanning(...[...$scope, 10001])['token'])) {
                        throw new InvalidArgumentException('evaluation_scenario_parameter_stale');
                    }
                } else {
                    $definitions = $this->scenarios->validate($input['definitions'], $positions);
                }
            } catch (InvalidArgumentException $error) {
                $message = match ($error->getMessage()) {
                    'evaluation_scenario_work_budget_exceeded' => 'Разделите оценку по разделам: один параметрический расчёт поддерживает до 2000 позиций.',
                    'evaluation_scenario_parameters_not_calculable' => 'Для этих формул недостаточно проверенных параметров или покрытия геометрии. Уточните исходные размеры и проёмы либо задайте объёмы работ вручную.',
                    'evaluation_scenario_parameter_stale' => 'Исходные параметры изменились. Обновите их и сохраните согласованные сценарии заново.',
                    'evaluation_scenario_quantity_order_invalid' => 'Наборы параметров дают несовместимый порядок объёмов работ. Задайте согласованные целые случаи: минимальный, базовый и максимальный.',
                    default => trans_message('estimate_evaluation.scenario_request_invalid'),
                };
                throw ValidationException::withMessages(['scenarios' => $message]);
            }
            $selection = ['version' => EvaluationScenarioSet::VERSION, 'definitions' => $definitions];
            if ($projection !== null) {
                $selection['parameter_projection'] = $projection;
            }
            $proof = $this->choices->write($actor, $session, 'scenario-set', 'evaluation_scenario', $selection,
                trans_message('estimate_evaluation.scenario_selected'), $input['request_id']);
            $payload['scenario_selections'] = [...$selection, ...$proof];
            unset($payload['confirmed_evaluation']);
            $result = [...$proof, 'request_id' => $input['request_id'], 'state_version' => $session->state_version + 1,
                'cost_units' => '0', 'recalculation_required' => true];
            $receipts[$input['request_id']] = ['actor_id' => (int) $actor->id, 'fingerprint' => $fingerprint, 'result' => $result];
            $payload['evaluation_scenario_receipts'] = $receipts;
            $session->update(['input_payload' => $payload, 'state_version' => $result['state_version']]);

            return $result;
        }, 3);
    }
}
