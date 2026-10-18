<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Analysis;

use App\BusinessModules\Addons\EstimateGeneration\Analysis\Audit\EstimateAuditDraftRecordPlanner;
use PHPUnit\Framework\TestCase;

final class EstimateAuditDraftRecordPlannerTest extends TestCase
{
    public function test_entity_work_quantity_and_fact_records_follow_their_context_dependencies(): void
    {
        $diagnostics = [
            ['code' => 'entity', 'details' => ['entity_id' => 'room:shared']],
            ['code' => 'direct_fact', 'fact_id' => 'fact:2'],
            ['code' => 'aggregate', 'details' => ['quantity_ids' => ['quantity:other']]],
            ['code' => 'global'],
            ['code' => 'fact', 'source_refs' => [['fact_id' => 'fact:1']]],
            ['code' => 'work', 'work_item_key' => 'work:0'],
            ['code' => 'input', 'details' => ['inputs' => [['source_locator' => ['fact_id' => 'fact:2']]]]],
            ['code' => 'entity', 'details' => ['entity_id' => 'room:shared']],
        ];
        $reviews = [['type' => 'quantity_blocking', 'entity_id' => 'room:shared']];
        $draft = ['building_quantities' => ['diagnostics' => $diagnostics], 'stage6_review_items' => $reviews];
        $parts = [];
        for ($i = 0; $i < 3; $i++) {
            $entity = $i < 2 ? 'room:shared' : 'room:other';
            $parts[] = ['draft' => $draft, 'entity_ids' => [$entity], 'work_keys' => ['work:'.$i],
                'facts' => [['id' => 'fact:'.$i, 'entity_id' => $entity]], 'evidence' => [],
                'derived_quantities' => [['id' => $i < 2 ? 'quantity:shared' : 'quantity:other', 'entity_id' => $entity]]];
        }
        $planner = new EstimateAuditDraftRecordPlanner;

        $result = $planner->distribute($draft, $parts);

        $codes = array_map(static fn (array $part): array => array_column($part['draft']['building_quantities']['diagnostics'], 'code'), $result['parts']);
        self::assertSame(2, count(array_keys($codes[0], 'entity', true)));
        self::assertSame(2, count(array_keys($codes[1], 'entity', true)));
        self::assertNotContains('entity', $codes[2]);
        self::assertContains('work', $codes[0]);
        self::assertNotContains('work', $codes[1]);
        self::assertNotContains('work', $codes[2]);
        self::assertContains('fact', $codes[1]);
        self::assertNotContains('fact', $codes[0]);
        self::assertNotContains('fact', $codes[2]);
        self::assertContains('aggregate', $codes[2]);
        self::assertContains('input', $codes[2]);
        self::assertContains('direct_fact', $codes[2]);
        self::assertNotContains('direct_fact', $codes[0]);
        self::assertNotContains('direct_fact', $codes[1]);
        self::assertNotContains('aggregate', $codes[0]);
        self::assertNotContains('aggregate', $codes[1]);
        self::assertSame(1, count(array_keys(array_merge(...$codes), 'global', true)));
        self::assertSame($reviews, $result['parts'][0]['draft']['stage6_review_items']);
        self::assertSame($reviews, $result['parts'][1]['draft']['stage6_review_items']);
        self::assertSame([], $result['parts'][2]['draft']['stage6_review_items']);
        self::assertSame(['building_quantities.diagnostics' => 8, 'stage6_review_items' => 1], $result['counts']);
        self::assertSame($result, $planner->distribute($draft, $parts));
        self::assertSame($diagnostics, $draft['building_quantities']['diagnostics']);
        self::assertSame($draft, $parts[0]['draft']);
    }

    public function test_a_non_list_record_collection_is_preserved_and_never_silently_scoped(): void
    {
        $draft = ['building_quantities' => ['diagnostics' => ['legacy' => ['message' => 'retain legacy diagnostic']]],
            'stage6_review_items' => null];
        $parts = [['draft' => $draft, 'entity_ids' => [], 'work_keys' => ['work:1'],
            'facts' => [], 'derived_quantities' => [], 'evidence' => []]];

        $result = (new EstimateAuditDraftRecordPlanner)->distribute($draft, $parts);

        self::assertSame($parts, $result['parts']);
        self::assertSame([], $result['counts']);
    }

