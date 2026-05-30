<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Services\EstimatePricingService;
use App\BusinessModules\Addons\EstimateGeneration\Services\ResourceAssemblyService;
use Tests\TestCase;

final class ResourceAssemblySafetyTest extends TestCase
{
    public function test_selected_norm_with_incompatible_unit_stays_unpriced_candidate(): void
    {
        $workItem = [
            'key' => 'roof-insulation-1',
            'name' => 'Утепление кровли 200 мм',
            'unit' => 'м2',
            'quantity' => 194.25,
            'confidence' => 0.7,
            'validation_flags' => [],
            'materials' => [],
            'labor' => [],
            'machinery' => [],
        ];
        $match = [
            'version' => ['source_type' => 'fsnb_2022', 'version_key' => '2026-05-07'],
            'price_version' => ['source_type' => 'fsbc', 'version_key' => '2026-05-07'],
            'selected' => $this->unsafeCandidate(),
            'candidates' => [$this->unsafeCandidate()],
        ];

        $item = app(ResourceAssemblyService::class)->applySelectedNormativeMatch($workItem, $match);
        $item = app(EstimatePricingService::class)->price([$item])[0];

        $this->assertSame('candidate', $item['normative_match']['status']);
        $this->assertSame([], $item['materials']);
        $this->assertSame([], $item['labor']);
        $this->assertSame([], $item['machinery']);
        $this->assertSame(0.0, $item['total_cost']);
        $this->assertNull($item['price_source']);
        $this->assertContains('unit_mismatch', $item['normative_match']['warnings']);
        $this->assertContains('requires_normative_review', $item['validation_flags']);
    }

    /**
     * @return array<string, mixed>
     */
    private function unsafeCandidate(): array
    {
        return [
            'key' => 'norm-100',
            'norm_id' => 100,
            'code' => '01-01-063-01',
            'name' => 'Разработка грунта в траншеях',
            'unit' => 'км',
            'collection' => ['code' => 'gesn', 'name' => 'ГЭСН', 'norm_type' => 'gesn'],
            'section' => ['code' => '01-01', 'name' => 'Земляные работы'],
            'score' => 90,
            'confidence' => 0.9,
            'match_reasons' => ['name'],
            'warnings' => [],
            'work_composition' => ['Разработка грунта'],
            'resources' => [
                'materials' => [[
                    'code' => '01.1.01.01-0001',
                    'name' => 'Песок',
                    'resource_type' => 'material',
                    'unit' => 'м3',
                    'quantity' => 1.0,
                    'unit_price' => 1000.0,
                    'total_price' => 1000.0,
                    'price_source' => 'fsbc_base',
                    'price_id' => 1,
                    'linked_resource_id' => null,
                ]],
                'labor' => [],
                'machinery' => [],
                'other' => [],
            ],
        ];
    }
}
