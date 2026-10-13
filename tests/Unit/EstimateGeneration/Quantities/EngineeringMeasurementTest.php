<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Quantities;

use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\FactVocabulary;
use PHPUnit\Framework\TestCase;

final class EngineeringMeasurementTest extends TestCase
{
    public function test_measurements_require_explicit_entity_parameter_and_dimension(): void
    {
        foreach ([
            ['equipment:pump-1', 'equipment_count', '2', 'шт', 'equipment_count', 'count'],
            ['water_pipe:w1', 'pipe_length', '12500', 'mm', 'water_pipe_length', 'm'],
            ['sewer_pipe:s1', 'pipe_length', '8.25', 'm', 'sewer_pipe_length', 'm'],
            ['technological_pipeline:p1', 'pipe_length', '23', 'm', 'process_pipe_length', 'm'],
            ['electrical_circuit:c1', 'cable_length', '90', 'м', 'cable_length', 'm'],
            ['ventilation_duct:v1', 'duct_length', '12', 'm', 'duct_length', 'm'],
        ] as [$entity, $parameter, $value, $unit, $formula, $canonicalUnit]) {
            $result = FactVocabulary::engineeringObservation($entity, $parameter, $value, $unit);
            self::assertSame($formula, $result['formula']);
            self::assertSame($canonicalUnit, $result['unit']);
        }

        foreach ([
            ['equipment:pump-1', 'equipment_count', '1.5', 'count'],
            ['equipment:pump-1', 'equipment_count', '2', 'm'],
            ['equipment:pump-1', 'cable_length', '2', 'm'],
            ['room:r1', 'cable_length', '2', 'm'],
            ['electrical_circuit:c1', 'cable_length', '2', 'm2'],
            ['electrical_circuit:c1', 'length', '2', 'm'],
            ['water_pipe:', 'pipe_length', '2', 'm'],
            ['water_pipe:w1', 'pipe_length', '-2', 'm'],
        ] as [$entity, $parameter, $value, $unit]) {
            self::assertNull(FactVocabulary::engineeringObservation($entity, $parameter, $value, $unit));
        }
    }
}
