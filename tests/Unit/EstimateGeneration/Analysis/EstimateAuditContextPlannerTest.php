<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Analysis;

use App\BusinessModules\Addons\EstimateGeneration\Analysis\Audit\EstimateAuditContextPlanner;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Audit\EstimateAuditInput;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class EstimateAuditContextPlannerTest extends TestCase
{
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
            self::assertSame($basis, $row['quantity_basis_details']);
            self::assertArrayNotHasKey('quantity_evidence', $row);
            self::assertSame('quantity_basis_details', $row['quantity_evidence_ref']);
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
