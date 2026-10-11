<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Sessions;

use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\DirectQuantityBinding;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\WorkQuantityInputUnit;
use App\BusinessModules\Addons\EstimateGeneration\Domain\OrdinaryEstimateDecimal;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/** Converts explicitly accepted scenario values into the existing canonical quantity ledger, retaining their assumed origin. */
final readonly class MaterializeSelectedEvaluationQuantities
{
    public function __construct(private UserWorkQuantityFactWriter $numbers, private UserEvaluationChoiceWriter $choices) {}

    public function materialize(User $actor, EstimateGenerationSession $session, array $selected, string $requestId, string $sourceRevisionId): bool
    {
        $payload = $session->input_payload ?? [];
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
            $source = $this->numbers->write($actor, $session, $position['key'], $normalized['value'], $normalized['unit'], $reason, $requestId, true);
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
