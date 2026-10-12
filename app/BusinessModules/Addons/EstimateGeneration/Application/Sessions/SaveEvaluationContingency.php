<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Sessions;

use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\EvaluationContingency;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\StaleEstimateGenerationState;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;
use App\Models\User;
use Illuminate\Database\Connection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final readonly class SaveEvaluationContingency
{
    public function __construct(private Connection $database, private EstimateGenerationActionAuthorization $authorization,
        private UserEvaluationChoiceWriter $choices) {}

    public function handle(User $actor, int $projectId, int $sessionId, array $input): array
    {
        if (! Str::isUuid($input['request_id'] ?? '') || ! is_int($input['state_version'] ?? null) || $input['state_version'] < 0
            || array_diff(array_keys($input), ['request_id', 'state_version', 'kind', 'currency', 'quoted_amount', 'vat_mode', 'vat_rate', 'basis']) !== []) {
            throw ValidationException::withMessages(['contingency' => 'Проверьте параметры подтверждения резерва.']);
        }
        try {
            $terms = (new EvaluationContingency)->terms($input);
        } catch (\InvalidArgumentException) {
            throw ValidationException::withMessages(['contingency' => 'Укажите фиксированную сумму в рублях, условия НДС и основание резерва.']);
        }

        return $this->database->transaction(function () use ($actor, $projectId, $sessionId, $input, $terms): array {
            $session = EstimateGenerationSession::query()->where('organization_id', $actor->current_organization_id)
                ->where('project_id', $projectId)->whereKey($sessionId)->lockForUpdate()->firstOrFail();
            $this->authorization->authorize($actor, $session, 'estimate_generation.review');
            if (! config('estimate-generation.universal_enabled', false) || ($session->input_payload['evaluation_mode'] ?? null) !== 'universal') {
                throw ValidationException::withMessages(['evaluation' => trans_message('estimate_evaluation.disabled')]);
            }
            $payload = $session->input_payload;
            $receipts = $payload['evaluation_contingency_receipts'] ?? [];
            $fingerprint = hash('sha256', CanonicalPipelineJson::encode($input));
            if (isset($receipts[$input['request_id']])) {
                $existing = $receipts[$input['request_id']];
                if ($existing['actor_id'] !== (int) $actor->id || ! hash_equals($existing['fingerprint'], $fingerprint)) {
                    throw ValidationException::withMessages(['request_id' => 'Идентификатор запроса уже использован для другого подтверждения.']);
                }

                return $existing['result'];
            }
            if ($session->state_version !== $input['state_version'] || $session->status->isTerminal()
                || in_array($session->status->value, ['applying', 'generating', 'processing_documents'], true)) {
                throw new StaleEstimateGenerationState($sessionId, $input['state_version']);
            }
            if (count($receipts) >= 10000) {
                throw ValidationException::withMessages(['contingency' => 'Достигнут предел подтверждений резерва.']);
            }
            $proof = $this->choices->write($actor, $session, 'contingency', 'evaluation_contingency', $terms,
                'Подтверждён фиксированный резерв и его условия НДС.', $input['request_id']);
            $payload['contingency_selection'] = [...$terms, ...$proof];
            unset($payload['confirmed_evaluation']);
            $result = [...$proof, 'request_id' => $input['request_id'], 'state_version' => $session->state_version + 1,
                'cost_units' => '0', 'recalculation_required' => true];
            $receipts[$input['request_id']] = ['fingerprint' => $fingerprint, 'actor_id' => (int) $actor->id, 'result' => $result];
            $payload['evaluation_contingency_receipts'] = $receipts;
            $session->update(['input_payload' => $payload, 'state_version' => $result['state_version']]);

            return $result;
        }, 3);
    }
}
