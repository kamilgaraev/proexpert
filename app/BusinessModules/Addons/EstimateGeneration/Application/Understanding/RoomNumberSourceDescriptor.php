<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Understanding;

use App\BusinessModules\Addons\EstimateGeneration\Analysis\Arbitration\RoomLocationObservation;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Arbitration\RoomNumberObservation;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Entity;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Evidence;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact;

final readonly class RoomNumberSourceDescriptor
{
    /** @param array<string,Evidence> $evidenceById */
    public function describe(Fact $fact, Entity $entity, array $evidenceById): ?array
    {
        $number = RoomNumberObservation::label('room:source', ['type' => 'string', 'data' => $fact->value], $fact->unit);
        $floor = $this->location($entity->attributes['floor_id'] ?? null);
        $zone = $this->location($entity->attributes['zone_id'] ?? null);
        if ($fact->type !== 'room_number' || $fact->status !== 'confirmed' || $fact->origin !== 'document'
            || $entity->type !== 'room' || $number === null || $floor === null || $fact->evidenceIds === []
            || (isset($entity->attributes['zone_id']) && $zone === null)) {
            return null;
        }
        $role = $artifact = null;
        $numberProof = $floorProof = $zoneProof = false;
        $nativeContext = true;
        foreach ($fact->evidenceIds as $id) {
            $evidence = $evidenceById[$id] ?? null;
            if ($evidence === null || $evidence->sourceType !== 'document'
                || $evidence->sourceVersion !== $fact->sourceVersion
                || [$evidence->organizationId, $evidence->projectId, $evidence->sessionId]
                    !== [$fact->organizationId, $fact->projectId, $fact->sessionId]
                || preg_match('/\Adocument:[1-9][0-9]*\z/', $evidence->sourceArtifactId) !== 1
                || $evidence->sourceReference !== $evidence->sourceArtifactId
                || $evidence->literalFact === null
                || ! in_array($evidence->documentRole, ['plan', 'room_schedule', 'explication'], true)
                || ($role !== null && $role !== $evidence->documentRole)
                || ($artifact !== null && $artifact !== $evidence->sourceArtifactId)) {
                return null;
            }
            $expected = match ($evidence->literalFact->factKey) {
                'room_number' => $number,
                'room_floor' => $floor,
                'room_zone' => $zone,
                default => null,
            };
            if ($expected === null || ! hash_equals($evidence->literalFact->valueHash, hash('sha256', $expected))) {
                return null;
            }
            $numberProof = $numberProof || $evidence->literalFact->factKey === 'room_number';
            $floorProof = $floorProof || $evidence->literalFact->factKey === 'room_floor';
            $zoneProof = $zoneProof || $evidence->literalFact->factKey === 'room_zone';
            $nativeContext = $nativeContext && str_starts_with($evidence->nativeReference ?? '', 'xlsx:sheet:');
            $role = $evidence->documentRole;
            $artifact = $evidence->sourceArtifactId;
        }
        if (! $numberProof || (! $nativeContext && (! $floorProof || ($zone !== null && ! $zoneProof)))) {
            return null;
        }

        return ['strategy' => 'room_number', 'side' => $role === 'plan' ? 'left' : 'right',
            'match_key' => 'literal:'.hash('sha256', json_encode([$number, $floor, $zone], JSON_THROW_ON_ERROR))];
    }

    private function location(mixed $value): ?string
    {
        return RoomLocationObservation::identifier($value);
    }
}
