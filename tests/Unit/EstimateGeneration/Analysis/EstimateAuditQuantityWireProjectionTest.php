<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Analysis;

use App\BusinessModules\Addons\EstimateGeneration\Analysis\Audit\EstimateAuditContextPlanner;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Audit\EstimateAuditDraftWireProjection;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Audit\EstimateAuditInput;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Audit\EstimateAuditQuantityWireProjection;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\EstimateComposerCorrectionInput;
use App\BusinessModules\Addons\EstimateGeneration\Observability\EstimatorTextWirePolicy;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;
use PHPUnit\Framework\TestCase;

final class EstimateAuditQuantityWireProjectionTest extends TestCase
{
    public function test_repeated_operand_context_is_lossless_and_preserves_both_replay_fingerprints(): void
    {
        $quantities = $this->quantities();
        $basis = $quantities[0]['quantity_basis'];
        $draft = ['local_estimates' => [['sections' => [['work_items' => [
            ['key' => 'wall', 'quantity_evidence' => $basis, 'quantity_basis_details' => $basis],
        ]]]]]];
        $input = new EstimateAuditInput(1, 2, 3, str_repeat('a', 64), 0, [], $quantities, $draft, [], 'estimate-auditor:v1');
        $wire = $input->canonicalPayload();
        $operands = $wire['derived_quantities'][0]['quantity_basis']['formula_inputs']['operands'];

        self::assertCount(8, $operands);
        self::assertSame('audit_input.derived_quantities[0].quantity_basis.formula_inputs.snapshot_identity', $operands[0]['snapshot_identity_ref']);
        self::assertArrayNotHasKey('snapshot_identity', $operands[0]);
        self::assertSame($basis['formula_inputs']['operands'][0]['geometry_relations'], $operands[0]['geometry_relations']);
        self::assertSame('audit_input.derived_quantities[0].quantity_basis.formula_inputs.operands[0].geometry_relations', $operands[7]['geometry_relations_ref']);
        self::assertSame('audit_input.derived_quantities[0].quantity_basis.formula_inputs.operands[0].quantity_binding', $operands[7]['quantity_binding_ref']);
        self::assertSame(CanonicalPipelineJson::encode($quantities), CanonicalPipelineJson::encode($this->expand($wire['derived_quantities'], ['audit_input' => $wire])));
        self::assertLessThan(strlen(CanonicalPipelineJson::encode($quantities)) / 2, strlen(CanonicalPipelineJson::encode($wire['derived_quantities'])));
        self::assertSame($quantities, $input->derivedQuantities);
        self::assertSame($draft, $input->draft);
        self::assertSame($wire['derived_quantities'], EstimateAuditQuantityWireProjection::project($wire['derived_quantities']));
        self::assertSame('audit_input.derived_quantities[0].quantity_basis', $wire['draft']['local_estimates'][0]['sections'][0]['work_items'][0]['quantity_basis_details_ref']);

        $legacyPayload = $wire;
        $legacyPayload['derived_quantities'] = $quantities;
        $legacyPayload['draft'] = $draft;
        self::assertSame(hash('sha256', CanonicalPipelineJson::encode($legacyPayload)), $input->fingerprint());
        $findings = [['finding_id' => 'finding:wall', 'type' => 'quantity_mismatch']];
        $correction = new EstimateComposerCorrectionInput($input, $findings);
        $legacyPayload['draft'] = EstimateAuditDraftWireProjection::project($draft, $quantities);
        self::assertSame(hash('sha256', json_encode(['audit_input' => $legacyPayload, 'findings' => $findings], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)), $correction->fingerprint());

        $changed = $quantities;
        $changed[0]['quantity_basis']['formula_inputs']['operands'][7]['decision_reason'] .= ' changed';
        $changedInput = new EstimateAuditInput(1, 2, 3, str_repeat('a', 64), 0, [], $changed, $draft, [], 'estimate-auditor:v1');
        self::assertNotSame($input->fingerprint(), $changedInput->fingerprint());
        self::assertNotSame($correction->fingerprint(), (new EstimateComposerCorrectionInput($changedInput, $findings))->fingerprint());
    }

