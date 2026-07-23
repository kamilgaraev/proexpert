<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Quality\Arbiter;

use App\BusinessModules\Addons\EstimateGeneration\Services\Quality\Arbiter\ArbiterReviewContextFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ArbiterReviewContextFactoryTest extends TestCase
{
    #[Test]
    public function it_keeps_internal_numeric_evidence_ids_as_verifiable_references(): void
    {
        $context = (new ArbiterReviewContextFactory)->make([
            'completeness' => [
                'status' => 'confirmed_scope_only',
                'scopes' => [['key' => 'heating', 'state' => 'unresolved']],
            ],
            'local_estimates' => [[
                'key' => 'heating',
                'sections' => [['work_items' => [[
                    'metadata' => ['composition_work_key' => 'heating.unit'],
                    'quantity_evidence' => ['evidence_ids' => [17]],
                ]]]],
            ]],
        ]);

        self::assertContains('17', $context['evidence_refs']);
    }

    #[Test]
    public function it_passes_the_structured_draft_context_to_the_arbiter_without_resource_payloads(): void
    {
        $context = (new ArbiterReviewContextFactory)->make([
            'object_profile' => ['description' => 'Two-storey house'],
            'building_model' => ['scale_status' => 'confirmed', 'floors' => 2],
            'building_quantities' => ['total_area' => 180],
            'source_documents' => [['id' => 7, 'source' => 'plan']],
            'document_requirements' => ['heating' => true],
            'traceability' => ['document_source_refs' => [7]],
            'regional_context' => ['region_code' => '77'],
            'completeness' => ['status' => 'confirmed_scope_only', 'scopes' => []],
            'budget_scope' => ['direct_costs' => 1200.0],
            'local_estimates' => [[
                'key' => 'heating',
                'sections' => [['work_items' => [[
                    'name' => 'Heating boiler',
                    'quantity' => 1,
                    'unit' => 'pcs',
                    'total_cost' => 1000.0,
                    'normative_match' => ['status' => 'matched'],
                    'materials' => [['name' => 'Boiler', 'quantity' => 1]],
                ]]]],
            ]],
        ]);

        self::assertSame('Two-storey house', $context['review_context']['object_profile']['description']);
        self::assertSame(2, $context['review_context']['building_model']['floors']);
        self::assertSame(180, $context['review_context']['building_quantities']['total_area']);
        self::assertSame(7, $context['review_context']['source_documents'][0]['id']);
        self::assertSame('Heating boiler', $context['work_items'][0]['name']);
        self::assertSame(1, $context['work_items'][0]['resource_summary']['materials_count']);
        self::assertArrayNotHasKey('materials', $context['work_items'][0]);
    }

    #[Test]
    public function it_keeps_the_arbiter_context_compact_for_resource_heavy_estimates(): void
    {
        $resource = [
            'name' => str_repeat('Concrete resource with verbose provider payload ', 20),
            'quantity' => 1.25,
            'unit' => 'm3',
            'unit_price' => 1234.56,
            'total_cost' => 1543.2,
            'metadata' => ['payload' => str_repeat('heavy metadata ', 80)],
        ];
        $workItems = [];
        for ($i = 0; $i < 50; $i++) {
            $workItems[] = [
                'name' => 'Work item '.$i,
                'description' => str_repeat('Long generated explanation ', 30),
                'quantity' => 10 + $i,
                'unit' => 'm2',
                'total_cost' => 1000 + $i,
                'quantity_evidence' => ['evidence_ids' => [$i + 1]],
                'normative_match' => [
                    'status' => 'matched',
                    'norm_code' => '08-01-00'.$i,
                    'candidates' => array_fill(0, 10, ['payload' => str_repeat('candidate ', 50)]),
                ],
                'price_snapshot' => ['payload' => str_repeat('price snapshot ', 80)],
                'materials' => array_fill(0, 20, $resource),
                'labor' => array_fill(0, 20, $resource),
                'machinery' => array_fill(0, 20, $resource),
            ];
        }

        $context = (new ArbiterReviewContextFactory)->make([
            'completeness' => ['status' => 'review_required', 'scopes' => []],
            'local_estimates' => [[
                'key' => 'house',
                'sections' => [['work_items' => $workItems]],
            ]],
        ]);

        $encoded = json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        self::assertLessThan(24_000, strlen($encoded));
        self::assertArrayNotHasKey('materials', $context['work_items'][0]);
        self::assertArrayNotHasKey('labor', $context['work_items'][0]);
        self::assertArrayNotHasKey('machinery', $context['work_items'][0]);
        self::assertArrayNotHasKey('price_snapshot', $context['work_items'][0]);
        self::assertSame(20, $context['work_items'][0]['resource_summary']['materials_count']);
    }
}
