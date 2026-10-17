<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Sessions;

use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\StaleEstimateGenerationState;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;
use App\BusinessModules\Addons\EstimateGeneration\Services\SelectedRegionalPriceContext;
use App\Models\User;
use Illuminate\Database\Connection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final readonly class SaveSessionPricingSource
{
    public function __construct(private Connection $database, private EstimateGenerationActionAuthorization $authorization,
        private SelectedRegionalPriceContext $prices, private UserEvaluationChoiceWriter $choices) {}

    public function handle(User $actor, int $projectId, int $sessionId, array $input): array
    {
        if (! Str::isUuid($input['request_id'] ?? '') || ! is_int($input['state_version'] ?? null) || $input['state_version'] < 0
            || ! is_int($input['estimate_regional_price_version_id'] ?? null) || $input['estimate_regional_price_version_id'] < 1
            || (array_key_exists('conditions', $input) && ! is_array($input['conditions']))) {
            throw ValidationException::withMessages(['price_source' => 'Укажите текущую версию сессии и действующий источник цен.']);
        }

        return $this->database->transaction(function () use ($actor, $projectId, $sessionId, $input): array {
            $session = EstimateGenerationSession::query()->where('organization_id', $actor->current_organization_id)
                ->where('project_id', $projectId)->whereKey($sessionId)->lockForUpdate()->firstOrFail();
            $this->authorization->authorize($actor, $session, 'estimate_generation.generate');
            $payload = $session->input_payload ?? [];
            $receipts = $payload['regional_price_selection_receipts'] ?? [];
            $hash = hash('sha256', CanonicalPipelineJson::encode($input));
            if (isset($receipts[$input['request_id']])) {
                $receipt = $receipts[$input['request_id']];
                if ($receipt['actor_id'] !== (int) $actor->id || ! hash_equals($receipt['fingerprint'], $hash)) {
                    throw ValidationException::withMessages(['request_id' => 'Идентификатор относится к другому выбору источника цен.']);
                }

                return $receipt['result'];
            }
            if ((int) $session->state_version !== $input['state_version'] || in_array($session->status->value,
                ['generating', 'processing_documents', 'applying', 'applied', 'archived', 'failed'], true)) {
                throw new StaleEstimateGenerationState($sessionId, $input['state_version']);
            }
            if (count($receipts) >= 10000) {
                throw ValidationException::withMessages(['price_source' => 'Предел изменений источника в этой сессии превышен.']);
            }
            try {
                $payload = $this->prices->replace($payload, $input['estimate_regional_price_version_id']);
                $conditions = array_key_exists('conditions', $input)
                    ? (new \App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\EvaluationRegionalPriceConditions)->normalize($input['conditions'], $payload['regional_context']) : null;
            } catch (\InvalidArgumentException) {
                throw ValidationException::withMessages(['price_source' => trans_message('estimate_evaluation.regional_price_conditions_invalid')]);
            }
            $selection = ['estimate_regional_price_version_id' => $input['estimate_regional_price_version_id'], 'regional_context' => $payload['regional_context'],
                ...($conditions === null ? [] : ['conditions' => $conditions])];
            $proof = $this->choices->write($actor, $session, 'regional-price-source', 'evaluation_price',
                $selection,
                'Выбран региональный источник цен', $input['request_id']);
            $payload['regional_price_selection'] = [...$selection, ...$proof];
            unset($payload['confirmed_evaluation']);
            $result = ['request_id' => $input['request_id'], 'state_version' => (int) $session->state_version + 1,
                'cost_units' => '0', 'recalculation_required' => $session->draft_payload !== null && $session->status->value !== 'cancelled'];
            $receipts[$input['request_id']] = ['actor_id' => (int) $actor->id, 'fingerprint' => $hash, 'result' => $result];
            $payload['regional_price_selection_receipts'] = $receipts;
            $session->update(['input_payload' => $payload, 'state_version' => $result['state_version']]);

            return $result;
        }, 3);
    }
}
