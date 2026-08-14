<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Dialogue;

use App\BusinessModules\Addons\EstimateGeneration\Application\Dialogue\EstimateChangeProposal;
use App\BusinessModules\Addons\EstimateGeneration\Application\Dialogue\EstimateUndoInterpretationFactory;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class EstimateUndoInterpretationFactoryTest extends TestCase
{
    public function test_applied_fact_change_becomes_an_inverse_canonical_interpretation(): void
    {
        $interpretation = (new EstimateUndoInterpretationFactory)->make(new EstimateChangeProposal([
            'id' => 'proposal-1',
            'status' => 'applied',
            'intent' => 'correct_fact',
            'before_payload' => [
                'stable_key' => 'fact:room:area',
                'entity_id' => 'entity:room',
                'type' => 'area',
                'value' => '42.5000',
            ],
        ]), $this->context('fact:room:area:v2', 'entity:room', 'area'));

        self::assertSame([
            'kind' => 'correct_fact',
            'version' => 'undo:v1',
            'target_key' => 'fact:room:area:v2',
            'value' => '42.5000',
        ], $interpretation->payload);
    }

    public function test_applied_technology_change_restores_the_previous_catalog_option(): void
    {
        $interpretation = (new EstimateUndoInterpretationFactory)->make(new EstimateChangeProposal([
            'id' => 'proposal-2',
            'status' => 'applied',
            'intent' => 'select_technology',
            'before_payload' => ['decision_key' => 'decision:roof', 'selected_option' => 'roof:metal'],
        ]), ['facts' => []]);

        self::assertSame('select_technology', $interpretation->kind());
        self::assertSame('roof:metal', $interpretation->payload['option_id']);
    }

    public function test_repeated_fact_change_unwraps_the_canonical_selected_fact_value(): void
    {
        $interpretation = (new EstimateUndoInterpretationFactory)->make(new EstimateChangeProposal([
            'id' => 'proposal-3',
            'status' => 'applied',
            'intent' => 'correct_fact',
            'before_payload' => [
                'stable_key' => 'fact:decision:previous',
                'entity_id' => 'entity:room',
                'type' => 'area',
                'value' => ['value' => '51.2500', 'unit' => 'm2'],
            ],
        ]), $this->context('fact:decision:current', 'entity:room', 'area'));

        self::assertSame('51.2500', $interpretation->payload['value']);
    }

    public function test_unapplied_or_unrestorable_change_fails_closed(): void
    {
        foreach ([
            ['status' => 'proposed', 'intent' => 'correct_fact', 'before_payload' => ['stable_key' => 'fact:a', 'value' => '1']],
            ['status' => 'applied', 'intent' => 'select_technology', 'before_payload' => ['decision_key' => 'decision:roof', 'selected_option' => null]],
        ] as $payload) {
            try {
                (new EstimateUndoInterpretationFactory)->make(
                    new EstimateChangeProposal(['id' => 'proposal-x', ...$payload]),
                    ['facts' => []],
                );
                self::fail('Unavailable undo must not create a proposal.');
            } catch (RuntimeException $exception) {
                self::assertSame('estimate_generation.proposal_undo_unavailable', $exception->getMessage());
            }
        }
    }

    private function context(string $stableKey, string $entityId, string $type): array
    {
        return ['facts' => [[
            'stable_key' => $stableKey,
            'entity_id' => $entityId,
            'type' => $type,
        ]]];
    }
}
