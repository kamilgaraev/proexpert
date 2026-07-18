<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\NormativeMatchDecisionService;
use PHPUnit\Framework\TestCase;

class NormativeMatchDecisionServiceTest extends TestCase
{
    public function test_low_confidence_candidate_is_not_used_for_pricing(): void
    {
        $decision = (new NormativeMatchDecisionService)->decide([
            'confidence' => 0.41,
            'unit' => 'м2',
            'resources' => [
                'materials' => [['price_source' => 'fsbc_base', 'total_price' => 1000]],
                'labor' => [],
                'machinery' => [],
                'other' => [],
            ],
        ], ['unit' => 'м2']);

        $this->assertSame('candidate', $decision->status);
        $this->assertFalse($decision->canUseForPricing);
        $this->assertContains('low_confidence', $decision->warnings);
    }

    public function test_middle_confidence_safe_candidate_is_priced_for_manual_review(): void
    {
        $decision = (new NormativeMatchDecisionService)->decide([
            'confidence' => 0.61,
            'unit' => 'м2',
            'resources' => [
                'materials' => [['price_source' => 'fsbc_base', 'total_price' => 1000]],
                'labor' => [],
                'machinery' => [],
                'other' => [],
            ],
        ], ['unit' => 'м2']);

        $this->assertSame('review_priced', $decision->status);
        $this->assertTrue($decision->canUseForPricing);
        $this->assertContains('low_confidence', $decision->warnings);
        $this->assertContains('requires_normative_review', $decision->warnings);
        $this->assertContains('safe_normative_analog', $decision->warnings);
    }