    public function test_composer_requests_keep_all_source_proofs_in_one_context_and_packages_follow_their_work(): void
    {
        $requests = [
            ['index' => 7, 'reason' => 'source_required', 'source_fact_ids' => ['fact:0', 'fact:1']],
            ['index' => 7, 'reason' => 'other_batch', 'source_fact_ids' => ['fact:0']],
            ['index' => -1, 'reason' => 'scope_unresolved', 'source_fact_ids' => []],
        ];
        $packages = [['key' => 'floors', 'title' => 'Полы'], ['key' => 'roof', 'title' => 'Кровля'], ['key' => 'uncovered']];
        $draft = ['package_plan' => ['packages' => $packages, 'target_items_min_total' => 20],
            'estimate_composition' => ['request_info' => $requests]];
        $parts = [];
        for ($i = 0; $i < 2; $i++) {
            $parts[] = ['draft' => [...$draft, 'local_estimates' => [['key' => $i === 0 ? 'floors' : 'roof']]],
                'entity_ids' => ['entity:'.$i], 'work_keys' => ['work:'.$i],
                'facts' => [['id' => 'fact:'.$i, 'entity_id' => 'entity:'.$i, 'value' => (string) ($i + 1)]],
                'evidence' => [['fact_id' => 'fact:'.$i, 'locator' => ['evidence_id' => 'evidence:'.$i]]],
                'derived_quantities' => []];
        }
        $planner = new EstimateAuditDraftRecordPlanner;
        $result = $planner->distribute($draft, $parts);
        $seen = $seenPackages = [];
        foreach ($result['parts'] as $part) {
            $facts = array_column($part['facts'], null, 'id');
            $evidence = array_column($part['evidence'], null, 'fact_id');
            foreach ($part['draft']['estimate_composition']['request_info'] as $request) {
                $seen[] = $request;
                foreach ($request['source_fact_ids'] as $id) {
                    self::assertSame($id === 'fact:0' ? '1' : '2', $facts[$id]['value']);
                    self::assertArrayHasKey($id, $evidence);
                }
            }
            array_push($seenPackages, ...$part['draft']['package_plan']['packages']);
            self::assertSame(20, $part['draft']['package_plan']['target_items_min_total']);
        }
        self::assertEqualsCanonicalizing($requests, $seen);
        self::assertEqualsCanonicalizing($packages, $seenPackages);
        self::assertContains($packages[0], $result['parts'][0]['draft']['package_plan']['packages']);
        self::assertContains($packages[1], $result['parts'][1]['draft']['package_plan']['packages']);
        self::assertSame(['estimate_composition.request_info' => 3, 'package_plan.packages' => 3], $result['counts']);
        self::assertSame($result, $planner->distribute($draft, $parts));
        self::assertSame($requests, $draft['estimate_composition']['request_info']);
        self::assertCount(1, $parts[0]['facts']);
        self::assertCount(1, $parts[1]['facts']);
    }

    public function test_a_covered_request_follows_its_position_or_candidate_before_fact_load_balancing(): void
    {
        foreach (['covered_by_position_key' => 'work:covered', 'covered_by_candidate_id' => 'candidate:covered'] as $field => $reference) {
            $request = ['index' => 2, 'reason' => 'estimate_composer_physical_duplicate_covered',
                'next_action' => 'keep_existing_work', $field => $reference,
                'source_fact_ids' => ['fact:shared', 'fact:external']];
            $draft = ['estimate_composition' => ['request_info' => [$request]]];
            $shared = ['id' => 'fact:shared', 'entity_id' => 'room:shared', 'value' => '5'];
            $external = ['id' => 'fact:external', 'entity_id' => 'room:other', 'value' => '7'];
            $proof = ['fact_id' => 'fact:external', 'locator' => ['evidence_id' => 'evidence:external']];
            $parts = [];
            foreach (['work:other', 'work:covered'] as $index => $key) {
                $item = ['key' => $key, 'name' => $index === 0 ? 'Other' : str_repeat('Covering work ', 100),
                    'composition_intent' => ['candidate_id' => $index === 0 ? 'candidate:other' : 'candidate:covered']];
                $parts[] = ['draft' => [...$draft, 'local_estimates' => [['key' => 'floors',
                    'sections' => [['key' => 'finish', 'work_items' => [$item]]]]]],
                    'entity_ids' => ['room:shared'], 'work_keys' => [$key],
                    'facts' => $index === 0 ? [$shared, $external] : [$shared],
                    'evidence' => $index === 0 ? [$proof] : [], 'derived_quantities' => []];
            }

            $result = (new EstimateAuditDraftRecordPlanner)->distribute($draft, $parts);

            self::assertSame([], $result['parts'][0]['draft']['estimate_composition']['request_info'], $field);
            self::assertSame([$request], $result['parts'][1]['draft']['estimate_composition']['request_info'], $field);
            self::assertContains($external, $result['parts'][1]['facts']);
            self::assertContains($proof, $result['parts'][1]['evidence']);
            self::assertSame($result, (new EstimateAuditDraftRecordPlanner)->distribute($draft, $parts));
        }
    }

    public function test_request_assignment_compares_load_after_adding_all_missing_source_proofs(): void
    {
        $request = ['index' => 1, 'reason' => 'source_required', 'source_fact_ids' => ['fact:shared', 'fact:large']];
        $draft = ['estimate_composition' => ['request_info' => [$request]]];
        $shared = ['id' => 'fact:shared', 'entity_id' => 'room:1', 'value' => '5'];
        $large = ['id' => 'fact:large', 'entity_id' => 'room:2', 'value' => str_repeat('source basis ', 3000)];
        $proof = ['fact_id' => 'fact:large', 'locator' => ['evidence_id' => 'evidence:large',
            'native_reference' => str_repeat('full evidence ', 50)]];
        $parts = [];
        for ($i = 0; $i < 2; $i++) {
            $parts[] = ['draft' => [...$draft, 'local_estimates' => [['key' => 'work:'.$i,
                'name' => $i === 0 ? 'Source owner' : str_repeat('additional work context ', 1000)]]],
                'entity_ids' => ['room:1'], 'work_keys' => ['work:'.$i],
                'facts' => $i === 0 ? [$shared, $large] : [$shared],
                'evidence' => $i === 0 ? [$proof] : [], 'derived_quantities' => []];
        }

        $result = (new EstimateAuditDraftRecordPlanner)->distribute($draft, $parts);

        self::assertSame([$request], $result['parts'][0]['draft']['estimate_composition']['request_info']);
        self::assertSame([], $result['parts'][1]['draft']['estimate_composition']['request_info']);
        self::assertSame([$shared, $large], $result['parts'][0]['facts']);
        self::assertSame([$proof], $result['parts'][0]['evidence']);
        self::assertSame([$shared], $result['parts'][1]['facts']);
        self::assertSame($result, (new EstimateAuditDraftRecordPlanner)->distribute($draft, $parts));
    }
}
