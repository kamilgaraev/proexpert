<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation;

use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelSnapshot;
use InvalidArgumentException;

final class ConfirmedEvaluationSelection
{
    public function accepted(ProjectModelSnapshot $snapshot, array $decisions, mixed $choice, array $revision, string $currentInputHash): ?array
    {
        if (! is_array($choice) || ($revision['input_hash'] ?? null) !== $currentInputHash
            || ! is_string($choice['scenario_id'] ?? null)) {
            return null;
        }
        try {
            $selected = (new SelectedEvaluationScenario)->select($revision['result'], $choice['scenario_id']);
        } catch (InvalidArgumentException) {
            return null;
        }
        $expected = ['answer_kind' => 'selected_scenario', 'revision_id' => $revision['revision_id'],
            'content_hash' => $revision['content_hash'], 'input_hash' => $currentInputHash,
            'scenario_id' => $choice['scenario_id'], 'scenario_hash' => $selected['scenario_hash'],
            'selected_scope' => $selected['selected_scope'], 'assumptions_accepted' => $selected['assumptions'] !== [] ? true : ($choice['assumptions_accepted'] ?? null)];
        if (! is_bool($expected['assumptions_accepted'])
            || ! (new EvaluationSelectionVerifier)->accepted($snapshot, $decisions, $choice, 'evaluation_scenario', $expected)) {
            return null;
        }

        return [...$expected, 'fact_id' => $choice['fact_id'], 'decision_id' => $choice['decision_id'],
            'total_by_currency' => $selected['total_by_currency'], 'assumptions' => $selected['assumptions']];
    }
}
