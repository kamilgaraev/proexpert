<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\NormativeMatchDecisionService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FsnbFirstNormativeSafetyTest extends TestCase
{
    public function test_cable_line_cannot_be_priced_by_piece_substation_norm(): void
    {
        $decision = (new NormativeMatchDecisionService())->decide($this->candidate([
            'code' => '08-01-025-03',
            'name' => 'Подстанция блочная',
            'unit' => 'шт',
            'confidence' => 0.95,
        ]), [
            'name' => 'Прокладка кабельных линий',
            'unit' => 'м',
            'quantity' => 834.68,
        ]);

        $this->assertFalse($decision->canUseForPricing);
        $this->assertContains('unit_mismatch', $decision->warnings);
    }

    public function test_roof_insulation_cannot_be_priced_by_trench_norm(): void
    {
        $decision = (new NormativeMatchDecisionService())->decide($this->candidate([
            'code' => '01-01-063-01',
            'name' => 'Разработка грунта в траншеях',
            'unit' => 'км',
            'confidence' => 0.95,
        ]), [
            'name' => 'Утепление кровли 200 мм',
            'unit' => 'м2',
            'quantity' => 194.25,
        ]);

        $this->assertFalse($decision->canUseForPricing);
        $this->assertContains('unit_mismatch', $decision->warnings);
    }

    public function test_heating_pipe_layout_cannot_be_priced_by_earthwork_norm(): void
    {
        $decision = (new NormativeMatchDecisionService())->decide($this->candidate([
            'code' => '01-01-063-01',
            'name' => 'Разработка грунта в траншеях',
            'unit' => 'км',
            'confidence' => 0.95,
        ]), [
            'name' => 'Разводка труб отопления',
            'unit' => 'м',
            'quantity' => 182.11,
            'work_intent' => ['scope' => 'engineering', 'system' => 'heating'],
        ]);

        $this->assertFalse($decision->canUseForPricing);
        $this->assertContains('scope_mismatch', $decision->warnings);
    }

    #[DataProvider('unsafeScopeProvider')]
    public function test_cross_scope_false_positive_cannot_be_used_for_pricing(
        string $workName,
        string $workUnit,
        string $candidateCode,
        string $candidateName,
        string $candidateUnit,
        string $sectionCode
    ): void {
        $decision = (new NormativeMatchDecisionService())->decide($this->candidate([
            'code' => $candidateCode,
            'name' => $candidateName,
            'unit' => $candidateUnit,
            'confidence' => 0.95,
            'section' => ['code' => $sectionCode],
        ]), [
            'name' => $workName,
            'unit' => $workUnit,
            'quantity' => 1,
        ]);

        $this->assertFalse($decision->canUseForPricing);
        $this->assertContains('scope_mismatch', $decision->warnings);
    }

    public static function unsafeScopeProvider(): array
    {
        return [
            'thermal curtain vs portal crane' => [
                'Монтаж тепловой завесы',
                'шт',
                '03-01-001-01',
                'Монтаж крана портального',
                'шт',
                '03',
            ],
            'aerated concrete masonry vs sheet pile' => [
                'Кладка стен из газобетонных блоков',
                'м3',
                '05-01-001-01',
                'Устройство шпунтового ограждения',
                'м3',
                '05',
            ],
            'roof insulation vs water valve' => [
                'Утепление кровли минераловатными плитами',
                'м2',
                '16-02-001-01',
                'Установка водопроводной арматуры',
                'м2',
                '16',
            ],
            'facade plastering vs blasting' => [
                'Фасадная штукатурка по утеплителю',
                'м2',
                '03-03-001-01',
                'Взрывные работы в грунтах',
                'м2',
                '03',
            ],
            'temporary fence vs railway track' => [
                'Устройство временного ограждения строительной площадки',
                'м',
                '28-01-001-01',
                'Укладка железнодорожного пути',
                'м',
                '28',
            ],
        ];
    }

    public function test_gas_concrete_masonry_cannot_be_priced_by_sheet_pile_norm(): void
    {
        $decision = (new NormativeMatchDecisionService())->decide($this->candidate([
            'code' => '05-01-016-01',
            'name' => 'Обстройка деревянного шпунтового ограждения',
            'unit' => 'м3',
            'confidence' => 0.95,
        ]), [
            'name' => 'Кладка наружных стен из газобетона D500 400 мм',
            'unit' => 'м3',
            'quantity' => 50.09,
            'work_intent' => [
                'scope' => 'walls',
                'action' => 'masonry',
                'preferred_section_prefixes' => ['08'],
                'forbidden_section_prefixes' => ['01'],
            ],
        ]);

        $this->assertFalse($decision->canUseForPricing);
        $this->assertContains('scope_mismatch', $decision->warnings);
    }

    public function test_roof_insulation_cannot_be_priced_by_plumbing_or_groundwork_norm(): void
    {
        $decision = (new NormativeMatchDecisionService())->decide($this->candidate([
            'code' => '02-01-001-05',
            'name' => 'Арматура фланцевая водопроводная',
            'unit' => 'м2',
            'confidence' => 0.95,
        ]), [
            'name' => 'Утепление кровли 200 мм',
            'unit' => 'м2',
            'quantity' => 194.25,
            'work_intent' => [
                'scope' => 'roof',
                'action' => 'insulation',
                'preferred_section_prefixes' => ['12', '26'],
                'forbidden_section_prefixes' => ['01'],
            ],
        ]);

        $this->assertFalse($decision->canUseForPricing);
        $this->assertContains('scope_mismatch', $decision->warnings);
    }

    public function test_thermal_air_curtain_cannot_be_priced_by_crane_norm(): void
    {
        $decision = (new NormativeMatchDecisionService())->decide($this->candidate([
            'code' => '03-01-065-01',
            'name' => 'Кран портальный электрический полноповоротный',
            'unit' => 'шт',
            'confidence' => 0.95,
        ]), [
            'name' => 'Воздушно-тепловые завесы ворот',
            'unit' => 'шт',
            'quantity' => 1,
            'work_intent' => [
                'scope' => 'engineering',
                'action' => 'heating_equipment',
                'system' => 'heating',
                'preferred_section_prefixes' => ['16', '18', '20'],
                'forbidden_section_prefixes' => ['01'],
            ],
        ]);

        $this->assertFalse($decision->canUseForPricing);
        $this->assertContains('scope_mismatch', $decision->warnings);
    }

    public function test_temporary_fence_cannot_be_priced_by_railway_earthwork_norm(): void
    {
        $decision = (new NormativeMatchDecisionService())->decide($this->candidate([
            'code' => '01-02-028-01',
            'name' => 'Отделка земляного полотна железнодорожного пути',
            'unit' => 'м',
            'confidence' => 0.95,
        ]), [
            'name' => 'Временное ограждение площадки',
            'unit' => 'м',
            'quantity' => 69.83,
            'work_intent' => [
                'scope' => 'temporary',
                'action' => 'fence_installation',
                'preferred_section_prefixes' => ['08', '09'],
                'forbidden_section_prefixes' => ['01'],
            ],
        ]);

        $this->assertFalse($decision->canUseForPricing);
        $this->assertContains('scope_mismatch', $decision->warnings);
    }

    public function test_air_curtain_cannot_be_priced_by_portal_crane_norm(): void
    {
        $decision = (new NormativeMatchDecisionService())->decide($this->candidate([
            'code' => '09-05-001-01',
            'name' => 'Кран портальный электрический',
            'unit' => 'шт',
            'confidence' => 0.9,
            'collection' => ['norm_type' => 'gesn_building'],
            'section' => ['code' => '09'],
        ]), [
            'name' => 'Воздушно-тепловые завесы ворот',
            'unit' => 'шт',
            'quantity' => 2,
            'work_intent' => [
                'scope' => 'engineering',
                'system' => 'heating',
                'action' => 'heating_equipment',
            ],
        ]);

        $this->assertFalse($decision->canUseForPricing);
        $this->assertContains('scope_mismatch', $decision->warnings);
    }

    public function test_aerated_concrete_masonry_cannot_be_priced_by_sheet_piling_norm(): void
    {
        $decision = (new NormativeMatchDecisionService())->decide($this->candidate([
            'code' => '05-01-001-01',
            'name' => 'Обстройка деревянного шпунтового ограждения',
            'unit' => 'м3',
            'confidence' => 0.9,
            'collection' => ['norm_type' => 'gesn_building'],
            'section' => ['code' => '05'],
        ]), [
            'name' => 'Кладка наружных стен из газобетона D500 400 мм',
            'unit' => 'м3',
            'quantity' => 80,
            'work_intent' => [
                'scope' => 'walls',
                'action' => 'masonry',
                'material' => 'aerated_concrete',
            ],
        ]);

        $this->assertFalse($decision->canUseForPricing);
        $this->assertContains('scope_mismatch', $decision->warnings);
    }

    public function test_roof_insulation_cannot_be_priced_by_water_fittings_norm(): void
    {
        $decision = (new NormativeMatchDecisionService())->decide($this->candidate([
            'code' => '16-07-001-01',
            'name' => 'Установка водопроводной арматуры',
            'unit' => 'м2',
            'confidence' => 0.88,
            'collection' => ['norm_type' => 'gesnp_plumbing'],
            'section' => ['code' => '16'],
        ]), [
            'name' => 'Утепление кровли 200 мм',
            'unit' => 'м2',
            'quantity' => 194.25,
            'work_intent' => [
                'scope' => 'roof',
                'action' => 'insulation',
                'material' => 'insulation',
            ],
        ]);

        $this->assertFalse($decision->canUseForPricing);
        $this->assertContains('scope_mismatch', $decision->warnings);
    }

    public function test_facade_plaster_cannot_be_priced_by_blasting_area_cover_norm(): void
    {
        $decision = (new NormativeMatchDecisionService())->decide($this->candidate([
            'code' => '03-01-001-01',
            'name' => 'Укрытие взрываемой площади',
            'unit' => 'м2',
            'confidence' => 0.86,
            'collection' => ['norm_type' => 'gesn_earthwork'],
            'section' => ['code' => '03'],
        ]), [
            'name' => 'Фасадная штукатурка по газобетону',
            'unit' => 'м2',
            'quantity' => 210,
            'work_intent' => [
                'scope' => 'facade',
                'action' => 'plastering',
                'material' => 'aerated_concrete',
            ],
        ]);

        $this->assertFalse($decision->canUseForPricing);
        $this->assertContains('scope_mismatch', $decision->warnings);
    }

    public function test_baseboard_can_be_priced_by_flooring_section_norm(): void
    {
        $decision = (new NormativeMatchDecisionService())->decide($this->candidate([
            'code' => '11-01-039-01',
            'name' => 'Устройство плинтусов: деревянных',
            'unit' => '100 м',
            'confidence' => 0.9,
        ]), [
            'name' => 'Монтаж плинтусов',
            'unit' => 'м',
            'quantity' => 77,
        ]);

        $this->assertTrue($decision->canUseForPricing);
        $this->assertNotContains('scope_mismatch', $decision->warnings);
    }

    public function test_linear_baseboard_cannot_be_priced_by_wall_tiling_baseboard_norm(): void
    {
        $decision = (new NormativeMatchDecisionService())->decide($this->candidate([
            'code' => '15-01-020-01',
            'name' => 'Облицовка стен на цементном растворе с карнизными, плинтусными и угловыми плитками',
            'unit' => '100 м2',
            'confidence' => 0.9,
        ]), [
            'name' => 'Монтаж плинтусов',
            'unit' => 'м',
            'quantity' => 77,
        ]);

        $this->assertFalse($decision->canUseForPricing);
        $this->assertContains('unit_mismatch', $decision->warnings);
        $this->assertContains('scope_mismatch', $decision->warnings);
    }

    public function test_floor_covering_can_be_priced_by_flooring_section_norm(): void
    {
        $decision = (new NormativeMatchDecisionService())->decide($this->candidate([
            'code' => '11-01-036-01',
            'name' => 'Устройство покрытий: из линолеума на клее',
            'unit' => '100 м2',
            'confidence' => 0.9,
        ]), [
            'name' => 'Устройство чистового покрытия пола',
            'unit' => 'м2',
            'quantity' => 87.14,
        ]);

        $this->assertTrue($decision->canUseForPricing);
        $this->assertNotContains('scope_mismatch', $decision->warnings);
    }

    public function test_ceiling_finishing_cannot_be_priced_by_boiler_ceiling_equipment_norm(): void
    {
        $decision = (new NormativeMatchDecisionService())->decide($this->candidate([
            'code' => '06-01-006-14',
            'name' => 'Пароперегреватель потолочный из гладких труб',
            'unit' => '100 м2',
            'confidence' => 0.9,
        ]), [
            'name' => 'Монтаж подвесного потолка',
            'unit' => 'м2',
            'quantity' => 87.14,
        ]);

        $this->assertFalse($decision->canUseForPricing);
        $this->assertContains('scope_mismatch', $decision->warnings);
    }

    public function test_temporary_site_fence_cannot_be_priced_by_railway_earthwork_norm(): void
    {
        $decision = (new NormativeMatchDecisionService())->decide($this->candidate([
            'code' => '27-01-001-01',
            'name' => 'Устройство железнодорожного земляного полотна',
            'unit' => 'м',
            'confidence' => 0.88,
            'collection' => ['norm_type' => 'gesn_earthwork'],
            'section' => ['code' => '27'],
        ]), [
            'name' => 'Временное ограждение площадки',
            'unit' => 'м',
            'quantity' => 120,
            'work_intent' => [
                'scope' => 'temporary',
                'action' => 'fence_installation',
            ],
        ]);

        $this->assertFalse($decision->canUseForPricing);
        $this->assertContains('scope_mismatch', $decision->warnings);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function candidate(array $overrides): array
    {
        return [
            ...$overrides,
            'resources' => [
                'materials' => [[
                    'price_source' => 'fsbc_base',
                    'quantity' => 1,
                    'unit_price' => 1000,
                ]],
                'machinery' => [],
                'labor' => [],
                'other' => [],
            ],
            'collection' => ['norm_type' => 'gesn'],
            'section' => ['code' => substr((string) $overrides['code'], 0, 5)],
        ];
    }
}
