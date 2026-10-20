<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Application;

use App\BusinessModules\Addons\EstimateGeneration\Application\Factory\FactoryWorkingInput;
use App\BusinessModules\Addons\EstimateGeneration\Services\ConstructionSemanticParser;
use PHPUnit\Framework\TestCase;

final class FactoryWorkingInputTest extends TestCase
{
    public function test_malformed_history_cannot_hide_oversized_input_or_break_safe_diagnostics(): void
    {
        $payload = ['manual_fact_receipts' => str_repeat('x', 1048577), 'feedback_receipts' => null];
        self::assertSame($payload, FactoryWorkingInput::fromPayload($payload));
        $metrics = FactoryWorkingInput::diagnostics($payload);
        self::assertSame($metrics['input_bytes'], $metrics['working_input_bytes']);
        self::assertGreaterThan(1048576, $metrics['working_input_bytes']);
        self::assertSame(0, $metrics['history_bytes']);
        self::assertSame([], $metrics['history_components']);
    }

    public function test_current_object_dimensions_selections_workflow_finances_and_unknown_fields_remain_semantically_identical(): void
    {
        $payload = [
            'description' => 'Одноэтажный жилой дом', 'object_type' => 'house',
            'building_type' => 'residential', 'dimensions' => ['length' => 6.4, 'width' => 6.25],
            'floors' => 1, 'area' => 40, 'height' => 2.4, 'region' => 'Тестовый регион',
            'construction_type' => 'new_construction', 'selected_sections' => ['structures', 'equipment'],
            'evaluation_mode' => 'universal', 'price_policy' => 'mixed',
            'planning_review' => ['source' => 'current'], 'input_confirmed' => true,
            'confirmed_evaluation' => ['revision_id' => 'current'],
            'qa_run_id' => '5ac504c5-5ca3-41dc-95b0-f3c8745ccc44', 'qa_limit_units' => 500,
            'credit_operation_id' => 'retained-operation', 'financial_snapshot' => ['maximum_cost' => '90'],
            'future_input' => ['value' => 'retained'], 'future_receipts' => ['value' => 'retained'],
            'manual_fact_receipts' => ['historic' => ['result' => ['description' => 'Промышленный объект']]],
            'prerequisite_receipts' => ['historic' => ['result' => ['questions' => ['old']]]],
        ];
        $before = $payload;
        $working = FactoryWorkingInput::fromPayload($payload);
        $parser = new ConstructionSemanticParser;
        self::assertSame($parser->parse($payload, []), $parser->parse($working, []));
        self::assertSame($payload['object_type'], $working['object_type']);
        self::assertSame($payload['dimensions'], $working['dimensions']);
        foreach (['planning_review', 'input_confirmed', 'confirmed_evaluation', 'qa_run_id', 'qa_limit_units',
            'credit_operation_id', 'financial_snapshot', 'future_input', 'future_receipts', 'selected_sections', 'price_policy'] as $key) {
            self::assertSame($payload[$key], $working[$key]);
        }
        self::assertArrayNotHasKey('manual_fact_receipts', $working);
        self::assertArrayNotHasKey('prerequisite_receipts', $working);
        self::assertSame($before, $payload);
    }
}