    public function test_distinct_values_existing_references_and_short_fields_remain_unchanged(): void
    {
        $quantities = $this->quantities();
        $operands = &$quantities[0]['quantity_basis']['formula_inputs']['operands'];
        $operands[0]['snapshot_identity_ref'] = 'existing.reference';
        $operands[1]['snapshot_identity']['version'] = 1;
        $operands[2]['snapshot_identity']['version'] = '1';
        $operands[3]['geometry_relations'] = array_reverse($operands[3]['geometry_relations']);
        $operands[4]['snapshot_identity']['scope'] = 'another snapshot';
        unset($operands);
        $wire = EstimateAuditQuantityWireProjection::project($quantities);
        $projected = $wire[0]['quantity_basis']['formula_inputs']['operands'];
        foreach ([0, 1, 2, 4] as $index) {
            self::assertSame($quantities[0]['quantity_basis']['formula_inputs']['operands'][$index]['snapshot_identity'], $projected[$index]['snapshot_identity']);
        }
        self::assertSame('existing.reference', $projected[0]['snapshot_identity_ref']);
        self::assertSame($quantities[0]['quantity_basis']['formula_inputs']['operands'][3]['geometry_relations'], $projected[3]['geometry_relations']);
        foreach ($projected as $index => $operand) {
            foreach (['fact_id', 'decision_id', 'value', 'unit', 'evidence_ids', 'locator', 'decision_reason', 'formula_identity', 'formula_version', 'rounding_policy', 'technology_decision_id'] as $field) {
                self::assertSame($quantities[0]['quantity_basis']['formula_inputs']['operands'][$index][$field], $operand[$field]);
            }
        }
    }

    public function test_native_wall_proof_fits_the_frozen_wire_limit_without_removing_unique_metadata(): void
    {
        $quantities = $this->quantities();
        $facts = array_map(static fn (int $i): array => ['id' => 'fact:'.$i, 'entity_id' => 'wall:1'], range(0, 7));
        $evidence = array_map(static fn (int $i): array => ['fact_id' => 'fact:'.$i, 'locator' => ['page' => 1, 'dimension' => $i]], range(0, 7));
        $draft = ['project_proof' => str_repeat('retained source; ', 2350), 'local_estimates' => [['sections' => [['work_items' => [
            ['key' => 'wall', 'entity_id' => 'wall:1', 'quantity_formula' => 'quantity:wall'],
        ]]]]]];
        $root = new EstimateAuditInput(1, 2, 3, str_repeat('a', 64), 0, $facts, $quantities, $draft, $evidence, 'estimate-auditor:v1');
        $legacyPayload = $root->canonicalPayload();
        $legacyPayload['derived_quantities'] = $quantities;
        $legacyUpper = EstimatorTextWirePolicy::serializedInputUpper([
            ['role' => 'system', 'content' => str_repeat('context ', 1024)],
            ['role' => 'user', 'content' => CanonicalPipelineJson::encode(['audit_input' => $legacyPayload])],
        ]);
        self::assertGreaterThan(65536, $legacyUpper);
        $policy = ['contract' => EstimatorTextWirePolicy::CONTRACT, 'profile' => 'normal',
            'token_limits' => ['input_tokens' => 24576, 'output_tokens' => 8192, 'max_calls' => 5],
            'maximum_serialized_input_bytes' => 65536];

        $parts = (new EstimateAuditContextPlanner($policy))->prepare($root, 458752);

        self::assertCount(1, $parts);
        self::assertSame($facts, $parts[0]['input']->facts);
        self::assertSame($evidence, $parts[0]['input']->evidence);
        self::assertSame($draft, $parts[0]['input']->draft);
        self::assertSame($quantities, $parts[0]['input']->derivedQuantities);
        self::assertLessThanOrEqual(65536, EstimatorTextWirePolicy::serializedInputUpper([
            ['role' => 'system', 'content' => str_repeat('context ', 1024)],
            ['role' => 'user', 'content' => CanonicalPipelineJson::encode(['audit_input' => $parts[0]['input']->canonicalPayload()])],
        ]));
    }

