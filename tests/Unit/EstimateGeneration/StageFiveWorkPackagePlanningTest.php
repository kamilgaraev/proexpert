<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Services\NormativeWorkItemPlannerService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class StageFiveWorkPackagePlanningTest extends TestCase
{
    #[Test]
    public function current_completeness_package_becomes_bounded_normal_work_items_with_canonical_quantity_keys(): void
    {
        $items = NormativeWorkItemPlannerService::stageFivePackageItems([[
            'id' => 'package:fasteners',
            'finding_key' => 'finding:fasteners',
            'finding_version' => 1,
            'status' => 'proven_missing',
            'works' => [
                ['id' => 'work:prepare', 'label' => 'Подготовить крепёж'],
                ['id' => 'work:install', 'label' => 'Установить крепёж'],
            ],
            'norm_intents' => [['id' => 'norm:fasteners', 'candidate_refs' => ['fsnb:intent:fasteners']]],
            'quantities' => [[
                'key' => 'quantity:technology_work_package:package:fasteners:formula:fasteners',
                'formula_id' => 'formula:fasteners',
                'unit' => 'm2',
            ]],
            'evidence_fact_ids' => ['fact:roof-area'],
            'technology_decision' => ['id' => 'decision:roof-system', 'version' => 1, 'status' => 'current'],
        ]]);

        self::assertCount(2, $items);
        self::assertSame('quantity:technology_work_package:package:fasteners:formula:fasteners', $items[0]['metadata']['quantity_key']);
        self::assertSame('decision:roof-system', $items[0]['metadata']['technology_decision']['id']);
        self::assertSame('priced_work', $items[0]['item_type']);
        self::assertSame([['fact_id' => 'fact:roof-area']], $items[0]['source_refs']);
        self::assertNotSame($items[0]['key'], $items[1]['key']);
    }

    #[Test]
    public function malformed_or_oversized_packages_fail_closed_without_unbounded_rows(): void
    {
        $packages = array_fill(0, 201, ['id' => 'broken']);

        self::assertSame([], NormativeWorkItemPlannerService::stageFivePackageItems($packages));
        self::assertSame([], NormativeWorkItemPlannerService::stageFivePackageItems([[
            'id' => 'package:broken',
            'works' => [['id' => 'work:broken', 'label' => '']],
            'quantities' => [],
        ]]));
    }
}
