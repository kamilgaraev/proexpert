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
    public function describe(Fact $fact, Entity $entity, array $evidenceById, array $facts = []): ?array
    {
        $number = RoomNumberObservation::label('room:source', ['type' => 'string', 'data' => $fact->value], $fact->unit);
        $supportingFacts = ! array_key_exists('floor_id', $entity->attributes);
        $location = $supportingFacts ? $this->supportingLocation($fact, $facts, $evidenceById) : $entity->attributes;
        if ($location === null) {
            return null;
        }
        $floor = $this->location($location['floor_id'] ?? null);
        $zone = $this->location($location['zone_id'] ?? null);
        if ($fact->type !== 'room_number' || $fact->status !== 'confirmed' || $fact->origin !== 'document'
            || $entity->type !== 'room' || $number === null || $floor === null || $fact->evidenceIds === []
            || (isset($location['zone_id']) && $zone === null)) {
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
            if (in_array($evidence->literalFact->factKey, ['room_floor', 'room_zone'], true) && $evidence->factSource === null) {
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

    /** Resolve literal values only through the exact supporting proof of the current number revision. */
    private function supportingLocation(Fact $number, array $facts, array $evidence): ?array
    {
        $values = [];
        foreach ($facts as $fact) {
            if (! $fact instanceof Fact || ! in_array($fact->type, ['room_floor', 'room_zone'], true)
                || $fact->status !== 'confirmed' || $fact->origin !== 'document' || $fact->unit !== null
                || [$fact->organizationId, $fact->projectId, $fact->sessionId, $fact->sourceVersion]
                    !== [$number->organizationId, $number->projectId, $number->sessionId, $number->sourceVersion]) {
                continue;
            }
            $literal = $this->location($fact->value);
            foreach (array_intersect($number->evidenceIds, $fact->evidenceIds) as $id) {
                $proof = $evidence[$id] ?? null;
                if ($literal !== null && $proof?->literalFact?->factKey === $fact->type
                    && hash_equals($proof->literalFact->valueHash, hash('sha256', $literal))) {
                    $values[$fact->type][$literal] = true;
                }
            }
        }
        foreach ($values as $candidates) {
            if (count($candidates) !== 1) {
                return null;
            }
        }

        return ['floor_id' => isset($values['room_floor']) ? (string) array_key_first($values['room_floor']) : null,
            'zone_id' => isset($values['room_zone']) ? (string) array_key_first($values['room_zone']) : null];
    }

    private function location(mixed $value): ?string
    {
        return RoomLocationObservation::identifier($value);
    }
}
