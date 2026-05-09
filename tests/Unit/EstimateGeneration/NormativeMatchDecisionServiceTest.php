<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\NormativeMatchDecisionService;
use Tests\TestCase;

class NormativeMatchDecisionServiceTest extends TestCase
{
    public function test_low_confidence_candidate_is_not_accepted_for_pricing(): void
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

    public function test_unit_mismatch_rejects_candidate(): void
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

        $this->assertSame('rejected', $decision->status);
        $this->assertFalse($decision->canUseForPricing);
        $this->assertContains('unit_mismatch', $decision->warnings);
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
}
