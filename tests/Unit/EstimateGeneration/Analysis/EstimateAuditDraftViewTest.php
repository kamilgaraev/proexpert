<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Analysis;

use App\BusinessModules\Addons\EstimateGeneration\Analysis\Audit\EstimateAuditDraftView;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\UniversalDraftProjector;
use PHPUnit\Framework\TestCase;

final class EstimateAuditDraftViewTest extends TestCase
{
    public function test_business_view_retains_every_source_and_item_but_excludes_post_audit_presentation_snapshots(): void
    {
        $business = ['generation_contract' => UniversalDraftProjector::CONTRACT,
            'local_estimates' => [['sections' => [['work_items' => [['key' => 'floor', 'quantity' => '20', 'unit' => 'm2',
                'quantity_evidence' => ['fact_id' => 'fact:area', 'locator' => ['page' => 2]],
                'price_snapshot' => ['id' => 'price:1', 'unit_price' => '10.123456']]]]]]],
            'estimate_composition' => ['derived_quantities' => [['id' => 'quantity:area', 'value' => '20']]],
            'estimate_audit' => ['findings' => [['reason' => 'A source must be checked.']]],
            'selected_technology' => ['key' => 'technology:floor'], 'financial_summary' => ['subtotal' => '202.47']];
        $full = [...$business, 'evaluation_result' => ['large_derived_view' => str_repeat('result ', 20000)],
            'quality_summary' => ['review_items' => ['derived review']]];
        self::assertSame($business, EstimateAuditDraftView::project($full, EstimateAuditDraftView::BUSINESS));
        self::assertSame($full, EstimateAuditDraftView::project($full, EstimateAuditDraftView::LEGACY));
        self::assertArrayHasKey('evaluation_result', $full);
        $ordinary = [...$full, 'generation_contract' => 'ordinary:v1'];
        self::assertSame($ordinary, EstimateAuditDraftView::project($ordinary, EstimateAuditDraftView::BUSINESS));
    }

    public function test_an_unknown_view_never_silently_discards_data(): void
    {
        $this->expectExceptionMessage('estimate_audit_draft_view_invalid');
        EstimateAuditDraftView::project(['evaluation_result' => ['source']], 'future');
    }
}
