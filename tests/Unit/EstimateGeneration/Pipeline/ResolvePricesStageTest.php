<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Pipeline;

use App\BusinessModules\Addons\EstimateGeneration\Pipeline\InMemoryPipelineArtifactStore;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineContext;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineDefinitionGraph;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\Stages\ResolvePricesStage;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\Stages\StageResultFactory;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimatePricingService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class ResolvePricesStageTest extends TestCase
{
    #[Test]
    public function pricing_summary_identifies_blocked_norm_and_abstract_resources(): void
    {
        $stage = new ResolvePricesStage(
            new EstimatePricingService,
            new StageResultFactory(new InMemoryPipelineArtifactStore, PipelineDefinitionGraph::standard()),
        );
        $context = new PipelineContext(58, 1, 89, 0, 'source:v1', 'generating');
        $method = new ReflectionMethod($stage, 'pricingSummary');
        $summary = $method->invoke($stage, $context, [[
            'sections' => [[
                'work_items' => [[
                    'key' => 'roof.gutter',
                    'name' => 'Монтаж водосточной системы',
                    'unit' => 'm',
                    'quantity' => '27',
                    'pricing_status' => 'not_calculated',
                    'pricing_blocker' => 'project_resource_selection_required',
                    'normative_match' => [
                        'code' => '12-01-035-03',
                        'name' => 'Устройство металлической водосточной системы: прямых звеньев труб',
                        'unpriced_abstract_resources' => [[
                            'resource_code' => '01.7.15.02',
                            'name' => 'Крепежные изделия по проекту',
                            'unit' => 'кг',
                            'quantity' => 1.25,
                            'reason' => 'project_resource_selection_required',
                        ]],
                    ],
                ]],
            ]],
        ]]);

        self::assertSame([[
            'work_key' => 'roof.gutter',
            'work_name' => 'Монтаж водосточной системы',
            'quantity' => '27',
            'unit' => 'm',
            'blocker' => 'project_resource_selection_required',
            'norm_code' => '12-01-035-03',
            'norm_name' => 'Устройство металлической водосточной системы: прямых звеньев труб',
            'unpriced_abstract_resources' => [[
                'resource_code' => '01.7.15.02',
                'name' => 'Крепежные изделия по проекту',
                'unit' => 'кг',
                'quantity' => 1.25,
            ]],
        ]], $summary['pricing_blocker_details']);
    }
}
