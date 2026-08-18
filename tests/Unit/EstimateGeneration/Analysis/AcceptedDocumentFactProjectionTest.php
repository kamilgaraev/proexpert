<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Analysis;

use App\BusinessModules\Addons\EstimateGeneration\Analysis\Arbitration\ObservationClaim;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\AcceptedDocumentFactProjector;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AcceptedDocumentFactProjectionTest extends TestCase
{
    #[Test]
    public function signed_and_numeric_zero_levels_are_elevations_not_floor_counts(): void
    {
        $signed = $this->project($this->claim('level', ['type' => 'string', 'data' => '±0,000']));
        self::assertSame('elevation', $signed['fact_type']);
        self::assertSame('m', $signed['unit']);
        self::assertSame('elevation', $this->project($this->claim('level', ['type' => 'number', 'data' => '0'], 'm'))['fact_type']);
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