    public function test_middle_confidence_candidate_with_wrong_domain_is_not_review_priced(): void
    {
        $decision = (new NormativeMatchDecisionService)->decide([
            'confidence' => 0.61,
            'unit' => 'м2',
            'code' => '16-07-001-01',
            'name' => 'Установка водопроводной арматуры',
            'collection' => ['norm_type' => 'gesnp_plumbing'],
            'section' => ['code' => '16'],
            'resources' => [
                'materials' => [['price_source' => 'fsbc_base', 'total_price' => 1000]],
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
        $decision = (new NormativeMatchDecisionService)->decide([
            'confidence' => 0.9,
            'unit' => 'м3',
            'resources' => [
                'materials' => [['price_source' => 'fsbc_base', 'total_price' => 1000]],
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
        $decision = (new NormativeMatchDecisionService)->decide([
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

    public function test_candidate_with_partially_unpriced_resources_is_not_used_for_pricing(): void
    {
        $decision = (new NormativeMatchDecisionService)->decide([
            'confidence' => 0.9,
            'unit' => 'м3',
            'resources' => [
                'materials' => [
                    ['price_source' => 'fsbc_base', 'total_price' => 1000],
                    ['price_source' => null],
                ],
                'labor' => [],
                'machinery' => [],
                'other' => [],
            ],
        ], ['unit' => 'м3']);

        $this->assertSame('candidate', $decision->status);
        $this->assertFalse($decision->canUseForPricing);
        $this->assertContains('norm_with_unpriced_resources', $decision->warnings);
    }

    public function test_candidate_with_zero_price_source_is_not_used_for_pricing(): void
    {
        $decision = (new NormativeMatchDecisionService)->decide([
            'confidence' => 0.9,
            'unit' => 'м3',
            'resources' => [
                'materials' => [[
                    'price_source' => 'fsbc_base',
                    'quantity' => 1,
                    'unit_price' => 0,
                    'total_price' => 0,
                ]],
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
        $decision = (new NormativeMatchDecisionService)->decide([
            'confidence' => 0.9,
            'unit' => 'м',
            'collection' => ['norm_type' => 'gesn'],
            'section' => ['code' => '01-01'],
            'resources' => [
                'materials' => [['price_source' => 'fsbc_base', 'total_price' => 1000]],
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
        $decision = (new NormativeMatchDecisionService)->decide([
            'confidence' => 0.91,
            'unit' => 'м3',
            'section' => ['code' => '05-01-016'],
            'resources' => [
                'materials' => [['price_source' => 'fsbc_base', 'total_price' => 1000]],
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

    public function test_semantically_wrong_norm_is_not_used_even_with_matching_unit_section_and_confidence(): void
    {
        $decision = (new NormativeMatchDecisionService)->decide([
            'confidence' => 0.95,
            'unit' => 'м3',
            'code' => '06-22-016-02',
            'name' => 'Бетонирование конструкций шахты реактора: электропрогрев серпентинитового бетона',
            'section' => ['code' => '06'],
            'resources' => [
                'materials' => [['price_source' => 'fsbc_base', 'total_price' => 1000]],
                'labor' => [],
                'machinery' => [],
                'other' => [],
            ],
        ], [
            'name' => 'Бетонирование фундаментов',
            'unit' => 'м3',
            'work_intent' => [
                'scope' => 'foundation',
                'action' => 'concreting',
                'system' => null,
            ],
        ]);

        $this->assertSame('candidate', $decision->status);
        $this->assertFalse($decision->canUseForPricing);
        $this->assertContains('semantic_mismatch', $decision->warnings);
    }

    public function test_semantically_matching_norm_can_be_accepted(): void
    {
        $decision = (new NormativeMatchDecisionService)->decide([
            'confidence' => 0.95,
            'unit' => 'м3',
            'code' => '06-01-001-01',
            'name' => 'Устройство бетонной подготовки под фундаменты',
            'section' => ['code' => '06'],
            'resources' => [
                'materials' => [['price_source' => 'fsbc_base', 'total_price' => 1000]],
                'labor' => [],
                'machinery' => [],
                'other' => [],
            ],
        ], [
            'name' => 'Бетонирование фундаментов',
            'unit' => 'м3',
            'work_intent' => [
                'scope' => 'foundation',
                'action' => 'concreting',
                'system' => null,
            ],
        ]);

        $this->assertSame('accepted', $decision->status, json_encode($decision->warnings, JSON_UNESCAPED_UNICODE));
        $this->assertTrue($decision->canUseForPricing);
        $this->assertNotContains('semantic_mismatch', $decision->warnings);
    }

    public function test_soil_haulage_can_use_transport_norm_from_earthwork_section(): void
    {
        $decision = (new NormativeMatchDecisionService)->decide([
            'confidence' => 0.95,
            'unit' => 'м3',
            'code' => '01-01-001-01',
            'name' => 'Перевозка грунта автомобилями-самосвалами',
            'section' => ['code' => '01'],
            'resources' => [
                'materials' => [['price_source' => 'fsbc_base', 'total_price' => 1000]],
                'labor' => [],
                'machinery' => [],
                'other' => [],
            ],
        ], [
            'name' => 'Вывоз излишнего грунта',
            'unit' => 'м3',
            'work_intent' => [
                'scope' => 'foundation',
                'action' => 'soil_haulage',
                'system' => null,
                'preferred_section_prefixes' => ['01'],
            ],
        ]);

        self::assertSame('accepted', $decision->status);
        self::assertTrue($decision->canUseForPricing);
        self::assertNotContains('scope_mismatch', $decision->warnings);
        self::assertNotContains('semantic_mismatch', $decision->warnings);
    }

    public function test_strong_action_is_not_accepted_from_candidate_composition_only(): void
    {
        $decision = (new NormativeMatchDecisionService)->decide([
            'confidence' => 0.95,
            'unit' => 'm',
            'code' => '08-02-001-01',
            'name' => 'Трубопровод стальной 219 мм',
            'section' => ['code' => '08'],
            'work_composition' => ['Прокладка кабеля в защитной трубе'],
            'resources' => [
                'materials' => [['price_source' => 'fsbc_base', 'total_price' => 1000]],
                'labor' => [],
                'machinery' => [],
                'other' => [],
            ],
        ], [
            'name' => 'Прокладка кабельных линий',
            'unit' => 'm',
            'work_intent' => [
                'scope' => 'engineering',
                'action' => 'cable_installation',
                'system' => 'electrical',
                'preferred_section_prefixes' => ['08'],
            ],
        ]);

        self::assertFalse($decision->canUseForPricing);
        self::assertContains('semantic_mismatch', $decision->warnings);
    }

    public function test_high_confidence_priced_candidate_is_accepted(): void
    {
        $decision = (new NormativeMatchDecisionService)->decide([
            'confidence' => 0.84,
            'unit' => 'м2',
            'resources' => [
                'materials' => [['price_source' => 'fsbc_base', 'total_price' => 1000]],
                'labor' => [['price_source' => 'fgis_labor_prices_base', 'total_price' => 300]],
                'machinery' => [],
                'other' => [],
            ],
        ], ['unit' => 'м2']);

        $this->assertSame('accepted', $decision->status);
        $this->assertTrue($decision->canUseForPricing);
    }

    public function test_scaled_normative_unit_is_compatible_with_work_unit(): void
    {
        $decision = (new NormativeMatchDecisionService)->decide([
            'confidence' => 0.84,
            'unit' => '1000 м3',
            'resources' => [
                'materials' => [['price_source' => 'fsbc_base', 'total_price' => 1000]],
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
