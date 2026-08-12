<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Pipeline\Stages\PlanWorkItemsStage;
use App\BusinessModules\Addons\EstimateGeneration\Planning\CanonicalTechnologyWorkItemPlanner;
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

    #[Test]
    public function every_work_uses_its_explicit_formula_norm_dependencies_and_resources(): void
    {
        $package = [
            'id' => 'package:roof',
            'finding_key' => 'finding:roof',
            'finding_version' => 2,
            'status' => 'proven_missing',
            'works' => [
                ['id' => 'work:deck', 'label' => 'Основание', 'quantity_formula_id' => 'formula:deck', 'norm_intent_id' => 'norm:deck'],
                ['id' => 'work:roof', 'label' => 'Покрытие', 'quantity_formula_id' => 'formula:roof', 'norm_intent_id' => 'norm:roof', 'depends_on' => ['work:deck']],
            ],
            'norm_intents' => [
                ['id' => 'norm:roof', 'candidate_refs' => ['fsnb:roof']],
                ['id' => 'norm:deck', 'candidate_refs' => ['fsnb:deck']],
            ],
            'quantities' => [
                ['key' => 'quantity:roof', 'formula_id' => 'formula:roof', 'unit' => 'm2'],
                ['key' => 'quantity:deck', 'formula_id' => 'formula:deck', 'unit' => 'm2'],
            ],
            'materials' => [['intent' => 'roof_material']],
            'machinery' => [['intent' => 'lifting_equipment']],
            'variants' => [['id' => 'variant:warm']],
            'dependencies' => [['from' => 'work:deck', 'to' => 'work:roof']],
            'assumptions' => ['roof_ready'],
            'risks' => ['weather_risk'],
            'evidence_fact_ids' => ['fact:roof-area'],
            'technology_decision_key' => 'decision:roof',
        ];

        $canonical = (new CanonicalTechnologyWorkItemPlanner)->planPackages([$package]);
        self::assertSame($canonical, NormativeWorkItemPlannerService::stageFivePackageItems([$package]));
        self::assertSame(['quantity:deck', 'quantity:roof'], array_column(array_column($canonical, 'metadata'), 'quantity_key'));
        self::assertSame(['norm:deck', 'norm:roof'], array_column(array_column(array_column($canonical, 'metadata'), 'normative_intent'), 'id'));
        self::assertContains('work:deck', $canonical[1]['metadata']['dependency_keys']);
        self::assertSame([['intent' => 'roof_material']], $canonical[1]['metadata']['technology_materials']);
        self::assertSame([['intent' => 'lifting_equipment']], $canonical[1]['metadata']['technology_machinery']);
        self::assertSame([['id' => 'variant:warm']], $canonical[1]['metadata']['technology_variants']);

        $parameter = (new \ReflectionMethod(PlanWorkItemsStage::class, '__construct'))->getParameters()[7];
        self::assertSame(CanonicalTechnologyWorkItemPlanner::class, $parameter->getType()?->getName());
    }
}
