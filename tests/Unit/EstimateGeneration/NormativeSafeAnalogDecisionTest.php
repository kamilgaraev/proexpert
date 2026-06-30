<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\NormativeMatchDecisionService;
use Tests\TestCase;

final class NormativeSafeAnalogDecisionTest extends TestCase
{
    public function test_middle_confidence_safe_analog_can_be_used_for_review_pricing(): void
    {
        $decision = app(NormativeMatchDecisionService::class)->decide([
            'confidence' => 0.61,
            'unit' => 'м2',
            'section' => ['code' => '12'],
            'resources' => [
                'materials' => [['price_source' => 'fsbc_base', 'unit_price' => 1000, 'quantity' => 1, 'total_price' => 1000]],
                'labor' => [['price_source' => 'fgis_labor_prices_base', 'unit_price' => 100, 'quantity' => 1, 'total_price' => 100]],
                'machinery' => [],
                'other' => [],
            ],
        ], [
            'name' => 'Утепление кровли 200 мм',
            'unit' => 'м2',
            'work_intent' => [
                'scope' => 'roof',
                'action' => 'insulation',
                'preferred_section_prefixes' => ['12', '26'],
                'forbidden_section_prefixes' => ['01', '16'],
            ],
        ]);

        $this->assertSame('review_priced', $decision->status);
        $this->assertTrue($decision->canUseForPricing);
        $this->assertContains('safe_normative_analog', $decision->warnings);
        $this->assertContains('requires_normative_review', $decision->warnings);
    }

    public function test_wrong_scope_analog_stays_unpriced_candidate(): void
    {
        $decision = app(NormativeMatchDecisionService::class)->decide([
            'confidence' => 0.91,
            'unit' => 'м2',
            'section' => ['code' => '16'],
            'name' => 'Установка водопроводной арматуры',
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
                'preferred_section_prefixes' => ['12', '26'],
                'forbidden_section_prefixes' => ['01', '16'],
            ],
        ]);

        $this->assertSame('candidate', $decision->status);
        $this->assertFalse($decision->canUseForPricing);
        $this->assertContains('scope_mismatch', $decision->warnings);
    }
}