    public function test_partitioned_wall_context_uses_the_same_projection_for_admission_and_wire(): void
    {
        $first = $this->quantities()[0];
        $second = json_decode(str_replace(['wall:1', 'fact:', 'quantity:wall'], ['wall:2', 'fact:second:', 'quantity:second'],
            CanonicalPipelineJson::encode($first)), true, 64, JSON_THROW_ON_ERROR);
        $quantities = [$first, $second];
        $facts = $items = [];
        foreach ($quantities as $index => $quantity) {
            foreach ($quantity['operand_fact_ids'] as $id) {
                $facts[] = ['id' => $id, 'entity_id' => $quantity['entity_id']];
            }
            $items[] = ['key' => 'work:'.$index, 'entity_id' => $quantity['entity_id'], 'quantity_formula' => $quantity['id'],
                'proof' => str_repeat('unique wall proof; ', 700)];
        }
        $draft = ['project_proof' => str_repeat('retained source; ', 1500),
            'local_estimates' => [['sections' => [['work_items' => $items]]]]];
        $root = new EstimateAuditInput(1, 2, 3, str_repeat('a', 64), 0, $facts, $quantities, $draft, [], 'estimate-auditor:v1');
        $policy = ['contract' => EstimatorTextWirePolicy::CONTRACT, 'profile' => 'normal',
            'token_limits' => ['input_tokens' => 24576, 'output_tokens' => 8192, 'max_calls' => 5],
            'maximum_serialized_input_bytes' => 65536];

        $parts = (new EstimateAuditContextPlanner($policy))->prepare($root, 458752);

        self::assertCount(2, $parts);
        $seen = [];
        foreach ($parts as $part) {
            $input = $part['input'];
            $wire = $input->canonicalPayload();
            self::assertArrayHasKey('context_manifest', $wire);
            self::assertCount(1, $input->derivedQuantities);
            $seen[] = $input->derivedQuantities[0]['id'];
            self::assertSame(CanonicalPipelineJson::encode($input->derivedQuantities), CanonicalPipelineJson::encode($this->expand($wire['derived_quantities'], ['audit_input' => $wire])));
            self::assertSame($input->derivedQuantities[0]['operand_fact_ids'], array_column($input->facts, 'id'));
            $messages = [
                ['role' => 'system', 'content' => str_repeat('context ', 1024)],
                ['role' => 'user', 'content' => CanonicalPipelineJson::encode(['audit_input' => $wire])],
            ];
            self::assertLessThanOrEqual(65536, EstimatorTextWirePolicy::serializedInputUpper($messages));
            $wire['derived_quantities'] = $input->derivedQuantities;
            $messages[1]['content'] = CanonicalPipelineJson::encode(['audit_input' => $wire]);
            self::assertGreaterThan(65536, EstimatorTextWirePolicy::serializedInputUpper($messages));
        }
        self::assertSame(array_column($quantities, 'id'), $seen);
    }

    public function test_unused_quantities_are_balanced_by_projected_size_instead_of_repeated_proofs(): void
    {
        $compressible = $this->quantities()[0];
        $unique = ['id' => 'quantity:unique', 'operand_fact_ids' => [],
            'quantity_basis' => ['key' => 'unique', 'proof' => str_repeat('unique proof; ', 900)]];
        $facts = array_map(static fn (string $id): array => ['id' => $id, 'entity_id' => 'wall:1'], $compressible['operand_fact_ids']);
        $draft = ['project_proof' => str_repeat('retained source; ', 1700),
            'local_estimates' => [['sections' => [['work_items' => [
                ['key' => 'large-work', 'proof' => str_repeat('large work; ', 1600)],
                ['key' => 'small-work'],
            ]]]]]];
        $root = new EstimateAuditInput(1, 2, 3, str_repeat('a', 64), 0, $facts, [$compressible, $unique], $draft, [], 'estimate-auditor:v1');
        $policy = ['contract' => EstimatorTextWirePolicy::CONTRACT, 'profile' => 'normal',
            'token_limits' => ['input_tokens' => 24576, 'output_tokens' => 8192, 'max_calls' => 5],
            'maximum_serialized_input_bytes' => 65536];

        $parts = (new EstimateAuditContextPlanner($policy))->prepare($root, 458752);

        self::assertCount(2, $parts);
        $seen = [];
        foreach ($parts as $part) {
            $input = $part['input'];
            $seen = [...$seen, ...$input->derivedQuantities];
            self::assertLessThanOrEqual(65536, EstimatorTextWirePolicy::serializedInputUpper([
                ['role' => 'system', 'content' => str_repeat('context ', 1024)],
                ['role' => 'user', 'content' => CanonicalPipelineJson::encode(['audit_input' => $input->canonicalPayload()])],
            ]));
        }
        self::assertSame(CanonicalPipelineJson::encode([$compressible, $unique]), CanonicalPipelineJson::encode($seen));
        self::assertSame([], $parts[0]['input']->derivedQuantities);
        self::assertCount(2, $parts[1]['input']->derivedQuantities);
    }

