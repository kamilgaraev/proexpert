<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Factory;

use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;

final class FactoryWorkingInput
{
    public const CONTRACT = 'factory-working-input:v1';

    private const REPLAY_FIELDS = [
        'manual_fact_receipts', 'prerequisite_receipts', 'feedback_receipts',
        'work_entity_receipts', 'room_identity_receipts', 'scenario_confirmation_receipts',
        'evaluation_recalculation_receipts', 'evaluation_contingency_receipts', 'evaluation_price_receipts',
        'evaluation_currency_receipts', 'evaluation_decision_receipts', 'evaluation_scenario_receipts',
        'evaluation_scope_receipts', 'evaluation_quantity_receipts', 'evaluation_technology_receipts',
        'regional_price_selection_receipts',
    ];

    public static function fromPayload(array $payload): array
    {
        foreach (self::REPLAY_FIELDS as $key) {
            if (is_array($payload[$key] ?? null)) {
                unset($payload[$key]);
            }
        }

        return $payload;
    }

    public static function diagnostics(array $payload): array
    {
        $rawBytes = strlen(CanonicalPipelineJson::encode($payload));
        $workingBytes = strlen(CanonicalPipelineJson::encode(self::fromPayload($payload)));
        $components = [];
        foreach (self::REPLAY_FIELDS as $key) {
            if (is_array($payload[$key] ?? null)) {
                $components[$key] = strlen(CanonicalPipelineJson::encode($payload[$key]));
            }
        }

        return ['contract_version' => self::CONTRACT, 'input_bytes' => $rawBytes,
            'working_input_bytes' => $workingBytes, 'history_bytes' => $rawBytes - $workingBytes,
            'history_components' => $components];
    }
}
