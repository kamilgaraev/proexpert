<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Analysis;

use App\BusinessModules\Addons\EstimateGeneration\Analysis\Arbitration\ObservationClaim;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\AcceptedDocumentFactProjector;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AcceptedDocumentFactProjectionTest extends TestCase
{
    public function test_room_dimensions_preserve_parameter_value_and_unit_without_becoming_area(): void
    {
        foreach (['length', 'width', 'height'] as $parameter) {
            $result = $this->project($this->claim($parameter, ['type' => 'number', 'data' => '2920'], 'mm', 'room.bedroom-1'));
            self::assertNotNull($result);
            self::assertSame('dimension', $result['fact_type']);
            self::assertSame($parameter, $result['label_key']);
            self::assertSame('2920', $result['value_number']);
            self::assertSame('mm', $result['unit']);
            self::assertNull($this->project($this->claim($parameter, ['type' => 'number', 'data' => '2920'], null, 'room.bedroom-1')));
            self::assertNull($this->project($this->claim($parameter, ['type' => 'number', 'data' => '2920'], 'm2', 'room.bedroom-1')));
            self::assertNull($this->project($this->claim($parameter, ['type' => 'number', 'data' => '2920'], 'mm', 'plan.overall')));
        }
    }

    public function test_room_number_has_a_text_label_in_compatible_document_storage_without_quantity(): void
    {
        $projection = $this->project($this->claim('room_number', ['type' => 'string', 'data' => '001'], null, 'room.office'));
        self::assertNotNull($projection);
        self::assertSame('note', $projection['fact_type']);
        self::assertSame('room_number', $projection['label_key']);
        self::assertSame('001', $projection['value_text']);
        self::assertNull($projection['value_number']);
        self::assertNull($projection['unit']);
        self::assertNull($this->project($this->claim('room_number', ['type' => 'string', 'data' => '001'], 'm2', 'room.office')));
    }

    public function test_engineering_measurement_retains_parameter_and_value_without_new_legacy_types(): void
    {
        $claim = $this->claim('equipment_count', ['type' => 'number', 'data' => '3'], 'count', 'equipment.p1');
        $result = $this->project($claim);
        self::assertSame('dimension', $result['fact_type']);
        self::assertSame('equipment_count', $result['label_key']);
        self::assertSame('3', $result['value_number']);
        self::assertSame('count', $result['unit']);
        self::assertNull($this->project($this->claim('equipment_count', ['type' => 'number', 'data' => '1.5'], 'count', 'equipment.p1')));
    }

    public function test_typed_document_requirement_uses_compatible_storage_without_inventing_a_quantity(): void
    {
        $claim = $this->claim('equipment_specification', ['type' => 'string', 'data' => 'Спецификация насоса P1: марка и комплектация.'], null, 'equipment.p1');
        $projected = $this->project($claim);
        self::assertSame('work_scope', $projected['fact_type']);
        self::assertSame('equipment_specification', $projected['label_key']);
        self::assertNull($projected['value_number']);
        self::assertNull($projected['unit']);
        self::assertSame($claim->value['data'], $projected['value_text']);
        self::assertNull($this->project($this->claim('equipment_specification', ['type' => 'boolean', 'data' => true], null, 'equipment.p1')));
    }

    #[Test]
    public function signed_and_numeric_zero_levels_use_the_existing_height_storage_contract(): void
    {
        $signed = $this->project($this->claim('level', ['type' => 'string', 'data' => '±0,000']));
        self::assertSame('height', $signed['fact_type']);
        self::assertSame('elevation', $signed['label_key']);
        self::assertSame('m', $signed['unit']);
        self::assertSame('height', $this->project($this->claim('level', ['type' => 'number', 'data' => '0'], 'm'))['fact_type']);
    }

    #[Test]
    public function only_positive_integer_unitless_floor_count_is_published(): void
    {
        self::assertSame('floor_count', $this->project($this->claim('floor_count', ['type' => 'number', 'data' => '1']))['fact_type']);
        self::assertSame('floor_count', $this->project($this->claim('floor_count', ['type' => 'number', 'data' => 2]))['fact_type']);
        self::assertNull($this->project($this->claim('floor_count', ['type' => 'number', 'data' => '0'])));
        self::assertNull($this->project($this->claim('floor_count', ['type' => 'number', 'data' => '1'], 'm')));
        self::assertNull($this->project($this->claim('floor_count', ['type' => 'number', 'data' => '1.5'])));
    }

    #[Test]
    public function room_area_recognizes_production_entity_separators(): void
    {
        self::assertSame('room_area', $this->project($this->claim('area', ['type' => 'number', 'data' => '25.97'], 'm2', 'room.kitchen_living'))['fact_type']);
        self::assertSame('room_area', $this->project($this->claim('area', ['type' => 'number', 'data' => '25.97'], 'm2', 'room_kitchen_living'))['fact_type']);
    }

    private function project(ObservationClaim $claim): ?array
    {
        return (new AcceptedDocumentFactProjector)->project($claim);
    }

    private function claim(string $factType, array $value, ?string $unit = null, string $entityKey = 'building.level_1'): ObservationClaim
    {
        return new ObservationClaim(
            'literal:1',
            'observer_literal',
            $entityKey,
            $factType,
            $value,
            $unit,
            'literal:evidence',
            true,
            1,
            2,
            3,
            'sha256:'.str_repeat('a', 64),
            ['page_number' => 5, 'source_version' => 'sha256:'.str_repeat('a', 64)],
            0.95,
        );
    }
}
