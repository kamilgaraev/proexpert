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
    public function it_passes_the_full_structured_draft_context_to_the_arbiter_without_persisting_it(): void
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
        self::assertSame('Boiler', $context['work_items'][0]['materials'][0]['name']);
    }
}
