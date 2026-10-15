<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Analysis;

use App\BusinessModules\Addons\EstimateGeneration\Analysis\Arbitration\ArbitrationDecision;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Arbitration\ObservationClaim;
use App\BusinessModules\Addons\EstimateGeneration\Application\Understanding\CrossDocumentFactLinker;
use App\BusinessModules\Addons\EstimateGeneration\Application\Understanding\TargetedConflictResolver;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\ProjectModelEvidenceWriter;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\InMemoryEvidenceRepository;
use PHPUnit\Framework\TestCase;
use Tests\Support\EstimateGeneration\InMemoryProjectModelRepository;
use Tests\Support\EstimateGeneration\RoomLocationVisionFixture;

final class RoomLocationVisionPublicationTest extends TestCase
{
    public function test_literal_location_identity_preserves_sign_case_and_leading_zeroes(): void
    {
        $matcher = new \App\BusinessModules\Addons\EstimateGeneration\Analysis\Arbitration\ClaimSemanticMatcher;
        foreach (['room_floor' => ['-1', '1'], 'room_zone' => ['A', 'a'], 'room_number' => ['001', '1']] as $type => [$left, $right]) {
            $claims = [];
            foreach ([$left, $right] as $index => $value) {
                $claims[] = new ObservationClaim('literal:'.($index + 1), 'observer_literal', 'room:office', $type,
                    ['type' => 'string', 'data' => $value], null, 'page:1', true, 7, 9, 11,
                    'sha256:'.str_repeat('a', 64), ['document_id' => 13, 'page' => 1]);
            }
            self::assertFalse($matcher->equivalent(...$claims));
            self::assertNotSame($matcher->key($claims[0]), $matcher->key($claims[1]));
        }
    }

    public function test_surrounding_whitespace_does_not_split_the_same_literal_observation(): void
    {
        $matcher = new \App\BusinessModules\Addons\EstimateGeneration\Analysis\Arbitration\ClaimSemanticMatcher;
        foreach (['room_number' => '001', 'room_floor' => '-1', 'room_zone' => 'A'] as $type => $value) {
            $canonical = ['entity_key' => 'room:office', 'fact_type' => $type,
                'value' => ['type' => 'string', 'data' => $value], 'unit' => null];
            $spaced = [...$canonical, 'value' => ['type' => 'string', 'data' => ' '.$value.' ']];
            self::assertSame($matcher->keyForCanonical($canonical), $matcher->keyForCanonical($spaced));
        }
    }

    public function test_per_fact_polygon_is_transformed_to_original_source_and_native_reference_is_preserved(): void
    {
        $transform = new \App\BusinessModules\Addons\EstimateGeneration\Vision\DTO\ProjectiveTransformData(
            [[2.0, 0.0, 0.0], [0.0, 2.0, 0.0], [0.0, 0.0, 1.0]],
            [[0.5, 0.0, 0.0], [0.0, 0.5, 0.0], [0.0, 0.0, 1.0]], 4.0, 2.0);
        $claims = RoomLocationVisionFixture::claims([7, 9, 11], 13, 'sha256:'.str_repeat('a', 64), 'floor_plan', transform: $transform);
        self::assertEquals(['kind' => 'polygon', 'coordinate_space' => 'normalized_source_v1',
            'value' => [[0.05, 0.05], [0.2, 0.05], [0.2, 0.1], [0.05, 0.1]]], $claims[1]->locator['fact_source']);
        $original = RoomLocationVisionFixture::claims([7, 9, 11], 13, 'sha256:'.str_repeat('a', 64),
            'floor_plan', variant: 'already_source', transform: $transform);
        self::assertEquals([[0.1, 0.1], [0.4, 0.1], [0.4, 0.2], [0.1, 0.2]], $original[1]->locator['fact_source']['value']);
        $native = RoomLocationVisionFixture::claims([7, 9, 11], 13, 'sha256:'.str_repeat('a', 64), 'floor_plan', variant: 'native');
        self::assertSame(['kind' => 'native', 'coordinate_space' => 'native', 'value' => 'pdf:page:1/text'], $native[1]->locator['fact_source']);
    }

