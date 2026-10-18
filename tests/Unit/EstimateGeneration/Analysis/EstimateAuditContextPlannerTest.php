<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Analysis;

use App\BusinessModules\Addons\EstimateGeneration\Analysis\Audit\EstimateAuditContextPlanner;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Audit\EstimateAuditDraftWireProjection;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Audit\EstimateAuditInput;
use App\BusinessModules\Addons\EstimateGeneration\Observability\EstimatorTextWirePolicy;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class EstimateAuditContextPlannerTest extends TestCase
{
    public function test_unused_quantities_can_move_outside_their_entity_part_with_all_operand_evidence(): void
    {
        $facts = [['id' => 'fact:house', 'entity_id' => 'house:1', 'value' => '40']];
        $evidence = [['fact_id' => 'fact:house', 'locator' => ['page' => 1]]];
        $quantities = [];
        for ($i = 0; $i < 3; $i++) {
            $quantities[] = ['id' => 'unused:'.$i, 'entity_id' => 'house:1', 'operand_fact_ids' => ['fact:house'],
                'quantity_basis' => ['key' => 'unused:'.$i, 'decision_reason' => str_repeat('confirmed dimension; ', 1000)]];
        }
        $draft = ['traceability' => ['source_proof' => str_repeat('trace proof; ', 1400)],
            'local_estimates' => [['sections' => [['work_items' => [
                ['key' => 'work:house', 'entity_id' => 'house:1'],
                ['key' => 'work:room', 'entity_id' => 'room:1'],
                ['key' => 'work:roof', 'entity_id' => 'roof:1'],
            ]]]]]];
        $root = new EstimateAuditInput(1, 2, 3, str_repeat('a', 64), 0, $facts, $quantities, $draft, $evidence, 'estimate-auditor:v1');
        $policy = ['contract' => EstimatorTextWirePolicy::CONTRACT, 'profile' => 'normal',
            'token_limits' => ['input_tokens' => 24576, 'output_tokens' => 8192, 'max_calls' => 5],
            'maximum_serialized_input_bytes' => 65536];

        $parts = (new EstimateAuditContextPlanner($policy))->prepare($root, 458752);

        self::assertCount(3, $parts);
        $seen = [];
        foreach ($parts as $part) {
            $input = $part['input'];
            self::assertCount(1, $input->derivedQuantities);
            self::assertSame($facts, $input->facts);
            self::assertSame($evidence, $input->evidence);
            self::assertSame($draft['traceability'], $input->draft['traceability']);
            self::assertSame($root->fingerprint(), $input->contextManifest['parent_fingerprint']);
            self::assertLessThanOrEqual(65536, EstimatorTextWirePolicy::serializedInputUpper([
                ['role' => 'system', 'content' => str_repeat('context ', 1024)],
                ['role' => 'user', 'content' => CanonicalPipelineJson::encode(['audit_input' => $input->canonicalPayload()])],
            ]));
            array_push($seen, ...$input->derivedQuantities);
        }
        self::assertEqualsCanonicalizing($quantities, $seen);
        self::assertSame($draft, $root->draft);
        self::assertSame(array_column($parts, 'id'), array_column((new EstimateAuditContextPlanner($policy))->prepare($root, 458752), 'id'));
    }

    public function test_unused_quantity_placement_accounts_for_required_proofs_that_appear_later_in_the_input(): void
    {
        $facts = [['id' => 'fact:area', 'entity_id' => 'house:1', 'value' => '40']];
        $quantities = [];
        foreach (['unused' => 1000, 'small' => 450, 'large' => 1800] as $key => $repetitions) {
            $quantities[] = ['id' => 'quantity:'.$key, 'entity_id' => 'house:1', 'operand_fact_ids' => ['fact:area'],
                'quantity_basis' => ['key' => $key, 'decision_reason' => str_repeat('confirmed dimension; ', $repetitions)]];
        }
        $draft = ['local_estimates' => [['sections' => [['work_items' => [
            ['key' => 'work:small', 'entity_id' => 'house:1', 'quantity_formula' => 'quantity:small',
                'description' => str_repeat('source dimensions; ', 30)],
            ['key' => 'work:large', 'entity_id' => 'house:1', 'quantity_formula' => 'quantity:large'],
        ]]]]]];
        $root = new EstimateAuditInput(1, 2, 3, str_repeat('a', 64), 0, $facts, $quantities, $draft,
            [['fact_id' => 'fact:area', 'locator' => ['page' => 1]]], 'estimate-auditor:v1');
        $policy = ['contract' => EstimatorTextWirePolicy::CONTRACT, 'profile' => 'normal',
            'token_limits' => ['input_tokens' => 24576, 'output_tokens' => 8192, 'max_calls' => 5],
            'maximum_serialized_input_bytes' => 65536];

        $parts = (new EstimateAuditContextPlanner($policy))->prepare($root, 458752);

        self::assertCount(2, $parts);
        foreach ($parts as $part) {
            $expected = $part['work_keys'] === ['work:small'] ? ['quantity:unused', 'quantity:small'] : ['quantity:large'];
            self::assertSame($expected, array_column($part['input']->derivedQuantities, 'id'));
            self::assertSame($facts, $part['input']->facts);
            self::assertSame($root->evidence, $part['input']->evidence);
        }
        self::assertSame($draft, $root->draft);
    }

    public function test_shared_entity_quantities_follow_work_dependencies_and_unused_proofs_are_preserved_once(): void
    {
        $facts = $evidence = $quantities = [];
        for ($i = 0; $i < 3; $i++) {
            $factId = 'fact:dimension:'.$i;
            $facts[] = ['id' => $factId, 'entity_id' => 'house:1', 'value' => '40', 'unit' => 'm2'];
            $evidence[] = ['fact_id' => $factId, 'locator' => ['evidence_id' => 'evidence:'.$i, 'page' => 1]];
            $quantities[] = ['id' => 'quantity:'.$i, 'entity_id' => 'house:1', 'operand_fact_ids' => [$factId],
                'quantity_basis' => ['key' => 'quantity:'.$i, 'amount' => '40', 'unit' => 'm2',
                    'formula_inputs' => ['entity_id' => 'house:1', 'operands' => [['fact_id' => $factId,
                        'decision_reason' => str_repeat('confirmed dimension; ', 1000)]]]]];
        }
        $basis = $quantities[0]['quantity_basis'];
        $items = [
            ['key' => 'work:0', 'entity_id' => 'house:1', 'quantity_formula' => 'floor_area',
                'quantity_evidence' => $basis, 'quantity_basis_details' => $basis],
            ['key' => 'work:1', 'entity_id' => 'house:1', 'quantity_formula' => 'quantity:1'],
            ['key' => 'work:2', 'entity_id' => 'house:1', 'quantity_formula' => 'quantity:0'],
        ];
        $draft = ['local_estimates' => [['key' => 'house', 'sections' => [['key' => 'structure', 'work_items' => $items]]]]];
        $root = new EstimateAuditInput(1, 2, 3, str_repeat('a', 64), 0, $facts, $quantities, $draft, $evidence, 'estimate-auditor:v1');
        $policy = ['contract' => EstimatorTextWirePolicy::CONTRACT, 'profile' => 'normal',
            'token_limits' => ['input_tokens' => 24576, 'output_tokens' => 8192, 'max_calls' => 5],
            'maximum_serialized_input_bytes' => 65536];

        $parts = (new EstimateAuditContextPlanner($policy))->prepare($root, 458752);

        self::assertCount(2, $parts);
        $seenFacts = $seenEvidence = $seenQuantities = [];
        foreach ($parts as $part) {
            $input = $part['input'];
            $quantityIds = array_column($input->derivedQuantities, 'id');
            foreach ($part['work_keys'] as $workKey) {
                self::assertContains($workKey === 'work:1' ? 'quantity:1' : 'quantity:0', $quantityIds);
            }
            if (! in_array('work:1', $part['work_keys'], true)) {
                self::assertNotContains('quantity:1', $quantityIds);
            }
            $factIds = array_column($input->facts, 'id');
            foreach ($input->derivedQuantities as $quantity) {
                foreach ($quantity['operand_fact_ids'] as $factId) {
                    self::assertContains($factId, $factIds);
                    self::assertContains($factId, array_column($input->evidence, 'fact_id'));
                }
            }
            $wire = $input->canonicalPayload();
            if (in_array('work:0', $part['work_keys'], true)) {
                $row = $wire['draft']['local_estimates'][0]['sections'][0]['work_items'][0];
                self::assertArrayNotHasKey('quantity_basis_details', $row);
                self::assertSame(1, preg_match('/^audit_input\.derived_quantities\[([0-9]+)\]\.quantity_basis$/D', $row['quantity_basis_details_ref'], $matches));
                self::assertSame($basis, $wire['derived_quantities'][(int) $matches[1]]['quantity_basis']);
            }
            self::assertSame($root->fingerprint(), $input->contextManifest['parent_fingerprint']);
            self::assertLessThanOrEqual(65536, EstimatorTextWirePolicy::serializedInputUpper([
                ['role' => 'system', 'content' => str_repeat('context ', 1024)],
                ['role' => 'user', 'content' => CanonicalPipelineJson::encode(['audit_input' => $wire])],
            ]));
            array_push($seenFacts, ...$factIds);
            array_push($seenEvidence, ...array_column($input->evidence, 'fact_id'));
            array_push($seenQuantities, ...$quantityIds);
        }
        self::assertSame(2, array_count_values($seenQuantities)['quantity:0']);
        self::assertSame(1, array_count_values($seenQuantities)['quantity:1']);
        self::assertSame(1, array_count_values($seenQuantities)['quantity:2']);
        self::assertEqualsCanonicalizing(array_column($facts, 'id'), array_values(array_unique($seenFacts)));
        self::assertEqualsCanonicalizing(array_column($evidence, 'fact_id'), array_values(array_unique($seenEvidence)));
        self::assertSame($draft, $root->draft);
        self::assertSame(array_column($parts, 'id'), array_column((new EstimateAuditContextPlanner($policy))->prepare($root, 458752), 'id'));
    }

    public function test_exact_selected_composition_copies_reuse_the_full_work_proof_without_changing_the_business_item(): void
    {
        $composition = [str_repeat('Prepare the concrete base; ', 10), str_repeat('Install reinforcing mesh; ', 8)];
        $distinctComposition = array_reverse($composition);
        $basis = ['amount' => '40', 'unit' => 'm2'];
        $item = ['key' => 'foundation:1', 'work_composition' => $composition,
            'metadata' => ['work_composition' => $composition, 'composition_source' => 'fsnb_norm'],
            'normative_match' => ['norm_id' => 42, 'work_composition' => $composition],
            'normative_candidates' => [['norm_id' => 42, 'work_composition' => $composition],
                ['norm_id' => 43, 'work_composition' => $distinctComposition]],
            'quantity_evidence' => $basis, 'quantity_basis_details' => $basis,
            'materials' => [['resource_code' => 'concrete', 'quantity' => '3.2', 'unit_price' => '7120.50']],
            'labor' => [['resource_code' => 'worker', 'quantity' => '8.4']],
            'machinery' => [['resource_code' => 'pump', 'quantity' => '1.5']],
            'price_snapshot' => ['unit_price' => '125.43', 'regional_price_version_id' => 491],
            'source_refs' => [['fact_id' => 'fact:area']]];
        $draft = ['local_estimates' => [['sections' => [['work_items' => [$item]]]]]];

        $wire = EstimateAuditDraftWireProjection::project($draft, []);

        $row = $wire['local_estimates'][0]['sections'][0]['work_items'][0];
        $reference = 'audit_input.draft.local_estimates[0].sections[0].work_items[0].work_composition';
        self::assertSame($composition, $row['work_composition']);
        foreach ([$row['metadata'], $row['normative_match'], $row['normative_candidates'][0]] as $record) {
            self::assertArrayNotHasKey('work_composition', $record);
            self::assertSame($reference, $record['work_composition_ref']);
        }
        self::assertSame($item['normative_candidates'][1], $row['normative_candidates'][1]);
        foreach (['materials', 'labor', 'machinery', 'price_snapshot', 'source_refs'] as $field) {
            self::assertSame($item[$field], $row[$field]);
        }
        self::assertSame(\App\BusinessModules\Addons\EstimateGeneration\Analysis\Audit\ApplyComposerCorrectionCycle::itemFingerprint($item), $row['item_fingerprint']);
        self::assertSame($item, $draft['local_estimates'][0]['sections'][0]['work_items'][0]);
        self::assertSame($wire, EstimateAuditDraftWireProjection::project($wire, []));
        self::assertLessThan(strlen(CanonicalPipelineJson::encode($draft)), strlen(CanonicalPipelineJson::encode($wire)));
    }

    public function test_short_composition_copies_remain_inline_when_a_reference_would_increase_the_wire_size(): void
    {
        foreach ([[], ['Install']] as $composition) {
            $item = ['key' => 'work:1', 'work_composition' => $composition,
                'metadata' => ['work_composition' => $composition],
                'normative_match' => ['work_composition' => $composition],
                'normative_candidates' => [['work_composition' => $composition]]];
            $draft = ['local_estimates' => [['sections' => [['work_items' => [$item]]]]]];
            self::assertSame($draft, EstimateAuditDraftWireProjection::project($draft, []));
        }
    }

    public function test_a_priced_work_reuses_composition_proofs_within_the_unchanged_serialized_ceiling(): void
    {
        $composition = [str_repeat('Prepare concrete base; ', 10), str_repeat('Install reinforcing mesh; ', 8)];
        $item = ['key' => 'foundation:1', 'work_composition' => $composition,
            'metadata' => ['work_composition' => $composition],
            'normative_match' => ['norm_id' => 42, 'work_composition' => $composition],
            'normative_candidates' => [['norm_id' => 42, 'work_composition' => $composition]],
            'materials' => [['resource_code' => 'concrete', 'quantity' => '3.2', 'unit_price' => '7120.50']],
            'price_snapshot' => ['unit_price' => '125.43', 'regional_price_version_id' => 491],
            'source_refs' => [['fact_id' => 'fact:area']], 'resource_proof' => ''];
        $draft = ['local_estimates' => [['sections' => [['work_items' => [$item]]]]]];
        $facts = [['id' => 'fact:area', 'value' => '40', 'unit' => 'm2']];
        $evidence = [['fact_id' => 'fact:area', 'locator' => ['page' => 1]]];
        $root = new EstimateAuditInput(1, 2, 3, str_repeat('a', 64), 0, $facts, [], $draft, $evidence, 'estimate-auditor:v1');
        $raw = $root->canonicalPayload();
        $raw['draft'] = $draft;
        $messages = static fn (array $payload): array => [
            ['role' => 'system', 'content' => str_repeat('context ', 1024)],
            ['role' => 'user', 'content' => CanonicalPipelineJson::encode(['audit_input' => $payload])],
        ];
        $paddingBytes = 65620 - EstimatorTextWirePolicy::serializedInputUpper($messages($raw));
        self::assertGreaterThan(0, $paddingBytes);
        $draft['local_estimates'][0]['sections'][0]['work_items'][0]['resource_proof'] = str_repeat('proof; ', intdiv($paddingBytes, 7)).str_repeat('.', $paddingBytes % 7);
        $raw['draft'] = $draft;
        self::assertSame(65620, EstimatorTextWirePolicy::serializedInputUpper($messages($raw)));
        $root = $root->withDraft($draft, 0);
        $policy = ['contract' => EstimatorTextWirePolicy::CONTRACT, 'profile' => 'normal',
            'token_limits' => ['input_tokens' => 24576, 'output_tokens' => 8192, 'max_calls' => 5],
            'maximum_serialized_input_bytes' => 65536];

        $parts = (new EstimateAuditContextPlanner($policy))->prepare($root, 458752);

        self::assertCount(1, $parts);
        $input = $parts[0]['input'];
        self::assertSame($draft, $input->draft);
        self::assertSame($facts, $input->facts);
        self::assertSame($evidence, $input->evidence);
        self::assertSame($root->fingerprint(), $input->fingerprint());
        $sourceItem = $draft['local_estimates'][0]['sections'][0]['work_items'][0];
        $wireItem = $input->canonicalPayload()['draft']['local_estimates'][0]['sections'][0]['work_items'][0];
        self::assertSame(\App\BusinessModules\Addons\EstimateGeneration\Analysis\Audit\ApplyComposerCorrectionCycle::itemFingerprint($sourceItem), $wireItem['item_fingerprint']);
        self::assertSame($sourceItem['materials'], $wireItem['materials']);
        self::assertSame($sourceItem['resource_proof'], $wireItem['resource_proof']);
        self::assertLessThanOrEqual(65536, EstimatorTextWirePolicy::serializedInputUpper($messages($input->canonicalPayload())));
    }

    public function test_a_large_snapshot_retains_all_sources_and_unused_facts_across_bounded_parts(): void
    {
        $root = $this->root();
        self::assertGreaterThan(2097152, $root->wireBytes());
        $parts = (new EstimateAuditContextPlanner)->prepare($root, 458752);
        $facts = $works = $evidence = $quantities = [];
        foreach ($parts as $part) {
            $input = $part['input'];
            self::assertLessThanOrEqual(458752, $input->wireBytes());
            self::assertSame($root->fingerprint(), $input->contextManifest['parent_fingerprint']);
            self::assertCount(65, $input->contextManifest['work_manifest']);
            self::assertSame($root->snapshotToken, $input->snapshotToken);
            array_push($facts, ...array_column($input->facts, 'id'));
            array_push($works, ...$part['work_keys']);
            array_push($evidence, ...array_column(array_column($input->evidence, 'locator'), 'evidence_id'));
            array_push($quantities, ...array_column($input->derivedQuantities, 'id'));
            $localIds = array_fill_keys(array_column($input->facts, 'id'), true);
            foreach ($input->evidence as $record) {
                self::assertArrayHasKey($record['fact_id'], $localIds);
            }
            if (in_array('work:0', $part['work_keys'], true)) {
                self::assertArrayHasKey('fact:209', $localIds, 'The inline quantity lineage must stay in its work context.');
            }
        }
        self::assertEqualsCanonicalizing(array_column($root->facts, 'id'), array_values(array_unique($facts)));
        self::assertEqualsCanonicalizing(array_column(array_column($root->evidence, 'locator'), 'evidence_id'), array_values(array_unique($evidence)));
        self::assertEqualsCanonicalizing(array_column($root->derivedQuantities, 'id'), array_values(array_unique($quantities)));
        self::assertCount(65, $works);
        self::assertCount(65, array_unique($works));
        self::assertSame(array_column($parts, 'id'), array_column((new EstimateAuditContextPlanner)->prepare($root, 458752), 'id'));
    }

    public function test_a_changed_fact_outside_a_slice_changes_its_parent_fingerprint(): void
    {
        $root = $this->root();
        $before = (new EstimateAuditContextPlanner)->prepare($root, 458752)[0]['input'];
        $included = array_fill_keys(array_column($before->facts, 'id'), true);
        $facts = $root->facts;
        foreach ($facts as &$fact) {
            if (! isset($included[$fact['id']])) {
                $fact['value'] = str_repeat('test ', 2400);
                break;
            }
        }
        unset($fact);
        $changed = new EstimateAuditInput(1, 2, 3, $root->snapshotToken, 0, $facts, $root->derivedQuantities,
            $root->draft, $root->evidence, $root->contractVersion);
        $after = (new EstimateAuditContextPlanner)->prepare($changed, 458752)[0]['input'];
        self::assertSame($before->facts, $after->facts);
        self::assertNotSame($before->fingerprint(), $after->fingerprint());
    }

    public function test_an_indivisible_oversized_source_is_rejected_without_truncation(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('estimate_audit_context_requires_source_split');
        (new EstimateAuditContextPlanner)->prepare($this->root(), 1000);
    }

    public function test_duplicate_quantity_lineage_fits_one_part_without_losing_server_draft_or_audit_proofs(): void
    {
        $basis = ['key' => 'floor_area', 'amount' => '20', 'unit' => 'm2', 'source' => 'evidenced',
            'assumptions' => [], 'formula_inputs' => ['operands' => [['fact_id' => 'fact:area',
                'value' => '20', 'evidence_ids' => ['evidence:area'], 'decision_reason' => str_repeat('confirmed input ', 800)]]]];
        $quantity = ['id' => 'floor_area', 'entity_id' => 'room:1', 'quantity_basis' => $basis,
            'operand_fact_ids' => ['fact:area']];
        $items = [];
        for ($i = 0; $i < 3; $i++) {
            $items[] = ['key' => 'work:'.$i, 'name' => 'Floor '.$i, 'unit' => 'm2', 'quantity' => '20',
                'quantity_status' => 'supported', 'quantity_evidence' => $basis, 'quantity_basis_details' => $basis,
                'price_snapshot' => ['id' => 'price:'.$i, 'unit_price' => '100'], 'source_refs' => [['fact_id' => 'fact:area']]];
        }
        $draft = ['local_estimates' => [['key' => 'room', 'sections' => [['key' => 'floor', 'work_items' => $items]]]],
            'building_quantities' => ['quantities' => [$basis], 'diagnostics' => ['keep_this_warning']],
            'estimate_composition' => ['derived_quantities' => [$quantity], 'snapshot_token' => str_repeat('b', 64)],
            'traceability' => ['document_source_refs' => [['document_id' => 7, 'page' => 2]]]];
        $root = new EstimateAuditInput(1, 2, 3, str_repeat('a', 64), 0,
            [['id' => 'fact:area', 'entity_id' => 'room:1', 'value' => '20', 'unit' => 'm2']], [$quantity], $draft,
            [['fact_id' => 'fact:area', 'locator' => ['evidence_id' => 'evidence:area', 'page' => 2]]], 'estimate-auditor:v1');
        $parts = (new EstimateAuditContextPlanner)->prepare($root, 55000);
        self::assertCount(1, $parts);
        $input = $parts[0]['input'];
        self::assertSame($draft, $input->draft, 'Wire deduplication must never rewrite the server business artifact.');
        self::assertSame($root->facts, $input->facts);
        self::assertSame($root->evidence, $input->evidence);
        self::assertSame([$quantity], $input->derivedQuantities);
        $wire = $input->canonicalPayload()['draft'];
        self::assertArrayNotHasKey('derived_quantities', $wire['estimate_composition']);
        self::assertArrayNotHasKey('quantities', $wire['building_quantities']);
        self::assertSame(['keep_this_warning'], $wire['building_quantities']['diagnostics']);
        self::assertSame($draft['traceability'], $wire['traceability']);
        foreach ($wire['local_estimates'][0]['sections'][0]['work_items'] as $row) {
            self::assertSame($basis, $input->canonicalPayload()['derived_quantities'][0]['quantity_basis']);
            self::assertArrayNotHasKey('quantity_basis_details', $row);
            self::assertArrayNotHasKey('quantity_evidence', $row);
            self::assertSame('audit_input.derived_quantities[0].quantity_basis', $row['quantity_evidence_ref']);
            self::assertSame($row['quantity_evidence_ref'], $row['quantity_basis_details_ref']);
            self::assertSame('100', $row['price_snapshot']['unit_price']);
            self::assertSame(\App\BusinessModules\Addons\EstimateGeneration\Analysis\Audit\ApplyComposerCorrectionCycle::itemFingerprint(
                $items[array_search($row['key'], array_column($items, 'key'), true)]), $row['item_fingerprint']);
        }
        self::assertLessThanOrEqual(55000, $input->wireBytes());
        $changed = $draft;
        $changed['local_estimates'][0]['sections'][0]['work_items'][0]['quantity_evidence']['amount'] = '21';
        $different = $root->withDraft($changed, 0);
        self::assertSame('21', $different->canonicalPayload()['draft']['local_estimates'][0]['sections'][0]['work_items'][0]['quantity_evidence']['amount']);
        self::assertNotSame($root->fingerprint(), $different->fingerprint());
    }

    public function test_quantity_proofs_are_scoped_to_their_parts_after_canonical_storage_round_trip(): void
    {
        $facts = $evidence = $items = $quantities = $bases = [];
        for ($i = 0; $i < 8; $i++) {
            $factId = 'fact:'.$i;
            $entityId = 'room:'.$i;
            $facts[] = ['id' => $factId, 'entity_id' => $entityId, 'value' => '20', 'unit' => 'm2'];
            $evidence[] = ['fact_id' => $factId, 'locator' => ['evidence_id' => 'evidence:'.$i]];
            $basis = ['key' => 'quantity:'.$i, 'amount' => '20', 'unit' => 'm2',
                'formula_inputs' => ['entity_id' => $entityId, 'operands' => [['fact_id' => $factId,
                    'decision_reason' => str_repeat('confirmed dimension ', 500)]]]];
            $bases[] = $basis;
            $quantities[] = ['id' => 'quantity:'.$i, 'entity_id' => $entityId,
                'quantity_basis' => $basis, 'operand_fact_ids' => [$factId]];
            $items[] = ['key' => 'work:'.$i, 'name' => 'Floor '.$i, 'entity_id' => $entityId,
                'quantity_formula' => 'quantity:'.$i, 'quantity_evidence' => $basis, 'quantity_basis_details' => $basis];
        }
        $draft = json_decode(CanonicalPipelineJson::encode([
            'local_estimates' => [['key' => 'building', 'sections' => [['key' => 'floor', 'work_items' => $items]]]],
            'building_quantities' => ['quantities' => $bases, 'diagnostics' => ['retain coverage warning']],
            'estimate_composition' => ['derived_quantities' => $quantities],
        ]), true, 64, JSON_THROW_ON_ERROR);
        $root = new EstimateAuditInput(1, 2, 3, str_repeat('a', 64), 0, $facts, $quantities, $draft, $evidence, 'estimate-auditor:v1');
        $policy = ['contract' => EstimatorTextWirePolicy::CONTRACT, 'profile' => 'normal',
            'token_limits' => ['input_tokens' => 24576, 'output_tokens' => 8192, 'max_calls' => 5],
            'maximum_serialized_input_bytes' => 65536];
        $parts = (new EstimateAuditContextPlanner($policy))->prepare($root, 458752);
        self::assertGreaterThan(1, count($parts));
        $seen = $diagnostics = [];
        foreach ($parts as $part) {
            $input = $part['input'];
            $wire = $input->canonicalPayload();
            self::assertArrayNotHasKey('quantities', $wire['draft']['building_quantities']);
            self::assertArrayNotHasKey('derived_quantities', $wire['draft']['estimate_composition']);
            array_push($diagnostics, ...$wire['draft']['building_quantities']['diagnostics']);
            self::assertSame($root->fingerprint(), $input->contextManifest['parent_fingerprint']);
            foreach ($wire['draft']['local_estimates'][0]['sections'][0]['work_items'] as $row) {
                self::assertSame(1, preg_match('/^audit_input\.derived_quantities\[([0-9]+)\]\.quantity_basis$/D', $row['quantity_basis_details_ref'], $matches));
                $referencedQuantity = $wire['derived_quantities'][(int) $matches[1]];
                self::assertSame($row['quantity_formula'], $referencedQuantity['id']);
                self::assertSame($row['entity_id'], $referencedQuantity['entity_id']);
                self::assertSame($row['quantity_basis_details_ref'], $row['quantity_evidence_ref']);
                self::assertNotEmpty($referencedQuantity['quantity_basis']['formula_inputs']['operands']);
            }
            $messages = [['role' => 'system', 'content' => str_repeat('context ', 1024)],
                ['role' => 'user', 'content' => CanonicalPipelineJson::encode(['audit_input' => $wire])]];
            self::assertLessThanOrEqual(65536, EstimatorTextWirePolicy::serializedInputUpper($messages));
            array_push($seen, ...$input->derivedQuantities);
        }
        self::assertEqualsCanonicalizing($quantities, $seen);
        self::assertSame(['retain coverage warning'], $diagnostics);
        self::assertSame($draft, $root->draft);
    }

    public function test_distinct_quantity_proofs_keep_their_values_types_and_list_order(): void
    {
        $basis = ['key' => 'floor_area', 'amount' => '20', 'operands' => ['length', 'width']];
        $quantity = ['id' => 'quantity:1', 'quantity_basis' => $basis];
        $distinctBasis = [...$basis, 'amount' => 20, 'operands' => ['width', 'length']];
        $distinctQuantity = [...$quantity, 'quantity_basis' => $distinctBasis];
        $draft = ['building_quantities' => ['quantities' => [$distinctBasis]],
            'estimate_composition' => ['derived_quantities' => [$distinctQuantity]],
            'local_estimates' => [['sections' => [['work_items' => [
                ['key' => 'work:1', 'quantity_evidence' => $basis, 'quantity_basis_details' => $distinctBasis],
            ]]]]]];
        self::assertSame($draft, EstimateAuditDraftWireProjection::forContextPart($draft, [$quantity], []));
        self::assertSame($draft, EstimateAuditDraftWireProjection::project($draft, [$quantity]));
    }

    public function test_a_room_work_reuses_its_exact_canonical_quantity_proof_within_the_frozen_wire_limit(): void
    {
        $basis = ['key' => 'quantity:floor_area:room:1', 'amount' => '8.51', 'unit' => 'm2',
            'formula_key' => 'floor_area', 'formula_version' => '1', 'source' => 'evidenced',
            'evidence_ids' => ['evidence:length', 'evidence:width'], 'model_version' => str_repeat('b', 64),
            'assumptions' => [], 'review_blockers' => [], 'formula_inputs' => ['entity_id' => 'room:1',
                'operands' => [['fact_id' => 'fact:length', 'decision_reason' => str_repeat('confirmed operand ', 2000)],
                    ['fact_id' => 'fact:width', 'value' => '2915', 'unit' => 'mm']]]];
        $quantity = ['id' => $basis['key'], 'entity_id' => 'room:1', 'quantity_basis' => $basis,
            'operand_fact_ids' => ['fact:length', 'fact:width']];
        $item = ['key' => 'room-floor:1', 'name' => 'Стяжка пола спальни', 'entity_id' => 'room:1',
            'quantity' => '8.51', 'unit' => 'm2', 'quantity_formula' => $quantity['id'],
            'quantity_evidence' => $basis, 'quantity_basis_details' => $basis,
            'quantity_evidence_id' => 27, 'quantity_evidence_fingerprint' => str_repeat('c', 64),
            'quantity_evidence_source_version' => str_repeat('d', 64),
            'price_snapshot' => ['unit_price' => '125.43', 'regional_price_version_id' => 491]];
        $draft = json_decode(CanonicalPipelineJson::encode([
            'local_estimates' => [['key' => 'floor', 'sections' => [['key' => 'floor', 'work_items' => [$item]]]]],
            'building_quantities' => ['quantities' => [$basis]],
            'estimate_composition' => ['derived_quantities' => [$quantity]],
        ]), true, 64, JSON_THROW_ON_ERROR);
        $root = new EstimateAuditInput(1, 2, 3, str_repeat('a', 64), 0,
            [['id' => 'fact:length', 'entity_id' => 'room:1', 'value' => '2920', 'unit' => 'mm'],
                ['id' => 'fact:width', 'entity_id' => 'room:1', 'value' => '2915', 'unit' => 'mm']],
            [$quantity], $draft, [['fact_id' => 'fact:length', 'locator' => ['page' => 1]]], 'estimate-auditor:v1');
        $policy = ['contract' => EstimatorTextWirePolicy::CONTRACT, 'profile' => 'normal',
            'token_limits' => ['input_tokens' => 24576, 'output_tokens' => 8192, 'max_calls' => 5],
            'maximum_serialized_input_bytes' => 65536];

        $parts = (new EstimateAuditContextPlanner($policy))->prepare($root, 458752);

        self::assertCount(1, $parts);
        $input = $parts[0]['input'];
        $wire = $input->canonicalPayload();
        $row = $wire['draft']['local_estimates'][0]['sections'][0]['work_items'][0];
        self::assertSame('audit_input.derived_quantities[0].quantity_basis', $row['quantity_evidence_ref']);
        self::assertSame($row['quantity_evidence_ref'], $row['quantity_basis_details_ref']);
        self::assertArrayNotHasKey('quantity_evidence', $row);
        self::assertArrayNotHasKey('quantity_basis_details', $row);
        self::assertSame($basis, $wire['derived_quantities'][0]['quantity_basis']);
        self::assertSame(\App\BusinessModules\Addons\EstimateGeneration\Analysis\Audit\ApplyComposerCorrectionCycle::itemFingerprint($item), $row['item_fingerprint']);
        self::assertSame($draft['local_estimates'][0]['sections'][0]['work_items'][0]['price_snapshot'], $row['price_snapshot']);
        self::assertSame(27, $row['quantity_evidence_id']);
        self::assertSame($item['quantity_evidence_fingerprint'], $row['quantity_evidence_fingerprint']);
        self::assertSame($item['quantity_evidence_source_version'], $row['quantity_evidence_source_version']);
        self::assertSame($root->fingerprint(), $input->fingerprint());
        self::assertSame($draft, $input->draft);
        self::assertSame($root->facts, $input->facts);
        self::assertSame($root->evidence, $input->evidence);
        self::assertSame($wire['draft'], EstimateAuditDraftWireProjection::project($wire['draft'], $input->derivedQuantities));
        self::assertLessThanOrEqual(65536, EstimatorTextWirePolicy::serializedInputUpper([
            ['role' => 'system', 'content' => str_repeat('context ', 1024)],
            ['role' => 'user', 'content' => CanonicalPipelineJson::encode(['audit_input' => $wire])],
        ]));
    }

    public function test_an_unsplittable_context_logs_only_field_sizes_and_scope(): void
    {
        $previousApplication = \Illuminate\Support\Facades\Facade::getFacadeApplication();
        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
        $logger->expects(self::once())->method('warning')->with(
            'estimate_generation.audit_context_requires_source_split',
            self::callback(static function (array $context): bool {
                self::assertSame(3, $context['session_id']);
                self::assertSame(500, $context['maximum_part_bytes']);
                self::assertIsInt($context['draft_field_bytes']['title']);
                self::assertIsInt($context['largest_work_field_bytes']['name']);
                self::assertGreaterThan(1000, $context['facts_bytes']);
                self::assertSame($context['facts_bytes'], $context['failed_part_sizes']['field_bytes']['facts']);
                self::assertGreaterThan(500, $context['failed_part_sizes']['serialized_input_upper']);
                self::assertStringNotContainsString('private drawing', json_encode($context, JSON_THROW_ON_ERROR));

                return true;
            }),
        );
        $container = new \Illuminate\Container\Container;
        $container->instance('log', $logger);
        \Illuminate\Support\Facades\Log::clearResolvedInstance('log');
        \Illuminate\Support\Facades\Facade::setFacadeApplication($container);
        try {
            $root = new EstimateAuditInput(1, 2, 3, str_repeat('a', 64), 0,
                [['id' => 'fact:1', 'value' => str_repeat('private drawing ', 100)]], [],
                ['title' => 'private drawing title', 'local_estimates' => [['sections' => [['work_items' => [
                    ['key' => 'work:1', 'name' => 'private drawing work'],
                ]]]]]], [], 'estimate-auditor:v1');
            $this->expectExceptionMessage('estimate_audit_context_requires_source_split');
            (new EstimateAuditContextPlanner)->prepare($root, 500);
        } finally {
            \Illuminate\Support\Facades\Log::clearResolvedInstance('log');
            \Illuminate\Support\Facades\Facade::setFacadeApplication($previousApplication);
        }
    }

    public function test_room_diagnostics_and_review_records_are_preserved_across_bounded_context_parts(): void
    {
        $facts = $items = $diagnostics = $reviews = [];
        for ($i = 0; $i < 40; $i++) {
            $entity = 'room:'.$i;
            $facts[] = ['id' => 'fact:'.$i, 'entity_id' => $entity, 'type' => 'room_name', 'value' => 'Комната '.$i];
            $items[] = ['key' => 'work:'.$i, 'name' => 'Подготовка пола комнаты '.$i, 'entity_id' => $entity,
                'quantity' => null, 'unit' => 'm2', 'quantity_status' => 'unknown',
                'source_refs' => [['fact_id' => 'fact:'.$i]]];
            $diagnostics[] = ['code' => 'canonical_quantity_unresolved', 'severity' => 'error',
                'path' => 'quantities.stage6', 'details' => ['entity_id' => $entity, 'logical_id' => 'quantity:'.$i,
                    'inputs' => [['fact_id' => 'fact:'.$i, 'source_locator' => ['page' => 1,
                        'native_reference' => str_repeat('room dimensions require verification ', 40)]]]]];
            $reviews[] = ['type' => 'quantity_blocking', 'code' => 'room_dimensions_required',
                'work_item_key' => null, 'entity_id' => $entity, 'message_key' => 'estimate_generation.geometry_coverage_review',
                'source_refs' => [['fact_id' => 'fact:'.$i, 'native_reference' => str_repeat('room source locator ', 30)]]];
        }
        $diagnostics[] = $diagnostics[0];
        $diagnostics[] = ['code' => 'canonical_quantity_aggregate_unresolved', 'severity' => 'error',
            'path' => 'quantities.stage6', 'details' => ['quantity_ids' => ['unresolved:global']]];
        $draft = ['building_quantities' => ['quantities' => [], 'diagnostics' => $diagnostics, 'metrics' => ['unresolved' => 40]],
            'stage6_review_items' => $reviews,
            'local_estimates' => [['key' => 'floors', 'sections' => [['key' => 'rooms', 'work_items' => $items]]]]];
        $root = new EstimateAuditInput(1, 2, 3, str_repeat('a', 64), 0, $facts, [], $draft, [], 'estimate-auditor:v1');
        $policy = ['contract' => EstimatorTextWirePolicy::CONTRACT, 'profile' => 'normal',
            'token_limits' => ['input_tokens' => 24576, 'output_tokens' => 8192, 'max_calls' => 5],
            'maximum_serialized_input_bytes' => 65536];

        $parts = (new EstimateAuditContextPlanner($policy))->prepare($root, 458752);

        self::assertGreaterThan(1, count($parts));
        $seenDiagnostics = $seenReviews = $seenWorks = [];
        foreach ($parts as $part) {
            $input = $part['input'];
            $entities = array_fill_keys(array_column($input->facts, 'entity_id'), true);
            foreach ($input->draft['building_quantities']['diagnostics'] as $record) {
                if (isset($record['details']['entity_id'])) {
                    self::assertArrayHasKey($record['details']['entity_id'], $entities);
                }
                $seenDiagnostics[] = $record;
            }
            foreach ($input->draft['stage6_review_items'] as $record) {
                self::assertArrayHasKey($record['entity_id'], $entities);
                $seenReviews[] = $record;
            }
            array_push($seenWorks, ...$part['work_keys']);
            self::assertSame(['building_quantities.diagnostics' => 42, 'stage6_review_items' => 40], $input->contextManifest['draft_record_counts']);
            self::assertSame($root->fingerprint(), $input->contextManifest['parent_fingerprint']);
            self::assertSame(['unresolved' => 40], $input->draft['building_quantities']['metrics']);
            self::assertLessThanOrEqual(65536, EstimatorTextWirePolicy::serializedInputUpper([
                ['role' => 'system', 'content' => str_repeat('context ', 1024)],
                ['role' => 'user', 'content' => CanonicalPipelineJson::encode(['audit_input' => $input->canonicalPayload()])],
            ]));
        }
        self::assertEqualsCanonicalizing($diagnostics, $seenDiagnostics);
        self::assertEqualsCanonicalizing($reviews, $seenReviews);
        self::assertEqualsCanonicalizing(array_column($items, 'key'), $seenWorks);
        self::assertSame($draft, $root->draft);
        self::assertSame($facts, $root->facts);
        self::assertSame(array_column($parts, 'id'), array_column((new EstimateAuditContextPlanner($policy))->prepare($root, 458752), 'id'));
    }

    public function test_full_house_package_plan_and_composer_requests_fit_without_losing_sources(): void
    {
        $facts = $evidence = $quantities = $locals = $requests = $packages = [];
        for ($i = 0; $i < 40; $i++) {
            $entity = 'entity:'.$i;
            $sourceIds = [];
            for ($j = 0; $j < 4; $j++) {
                $id = 'fact:'.$i.':'.$j;
                $sourceIds[] = $id;
                $facts[] = ['id' => $id, 'entity_id' => $entity, 'value' => str_repeat('source dimension proof ', 40)];
                $evidence[] = ['fact_id' => $id, 'locator' => ['evidence_id' => 'evidence:'.$i.':'.$j,
                    'native_reference' => str_repeat('drawing measurement reference ', 24)]];
            }
            for ($j = 0; $j < 3; $j++) {
                $quantities[] = ['id' => 'quantity:'.$i.':'.$j, 'entity_id' => $entity, 'value' => '10', 'unit' => 'm2',
                    'operand_fact_ids' => $sourceIds, 'quantity_basis' => ['key' => 'quantity:'.$i.':'.$j,
                        'amount' => '10', 'unit' => 'm2', 'formula_inputs' => ['entity_id' => $entity,
                            'decision_reason' => str_repeat('confirmed dimensional source with provenance ', 145)]]];
            }
            $locals[] = ['key' => 'package:'.($i % 20), 'sections' => [['key' => 'section:'.$i, 'work_items' => [[
                'key' => 'work:'.$i, 'name' => 'Устройство части дома '.$i, 'entity_id' => $entity,
                'quantity_formula' => 'quantity:'.$i.':0', 'quantity' => '10', 'unit' => 'm2',
                'source_refs' => [['fact_id' => $sourceIds[0]]],
            ]]]]];
            $requests[] = ['index' => $i % 3, 'batch_key' => 'batch:'.$i, 'reason' => 'work_source_required',
                'work_name' => str_repeat('Уточнение сопутствующей работы ', 5),
                'next_action' => 'confirm_work_source', 'source_fact_ids' => [$sourceIds[0]]];
        }
        for ($i = 0; $i < 20; $i++) {
            $packages[] = ['key' => 'package:'.$i, 'title' => 'Раздел дома '.$i,
                'basis' => str_repeat('package plan requires complete coverage ', 10)];
        }
        $draft = ['local_estimates' => $locals, 'estimate_composition' => ['request_info' => $requests],
            'package_plan' => ['packages' => $packages, 'target_items_min_total' => 40],
            'traceability' => ['document_context' => ['context_text' => str_repeat('building drawing source context ', 130)]]];
        $root = new EstimateAuditInput(1, 2, 3, str_repeat('a', 64), 0, $facts, $quantities, $draft, $evidence, 'estimate-auditor:v1');
        $policy = ['contract' => EstimatorTextWirePolicy::CONTRACT, 'profile' => 'normal',
            'token_limits' => ['input_tokens' => 24576, 'output_tokens' => 8192, 'max_calls' => 5],
            'maximum_serialized_input_bytes' => 65536];

        $parts = (new EstimateAuditContextPlanner($policy))->prepare($root, 458752);

        $seenFacts = $seenEvidence = $seenQuantities = $seenWorks = $seenRequests = $seenPackages = [];
        foreach ($parts as $part) {
            $input = $part['input'];
            self::assertLessThanOrEqual(65536, EstimatorTextWirePolicy::serializedInputUpper([
                ['role' => 'system', 'content' => str_repeat('context ', 1024)],
                ['role' => 'user', 'content' => CanonicalPipelineJson::encode(['audit_input' => $input->canonicalPayload()])],
            ]));
            self::assertSame($root->fingerprint(), $input->contextManifest['parent_fingerprint']);
            self::assertSame(['package_plan.packages' => 20, 'estimate_composition.request_info' => 40], $input->contextManifest['draft_record_counts']);
            self::assertSame($draft['traceability'], $input->draft['traceability']);
            array_push($seenFacts, ...array_column($input->facts, 'id'));
            array_push($seenEvidence, ...array_column(array_column($input->evidence, 'locator'), 'evidence_id'));
            array_push($seenQuantities, ...array_column($input->derivedQuantities, 'id'));
            array_push($seenWorks, ...$part['work_keys']);
            array_push($seenRequests, ...$input->draft['estimate_composition']['request_info']);
            foreach ($input->draft['package_plan']['packages'] as $package) {
                $seenPackages[$package['key']] = $package;
            }
        }
        self::assertEqualsCanonicalizing(array_column($facts, 'id'), array_values(array_unique($seenFacts)));
        self::assertEqualsCanonicalizing(array_column(array_column($evidence, 'locator'), 'evidence_id'), array_values(array_unique($seenEvidence)));
        self::assertEqualsCanonicalizing(array_column($quantities, 'id'), array_values(array_unique($seenQuantities)));
        self::assertEqualsCanonicalizing(array_map(static fn (int $index): string => 'work:'.$index, range(0, 39)), $seenWorks);
        self::assertEqualsCanonicalizing($requests, $seenRequests);
        self::assertEqualsCanonicalizing($packages, array_values($seenPackages));
        self::assertSame($draft, $root->draft);
    }

    public function test_compact_work_parts_distribute_requests_even_when_all_source_context_already_fits(): void
    {
        $items = [];
        for ($i = 0; $i < 65; $i++) {
            $items[] = ['key' => 'work:'.$i, 'name' => 'Работа '.$i, 'source_refs' => [['fact_id' => 'fact:shared']]];
        }
        $requests = [
            ['index' => 0, 'reason' => 'source_required', 'source_fact_ids' => ['fact:shared']],
            ['index' => 1, 'reason' => 'estimate_composer_physical_duplicate_covered', 'next_action' => 'keep_existing_work',
                'covered_by_position_key' => 'work:64', 'source_fact_ids' => ['fact:shared']],
        ];
        $draft = ['estimate_composition' => ['request_info' => $requests],
            'package_plan' => ['packages' => [['key' => 'floors', 'title' => 'Полы']]],
            'local_estimates' => [['key' => 'floors', 'sections' => [['key' => 'finish', 'work_items' => $items]]]]];
        $facts = [['id' => 'fact:shared', 'value' => '40', 'unit' => 'm2']];
        $evidence = [['fact_id' => 'fact:shared', 'locator' => ['evidence_id' => 'evidence:shared']]];
        $root = new EstimateAuditInput(1, 2, 3, str_repeat('a', 64), 0, $facts, [], $draft, $evidence, 'estimate-auditor:v1');

        $parts = (new EstimateAuditContextPlanner)->prepare($root, 458752);

        self::assertCount(3, $parts);
        $seen = $works = [];
        foreach ($parts as $part) {
            array_push($seen, ...$part['input']->draft['estimate_composition']['request_info']);
            array_push($works, ...$part['work_keys']);
            foreach ($part['input']->draft['estimate_composition']['request_info'] as $request) {
                if (isset($request['covered_by_position_key'])) {
                    self::assertContains($request['covered_by_position_key'], $part['work_keys']);
                }
            }
            self::assertSame($facts, $part['input']->facts);
            self::assertSame($evidence, $part['input']->evidence);
            self::assertSame(2, $part['input']->contextManifest['draft_record_counts']['estimate_composition.request_info']);
            self::assertSame($root->fingerprint(), $part['input']->contextManifest['parent_fingerprint']);
        }
        self::assertEqualsCanonicalizing($requests, $seen);
        self::assertEqualsCanonicalizing(array_column($items, 'key'), $works);
        self::assertSame($draft, $root->draft);
    }

    public function test_compact_parts_keep_entity_diagnostics_with_the_work_even_without_entity_facts(): void
    {
        $items = [];
        for ($i = 0; $i < 65; $i++) {
            $items[] = ['key' => 'work:'.$i, 'name' => 'Работа '.$i, 'entity_id' => 'entity:'.$i];
        }
        $diagnostic = ['code' => 'quantity_unresolved', 'details' => ['entity_id' => 'entity:0']];
        $review = ['type' => 'quantity_blocking', 'entity_id' => 'entity:64'];
        $draft = ['building_quantities' => ['diagnostics' => [$diagnostic]], 'stage6_review_items' => [$review],
            'local_estimates' => [['key' => 'floors', 'sections' => [['key' => 'finish', 'work_items' => $items]]]]];
        $facts = [['id' => 'fact:0', 'entity_id' => 'entity:0', 'value' => '40', 'unit' => 'm2']];
        $root = new EstimateAuditInput(1, 2, 3, str_repeat('a', 64), 0, $facts, [], $draft, [], 'estimate-auditor:v1');

        $parts = (new EstimateAuditContextPlanner)->prepare($root, 458752);

        $diagnostics = $reviews = [];
        foreach ($parts as $part) {
            foreach ($part['input']->draft['building_quantities']['diagnostics'] as $record) {
                self::assertContains('work:0', $part['work_keys']);
                $diagnostics[] = $record;
            }
            foreach ($part['input']->draft['stage6_review_items'] as $record) {
                self::assertContains('work:64', $part['work_keys']);
                $reviews[] = $record;
            }
            self::assertSame($facts, $part['input']->facts);
        }
        self::assertSame([$diagnostic], $diagnostics);
        self::assertSame([$review], $reviews);
        self::assertSame($draft, $root->draft);
    }

    private function root(): EstimateAuditInput
    {
        $facts = $evidence = $items = [];
        for ($i = 0; $i < 210; $i++) {
            $facts[] = ['id' => 'fact:'.$i, 'entity_id' => 'room:'.$i, 'value' => str_repeat('word ', 2400), 'evidence_ids' => ['evidence:'.$i]];
            $evidence[] = ['fact_id' => 'fact:'.$i, 'locator' => ['evidence_id' => 'evidence:'.$i, 'page' => $i + 1]];
        }
        for ($i = 0; $i < 65; $i++) {
            $items[] = ['key' => 'work:'.$i, 'name' => 'Работа '.$i, 'entity_id' => 'room:'.$i,
                'quantity_formula' => 'quantity:'.$i, 'source_refs' => [['fact_id' => 'fact:'.$i]]];
        }
        $items[0]['quantity_evidence'] = ['evidence_ids' => ['evidence:209'], 'formula_inputs' => ['entity_id' => 'room:0']];
        $quantities = [];
        for ($i = 0; $i < 70; $i++) {
            $quantities[] = ['id' => 'quantity:'.$i, 'entity_id' => 'room:'.$i, 'value' => '1', 'unit' => 'm2',
                'operand_fact_ids' => ['fact:'.$i]];
        }

        return new EstimateAuditInput(1, 2, 3, str_repeat('a', 64), 0, $facts, $quantities,
            ['local_estimates' => [['key' => 'building', 'sections' => [['key' => 'works', 'work_items' => $items]]]]],
            $evidence, 'estimate-auditor:v1');
    }
}
