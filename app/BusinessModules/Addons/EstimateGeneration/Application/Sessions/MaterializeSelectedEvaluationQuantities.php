<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Sessions;

use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\DirectQuantityBinding;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\EvaluationSelectionVerifier;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\WorkQuantityInputUnit;
use App\BusinessModules\Addons\EstimateGeneration\Domain\OrdinaryEstimateDecimal;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\Models\User;
use Brick\Math\BigDecimal;
use Illuminate\Validation\ValidationException;

/** Converts explicitly accepted scenario values into the existing canonical quantity ledger, retaining their assumed origin. */
final readonly class MaterializeSelectedEvaluationQuantities
{
    public function __construct(private UserWorkQuantityFactWriter $numbers, private UserEvaluationChoiceWriter $choices,
        private \App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository $models) {}

    public function materialize(User $actor, EstimateGenerationSession $session, array $selected, string $requestId, string $sourceRevisionId): bool
    {
        $payload = $session->input_payload ?? [];
        $snapshot = $this->models->snapshotForPlanning((int) $session->organization_id, (int) $session->project_id, (int) $session->id, 10001)['snapshot'];
        if (count($snapshot->facts) > 10000) {
            throw ValidationException::withMessages(['scenario' => 'Разделите проект: предел фактов превышен.']);
        }
        $entities = array_column($snapshot->entities, null, 'id');
        $facts = array_column($snapshot->facts, null, 'id');
        $bindingIds = [];
        foreach ($selected['scenario']['positions'] as $position) {
            $id = $payload['quantity_selections'][$position['key']]['fact_id'] ?? null;
            if (($position['quantity_status'] ?? null) === 'assumed' && is_string($id) && isset($facts[$id])) {
                $bindingIds[] = $id;
            }
        }
        $decisions = $bindingIds === [] ? [] : $this->models->decisionsForSelectedFacts((int) $session->organization_id,
            (int) $session->project_id, (int) $session->id, array_values(array_unique($bindingIds)));
        $decisionIndex = array_column($decisions, null, 'selectedFactId');
        $changed = false;
        foreach ($selected['scenario']['positions'] as $position) {
            if (($position['quantity_status'] ?? null) !== 'assumed') {
                continue;
            }
            $normalized = WorkQuantityInputUnit::normalize($position['quantity'], $position['unit']);
            if ($normalized === null || ! OrdinaryEstimateDecimal::fitsQuantity($normalized['value'])) {
                throw ValidationException::withMessages(['scenario' => 'Сценарный объём после перевода единицы превышает допустимую точность.']);
            }
            $reason = 'Принятое допущение сценария '.$selected['scenario_id'].' ревизии '.$sourceRevisionId.'. Размеры по фотографии не подтверждаются.';
            $target = $entities[$position['entity_id'] ?? ''] ?? null;
            if ($target === null) {
                throw ValidationException::withMessages(['scenario' => 'Подтвердите объект работы перед сохранением сценарного объёма.']);
            }
            $selection = $payload['quantity_selections'][$position['key']] ?? null;
            $bindingId = is_array($selection) ? ($selection['fact_id'] ?? null) : null;
            $binding = is_string($bindingId) ? ($facts[$bindingId] ?? null) : null;
            $decision = $binding === null ? null : ($decisionIndex[$binding->id] ?? null);
            $current = $binding === null || $decision === null ? null : (new DirectQuantityBinding)->source($snapshot, $binding, $decision);
            if ($current !== null && ($selection['decision_id'] ?? null) === $decision->id
                && ($binding->value['work_item_key'] ?? null) === $position['key']
                && (new EvaluationSelectionVerifier)->accepted($snapshot, $decisions, $selection, 'evaluation_answer',
                    array_diff_key($binding->value, array_flip(['reason', 'decision_id'])))
                && $current->origin === 'user_assumption' && $current->entityId === $target->id
                && $current->unit === $normalized['unit'] && BigDecimal::of($current->value)->isEqualTo($normalized['value'])) {
                continue;
            }
            $source = $this->numbers->write($actor, $session, $position['key'], $normalized['value'], $normalized['unit'], $reason, $requestId, true, $target);
            $selection = ['answer_kind' => 'direct_quantity', 'work_item_key' => $position['key'], 'quantity_key' => DirectQuantityBinding::key($position['key']),
                'source_fact_id' => $source->id, 'source_fact_version' => $source->version, 'source_version' => $source->sourceVersion,
                'source_entity_id' => $source->entityId, 'source_unit' => $source->unit, 'source_value_hash' => DirectQuantityBinding::valueHash($source->value),
                'entered_value' => $position['quantity'], 'entered_unit' => $position['unit'], 'value_status' => 'user_assumption',
                'source_revision_id' => $sourceRevisionId, 'source_scenario_id' => $selected['scenario_id']];
            $proof = $this->choices->write($actor, $session, 'work:'.$position['key'], 'evaluation_answer', $selection, $reason, $requestId);
            $payload['quantity_selections'][$position['key']] = [...$selection, ...$proof];
            $changed = true;
        }
        if ($changed) {
            $payload['quantity_selection_revision'] = ($payload['quantity_selection_revision'] ?? 0) + 1;
            unset($payload['confirmed_evaluation']);
            $session->update(['input_payload' => $payload]);
        }

        return $changed;
    }
}
