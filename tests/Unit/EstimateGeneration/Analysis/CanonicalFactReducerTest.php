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
            ['literal:1', 'observer_literal', 'room.kitchen.sink', 'Visible kitchen counter with kitchen sink', 'literal:sink', 0.91],
            ['construction:1', 'observer_construction', 'room_kitchen_sink', 'Кухонная мойка в рабочей зоне', 'construction:sink', 0.96],
            ['risk:1', 'observer_risk', 'room:kitchen:sink', 'Раковина кухни', 'risk:sink', 0.88],
        ];
        $claims = [];
        $decisions = [];
        foreach ($descriptions as [$id, $role, $entityKey, $value, $evidenceRef, $confidence]) {
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
                status: 'accepted',
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
        self::assertSame('construction:1', $reduced[0]->claimId);
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
}
