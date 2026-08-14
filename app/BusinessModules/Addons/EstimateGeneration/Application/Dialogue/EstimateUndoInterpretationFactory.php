<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Dialogue;

use RuntimeException;

final class EstimateUndoInterpretationFactory
{
    public function make(EstimateChangeProposal $proposal): EstimateCommandInterpretation
    {
        if (($proposal->payload['status'] ?? null) !== 'applied') {
            throw new RuntimeException('estimate_generation.proposal_undo_unavailable');
        }
        $before = is_array($proposal->payload['before_payload'] ?? null)
            ? $proposal->payload['before_payload']
            : [];

        return match ($proposal->payload['intent'] ?? null) {
            'correct_fact' => $this->fact($before),
            'select_technology' => $this->technology($before),
            default => throw new RuntimeException('estimate_generation.proposal_undo_unavailable'),
        };
    }

    private function fact(array $before): EstimateCommandInterpretation
    {
        $target = $before['stable_key'] ?? null;
        $value = $before['value'] ?? null;
        if (is_array($value) && is_scalar($value['value'] ?? null)) {
            $value = $value['value'];
        }
        if (! is_string($target) || $target === '' || ! is_scalar($value)) {
            throw new RuntimeException('estimate_generation.proposal_undo_unavailable');
        }

        return new EstimateCommandInterpretation([
            'kind' => 'correct_fact',
            'version' => 'undo:v1',
            'target_key' => $target,
            'value' => (string) $value,
        ]);
    }

    private function technology(array $before): EstimateCommandInterpretation
    {
        $decisionKey = $before['decision_key'] ?? null;
        $option = $before['selected_option'] ?? null;
        if (! is_string($decisionKey) || $decisionKey === '' || ! is_string($option) || $option === '') {
            throw new RuntimeException('estimate_generation.proposal_undo_unavailable');
        }

        return new EstimateCommandInterpretation([
            'kind' => 'select_technology',
            'version' => 'undo:v1',
            'decision_key' => $decisionKey,
            'option_id' => $option,
        ]);
    }
}
