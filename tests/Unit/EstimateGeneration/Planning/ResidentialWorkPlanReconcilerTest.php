<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Planning;

use App\BusinessModules\Addons\EstimateGeneration\Planning\AiWorkCompositionAdviceData;
use App\BusinessModules\Addons\EstimateGeneration\Planning\ResidentialWorkPlanReconciler;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ResidentialWorkPlanReconcilerTest extends TestCase
{
    #[Test]
    public function ai_cannot_remove_a_required_work_item(): void
    {
        $plan = [
            'generation_mode' => 'ai_assisted',
            'object_profile' => ['object_type' => 'house', 'floors' => 2],
            'package_plan' => ['packages' => []],
            'local_estimates' => [[
                'key' => 'heating',
                'sections' => [['work_items' => [[
                    'name' => 'Монтаж радиаторов',
                    'metadata' => ['quantity_key' => 'heating.radiators'],
                ]]]],
            ]],
        ];

        $result = (new ResidentialWorkPlanReconciler)->reconcile($plan, new AiWorkCompositionAdviceData(
            'completed',
            ['heating.radiators' => [
                'status' => 'not_applicable',
                'reason_codes' => ['model_claimed_not_applicable'],
                'confidence' => 0.7,
            ]],
            'test-model',
        ));

        $item = $result['local_estimates'][0]['sections'][0]['work_items'][0];
        self::assertSame('Монтаж радиаторов', $item['name']);
        self::assertTrue($item['metadata']['composition_coverage']['required']);
        self::assertSame('not_applicable', $item['metadata']['composition_coverage']['ai_status']);
        self::assertSame('ai_bounded_catalog', $item['metadata']['composition_coverage']['source']);
    }
}