    public function test_unbound_draft_records_follow_the_actual_projected_context_load(): void
    {
        $quantity = $this->quantities()[0];
        $facts = array_map(static fn (string $id): array => ['id' => $id, 'entity_id' => 'wall:1'], $quantity['operand_fact_ids']);
        $diagnostic = ['code' => 'unbound', 'proof' => str_repeat('unbound record; ', 750)];
        $draft = ['project_proof' => str_repeat('retained source; ', 1700),
            'building_quantities' => ['diagnostics' => [$diagnostic]],
            'local_estimates' => [['sections' => [['work_items' => [
                ['key' => 'large-work', 'proof' => str_repeat('large work; ', 1600)],
                ['key' => 'small-work', 'entity_id' => 'wall:1', 'quantity_formula' => $quantity['id']],
            ]]]]]];
        $root = new EstimateAuditInput(1, 2, 3, str_repeat('a', 64), 0, $facts, [$quantity], $draft, [], 'estimate-auditor:v1');
        $policy = ['contract' => EstimatorTextWirePolicy::CONTRACT, 'profile' => 'normal',
            'token_limits' => ['input_tokens' => 24576, 'output_tokens' => 8192, 'max_calls' => 5],
            'maximum_serialized_input_bytes' => 65536];

        $parts = (new EstimateAuditContextPlanner($policy))->prepare($root, 458752);

        self::assertCount(2, $parts);
        self::assertSame([], $parts[0]['input']->draft['building_quantities']['diagnostics']);
        self::assertSame([$diagnostic], $parts[1]['input']->draft['building_quantities']['diagnostics']);
        self::assertSame([$quantity], $parts[1]['input']->derivedQuantities);
        foreach ($parts as $part) {
            self::assertLessThanOrEqual(65536, EstimatorTextWirePolicy::serializedInputUpper([
                ['role' => 'system', 'content' => str_repeat('context ', 1024)],
                ['role' => 'user', 'content' => CanonicalPipelineJson::encode(['audit_input' => $part['input']->canonicalPayload()])],
            ]));
        }
    }

    private function quantities(): array
    {
        $snapshot = ['scope' => 'current model', 'identities' => array_fill(0, 18, str_repeat('a', 64))];
        $relations = array_map(static fn (int $i): array => ['entity_id' => 'opening:'.$i, 'parent_entity_id' => 'wall:1',
            'relation' => 'opening_of', 'fact_id' => 'relation:'.$i, 'decision_id' => 'decision:relation:'.$i,
            'evidence_ids' => ['evidence:relation:'.$i]], range(0, 5));
        $binding = ['fact_id' => 'binding:wall', 'decision_id' => 'decision:binding',
            'evidence_ids' => array_map(static fn (int $i): string => 'evidence:binding:'.$i, range(0, 12))];
        $operands = array_map(static fn (int $i): array => ['fact_id' => 'fact:'.$i, 'value' => (string) ($i + 1),
            'unit' => 'm', 'decision_id' => 'decision:'.$i, 'evidence_ids' => ['evidence:'.$i],
            'locator' => ['page' => 1, 'dimension' => $i], 'decision_reason' => 'Confirmed dimension '.$i,
            'snapshot_identity' => $snapshot, 'formula_identity' => 'wall_net_area', 'formula_version' => '1',
            'rounding_policy' => ['mode' => 'half_up', 'scale' => 2], 'technology_decision_id' => null,
            'geometry_relations' => $relations, 'quantity_binding' => $binding], range(0, 7));

        return [['id' => 'quantity:wall', 'entity_id' => 'wall:1', 'operand_fact_ids' => array_column($operands, 'fact_id'),
            'quantity_basis' => ['key' => 'wall_net_area', 'amount' => '11.77', 'unit' => 'm2',
                'formula_key' => 'wall_net_area', 'formula_version' => '1',
                'formula_inputs' => ['entity_id' => 'wall:1', 'operands' => $operands, 'snapshot_identity' => $snapshot,
                    'rounding_policy' => ['mode' => 'half_up', 'scale' => 2], 'technology_decision_id' => null]]]];
    }

    private function expand(mixed $value, array $root): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        $expanded = [];
        foreach ($value as $key => $item) {
            if (is_string($key) && str_ends_with($key, '_ref')) {
                $resolved = $root;
                foreach (explode('.', preg_replace('/\[(\d+)\]/', '.$1', $item)) as $segment) {
                    self::assertArrayHasKey($segment, $resolved);
                    $resolved = $resolved[$segment];
                }
                $expanded[substr($key, 0, -4)] = $this->expand($resolved, $root);
            } else {
                $expanded[$key] = $this->expand($item, $root);
            }
        }

        return $expanded;
    }
}
