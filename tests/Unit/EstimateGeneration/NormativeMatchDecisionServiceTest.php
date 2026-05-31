<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\NormativeMatchDecisionService;
use Tests\TestCase;

class NormativeMatchDecisionServiceTest extends TestCase
{
    public function test_low_confidence_candidate_is_not_used_for_pricing(): void
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
        $this->assertFalse($decision->canUseForPricing);
        $this->assertContains('low_confidence', $decision->warnings);
    }

    public function test_middle_confidence_safe_candidate_requires_manual_review_without_pricing(): void
    {
        $decision = app(NormativeMatchDecisionService::class)->decide([
            'confidence' => 0.61,
            'unit' => 'м2',
            'resources' => [
                'materials' => [['price_source' => 'fsbc_base']],
                'labor' => [],
                'machinery' => [],
                'other' => [],
            ],
        ], ['unit' => 'м2']);

        $this->assertSame('candidate', $decision->status);
        $this->assertFalse($decision->canUseForPricing);
        $this->assertContains('low_confidence', $decision->warnings);
        $this->assertContains('requires_normative_review', $decision->warnings);
    }

    public function test_middle_confidence_candidate_with_wrong_domain_is_not_review_priced(): void
    {
        $decision = app(NormativeMatchDecisionService::class)->decide([
            'confidence' => 0.61,
            'unit' => 'м2',
            'code' => '16-07-001-01',
            'name' => 'Установка водопроводной арматуры',
            'collection' => ['norm_type' => 'gesnp_plumbing'],
            'section' => ['code' => '16'],
            'resources' => [
                'materials' => [['price_source' => 'fsbc_base']],
                'labor' => [],
                'machinery' => [],
                'other' => [],
            ],
        ], [
            'name' => 'Утепление кровли 200 мм',
            'unit' => 'м2',
            'work_intent' => [
                'scope' => 'roof',
                'action' => 'insulation',
                'material' => 'insulation',
            ],
        ]);

        $this->assertSame('candidate', $decision->status);
        $this->assertFalse($decision->canUseForPricing);
        $this->assertContains('scope_mismatch', $decision->warnings);
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

    public function test_scope_mismatch_candidate_is_not_used_for_pricing(): void
    {
        $decision = app(NormativeMatchDecisionService::class)->decide([
            'confidence' => 0.9,
            'unit' => 'м',
            'collection' => ['norm_type' => 'gesn'],
            'section' => ['code' => '01-01'],
            'resources' => [
                'materials' => [['price_source' => 'fsbc_base']],
                'labor' => [],
                'machinery' => [],
                'other' => [],
            ],
        ], [
            'unit' => 'м',
            'work_intent' => ['scope' => 'engineering', 'system' => 'heating'],
        ]);

        $this->assertSame('candidate', $decision->status);
        $this->assertFalse($decision->canUseForPricing);
        $this->assertContains('scope_mismatch', $decision->warnings);
    }

    public function test_strict_scope_prefix_mismatch_candidate_is_not_used_for_pricing(): void
    {
        $decision = app(NormativeMatchDecisionService::class)->decide([
            'confidence' => 0.91,
            'unit' => 'м3',
            'section' => ['code' => '05-01-016'],
            'resources' => [
                'materials' => [['price_source' => 'fsbc_base']],
                'labor' => [],
                'machinery' => [],
                'other' => [],
            ],
        ], [
            'unit' => 'м3',
            'work_intent' => [
                'scope' => 'walls',
                'action' => 'masonry',
                'preferred_section_prefixes' => ['08'],
                'forbidden_section_prefixes' => ['01'],
            ],
        ]);

        $this->assertSame('candidate', $decision->status);
        $this->assertFalse($decision->canUseForPricing);
        $this->assertContains('scope_mismatch', $decision->warnings);
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
