<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Analysis;

use App\BusinessModules\Addons\EstimateGeneration\Analysis\Arbitration\ArbitrationDecision;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Arbitration\ObservationClaim;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Arbitration\RoomIdentityObservation;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Arbitration\RoomNumberObservation;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\ProjectModelEvidenceWriter;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceSchema;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceType;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\InMemoryEvidenceRepository;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Tests\Support\EstimateGeneration\InMemoryProjectModelRepository;

final class RoomNumberObservationTest extends TestCase
{
    public function test_explicit_room_number_is_a_literal_and_never_a_name_or_measurement(): void
    {
        foreach (['001', '0', 'А-12', '101/1', 'B.01', 'А', '№ 001'] as $number) {
            $claim = $this->claim(' '.$number.' ');
            $observation = new RoomNumberObservation;
            self::assertSame($number, $observation->number($claim));
            self::assertSame(hash('sha256', $number), $observation->fingerprint($claim));
            self::assertNull((new RoomIdentityObservation)->name($claim));
            self::assertSame(['fact_key' => 'room_number', 'fact_value' => hash('sha256', $number)],
                EvidenceSchema::value(EvidenceType::SourceFact, ['fact_key' => 'room_number', 'fact_value' => $observation->fingerprint($claim)]));
        }
    }

    public function test_invalid_numbers_units_entities_and_numeric_values_are_rejected(): void
    {
        $observation = new RoomNumberObservation;
        foreach ([$this->claim('001', unit: 'm2'), $this->claim('001', type: 'number'),
            $this->claim('001', entity: 'wall.1'), $this->claim('Офис'), $this->claim(''),
            $this->claim(str_repeat('1', 65)), $this->claim("001\n2"), $this->claim('room:001'),
            $this->claim("\xFF")] as $claim) {
            self::assertNull($observation->number($claim));
            self::assertNull($observation->fingerprint($claim));
        }
        $this->expectException(InvalidArgumentException::class);
        EvidenceSchema::value(EvidenceType::SourceFact, ['fact_key' => 'room_number', 'fact_value' => str_repeat('a', 64), 'unit' => 'm2']);
    }

    public function test_a_number_cannot_be_confirmed_with_missing_foreign_or_implicit_source_locators(): void
    {
        $original = $this->claim('001');
        foreach ([['document_id' => 13, 'page' => 1, 'explicit' => true, 'status' => 'confirmed'],
            ['document_id' => 14, 'page' => 1], ['document_id' => 13, 'page' => 2],
            ['document_id' => 13, 'page' => 1, 'unit_index' => 2],
            ['page' => 1], ['document_id' => 13, 'page' => 1, 'explicit' => false]] as $input) {
            $explicit = $input['explicit'] ?? true;
            $status = $input['status'] ?? 'unresolved';
            unset($input['explicit'], $input['status']);
            $claim = new ObservationClaim(...[...get_object_vars($original), 'locator' => $input, 'explicitEvidence' => $explicit]);
            $models = new InMemoryProjectModelRepository;
            $decision = new ArbitrationDecision($claim->id, 'accepted', [$claim->id], [$claim->evidenceRef], 'Literal identifier',
                ['entity_key' => $claim->entityKey, 'fact_type' => $claim->factType, 'value' => $claim->value, 'unit' => null, 'source_claim_id' => $claim->id]);
            (new ProjectModelEvidenceWriter($models, new InMemoryEvidenceRepository))->writeArbitration([$claim], [$decision], 13, 1);
            self::assertSame($status, array_values($models->facts)[0]->status);
            self::assertCount($status === 'confirmed' ? 1 : 0, array_values($models->facts)[0]->evidenceIds);
        }
    }

    private function claim(string $number, ?string $unit = null, string $type = 'string', string $entity = 'room.office'): ObservationClaim
    {
        return new ObservationClaim('literal:number', 'observer_literal', $entity, 'room_number',
            ['type' => $type, 'data' => $number], $unit, 'literal:number', true, 1, 2, 3,
            'sha256:'.str_repeat('a', 64), ['page' => 1, 'floor_id' => '1']);
    }
}
