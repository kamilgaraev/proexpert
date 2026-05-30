<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Services\Quality\EstimateGenerationQualityGateService;
use Tests\TestCase;

class EstimateGenerationQualityGateServiceTest extends TestCase
{
    public function test_house_with_too_few_items_requires_review(): void
    {
        $report = app(EstimateGenerationQualityGateService::class)->evaluate([
            'object_profile' => [
                'object_type' => 'house',
                'area' => 150,
            ],
            'totals' => [
                'total_cost' => 15000000,
                'work_items_count' => 31,
            ],
            'local_estimates' => array_fill(0, 8, [
                'totals' => ['items_count' => 4, 'total_cost' => 1000000],
                'validation_flags' => [],
            ]),
        ]);

        $this->assertSame('review_required', $report->level);
        $this->assertContains('insufficient_detail', $report->criticalFlags);
    }

    public function test_absurd_total_blocks_generation(): void
    {
        $report = app(EstimateGenerationQualityGateService::class)->evaluate([
            'object_profile' => [
                'object_type' => 'house',
                'area' => 150,
            ],
            'totals' => [
                'total_cost' => 680000000,
                'work_items_count' => 320,
            ],
            'local_estimates' => [
                [
                    'scope_type' => 'roof',
                    'totals' => ['items_count' => 40, 'total_cost' => 390000000],
                    'validation_flags' => [],
                ],
            ],
        ]);

        $this->assertSame('blocked', $report->level);
        $this->assertContains('total_out_of_range', $report->criticalFlags);
        $this->assertContains('section_total_anomaly', $report->criticalFlags);
    }

    public function test_candidate_line_total_anomaly_blocks_generation(): void
    {
        $report = app(EstimateGenerationQualityGateService::class)->evaluate([
            'object_profile' => [
                'object_type' => 'house',
                'area' => 150,
            ],
            'totals' => [
                'total_cost' => 40000000,
                'work_items_count' => 320,
            ],
            'local_estimates' => [
                [
                    'scope_type' => 'engineering',
                    'totals' => ['items_count' => 120, 'total_cost' => 16000000],
                    'sections' => [
                        [
                            'totals' => ['items_count' => 60, 'total_cost' => 16000000],
                            'validation_flags' => [],
                            'work_items' => [
                                [
                                    'name' => 'Разводка труб отопления',
                                    'unit' => 'м2',
                                    'quantity' => 15,
                                    'total_cost' => 14500000,
                                    'normative_match' => [
                                        'status' => 'candidate',
                                        'can_use_for_pricing' => true,
                                        'warnings' => ['unit_mismatch'],
                                    ],
                                    'validation_flags' => [],
                                ],
                            ],
                        ],
                    ],
                    'validation_flags' => [],
                ],
                [
                    'scope_type' => 'roof',
                    'totals' => ['items_count' => 100, 'total_cost' => 12000000],
                    'validation_flags' => [],
                ],
                [
                    'scope_type' => 'finishing',
                    'totals' => ['items_count' => 100, 'total_cost' => 12000000],
                    'validation_flags' => [],
                ],
            ],
        ]);

        $this->assertSame('blocked', $report->level);
        $this->assertContains('line_total_anomaly', $report->criticalFlags);
    }
}
