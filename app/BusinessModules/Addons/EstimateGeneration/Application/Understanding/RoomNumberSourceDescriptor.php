<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Understanding;

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
        foreach ($fact->evidenceIds as $id) {
            $evidence = $evidenceById[$id] ?? null;
            if ($evidence === null || $evidence->sourceType !== 'document'
                || $evidence->sourceVersion !== $fact->sourceVersion
                || [$evidence->organizationId, $evidence->projectId, $evidence->sessionId]
                    !== [$fact->organizationId, $fact->projectId, $fact->sessionId]
                || preg_match('/\Adocument:[1-9][0-9]*\z/', $evidence->sourceArtifactId) !== 1
                || $evidence->sourceReference !== $evidence->sourceArtifactId
                || $evidence->literalFact?->factKey !== 'room_number'
                || ! hash_equals($evidence->literalFact->valueHash, hash('sha256', $number))
                || ! in_array($evidence->documentRole, ['plan', 'room_schedule', 'explication'], true)
                || ($role !== null && $role !== $evidence->documentRole)
                || ($artifact !== null && $artifact !== $evidence->sourceArtifactId)) {
                return null;
            }
            $role = $evidence->documentRole;
            $artifact = $evidence->sourceArtifactId;
        }

        return ['strategy' => 'room_number', 'side' => $role === 'plan' ? 'left' : 'right',
            'match_key' => 'literal:'.hash('sha256', json_encode([$number, $floor, $zone], JSON_THROW_ON_ERROR))];
    }

    private function location(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' && mb_strlen($value) <= 120 ? trim($value) : null;
    }
}
