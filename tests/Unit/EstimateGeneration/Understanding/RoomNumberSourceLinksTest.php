<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Understanding;

use App\BusinessModules\Addons\EstimateGeneration\Application\Understanding\CrossDocumentFactLinker;
use App\BusinessModules\Addons\EstimateGeneration\Application\Understanding\TargetedConflictResolver;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Entity;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Evidence;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\FactSourceLocator;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\LiteralFactEvidence;
use PHPUnit\Framework\TestCase;

final class RoomNumberSourceLinksTest extends TestCase
{
    public function test_literal_numbers_link_with_actual_evidence_without_synthetic_entity_attributes(): void
    {
        foreach (['room_schedule', 'explication'] as $rightRole) {
            $input = $this->pair(rightRole: $rightRole);
            $result = $this->linker()->link(...$input);
            self::assertCount(1, $result->links);
            self::assertSame('linked', $result->links[0]['status']);
            self::assertSame('room_number', $result->links[0]['strategy']);
            self::assertSame(['left' => ['evidence:1', 'evidence:floor:1', 'evidence:zone:1'], 'right' => ['evidence:2', 'evidence:floor:2', 'evidence:zone:2']], $result->links[0]['evidence']);
            self::assertSame(0, $result->providerCalls);
            self::assertSame($result->links, $this->linker()->link(...array_map(array_reverse(...), $input))->links);
        }
    }

    public function test_incomplete_unrelated_or_mismatched_sources_do_not_link(): void
    {
        foreach (['hash', 'key', 'version', 'role', 'artifact', 'source_ref', 'floor', 'missing_floor', 'zone', 'number', 'candidate', 'unit', 'missing_proof', 'missing_floor_proof', 'missing_zone_proof', 'wrong_floor_proof', 'unknown_floor', 'unknown_zone'] as $variant) {
            $input = $this->pair(variant: $variant);
            $result = $this->linker()->link(...$input);
            self::assertSame([], $result->links, $variant);
            self::assertSame(0, $result->providerCalls, $variant);
        }
    }

    public function test_source_fact_value_parser_does_not_treat_generic_or_malformed_hashes_as_literal_proof(): void
    {
        $proof = LiteralFactEvidence::fromSourceFactValue(['fact_key' => 'room_number', 'fact_value' => hash('sha256', '001')]);
        self::assertSame(hash('sha256', '001'), $proof?->valueHash);
        foreach ([['fact_key' => 'area', 'fact_value' => hash('sha256', '001')],
            ['fact_key' => 'room_number', 'fact_value' => '001'], ['fact_key' => 'room_number', 'fact_value' => 1], []] as $value) {
            self::assertNull(LiteralFactEvidence::fromSourceFactValue($value));
        }
    }

    public function test_new_evidence_binding_has_a_new_operation_identity(): void
    {
        $input = $this->pair();
        $original = $this->linker()->link(...$input)->links[0];
        $version = 'sha256:'.str_repeat('1', 64);
        $input[1][0] = new Fact('fact:1', 1, 2, 3, $version, 'entity:1', 'room_number', '001', null,
            0.0, 'document', 'confirmed', ['evidence:3', 'evidence:floor:1', 'evidence:zone:1']);
        $input[2][0] = new Evidence('evidence:3', 1, 2, 3, $version, 'document:1', 'document', 2,
            sourceReference: 'document:1', documentRole: 'plan', literalFact: new LiteralFactEvidence('room_number', hash('sha256', '001')));
        $rebound = $this->linker()->link(...$input)->links[0];
        self::assertNotSame($original['operation_identity'], $rebound['operation_identity']);
        self::assertSame(['evidence:3', 'evidence:floor:1', 'evidence:zone:1'], $rebound['evidence']['left']);
    }

    private function pair(string $rightRole = 'explication', ?string $variant = null): array
    {
        $entities = $facts = $evidence = [];
        foreach ([1 => 'plan', 2 => $rightRole] as $index => $role) {
            $version = 'sha256:'.str_repeat((string) $index, 64);
            $number = $index === 2 && $variant === 'number' ? '1' : '001';
            $location = ['semantic_type' => 'room', 'floor_id' => $index === 2 && $variant === 'floor' ? '2' : '1',
                'zone_id' => $index === 2 && $variant === 'zone' ? 'B' : 'A'];
            if ($variant === 'unknown_floor') {
                $location['floor_id'] = 'unknown';
            }
            if ($variant === 'unknown_zone') {
                $location['zone_id'] = 'не указана';
            }
            if ($variant === 'missing_floor') {
                unset($location['floor_id']);
            }
            $entities[] = new Entity('entity:'.$index, 1, 2, 3, $version, 'room', 'entity:'.$index, $location);
            $facts[] = new Fact('fact:'.$index, 1, 2, 3, $version, 'entity:'.$index, 'room_number', $number,
                $variant === 'unit' && $index === 2 ? 'm2' : null, 0.0, 'document',
                $variant === 'candidate' && $index === 2 ? 'candidate' : 'confirmed', ['evidence:'.$index]);
            $proof = $variant === 'missing_proof' && $index === 2 ? null : new LiteralFactEvidence(
                $variant === 'key' && $index === 2 ? 'room_name' : 'room_number',
                hash('sha256', $variant === 'hash' && $index === 2 ? '002' : $number));
            $evidence[] = new Evidence('evidence:'.$index, 1, 2, 3,
                $variant === 'version' && $index === 2 ? 'sha256:'.str_repeat('f', 64) : $version,
                'document:'.($variant === 'artifact' ? 1 : $index), 'document', 1,
                sourceReference: 'document:'.($variant === 'source_ref' && $index === 2 ? 99 : ($variant === 'artifact' ? 1 : $index)),
                documentRole: $variant === 'role' && $index === 2 ? null : $role, literalFact: $proof);
            foreach (['floor' => 'floor_id', 'zone' => 'zone_id'] as $type => $attribute) {
                if (! isset($location[$attribute]) || ($index === 2 && $variant === 'missing_'.$type.'_proof')) {
                    continue;
                }
                $id = 'evidence:'.$type.':'.$index;
                $last = array_key_last($facts);
                $old = $facts[$last];
                $facts[$last] = new Fact($old->id, 1, 2, 3, $version, $old->entityId, $old->type, $old->value,
                    $old->unit, 0.0, $old->origin, $old->status, [...$old->evidenceIds, $id]);
                $evidence[] = new Evidence($id, 1, 2, 3, $version, 'document:'.$index, 'document', 1,
                    sourceReference: 'document:'.$index, documentRole: $role,
                    literalFact: new LiteralFactEvidence('room_'.$type,
                        hash('sha256', $index === 2 && $variant === 'wrong_floor_proof' && $type === 'floor' ? '9' : $location[$attribute])),
                    factSource: new FactSourceLocator(['kind' => 'native', 'coordinate_space' => 'native', 'value' => 'pdf:page:1/'.$type]));
            }
        }

        return [$entities, $facts, $evidence];
    }

    private function linker(): CrossDocumentFactLinker
    {
        return new CrossDocumentFactLinker(new TargetedConflictResolver(static fn (string $key, array $replace): string => $key));
    }
}
