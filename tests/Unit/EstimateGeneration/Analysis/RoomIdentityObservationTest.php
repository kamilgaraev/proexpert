<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Analysis;

use App\BusinessModules\Addons\EstimateGeneration\Analysis\Arbitration\ObservationClaim;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Arbitration\RoomIdentityObservation;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceSchema;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceType;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RoomIdentityObservationTest extends TestCase
{
    #[Test]
    public function name_has_typed_fingerprint_without_any_measurement(): void
    {
        $policy = new RoomIdentityObservation;
        $claim = $this->claim('  Кухня  ');
        self::assertSame('Кухня', $policy->name($claim));
        self::assertSame(hash('sha256', 'Кухня'), $policy->fingerprint($claim));
        self::assertSame(['fact_key' => 'room_name', 'fact_value' => hash('sha256', 'Кухня')],
            EvidenceSchema::value(EvidenceType::SourceFact, ['fact_key' => 'room_name', 'fact_value' => $policy->fingerprint($claim)]));
    }

    #[Test]
    public function numerical_quantities_wrong_entity_and_truncated_names_are_not_room_identity(): void
    {
        $policy = new RoomIdentityObservation;
        foreach ([$this->claim('12', unit: 'm2'), $this->claim('12', valueType: 'number'),
            $this->claim('Кухня', entity: 'wall.1'), $this->claim(str_repeat('а', 241)), $this->claim("Кухня\nкомната")] as $claim) {
            self::assertNull($policy->name($claim));
            self::assertNull($policy->fingerprint($claim));
        }
    }

    #[Test]
    public function room_name_evidence_rejects_a_material_hash_or_numeric_unit(): void
    {
        foreach ([['fact_key' => 'room_name', 'fact_value' => 'material:'.str_repeat('a', 64)],
            ['fact_key' => 'room_name', 'fact_value' => str_repeat('a', 64), 'unit' => 'm2']] as $value) {
            try {
                EvidenceSchema::value(EvidenceType::SourceFact, $value);
                self::fail('Invalid room identity evidence was admitted.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    private function claim(string $name, ?string $unit = null, string $valueType = 'string', string $entity = 'room.photo.1'): ObservationClaim
    {
        return new ObservationClaim('literal:room', 'observer_literal', $entity, 'room_name',
            ['type' => $valueType, 'data' => $name], $unit, 'literal:room', true, 1, 2, 3,
            'sha256:'.str_repeat('a', 64), ['page' => 1, 'unit_type' => 'raster_image']);
    }
}
