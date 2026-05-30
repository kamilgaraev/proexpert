<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\NormativeMatchDecisionService;
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
