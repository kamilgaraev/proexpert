<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Quantities;

use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\DirectQuantityBinding;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Decision;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Entity;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Evidence;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelSnapshot;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\DerivedQuantityFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DirectQuantityFactoryTest extends TestCase
{
    #[DataProvider('units')]
    public function test_literal_quantities_need_an_exact_user_binding_and_preserve_real_evidence(string $value, string $sourceUnit, string $unit, string $expected): void
    {
        [$snapshot, $decisions, $request] = $this->fixture($value, $sourceUnit, $unit);
        $result = (new DerivedQuantityFactory)->derive($snapshot, $decisions, $request);
        self::assertTrue($result->isReady(), json_encode($result->unresolvedInputs));
        self::assertSame($expected, $result->quantity->value);
        self::assertSame($unit, $result->quantity->unit);
        self::assertSame(['evidence:1', 'evidence:2'], $result->quantity->evidenceIds);
        self::assertSame('equipment:purchase', $result->quantity->operands[0]['quantity_binding']['work_item_key']);
        $request['quantity_binding_fact_id'] = 'fact:missing';
        self::assertFalse((new DerivedQuantityFactory)->derive($snapshot, $decisions, $request)->isReady());
    }

    public static function units(): array
    {
        return [['3', 'шт', 'count', '3'], ['1500', 'mm', 'm', '1.5'],
            ['125', 'm2', 'm2', '125'], ['2.25', 'm3', 'm3', '2.25'],
            ['2.5', 't', 'kg', '2500'], ['12', 'h', 'h', '12']];
    }

    public function test_user_binding_does_not_promote_a_model_number_or_pdf_locator_to_a_native_quantity(): void
    {
        foreach ([['ai_inference', 'native_numeric_parser'], ['document', 'drawing_analyzer'], ['document', 'pdf_geometry']] as [$origin, $producer]) {
            [$snapshot, $decisions, $request] = $this->fixture('3', 'count', 'count', $origin, $producer);
            self::assertFalse((new DerivedQuantityFactory)->derive($snapshot, $decisions, $request)->isReady());
        }
    }

    public function test_binding_is_invalid_when_the_source_value_version_or_choice_evidence_changes(): void
    {
        foreach (['source_value_hash', 'source_fact_version', 'source_version', 'source_entity_id', 'choice_hash'] as $field) {
            [$snapshot, $decisions, $request] = $this->fixture('3', 'count', 'count', changed: $field);
            self::assertFalse((new DerivedQuantityFactory)->derive($snapshot, $decisions, $request)->isReady(), $field);
        }
    }

    private function fixture(string $value, string $sourceUnit, string $unit, string $origin = 'document', string $producer = 'native_numeric_parser', ?string $changed = null): array
    {
        $version = 'sha256:'.str_repeat('a', 64);
        $choiceVersion = 'sha256:'.str_repeat('b', 64);
        $sourceFingerprint = hash('sha256', 'literal-source');
        $source = new Fact('fact:source', 1, 2, 3, $version, 'entity:native', 'quantity', $value, $sourceUnit, 0.0, $origin, 'confirmed', ['evidence:1'],
            nativeNumericProofs: ['evidence:1' => ['entity_id' => 'entity:native', 'source_version' => $version, 'parameter' => 'quantity',
                'measurement' => \App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\FactVocabulary::measurementSignature($value, $sourceUnit), 'evidence_fingerprint' => $sourceFingerprint]]);
        $bindingValue = ['answer_kind' => 'direct_quantity', 'work_item_key' => 'equipment:purchase',
            'quantity_key' => DirectQuantityBinding::key('equipment:purchase'), 'source_fact_id' => $source->id, 'source_fact_version' => 1,
            'source_version' => $version, 'source_entity_id' => $source->entityId, 'source_unit' => $sourceUnit,
            'source_value_hash' => DirectQuantityBinding::valueHash($value), 'decision_id' => 'decision:binding', 'reason' => 'Спецификация выбранного оборудования'];
        if ($changed !== null && $changed !== 'choice_hash') {
            $bindingValue[$changed] = $changed === 'source_fact_version' ? 2 : str_repeat('c', 64);
        }
        $binding = new Fact('fact:binding', 1, 2, 3, $choiceVersion, 'entity:choice', 'evaluation_answer', $bindingValue, null, 0.0, 'user_input', 'confirmed', ['evidence:2']);
        $choiceHash = $changed === 'choice_hash' ? str_repeat('d', 64) : hash('sha256', CanonicalPipelineJson::encode($bindingValue));
        $proofs = [new Evidence('evidence:1', 1, 2, 3, $version, 'document:1', 'document', nativeReference: 'xlsx:sheet:Оборудование!C2', producerName: $producer, fingerprint: $sourceFingerprint),
            new Evidence('evidence:2', 1, 2, 3, $choiceVersion, 'input:3', 'user_input', nativeReference: 'input_payload.evaluation_choices',
                producerName: 'user_input_normalizer', sourceReference: 'input:3', choiceHash: $choiceHash)];
        $snapshot = new ProjectModelSnapshot([new Entity('entity:native', 1, 2, 3, $version, 'quantity', 'entity:native'),
            new Entity('entity:choice', 1, 2, 3, $choiceVersion, 'quantity', 'entity:choice')], [$source, $binding], $proofs, []);
        $decisions = [new Decision('decision:binding', 1, 2, 3, $choiceVersion, 'fact', $binding->id, $binding->id, 'user', '42', $bindingValue['reason'], 1, ['evidence:2'])];
        $request = ['quantity_id' => $bindingValue['quantity_key'], 'formula_identity' => 'direct_quantity', 'formula_version' => '1',
            'entity_id' => $source->entityId, 'operands' => ['measurement' => $source->id], 'output_unit' => $unit, 'rounding_mode' => 'half_up', 'rounding_scale' => 8,
            'quantity_binding_fact_id' => $binding->id, 'quantity_binding_decision_id' => 'decision:binding'];

        return [$snapshot, $decisions, $request];
    }
}
