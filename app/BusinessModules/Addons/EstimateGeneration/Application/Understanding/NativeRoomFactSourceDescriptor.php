<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Understanding;

use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\NativeNumericFactProof;

final readonly class NativeRoomFactSourceDescriptor
{
    public function index(array $entities, array $facts, array $evidence): array
    {
        $candidates = [];
        foreach ($facts as $fact) {
            if ($fact->type !== 'room_number') {
                continue;
            }
            $key = $fact->entityId.'|'.$fact->sourceVersion;
            $descriptor = (new RoomNumberSourceDescriptor)->describe($fact, $entities[$key], $evidence, $facts);
            if ($descriptor === null) {
                continue;
            }
            foreach ($fact->evidenceIds as $id) {
                if (! str_starts_with($evidence[$id]->nativeReference ?? '', 'xlsx:sheet:')) {
                    continue 2;
                }
            }
            $source = $evidence[$fact->evidenceIds[0]];
            $entry = ['descriptor' => $descriptor, 'artifact' => $source->sourceArtifactId, 'role' => $source->documentRole];
            $candidates[$key][hash('sha256', json_encode($entry, JSON_THROW_ON_ERROR))] = $entry;
        }
        $index = [];
        foreach ($candidates as $key => $entries) {
            if (count($entries) === 1) {
                $index[$key] = array_values($entries)[0];
            }
        }

        return $index;
    }

    public function describe(Fact $fact, array $index, array $evidence): ?array
    {
        $identity = $index[$fact->entityId.'|'.$fact->sourceVersion] ?? null;
        if ($identity === null || $fact->origin !== 'document' || $fact->evidenceIds === []) {
            return null;
        }
        foreach ($fact->evidenceIds as $id) {
            $proof = $evidence[$id] ?? null;
            if ($proof === null || $proof->sourceArtifactId !== $identity['artifact']
                || $proof->documentRole !== $identity['role'] || $proof->sourceReference !== $identity['artifact']
                || ! str_starts_with($proof->nativeReference ?? '', 'xlsx:sheet:')
                || ! (new NativeNumericFactProof)->matches($fact, $proof)) {
                return null;
            }
        }

        return $identity['descriptor'];
    }
}