    public function test_real_vision_contract_publishes_independent_floor_and_zone_proof_for_number_links(): void
    {
        foreach (['valid', 'missing', 'other_room', 'implicit', 'candidate', 'other_page', 'conflict', 'mismatch', 'missing_exact_source'] as $variant) {
            $models = new InMemoryProjectModelRepository;
            $writer = new ProjectModelEvidenceWriter($models, new InMemoryEvidenceRepository);
            foreach ([13 => 'floor_plan', 14 => 'explication'] as $document => $role) {
                $claims = RoomLocationVisionFixture::claims([7, 9, 11], $document,
                    'sha256:'.str_repeat($document === 13 ? 'a' : 'b', 64), $role,
                    $document === 14 && $variant === 'missing' ? null : ($document === 14 && $variant === 'mismatch' ? '2' : '-1'),
                    $document === 14 ? $variant : 'valid');
                $decisions = [];
                foreach ($claims as $index => $claim) {
                    self::assertSame($document, $claim->locator['document_id']);
                    self::assertSame(1, $claim->locator['page']);
                    self::assertArrayNotHasKey('floor_id', $claim->locator);
                    if ($document === 14 && $variant === 'other_page' && $claim->factType === 'room_floor') {
                        $claims[$index] = $claim = new ObservationClaim($claim->id, $claim->observerRole, $claim->entityKey,
                            $claim->factType, $claim->value, null, $claim->evidenceRef, true, 7, 9, 11, $claim->sourceVersion,
                            [...$claim->locator, 'page' => 2]);
                    }
                    if ($document === 14 && $variant === 'missing_exact_source' && $claim->factType === 'room_floor') {
                        $locator = $claim->locator;
                        unset($locator['fact_source']);
                        $claims[$index] = $claim = new ObservationClaim($claim->id, $claim->observerRole, $claim->entityKey,
                            $claim->factType, $claim->value, null, $claim->evidenceRef, true, 7, 9, 11, $claim->sourceVersion, $locator);
                    }
                    $decisions[] = new ArbitrationDecision($claim->id,
                        $document === 14 && $variant === 'candidate' && $claim->factType === 'room_floor' ? 'candidate' : 'accepted',
                        [$claim->id], [$claim->evidenceRef], 'explicit', null);
                    if ($document === 14 && $variant === 'conflict' && $claim->factType === 'room_floor') {
                        $conflict = new ObservationClaim('construction:floor', 'observer_construction', $claim->entityKey,
                            'room_floor', ['type' => 'string', 'data' => '2'], null, $claim->evidenceRef, true,
                            7, 9, 11, $claim->sourceVersion, $claim->locator);
                        $claims[] = $conflict;
                        $decisions[] = new ArbitrationDecision($conflict->id, 'accepted', [$conflict->id], [$conflict->evidenceRef], 'explicit', null);
                    }
                }
                $writer->writeArbitration($claims, $decisions, $document, 1);
            }
            $result = (new CrossDocumentFactLinker(new TargetedConflictResolver(static fn (string $key, array $replace): string => $key)))
                ->link(array_values($models->entities), array_values($models->facts), array_values($models->evidence));
            self::assertCount($variant === 'valid' ? 1 : 0, $result->links, $variant);
            self::assertSame(0, $result->providerCalls);
            if ($variant === 'valid') {
                $entities = array_column($models->entities, null, 'id');
                $evidence = array_column($models->evidence, null, 'id');
                foreach (array_filter($models->facts, static fn ($fact): bool => $fact->type === 'room_number') as $number) {
                    self::assertCount(3, $number->evidenceIds);
                    self::assertSame('-1', $entities[$number->entityId]->attributes['floor_id']);
                    self::assertSame('A', $entities[$number->entityId]->attributes['zone_id']);
                    self::assertSame(['room_number', 'room_floor', 'room_zone'], array_map(
                        static fn (string $id): string => $evidence[$id]->literalFact->factKey, $number->evidenceIds));
                }
            }
        }
    }
}
