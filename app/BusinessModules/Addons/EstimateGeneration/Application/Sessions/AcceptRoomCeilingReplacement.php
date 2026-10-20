<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Sessions;

use App\BusinessModules\Addons\EstimateGeneration\Application\Credits\FrozenWorkItemLimit;
use App\BusinessModules\Addons\EstimateGeneration\Application\Generation\BuildMostEstimateDraft;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\UniversalReviewSummaryProjector;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\WorkEntityScopePolicy;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\StaleEstimateGenerationState;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\CurrentProjectDerivedQuantityService;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\NativeQuantityDecisionInputs;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\RoomCeilingReplacement;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\RoomCeilingScope;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\WorkItemQuantityMapper;
use App\Models\User;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final readonly class AcceptRoomCeilingReplacement
{
    public function __construct(private ConnectionInterface $database, private EstimateGenerationActionAuthorization $authorization,
        private RoomCeilingReplacementPreview $preview, private UserEvaluationChoiceWriter $choices, private ProjectModelRepository $models,
        private CurrentProjectDerivedQuantityService $quantities, private WorkItemQuantityMapper $mapper,
        private EvaluationInputFingerprint $inputs, private BuildMostEstimateDraft $artifacts, private UniversalReviewSummaryProjector $reviews) {}

    public function show(User $actor, int $projectId, int $sessionId, string $parentKey): array
    {
        return $this->database->transaction(function () use ($actor, $projectId, $sessionId, $parentKey): array {
            $session = $this->session($actor, $projectId, $sessionId);
            $this->authorization->authorize($actor, $session, 'estimate_generation.view');

            return $this->preview->current($session, $parentKey);
        });
    }

    public function handle(User $actor, int $projectId, int $sessionId, array $input): array
    {
        if (! is_string($input['request_id'] ?? null) || ! Str::isUuid($input['request_id'])
            || ! is_int($input['state_version'] ?? null) || $input['state_version'] < 0
            || ! is_string($input['parent_work_item_key'] ?? null) || $input['parent_work_item_key'] === '' || strlen($input['parent_work_item_key']) > 160
            || ! is_string($input['preview_fingerprint'] ?? null) || preg_match('/\A[a-f0-9]{64}\z/', $input['preview_fingerprint']) !== 1
            || ($input['method'] ?? null) !== RoomCeilingScope::METHOD || ! is_string($input['reason'] ?? null)
            || mb_strlen(trim($input['reason'])) < 12 || strlen($input['reason']) > 1000
            || array_diff(array_keys($input), ['request_id', 'state_version', 'parent_work_item_key', 'preview_fingerprint', 'method', 'reason']) !== []) {
            $this->reject('invalid');
        }

        return $this->database->transaction(function () use ($actor, $projectId, $sessionId, $input): array {
            $session = $this->session($actor, $projectId, $sessionId);
            $this->authorization->authorize($actor, $session, 'estimate_generation.review');
            $payload = $session->input_payload ?? [];
            $receipts = $payload['room_ceiling_replacement_receipts'] ?? [];
            $fingerprint = hash('sha256', CanonicalPipelineJson::encode($input));
            if (isset($receipts[$input['request_id']])) {
                $receipt = $receipts[$input['request_id']];
                if ($receipt['actor_id'] !== (int) $actor->id || ! hash_equals($receipt['fingerprint'], $fingerprint)) {
                    $this->reject('receipt_conflict');
                }

                return $receipt['result'];
            }
            if ((int) $session->state_version !== $input['state_version']) {
                throw new StaleEstimateGenerationState($sessionId, $input['state_version']);
            }
            if (count($receipts) >= 10000) {
                $this->reject('unavailable');
            }
            $preview = $this->preview->current($session, $input['parent_work_item_key']);
            if (! hash_equals($preview['preview_fingerprint'], $input['preview_fingerprint'])) {
                $this->reject('changed');
            }
            $draft = $session->draft_payload;
            $parentKey = $input['parent_work_item_key'];
            $parent = array_values(array_filter(RoomCeilingReplacement::rows($draft), static fn (array $row): bool => $row['key'] === $parentKey))[0];
            $scope = [(int) $session->organization_id, $projectId, $sessionId];
            $capture = $this->models->snapshotForPlanning(...[...$scope, 10001]);
            $decisions = $this->models->decisionsForSelectedFacts(...[...$scope, NativeQuantityDecisionInputs::factIds($capture['snapshot'])]);
            $ceilingScopes = RoomCeilingScope::select($capture['snapshot'], $decisions);
            $bindings = $scopeProofs = [];
            foreach ($preview['rooms'] as $room) {
                $id = $room['entity_id'];
                $scopeProofs[$id] = $ceilingScopes[$id] ?? $this->choices->write($actor, $session, RoomCeilingScope::anchor($id),
                    'evaluation_answer', $room['scope'], $input['reason'], $input['request_id']);
                $identity = $room['identity'];
                $selection = ['answer_kind' => 'work_entity_binding', 'binding_contract' => WorkEntityScopePolicy::BINDING_CONTRACT,
                    'work_item_key' => $room['work_item_key'], 'entity_id' => $id, 'source_entity_type' => 'room',
                    'source_fact_id' => $identity['source_fact_id'], 'source_fact_version' => $identity['source_fact_version'],
                    'source_value_hash' => $identity['source_value_hash'], 'source_version' => $identity['source_version']];
                $proof = $this->choices->write($actor, $session, 'work-entity:'.$room['work_item_key'], 'evaluation_answer',
                    $selection, $input['reason'], $input['request_id']);
                $bindings[$id] = [...$selection, 'fact_id' => $proof['fact_id'], 'decision_id' => $proof['decision_id'], 'ledger_entity_id' => $proof['entity_id']];
                $payload['work_entity_selections'][$room['work_item_key']] = $bindings[$id];
            }
            $selection = ['answer_kind' => 'room_ceiling_replacement', 'contract' => RoomCeilingReplacement::CONTRACT,
                'generation_id' => $payload['composition_generation_id'] ?? null, 'work_item_key' => $parentKey, 'work_key' => $preview['work_key'],
                'rooms' => array_column($preview['rooms'], 'work_item_key', 'entity_id'),
                'scope_fact_ids' => array_column($scopeProofs, 'fact_id'),
                'previous_parent_hash' => hash('sha256', CanonicalPipelineJson::encode($parent)), 'preview_fingerprint' => $input['preview_fingerprint']];
            $proof = $this->choices->write($actor, $session, 'room-ceiling-replacement:'.hash('sha256', $parentKey), 'evaluation_answer',
                $selection, $input['reason'], $input['request_id']);
            $replacement = [...$selection, 'fact_id' => $proof['fact_id'], 'decision_id' => $proof['decision_id'], 'ledger_entity_id' => $proof['entity_id']];
            $this->choices->write($actor, $session, 'work:'.$parentKey, 'evaluation_answer',
                ['answer_kind' => 'quantity_scope_invalidated', 'work_item_key' => $parentKey, 'replacement_fact_id' => $proof['fact_id']],
                $input['reason'], $input['request_id']);
            if (isset($payload['price_selections'][$parentKey])) {
                $this->choices->write($actor, $session, 'work:'.$parentKey, 'evaluation_price',
                    ['answer_kind' => 'price_scope_invalidated', 'work_item_key' => $parentKey, 'replacement_fact_id' => $proof['fact_id']],
                    $input['reason'], $input['request_id']);
                $payload['price_selection_revision'] = ($payload['price_selection_revision'] ?? 0) + 1;
            }
            unset($payload['quantity_selections'][$parentKey], $payload['price_selections'][$parentKey], $payload['work_entity_selections'][$parentKey]);
            $payload['quantity_selection_revision'] = ($payload['quantity_selection_revision'] ?? 0) + 1;
            $payload['work_entity_selection_revision'] = ($payload['work_entity_selection_revision'] ?? 0) + 1;
            $payload['room_ceiling_replacements'][$parentKey] = $replacement;
            $payload['room_ceiling_replacement_history'][$input['request_id']] = ['parent_item' => $parent, 'replacement' => $replacement];
            $payload['room_ceiling_replacement_revision'] = ($payload['room_ceiling_replacement_revision'] ?? 0) + 1;
            $capture = $this->models->snapshotForPlanning(...[...$scope, 10001]);
            $decisions = $this->models->decisionsForSelectedFacts(...[...$scope, NativeQuantityDecisionInputs::factIds($capture['snapshot'])]);
            $quantities = $this->quantities->simulateInput($capture['snapshot'], $capture['token'], $decisions)['quantities'];
            $children = [];
            foreach ($preview['rooms'] as $room) {
                $quantity = $this->mapper->mapForEntity($preview['work_key'], $quantities, $room['entity_id']);
                if ($quantity === null) {
                    $this->reject('changed');
                }
                $basis = $quantity->toArray();
                $child = array_intersect_key($parent, array_flip(['work_key', 'name', 'work_category', 'normative_search_text', 'evaluation_section', 'item_type']));
                $child = [...$child, 'key' => $room['work_item_key'], 'name' => ($parent['name'] ?? $preview['work_key']).' — '.$room['name'],
                    'entity_id' => $room['entity_id'], 'item_type' => 'priced_work', 'unit' => $quantity->unit, 'quantity' => $quantity->amount,
                    'quantity_status' => 'assumed', 'quantity_formula' => $quantity->formulaKey, 'quantity_basis_details' => $basis, 'quantity_evidence' => $basis,
                    'unit_price' => null, 'total_cost' => null, 'pricing_status' => 'not_calculated', 'pricing_blocker' => 'normative_required',
                    'materials' => [], 'labor' => [], 'machinery' => [], 'equipment' => [], 'other_resources' => [],
                    'validation_flags' => ['normative_required'], 'normative_rate_code' => null,
                    'metadata' => ['generation_source' => 'accepted_room_ceiling_replacement', 'composition_work_key' => $preview['work_key'],
                        'quantity_key' => $preview['work_key'], 'quantity_source' => $quantity->source->value, 'display_role' => 'work',
                        'scope_entity_id' => $room['entity_id'], 'scope_entity_binding' => $bindings[$room['entity_id']],
                        'room_ceiling_replacement' => $replacement, 'requires_quantity_confirmation' => false],
                    'source_refs' => array_map(static fn (array $operand): array => ['fact_id' => $operand['fact_id']], $quantity->formulaInputs['operands'])];
                if (isset($parent['logical_key'])) {
                    $child['logical_key'] = $child['key'];
                }
                $children[] = $child;
            }
            foreach ($draft['local_estimates'] as &$local) {
                foreach ($local['sections'] as &$section) {
                    $items = [];
                    foreach ($section['work_items'] ?? [] as $item) {
                        array_push($items, ...($item['key'] === $parentKey ? $children : [$item]));
                    }
                    $section['work_items'] = $items;
                }
                unset($section);
            }
            unset($local);
            FrozenWorkItemLimit::assertDraft($draft, 1000);
            $draft['evaluation_rows_count'] = count(RoomCeilingReplacement::rows($draft));
            unset($payload['confirmed_evaluation'], $payload['scope_selection'], $payload['scenario_selections']);
            unset($draft['evaluation_result'], $draft['evaluation_policy']['scope_selection'], $draft['evaluation_policy']['scenario_selections']);
            $session->input_payload = $payload;
            $draft['source_input_version'] = 'sha256:'.$this->inputs->fromSession($session);
            $draft['is_complete'] = false;
            $draft['stage6_status'] = 'scenario_confirmation_required';
            $result = ['request_id' => $input['request_id'], 'state_version' => (int) $session->state_version + 1,
                'removed_work_item_key' => $parentKey, 'added_work_item_keys' => array_column($children, 'key'),
                'cost_units' => '0', 'recalculation_required' => true, 'scope_confirmation_required' => true,
                'scenario_confirmation_required' => true, 'estimate_complete' => false];
            $receipts[$input['request_id']] = ['fingerprint' => $fingerprint, 'actor_id' => (int) $actor->id, 'result' => $result];
            $payload['room_ceiling_replacement_receipts'] = $receipts;
            $this->authorization->authorize($actor, $session, 'estimate_generation.review');
            $session->update(['input_payload' => $payload, 'draft_payload' => $this->artifacts->seal($this->reviews->project($draft)),
                'state_version' => $result['state_version']]);

            return $result;
        }, 3);
    }

    private function session(User $actor, int $projectId, int $sessionId): EstimateGenerationSession
    {
        return EstimateGenerationSession::query()->where('organization_id', $actor->current_organization_id)
            ->where('project_id', $projectId)->whereKey($sessionId)->lockForUpdate()->firstOrFail();
    }

    private function reject(string $key): never
    {
        throw ValidationException::withMessages(['ceiling' => trans_message('estimate_ceiling.'.$key)]);
    }
}
