<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Sessions;

use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ApplyProjectModelDecision;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\StaleEstimateGenerationState;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;
use App\Models\User;
use Illuminate\Database\Connection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final readonly class ConfirmRoomIdentity
{
    public function __construct(private Connection $database, private EstimateGenerationActionAuthorization $authorization,
        private CurrentRoomIdentitySources $sources, private ApplyProjectModelDecision $decisions) {}

    public function handle(User $actor, int $projectId, int $sessionId, array $input): array
    {
        if (! Str::isUuid($input['request_id'] ?? '') || ! is_int($input['state_version'] ?? null) || $input['state_version'] < 0
            || ! is_string($input['source_fact_id'] ?? null) || preg_match('/\Afact:[a-f0-9]{64}\z/', $input['source_fact_id']) !== 1
            || ! is_int($input['source_fact_version'] ?? null) || $input['source_fact_version'] < 1
            || ! is_string($input['source_value_hash'] ?? null) || preg_match('/\A[a-f0-9]{64}\z/', $input['source_value_hash']) !== 1
            || ! is_string($input['reason'] ?? null) || mb_strlen(trim($input['reason'])) < 12 || strlen($input['reason']) > 1000
            || array_diff(array_keys($input), ['request_id', 'state_version', 'source_fact_id', 'source_fact_version', 'source_value_hash', 'reason']) !== []) {
            throw ValidationException::withMessages(['identity' => trans_message('estimate_generation.room_identity.input_invalid')]);
        }

        return $this->database->transaction(function () use ($actor, $projectId, $sessionId, $input): array {
            $session = EstimateGenerationSession::query()->where('organization_id', $actor->current_organization_id)
                ->where('project_id', $projectId)->whereKey($sessionId)->lockForUpdate()->firstOrFail();
            $this->authorization->authorize($actor, $session, 'estimate_generation.review');
            if (! config('estimate-generation.universal_enabled', false) || ($session->input_payload['evaluation_mode'] ?? null) !== 'universal') {
                throw ValidationException::withMessages(['identity' => trans_message('estimate_generation.room_identity.unavailable')]);
            }
            $payload = $session->input_payload;
            $fingerprint = hash('sha256', CanonicalPipelineJson::encode($input));
            $receipts = $payload['room_identity_receipts'] ?? [];
            if (isset($receipts[$input['request_id']])) {
                $receipt = $receipts[$input['request_id']];
                if ($receipt['actor_id'] !== (int) $actor->id || ! hash_equals($receipt['fingerprint'], $fingerprint)) {
                    throw ValidationException::withMessages(['request_id' => trans_message('estimate_generation.room_identity.request_conflict')]);
                }

                return $receipt['result'];
            }
            if ($session->state_version !== $input['state_version'] || $session->status->isTerminal()
                || in_array($session->status->value, ['applying', 'generating', 'processing_documents', 'failed'], true)) {
                throw new StaleEstimateGenerationState($sessionId, $input['state_version']);
            }
            if (count($receipts) >= 10000) {
                throw ValidationException::withMessages(['identity' => trans_message('estimate_generation.room_identity.fact_limit')]);
            }
            $selected = null;
            foreach ($this->sources->rows($session) as $row) {
                if ($row['source_fact_id'] === $input['source_fact_id'] && $row['source_fact_version'] === $input['source_fact_version']
                    && hash_equals($row['source_value_hash'], $input['source_value_hash']) && $row['status'] === 'candidate') {
                    $selected = $row;
                    break;
                }
            }
            if ($selected === null) {
                throw ValidationException::withMessages(['identity' => trans_message('estimate_generation.room_identity.source_stale')]);
            }
            $decision = $this->decisions->apply((int) $session->organization_id, $projectId, $sessionId, $selected['source_version'],
                $selected['source_fact_id'], $selected['name'], null, (string) $actor->id, $input['reason'],
                'decision:'.hash('sha256', $input['request_id'].'|'.$selected['source_fact_id']));
            $result = ['request_id' => $input['request_id'], 'state_version' => $session->state_version + 1, 'cost_units' => '0',
                'decision_id' => $decision->id, 'fact_id' => $decision->selectedFactId, 'entity_id' => $selected['entity_id'],
                'identity_confirmed' => true, 'dimensions_confirmed' => false,
                'recalculation_required' => isset($session->draft_payload['evaluation_result'])];
            $receipts[$input['request_id']] = ['actor_id' => (int) $actor->id, 'fingerprint' => $fingerprint, 'result' => $result];
            $payload['room_identity_receipts'] = $receipts;
            $payload['room_identity_revision'] = ($payload['room_identity_revision'] ?? 0) + 1;
            unset($payload['confirmed_evaluation']);
            $this->authorization->authorize($actor, $session, 'estimate_generation.review');
            $session->update(['input_payload' => $payload, 'state_version' => $result['state_version']]);

            return $result;
        }, 3);
    }
}
