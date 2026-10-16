<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Understanding;

use App\BusinessModules\Addons\EstimateGeneration\Application\Understanding\CrossDocumentFactLinker;
use App\BusinessModules\Addons\EstimateGeneration\Application\Understanding\TargetedConflictResolver;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Entity;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Evidence;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\FactSourceLocator;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\FactVocabulary;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\LiteralFactEvidence;
use PHPUnit\Framework\TestCase;

final class NativeRoomFactSourceDescriptorTest extends TestCase
{
    public function test_native_numeric_conflict_uses_literal_room_identity_without_rewriting_the_entities(): void
    {
        $input = $this->sources();
        $result = $this->linker()->link(...$input);
        self::assertCount(1, $result->links);
        self::assertCount(1, $result->conflicts);
        self::assertSame(['area:1', 'area:2'], array_column($result->conflicts[0]->facts, 'id'));
        self::assertSame(['evidence:area:1', 'evidence:area:2'], $result->conflicts[0]->evidenceIds);
        self::assertSame([], $input[0][0]->attributes);
        self::assertSame(0, $result->providerCalls);
        self::assertSame($result->conflicts[0]->id, $this->linker()->link(...array_map(array_reverse(...), $input))->conflicts[0]->id);
    }

    public function test_numeric_evidence_cannot_borrow_another_rooms_document_floor_role_or_confirmation(): void
    {
        foreach (['floor', 'artifact', 'role', 'candidate', 'proof', 'number', 'missing_floor'] as $variant) {
            $result = $this->linker()->link(...$this->sources($variant));
            self::assertSame([], $result->conflicts, $variant);
            self::assertSame(0, $result->providerCalls, $variant);
        }
    }

    private function sources(?string $variant = null): array
    {
        $entities = $facts = $evidence = [];
        foreach ([1 => 'plan', 2 => 'room_schedule'] as $index => $role) {
            $version = 'sha256:'.str_repeat((string) $index, 64);
            $entity = 'room:'.$index;
            $artifact = 'document:'.$index;
            $entities[] = new Entity($entity, 1, 2, 3, $version, 'room', $entity);
            $number = $index === 2 && $variant === 'number' ? '002' : '001';
            $floor = $index === 2 && $variant === 'floor' ? '2' : '1';
            $numberProofs = ['evidence:number:'.$index];
            foreach (['number' => $number, 'floor' => $floor] as $type => $value) {
                if ($index === 2 && $variant === 'missing_floor' && $type === 'floor') {
                    continue;
                }
                $id = 'evidence:'.$type.':'.$index;
                $evidence[] = new Evidence($id, 1, 2, 3, $version, $artifact, 'document', 1,
                    sourceReference: $artifact, documentRole: $role,
                    literalFact: new LiteralFactEvidence('room_'.$type, hash('sha256', $value)),
                    factSource: new FactSourceLocator(['kind' => 'native', 'coordinate_space' => 'native', 'value' => 'xlsx:sheet:Rooms!'.$type]));
                if ($type === 'floor') {
                    $numberProofs[] = $id;
                    $facts[] = new Fact('floor:'.$index, 1, 2, 3, $version, $entity, 'room_floor', $value, null, 0, 'document', 'confirmed', [$id]);
                }
            }
            $facts[] = new Fact('number:'.$index, 1, 2, 3, $version, $entity, 'room_number', $number, null, 0, 'document', 'confirmed', $numberProofs);
            $id = 'evidence:area:'.$index;
            $fingerprint = hash('sha256', $id);
            $area = $index === 1 ? '50' : '60';
            $evidence[] = new Evidence($id, 1, 2, 3, $version, $index === 2 && $variant === 'artifact' ? 'document:99' : $artifact,
                'document', 1, nativeReference: 'xlsx:sheet:Rooms!C2', producerName: 'native_numeric_parser', sourceReference: $artifact,
                fingerprint: $fingerprint, documentRole: $index === 2 && $variant === 'role' ? 'plan' : $role);
            $proof = ['entity_id' => $entity, 'source_version' => $version, 'parameter' => 'area',
                'evidence_fingerprint' => $fingerprint, 'measurement' => FactVocabulary::measurementSignature($area, 'm2')];
            $facts[] = new Fact('area:'.$index, 1, 2, 3, $version, $entity, 'area', $area, 'm2', 0, 'document',
                $index === 2 && $variant === 'candidate' ? 'candidate' : 'confirmed', [$id],
                nativeNumericProofs: $index === 2 && $variant === 'proof' ? [] : [$id => $proof]);
        }

        return [$entities, $facts, $evidence];
    }

    private function linker(): CrossDocumentFactLinker
    {
        return new CrossDocumentFactLinker(new TargetedConflictResolver(static fn (string $key, array $replace): string => $key));
    }
}
