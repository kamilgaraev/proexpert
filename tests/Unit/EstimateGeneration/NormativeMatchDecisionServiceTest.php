<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\NormativeMatchDecisionService;
use Tests\TestCase;

class NormativeMatchDecisionServiceTest extends TestCase
{
    public function test_low_confidence_candidate_can_be_used_for_pricing_with_review_warning(): void
    {
        $decision = app(NormativeMatchDecisionService::class)->decide([
            'confidence' => 0.41,
            'unit' => 'м2',
            'resources' => [
                'materials' => [['price_source' => 'fsbc_base']],
                'labor' => [],
                'machinery' => [],
                'other' => [],
            ],
        ], ['unit' => 'м2']);

        $this->assertSame('candidate', $decision->status);
        $this->assertTrue($decision->canUseForPricing);
        $this->assertContains('low_confidence', $decision->warnings);
    }

    public function test_unit_mismatch_candidate_cannot_be_used_for_pricing(): void
    {
        $decision = app(NormativeMatchDecisionService::class)->decide([
            'confidence' => 0.9,
            'unit' => 'м3',
            'resources' => [
                'materials' => [['price_source' => 'fsbc_base']],
                'labor' => [],
                'machinery' => [],
                'other' => [],
            ],
        ], ['unit' => 'м2']);

        $this->assertSame('candidate', $decision->status);
        $this->assertFalse($decision->canUseForPricing);
        $this->assertContains('unit_mismatch', $decision->warnings);
    }

    public function test_candidate_without_prices_is_not_used_for_pricing(): void
    {
        $decision = app(NormativeMatchDecisionService::class)->decide([
            'confidence' => 0.9,
            'unit' => 'м3',
            'resources' => [
                'materials' => [['price_source' => null]],
                'labor' => [],
                'machinery' => [],
                'other' => [],
            ],
        ], ['unit' => 'м3']);

        $this->assertSame('candidate', $decision->status);
        $this->assertFalse($decision->canUseForPricing);
        $this->assertContains('norm_without_prices', $decision->warnings);
    }

    public function test_high_confidence_priced_candidate_is_accepted(): void
    {
        $decision = app(NormativeMatchDecisionService::class)->decide([
            'confidence' => 0.84,
            'unit' => 'м2',
            'resources' => [
                'materials' => [['price_source' => 'fsbc_base']],
                'labor' => [['price_source' => 'fgis_labor_prices_base']],
                'machinery' => [],
                'other' => [],
            ],
        ], ['unit' => 'м2']);

        $this->assertSame('accepted', $decision->status);
        $this->assertTrue($decision->canUseForPricing);
    }

    public function test_scaled_normative_unit_is_compatible_with_work_unit(): void
    {
        $decision = app(NormativeMatchDecisionService::class)->decide([
            'confidence' => 0.84,
            'unit' => '1000 м3',
            'resources' => [
                'materials' => [['price_source' => 'fsbc_base']],
                'labor' => [],
                'machinery' => [],
                'other' => [],
            ],
        ], ['unit' => 'м3']);

        $this->assertSame('accepted', $decision->status);
        $this->assertTrue($decision->canUseForPricing);
        $this->assertNotContains('unit_mismatch', $decision->warnings);
    }
}
