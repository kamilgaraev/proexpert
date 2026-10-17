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
}
