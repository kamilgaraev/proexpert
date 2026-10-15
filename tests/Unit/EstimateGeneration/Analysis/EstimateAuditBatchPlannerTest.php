<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Analysis;

use App\BusinessModules\Addons\EstimateGeneration\Analysis\Audit\EstimateAuditBatchPlanner;
use PHPUnit\Framework\TestCase;

final class EstimateAuditBatchPlannerTest extends TestCase
{
    public function test_sparse_sections_are_fully_covered_with_the_prequoted_part_ceiling(): void
    {
        $sections = [];
        for ($index = 0; $index < 65; $index++) {
            $sections[] = ['key' => 'section-'.$index, 'title' => 'Раздел '.$index,
                'work_items' => [['key' => 'work-'.$index, 'name' => 'Работа '.$index, 'quantity' => '1', 'source_refs' => [['fact_id' => 'fact-'.$index]]]]];
        }
        $draft = ['scope' => ['finishing'], 'local_estimates' => [['key' => 'building', 'title' => 'Объект', 'sections' => $sections]]];
        $planner = new EstimateAuditBatchPlanner;
        $parts = $planner->plan($draft);
        self::assertCount(EstimateAuditBatchPlanner::partCeiling(65), $parts);
        $actual = [];
        foreach ($parts as $part) {
            self::assertLessThanOrEqual(32, count($part['work_keys']));
            self::assertSame(['finishing'], $part['draft']['scope']);
            foreach ($part['draft']['local_estimates'] as $local) {
                foreach ($local['sections'] as $section) {
                    foreach ($section['work_items'] as $item) {
                        $actual[$item['key']] = $item;
                    }
                }
            }
        }
        self::assertCount(65, $actual);
        foreach ($sections as $section) {
            self::assertSame($section['work_items'][0], $actual[$section['work_items'][0]['key']]);
        }
        $reordered = $draft;
        $reordered['local_estimates'][0]['sections'] = array_reverse($sections);
        self::assertSame(array_column($parts, 'id'), array_column($planner->plan($reordered), 'id'));
    }

    public function test_duplicate_work_identity_is_rejected_instead_of_overwriting_a_position(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new EstimateAuditBatchPlanner)->plan(['local_estimates' => [['sections' => [['work_items' => [['key' => 'same'], ['key' => 'same']]]]]]]);
    }

    public function test_candidate_rows_do_not_make_an_empty_accepted_composition_auditable(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('estimate_audit_partition_limit');
        (new EstimateAuditBatchPlanner)->plan([
            'local_estimates' => [['sections' => [['work_items' => []]]]],
            'stage6_candidate_rows' => [['key' => 'unaccepted-work']],
        ]);
    }
}
