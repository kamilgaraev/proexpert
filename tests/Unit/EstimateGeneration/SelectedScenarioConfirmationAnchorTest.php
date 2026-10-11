<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\SelectedScenarioConfirmationAnchor;
use PHPUnit\Framework\TestCase;

final class SelectedScenarioConfirmationAnchorTest extends TestCase
{
    public function test_only_the_exact_final_confirmation_anchor_is_non_numerical(): void
    {
        $anchor = SelectedScenarioConfirmationAnchor::identity(1, 2, 3);
        $value = ['answer_kind' => 'selected_scenario', 'revision_id' => 'b7028058-e768-4853-85b2-12b8e9350a34',
            'scenario_id' => 'base', 'assumptions_accepted' => true, 'content_hash' => str_repeat('a', 64),
            'input_hash' => str_repeat('b', 64), 'scenario_hash' => str_repeat('c', 64)];
        $matches = static fn (array $changes = []): bool => SelectedScenarioConfirmationAnchor::matchesIdentity(1, 2, 3,
            $changes['source_version'] ?? $anchor['source_version'], $changes['entity_id'] ?? $anchor['entity_id'],
            $changes['type'] ?? 'evaluation_scenario', $changes['origin'] ?? 'user_input', $changes['status'] ?? 'confirmed', $changes['value'] ?? $value);
        self::assertTrue($matches());
        foreach ([['entity_id' => 'entity:scenario-set'], ['type' => 'quantity'], ['type' => 'evaluation_price'], ['type' => 'evaluation_scope'],
            ['origin' => 'ai_inference'], ['origin' => 'user_assumption'], ['status' => 'candidate'],
            ['source_version' => 'sha256:'.str_repeat('d', 64)], ['value' => ['version' => 'scenario-set:v1', 'definitions' => []]],
            ['value' => [...$value, 'content_hash' => 'bad']], ['value' => [...$value, 'revision_id' => 'bad']]] as $change) {
            self::assertFalse($matches($change));
        }
        self::assertFalse(SelectedScenarioConfirmationAnchor::matchesIdentity(4, 2, 3, $anchor['source_version'], $anchor['entity_id'],
            'evaluation_scenario', 'user_input', 'confirmed', $value));
    }
}
