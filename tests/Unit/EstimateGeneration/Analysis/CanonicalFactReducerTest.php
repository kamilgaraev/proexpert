<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Analysis;

use App\BusinessModules\Addons\EstimateGeneration\Analysis\Arbitration\ArbitrationDecision;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Arbitration\CanonicalFactConfidence;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Arbitration\CanonicalFactReducer;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Arbitration\ObservationClaim;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CanonicalFactReducerTest extends TestCase
{
    #[Test]
    public function three_observers_become_one_canonical_fact_with_complete_lineage(): void
    {
        [$claims, $decisions] = $this->fixture();

        $reduced = (new CanonicalFactReducer)->reduce($claims, $decisions);
        $areas = array_values(array_filter(
            $reduced,
            static fn (ArbitrationDecision $decision): bool => ($decision->canonicalClaim['fact_type'] ?? null) === 'area',
        ));

        self::assertCount(1, $areas);
        self::assertSame(
            ['construction:area-kitchen', 'literal:area-kitchen', 'risk:area-kitchen'],
            $areas[0]->supportingClaimIds,
        );
        self::assertSame(
            ['construction:rooms', 'literal:rooms', 'risk:rooms'],
            $areas[0]->evidenceRefs,
        );
    }

    #[Test]
    public function equal_numbers_for_distinct_entities_are_not_deduplicated(): void
    {
        [$claims, $decisions] = $this->fixture();

        $reduced = (new CanonicalFactReducer)->reduce($claims, $decisions);
        $matchingNumbers = array_values(array_filter(
            $reduced,
            static fn (ArbitrationDecision $decision): bool => ($decision->canonicalClaim['value']['data'] ?? null) === '11100',
        ));

        self::assertCount(2, $matchingNumbers);
        $entities = array_values(array_map(
            static fn (ArbitrationDecision $decision): string => (string) $decision->canonicalClaim['entity_key'],
            $matchingNumbers,
        ));
        sort($entities, SORT_STRING);
        self::assertSame(['building.grid_span_a_d', 'building.overall_width'], $entities);
    }

    #[Test]
    public function confidence_uses_independent_observer_values_without_becoming_one(): void
    {
        [$claims, $decisions] = $this->fixture();
        $area = array_values(array_filter(
            (new CanonicalFactReducer)->reduce($claims, $decisions),
            static fn (ArbitrationDecision $decision): bool => ($decision->canonicalClaim['fact_type'] ?? null) === 'area',
        ))[0];

        $confidence = (new CanonicalFactConfidence)->forDecision($area, $claims);

        self::assertSame(0.97, $claims['literal:area-kitchen']->confidence);
        self::assertSame(0.97, $confidence);
        self::assertLessThan(1.0, $confidence);
    }

    #[Test]
    public function visual_descriptions_of_one_kitchen_sink_reduce_to_one_object_with_evidence_union(): void
    {
        $sourceVersion = 'sha256:'.str_repeat('a', 64);
        $descriptions = [
            ['literal:1', 'observer_literal', 'room.kitchen.sink', 'Visible kitchen counter with kitchen sink', 'literal:sink', 0.91, 'accepted'],
            ['construction:1', 'observer_construction', 'room_kitchen_sink', 'Кухонная мойка в рабочей зоне', 'construction:sink', 0.96, 'candidate'],
            ['risk:1', 'observer_risk', 'room:kitchen:sink', 'Раковина кухни', 'risk:sink', 0.88, 'accepted'],
        ];
        $claims = [];
        $decisions = [];
        foreach ($descriptions as [$id, $role, $entityKey, $value, $evidenceRef, $confidence, $status]) {
            $claim = new ObservationClaim(
                $id,
                $role,
                $entityKey,
                'kitchen_fixture',
                ['type' => 'string', 'data' => $value],
                null,
                $evidenceRef,
                true,
                901,
                902,
                903,
                $sourceVersion,
                ['page_id' => 1120, 'page_number' => 5, 'source_version' => $sourceVersion],
                $confidence,
            );
            $claims[$id] = $claim;
            $decisions[] = new ArbitrationDecision(
                claimId: $id,
                status: $status,
                supportingClaimIds: [$id],
                evidenceRefs: [$evidenceRef],
                reasonCode: 'visible_fixture',
                canonicalClaim: [
                    'entity_key' => $entityKey,
                    'fact_type' => 'kitchen_fixture',
                    'value' => $claim->value,
                    'unit' => null,
                    'source_claim_id' => $id,
                ],
            );
        }

        $reduced = (new CanonicalFactReducer)->reduce($claims, $decisions);

        self::assertCount(1, $reduced);
        self::assertSame(['construction:1', 'literal:1', 'risk:1'], $reduced[0]->supportingClaimIds);
        self::assertSame(['construction:sink', 'literal:sink', 'risk:sink'], $reduced[0]->evidenceRefs);
        self::assertSame('literal:1', $reduced[0]->claimId);
        self::assertSame('accepted', $reduced[0]->status);
    }

    #[Test]
    public function two_physical_toilets_remain_distinct_while_each_observer_lineage_is_merged(): void
    {
        $sourceVersion = 'sha256:'.str_repeat('b', 64);
        $claims = [];
        $decisions = [];
        foreach ([1, 2] as $ordinal) {
            foreach ([
                ['literal', '.', 0.91],
                ['construction', '_', 0.96],
                ['risk', ':', 0.88],
            ] as [$role, $separator, $confidence]) {
                $id = $role.':toilet-'.$ordinal;
                $entityKey = implode($separator, ['room', 'bathroom', 'toilet', (string) $ordinal]);
                $claim = new ObservationClaim(
                    $id,
                    'observer_'.$role,
                    $entityKey,
                    'sanitary_fixture',
                    ['type' => 'string', 'data' => 'Унитаз'],
                    null,
                    $id.':evidence',
                    true,
                    901,
                    902,
                    903,
                    $sourceVersion,
                    ['page_id' => 1120, 'page_number' => 5, 'source_version' => $sourceVersion],
                    $confidence,
                );
                $claims[$id] = $claim;
                $decisions[] = new ArbitrationDecision(
                    $id,
                    'accepted',
                    [$id],
                    [$id.':evidence'],
                    'visible_fixture',
                    [
                        'entity_key' => $entityKey,
                        'fact_type' => 'sanitary_fixture',
                        'value' => $claim->value,
                        'unit' => null,
                        'source_claim_id' => $id,
                    ],
                );
            }
        }

        $reduced = (new CanonicalFactReducer)->reduce($claims, $decisions);

        self::assertCount(2, $reduced);
        self::assertSame([3, 3], array_map(
            static fn (ArbitrationDecision $decision): int => count($decision->supportingClaimIds),
            $reduced,
        ));
        self::assertNotSame(
            $reduced[0]->canonicalClaim['entity_key'],
            $reduced[1]->canonicalClaim['entity_key'],
        );
    }

    #[Test]
    public function physical_group_reduction_is_commutative_associative_and_idempotent_for_unsafe_statuses(): void
    {
        $sourceVersion = 'sha256:'.str_repeat('c', 64);
        $claims = [
            'literal:1' => $this->visualClaim('literal:1', 'room.kitchen.sink', 'Кухонная мойка', 'literal:sink', $sourceVersion, 0.95),
            'construction:1' => $this->visualClaim('construction:1', 'room.кухня.мойка', 'Kitchen sink', 'construction:sink', $sourceVersion, 0.90),
            'risk:1' => $this->visualClaim('risk:1', 'room-kitchen-sink', 'Кухонная мойка', 'risk:sink', $sourceVersion, 0.85),
        ];
        $reducer = new CanonicalFactReducer;

        foreach ([
            ['conditional', 'needs_confirmation', 'conditional'],
            ['unresolved', 'manual_review_required', 'unresolved'],
            ['ambiguous', 'conflicting_observation', 'ambiguous'],
            ['rejected', 'unsafe_conflict', 'rejected'],
            ['future_status', 'future_reason', 'unresolved'],
        ] as [$unsafeStatus, $unsafeReason, $expectedStatus]) {
            $accepted = $this->visualDecision($claims['literal:1'], 'accepted', 'accepted_fixture');
            $unsafe = $this->visualDecision($claims['construction:1'], $unsafeStatus, $unsafeReason);
            $candidate = $this->visualDecision($claims['risk:1'], 'candidate', 'visual_candidate');
            $flat = $reducer->reduce($claims, [$accepted, $unsafe, $candidate]);
            $left = $reducer->reduce($claims, [
                ...$reducer->reduce($claims, [$accepted, $unsafe]),
                $candidate,
            ]);
            $right = $reducer->reduce($claims, [
                $accepted,
                ...$reducer->reduce($claims, [$unsafe, $candidate]),
            ]);
            $idempotent = $reducer->reduce($claims, [...$flat, ...$flat]);
            $encoded = [
                ...array_map(
                    static fn (array $permutation): string => json_encode(
                        $reducer->reduce($claims, $permutation),
                        JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
                    ),
                    $this->permutations([$accepted, $unsafe, $candidate]),
                ),
                ...array_map(
                    static fn (array $value): string => json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    [$flat, $left, $right, $idempotent],
                ),
            ];

            self::assertCount(1, array_unique($encoded), $unsafeStatus);
            self::assertCount(1, $flat, $unsafeStatus);
            self::assertSame($expectedStatus, $flat[0]->status, $unsafeStatus);
            self::assertNotSame('accepted', $flat[0]->status, $unsafeStatus);
            self::assertSame(['construction:1', 'literal:1', 'risk:1'], $flat[0]->supportingClaimIds, $unsafeStatus);
            self::assertSame(['construction:sink', 'literal:sink', 'risk:sink'], $flat[0]->evidenceRefs, $unsafeStatus);
        }
    }

    #[Test]
    public function duplicate_decisions_for_one_claim_cannot_restore_accepted_by_order(): void
    {
        $sourceVersion = 'sha256:'.str_repeat('d', 64);
        $claim = $this->visualClaim('literal:1', 'floor.1.room.kitchen.sink', 'Кухонная мойка', 'literal:sink', $sourceVersion, 0.95);
        $claims = [$claim->id => $claim];
        $accepted = $this->visualDecision($claim, 'accepted', 'accepted_fixture');
        $unresolved = $this->visualDecision($claim, 'unresolved', 'manual_review_required');
        $reducer = new CanonicalFactReducer;

        $forward = $reducer->reduce($claims, [$accepted, $unresolved]);
        $reverse = $reducer->reduce($claims, [$unresolved, $accepted]);

        self::assertSame(
            json_encode($forward, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            json_encode($reverse, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        );
        self::assertCount(1, $forward);
        self::assertSame('unresolved', $forward[0]->status);
    }

    /** @return array{array<string,ObservationClaim>,list<ArbitrationDecision>} */
    private function fixture(): array
    {
        $payload = json_decode((string) file_get_contents(
            dirname(__DIR__, 3).'/Fixtures/EstimateGeneration/Vision/session-75-page-5-canonicalization.json',
        ), true, flags: JSON_THROW_ON_ERROR);
        $source = $payload['source'];
        $claims = [];
        foreach ($payload['independent_observations'] as $role => $observer) {
            foreach ($observer['claims'] as $claim) {
                $claims[$claim['id']] = new ObservationClaim(
                    $claim['id'],
                    $role,
                    $claim['entityKey'],
                    $claim['factType'],
                    $claim['value'],
                    $claim['unit'],
                    $claim['evidenceRef'],
                    true,
                    $source['organization_id'],
                    $source['project_id'],
                    $source['session_id'],
                    $source['source_version'],
                    [
                        'page_id' => $source['page_id'],
                        'page_number' => $source['page_number'],
                        'source_version' => $source['source_version'],
                    ],
                    $claim['confidence'],
                );
            }
        }
        $decisions = array_map(static fn (array $decision): ArbitrationDecision => new ArbitrationDecision(
            claimId: $decision['claim_id'],
            status: $decision['status'],
            supportingClaimIds: $decision['supporting_claim_ids'],
            evidenceRefs: $decision['evidence_refs'],
            reasonCode: $decision['reason_code'],
            canonicalClaim: $decision['canonical_claim'],
        ), $payload['document_arbitration']['decisions']);

        return [$claims, $decisions];
    }

    private function visualClaim(
        string $id,
        string $entityKey,
        string $value,
        string $evidenceRef,
        string $sourceVersion,
        float $confidence,
    ): ObservationClaim {
        return new ObservationClaim(
            $id,
            'observer_'.strstr($id, ':', true),
            $entityKey,
            'kitchen_fixture',
            ['type' => 'string', 'data' => $value],
            null,
            $evidenceRef,
            true,
            901,
            902,
            903,
            $sourceVersion,
            ['page_id' => 1120, 'page_number' => 5, 'source_version' => $sourceVersion],
            $confidence,
        );
    }

    private function visualDecision(ObservationClaim $claim, string $status, string $reasonCode): ArbitrationDecision
    {
        return new ArbitrationDecision(
            $claim->id,
            $status,
            [$claim->id],
            $claim->evidenceRef === null ? [] : [$claim->evidenceRef],
            $reasonCode,
            [
                'entity_key' => $claim->entityKey,
                'fact_type' => $claim->factType,
                'value' => $claim->value,
                'unit' => $claim->unit,
                'source_claim_id' => $claim->id,
            ],
            $reasonCode,
        );
    }

    /** @template T @param list<T> $items @return list<list<T>> */
    private function permutations(array $items): array
    {
        if (count($items) < 2) {
            return [$items];
        }
        $result = [];
        foreach ($items as $index => $item) {
            $remaining = $items;
            array_splice($remaining, $index, 1);
            foreach ($this->permutations($remaining) as $tail) {
                $result[] = [$item, ...$tail];
            }
        }

        return $result;
    }
}
