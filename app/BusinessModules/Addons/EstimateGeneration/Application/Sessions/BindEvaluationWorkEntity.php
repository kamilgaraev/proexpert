<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Sessions;

use App\BusinessModules\Addons\EstimateGeneration\Application\Generation\BuildMostEstimateDraft;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\EvaluationWorkItemLocator;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\UniversalReviewSummaryProjector;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\WorkEntityScopePolicy;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\StaleEstimateGenerationState;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;
use App\Models\User;
use Illuminate\Database\Connection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final readonly class BindEvaluationWorkEntity
{
    public function __construct(private Connection $database, private EstimateGenerationActionAuthorization $authorization,
        private CurrentWorkEntitySources $sources, private UserEvaluationChoiceWriter $choices,
        private EvaluationInputFingerprint $inputs, private UniversalReviewSummaryProjector $reviews, private BuildMostEstimateDraft $artifacts) {}

    public function handle(User $actor, int $projectId, int $sessionId, array $input): array
    {
        if (! Str::isUuid($input['request_id'] ?? '') || ! is_int($input['state_version'] ?? null) || $input['state_version'] < 0
            || ! is_string($input['work_item_key'] ?? null) || trim($input['work_item_key']) === '' || strlen($input['work_item_key']) > 160
            || ! is_string($input['source_fact_id'] ?? null) || preg_match('/\Afact:(?:[a-f0-9]{64}|decision:[a-f0-9]{48})\z/', $input['source_fact_id']) !== 1
            || ! is_int($input['source_fact_version'] ?? null) || $input['source_fact_version'] < 1
            || ! is_string($input['source_value_hash'] ?? null) || preg_match('/\A[a-f0-9]{64}\z/', $input['source_value_hash']) !== 1
            || ! is_string($input['reason'] ?? null) || mb_strlen(trim($input['reason'])) < 12 || strlen($input['reason']) > 1000
            || array_diff(array_keys($input), ['request_id', 'state_version', 'work_item_key', 'source_fact_id', 'source_fact_version', 'source_value_hash', 'reason']) !== []) {
            throw ValidationException::withMessages(['entity' => trans_message('estimate_work_entity.binding_invalid')]);
        }

        return $this->database->transaction(function () use ($actor, $projectId, $sessionId, $input): array {
            $session = EstimateGenerationSession::query()->where('organization_id', $actor->current_organization_id)
                ->where('project_id', $projectId)->whereKey($sessionId)->lockForUpdate()->firstOrFail();
            $this->authorization->authorize($actor, $session, 'estimate_generation.review');
            if (! config('estimate-generation.universal_enabled', false) || ($session->input_payload['evaluation_mode'] ?? null) !== 'universal') {
                throw ValidationException::withMessages(['entity' => trans_message('estimate_generation.room_identity.unavailable')]);
            }
            $payload = $session->input_payload;
            $fingerprint = hash('sha256', CanonicalPipelineJson::encode($input));
            $receipts = $payload['work_entity_receipts'] ?? [];
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
                throw ValidationException::withMessages(['entity' => trans_message('estimate_generation.room_identity.fact_limit')]);
            }
            $locator = new EvaluationWorkItemLocator;
            $draft = $session->draft_payload ?? [];
            $item = $locator->find($draft, $payload, $input['work_item_key']);
            $selected = null;
            foreach ($this->sources->rows($session, $item) as $row) {
                if ($row['source_fact_id'] === $input['source_fact_id'] && $row['source_fact_version'] === $input['source_fact_version']
                    && hash_equals($row['source_value_hash'], $input['source_value_hash']) && $row['status'] === 'confirmed') {
                    $selected = $row;
                    break;
                }
            }
            if ($selected === null) {
                throw ValidationException::withMessages(['entity' => trans_message('estimate_work_entity.binding_source_invalid')]);
            }
            $previous = $payload['work_entity_selections'][$item['key']] ?? null;
            $changed = ! is_array($previous) || ($previous['entity_id'] ?? null) !== $selected['entity_id']
                || ($previous['source_fact_id'] ?? null) !== $selected['source_fact_id']
                || ($previous['source_fact_version'] ?? null) !== $selected['source_fact_version']
                || ($previous['source_value_hash'] ?? null) !== $selected['source_value_hash']
                || ($previous['source_version'] ?? null) !== $selected['source_version'];
            $selection = ['answer_kind' => 'work_entity_binding', 'binding_contract' => WorkEntityScopePolicy::BINDING_CONTRACT,
                'work_item_key' => $item['key'], 'entity_id' => $selected['entity_id'], 'source_entity_type' => $selected['entity_type'],
                'source_fact_id' => $selected['source_fact_id'], 'source_fact_version' => $selected['source_fact_version'],
                'source_value_hash' => $selected['source_value_hash'], 'source_version' => $selected['source_version']];
            $proof = $this->choices->write($actor, $session, 'work-entity:'.$item['key'], 'evaluation_answer', $selection, $input['reason'], $input['request_id']);
            $bindingProof = ['fact_id' => $proof['fact_id'], 'decision_id' => $proof['decision_id'], 'ledger_entity_id' => $proof['entity_id']];
            $payload['work_entity_selections'][$item['key']] = [...$selection, ...$bindingProof];
            $payload['work_entity_selection_revision'] = ($payload['work_entity_selection_revision'] ?? 0) + 1;
            $item['metadata']['scope_entity_id'] = $selected['entity_id'];
            $item['metadata']['scope_entity_binding'] = [...$selection, ...$bindingProof];
            if ($changed) {
                // Revoke the quantity's assignment, retaining its numerical source and all previous decisions.
                $this->choices->write($actor, $session, 'work:'.$item['key'], 'evaluation_answer',
                    ['answer_kind' => 'quantity_scope_invalidated', 'work_item_key' => $item['key'], 'entity_id' => $selected['entity_id']],
                    $input['reason'], $input['request_id']);
                if (isset($payload['scenario_selections'])) {
                    $this->choices->write($actor, $session, 'scenario-set', 'evaluation_scenario',
                        ['answer_kind' => 'scenario_scope_invalidated', 'affected_work_item_key' => $item['key']],
                        $input['reason'], $input['request_id']);
                }
                unset($payload['quantity_selections'][$item['key']], $payload['scenario_selections']);
                $payload['quantity_selection_revision'] = ($payload['quantity_selection_revision'] ?? 0) + 1;
                $item['quantity'] = null;
                $item['quantity_status'] = 'unknown';
                $item['quantity_basis_details'] = null;
                $item['resources'] = [];
                foreach (['materials', 'labor', 'machinery', 'other_resources', 'equipment'] as $group) {
                    $item[$group] = [];
                }
                foreach (['unit_price', 'total_cost', 'work_cost', 'materials_cost', 'labor_cost', 'machinery_cost', 'other_cost'] as $money) {
                    $item[$money] = null;
                }
                unset($item['price_snapshot'], $item['evaluation_price_basis_quantity']);
                $item['pricing_status'] = 'not_calculated';
                $item['pricing_blocker'] = 'quantity_unknown';
                foreach (['quantity_evidence', 'quantity_evidence_id', 'quantity_evidence_fingerprint', 'quantity_evidence_source_version',
                    'current_total_amount', 'base_total_amount'] as $field) {
                    unset($item[$field]);
                }
                $item['metadata']['quantity_reset_required'] = true;
            }
            $draft = $locator->replace($draft, $payload, $item['key'], $item);
            unset($payload['confirmed_evaluation']);
            $result = [...$proof, 'request_id' => $input['request_id'], 'work_item_key' => $item['key'],
                'scope_entity_id' => $selected['entity_id'], 'quantity_reset' => $changed, 'state_version' => $session->state_version + 1,
                'cost_units' => '0', 'recalculation_required' => true];
            $receipts[$input['request_id']] = ['actor_id' => (int) $actor->id, 'fingerprint' => $fingerprint, 'result' => $result];
            $payload['work_entity_receipts'] = $receipts;
            $session->input_payload = $payload;
            $draft['source_input_version'] = 'sha256:'.$this->inputs->fromSession($session);
            unset($draft['evaluation_result']);
            $draft['is_complete'] = false;
            $draft['stage6_status'] = 'scenario_confirmation_required';
            $draft = $this->artifacts->seal($this->reviews->project($draft));
            $this->authorization->authorize($actor, $session, 'estimate_generation.review');
            $session->update(['input_payload' => $payload, 'draft_payload' => $draft, 'state_version' => $result['state_version']]);

            return $result;
        }, 3);
    }
}
