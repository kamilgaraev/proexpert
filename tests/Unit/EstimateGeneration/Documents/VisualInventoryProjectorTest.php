<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Documents;

use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\VisualInventoryProjector;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class VisualInventoryProjectorTest extends TestCase
{
    #[Test]
    public function every_observer_claim_evidence_and_decision_permutation_has_identical_canonical_bytes(): void
    {
        $observers = [
            'observer_literal' => $this->observer('observer_literal', [
                $this->claim('room.kitchen.sink', 'kitchen_fixture', 'Kitchen sink 2', 'evidence:kitchen'),
                $this->claim('room-kitchen-sink', 'kitchen_fixture', 'Visible sink', 'evidence:kitchen'),
            ]),
            'observer_construction' => $this->observer('observer_construction', [
                $this->claim('room_кухня_мойка', 'kitchen_fixture', 'Кухонная мойка', 'evidence:kitchen'),
                $this->claim('room.кухня.мойка', 'kitchen_fixture', 'Мойка в рабочей зоне', 'evidence:kitchen'),
            ]),
            'observer_risk' => $this->observer('observer_risk', [
                $this->claim('room-kitchen-sink', 'kitchen_fixture', 'Sink without specification', 'evidence:kitchen'),
                $this->claim('room:kitchen:sink', 'kitchen_fixture', 'Kitchen fixture', 'evidence:kitchen'),
            ]),
        ];
        $decisions = [
            ['claim_id' => 'literal:1', 'status' => 'conditional', 'reason_code' => 'minority_evidence_preserved', 'supporting_claim_ids' => ['risk:1', 'literal:1'], 'evidence_refs' => ['risk:evidence:kitchen', 'literal:evidence:kitchen']],
            ['claim_id' => 'construction:1', 'status' => 'candidate', 'reason_code' => 'fixture_requires_specification', 'supporting_claim_ids' => ['construction:1', 'literal:1'], 'evidence_refs' => ['construction:evidence:kitchen', 'literal:evidence:kitchen']],
        ];

        $encoded = [];
        foreach ($this->permutations(array_keys($observers)) as $permutationIndex => $roles) {
            $ordered = [];
            foreach ($roles as $role) {
                $observer = $observers[$role];
                if ($permutationIndex % 2 === 1) {
                    $observer['claims'] = array_reverse($observer['claims']);
                    $observer['evidence'] = array_reverse($observer['evidence']);
                }
                $ordered[$role] = $observer;
            }
            $result = (new VisualInventoryProjector)->project(
                $ordered,
                ['decisions' => $permutationIndex % 2 === 1 ? array_reverse($decisions) : $decisions],
                $this->scope(),
            );
            $encoded[] = json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        self::assertCount(1, array_unique($encoded));
        $result = json_decode($encoded[0], true, flags: JSON_THROW_ON_ERROR);
        self::assertCount(1, $result['items']);
        self::assertSame(['Кухонная мойка'], array_column($result['items'], 'label'));
        self::assertNull($result['items'][0]['quantity']);
        self::assertTrue($result['items'][0]['quantity_uncertain']);
        self::assertSame('requires_confirmation', $result['items'][0]['scope']);
        self::assertSame('candidate', $result['items'][0]['arbitration']['status']);
        self::assertSame('fixture_requires_specification', $result['items'][0]['arbitration']['reason_code']);
        self::assertSame(
            ['construction:1', 'construction:2', 'literal:1', 'literal:2', 'risk:1', 'risk:2'],
            $result['items'][0]['lineage']['supporting_claim_ids'],
        );
    }

    #[Test]
    public function fixtures_and_conditional_furniture_are_kept_separate_without_losing_minority_evidence(): void
    {
        $result = (new VisualInventoryProjector)->project([
            'observer_literal' => $this->observer('observer_literal', [
                $this->claim('room.bathroom.toilet', 'sanitary_fixture', 'Унитаз', 'evidence:bath'),
                $this->claim('room.bedroom.beds', 'furniture', '2 кровати', 'evidence:beds'),
                $this->claim('document.note', 'note', 'Мебель и оборудование показаны условно', 'evidence:note'),
            ]),
            'observer_construction' => $this->observer('observer_construction', [
                $this->claim('room.bathroom.sink', 'sanitary_fixture', 'Bathroom sink', 'evidence:bath'),
                $this->claim('room.kitchen.sink', 'kitchen_fixture', 'Кухонная мойка', 'evidence:kitchen'),
                $this->claim('room.kitchen.cabinets', 'furniture', 'Кухонная мебель', 'evidence:kitchen'),
            ]),
        ], [
            'decisions' => [[
                'claim_id' => 'construction:1',
                'status' => 'candidate',
                'reason_code' => 'fixture_requires_specification',
                'supporting_claim_ids' => ['literal:1', 'construction:1'],
                'evidence_refs' => ['literal:evidence:bath', 'construction:evidence:bath'],
            ]],
        ], $this->scope());

        self::assertCount(5, $result['items']);
        self::assertSame(3, count(array_filter(
            $result['items'],
            static fn (array $item): bool => $item['scope'] === 'requires_confirmation',
        )));
        self::assertSame(2, count(array_filter(
            $result['items'],
            static fn (array $item): bool => $item['scope'] === 'excluded_by_document_note',
        )));
        $byType = [];
        foreach ($result['items'] as $item) {
            $byType[$item['object_type']] = $item;
        }
        self::assertSame(2, $byType['bed']['quantity']);
        self::assertContains('literal:1', $byType['washbasin']['lineage']['supporting_claim_ids']);
        self::assertSame('Умывальник', $byType['washbasin']['label']);
        self::assertSame('sanitary_fixture', $byType['washbasin']['category']);
        self::assertSame('minority_evidence_preserved', $byType['toilet']['arbitration']['reason_code']);
        self::assertSame([], $result['quarantined_items']);
    }

    #[Test]
    public function malformed_and_out_of_scope_items_are_quarantined_without_destroying_valid_items(): void
    {
        $observer = $this->observer('observer_literal', [
            $this->claim('room.bathroom.toilet', 'sanitary_fixture', 'Унитаз', 'evidence:bath'),
            ['entityKey' => 'broken'],
        ]);
        $observer['evidence'][0]['locator']['source_version'] = 'sha256:'.str_repeat('b', 64);

        $result = (new VisualInventoryProjector)->project(['observer_literal' => $observer], null, $this->scope());

        self::assertSame([], $result['items']);
        self::assertCount(2, $result['quarantined_items']);
    }

    #[Test]
    public function observer_descriptions_of_the_same_physical_objects_merge_with_complete_lineage(): void
    {
        $result = (new VisualInventoryProjector)->project([
            'observer_literal' => $this->observer('observer_literal', [
                $this->claim('room.kitchen.sink', 'kitchen_fixture', 'Visible kitchen counter with kitchen sink', 'evidence:kitchen'),
                $this->claim('room.bathroom.toilet', 'sanitary_fixture', 'Унитаз на плане', 'evidence:bathroom'),
                $this->claim('room.bedroom.bed', 'furniture', 'Кровать показана условно', 'evidence:bedroom'),
                $this->claim('document.note', 'note', 'Мебель и оборудование показаны условно', 'evidence:note'),
            ]),
            'observer_construction' => $this->observer('observer_construction', [
                $this->claim('room_kitchen_sink', 'kitchen_fixture', 'Кухонная мойка в рабочей зоне', 'evidence:kitchen'),
                $this->claim('room_bathroom_toilet', 'sanitary_fixture', 'Санитарный прибор: унитаз', 'evidence:bathroom'),
                $this->claim('room_bedroom_bed', 'furniture', 'Предмет мебели — кровать', 'evidence:bedroom'),
            ]),
            'observer_risk' => $this->observer('observer_risk', [
                $this->claim('room:kitchen:sink', 'kitchen_fixture', 'Кухонное оборудование и мойка', 'evidence:kitchen'),
                $this->claim('room:bathroom:toilet', 'sanitary_fixture', 'Условное обозначение унитаза', 'evidence:bathroom'),
                $this->claim('room:bedroom:bed', 'furniture', 'Условный контур кровати', 'evidence:bedroom'),
            ]),
        ], null, $this->scope());

        self::assertCount(3, $result['items']);
        $byType = [];
        foreach ($result['items'] as $item) {
            $byType[$item['object_type']] = $item;
        }

        self::assertSame('requires_confirmation', $byType['kitchen_sink']['scope']);
        self::assertSame('requires_confirmation', $byType['toilet']['scope']);
        self::assertSame('excluded_by_document_note', $byType['bed']['scope']);
        self::assertCount(3, $byType['kitchen_sink']['lineage']['supporting_claim_ids']);
        self::assertCount(3, $byType['toilet']['lineage']['supporting_claim_ids']);
        self::assertCount(3, $byType['bed']['lineage']['supporting_claim_ids']);
        self::assertSame([], $result['quarantined_items']);
    }

    #[Test]
    public function conflicting_or_partially_observed_quantity_requires_confirmation(): void
    {
        $projector = new VisualInventoryProjector;
        $conflicting = $projector->project([
            'observer_literal' => $this->observer('observer_literal', [
                $this->claim('room.kitchen.sink', 'kitchen_fixture', '2 kitchen sinks', 'evidence:kitchen'),
            ]),
            'observer_construction' => $this->observer('observer_construction', [
                $this->claim('room.кухня.мойка', 'kitchen_fixture', '3 кухонные мойки', 'evidence:kitchen'),
            ]),
        ], null, $this->scope());
        $partial = $projector->project([
            'observer_literal' => $this->observer('observer_literal', [
                $this->claim('room.kitchen.sink', 'kitchen_fixture', '2 kitchen sinks', 'evidence:kitchen'),
            ]),
            'observer_construction' => $this->observer('observer_construction', [
                $this->claim('room.кухня.мойка', 'kitchen_fixture', 'Кухонная мойка', 'evidence:kitchen'),
            ]),
        ], null, $this->scope());

        foreach ([$conflicting, $partial] as $result) {
            self::assertNull($result['items'][0]['quantity']);
            self::assertTrue($result['items'][0]['quantity_uncertain']);
            self::assertSame('requires_confirmation', $result['items'][0]['scope']);
        }
    }

    #[Test]
    public function dimensions_models_articles_and_marks_are_never_projected_as_quantity(): void
    {
        $projector = new VisualInventoryProjector;
        foreach ([
            'Кухонная мойка 60 см',
            'Кухонная мойка 600 мм',
            'Кухонная мойка Ø50',
            'Кухонная мойка, модель 60',
            'Кухонная мойка, арт. 123',
            'Кухонная мойка по оси 11 100 мм',
            'Кухонная мойка, помещение 22.10 м²',
        ] as $value) {
            $result = $projector->project([
                'observer_literal' => $this->observer('observer_literal', [
                    $this->claim('room.kitchen.sink', 'kitchen_fixture', $value, 'evidence:kitchen'),
                ]),
            ], null, $this->scope());

            self::assertNull($result['items'][0]['quantity'], $value);
            self::assertTrue($result['items'][0]['quantity_uncertain'], $value);
        }

        $threeObservers = $projector->project([
            'observer_literal' => $this->observer('observer_literal', [
                $this->claim('room.kitchen.sink', 'kitchen_fixture', 'Кухонная мойка 60 см', 'evidence:kitchen'),
            ]),
            'observer_construction' => $this->observer('observer_construction', [
                $this->claim('room.кухня.мойка', 'kitchen_fixture', 'Кухонная мойка 60 см', 'evidence:kitchen'),
            ]),
            'observer_risk' => $this->observer('observer_risk', [
                $this->claim('room:kitchen:sink', 'kitchen_fixture', 'Кухонная мойка 60 см', 'evidence:kitchen'),
            ]),
        ], null, $this->scope());

        self::assertNull($threeObservers['items'][0]['quantity']);
        self::assertTrue($threeObservers['items'][0]['quantity_uncertain']);
    }

    #[Test]
    public function explicit_integer_count_semantics_are_projected_without_float_guessing(): void
    {
        $projector = new VisualInventoryProjector;
        foreach ([
            ['room.kitchen.sink', 'kitchen_fixture', '2 мойки', 2],
            ['room.bathroom.toilet', 'sanitary_fixture', 'Унитаз — 1 шт.', 1],
            ['room.living.window.1', 'unknown_fixture', '3 окна', 3],
        ] as [$entityKey, $factType, $value, $expected]) {
            $result = $projector->project([
                'observer_literal' => $this->observer('observer_literal', [
                    $this->claim($entityKey, $factType, $value, 'evidence:count'),
                ]),
            ], null, $this->scope());

            self::assertSame($expected, $result['items'][0]['quantity'], $value);
            self::assertFalse($result['items'][0]['quantity_uncertain'], $value);
        }
    }

    #[Test]
    public function every_duplicate_decision_permutation_reduces_to_the_same_conservative_payload(): void
    {
        $observer = $this->observer('observer_literal', [
            $this->claim('room.kitchen.sink', 'kitchen_fixture', 'Кухонная мойка', 'evidence:kitchen'),
        ]);
        $decisions = [
            ['claim_id' => 'literal:1', 'status' => 'accepted', 'reason_code' => 'accepted_fixture', 'supporting_claim_ids' => ['literal:1'], 'evidence_refs' => ['literal:evidence:kitchen']],
            ['claim_id' => 'literal:1', 'status' => 'conditional', 'reason_code' => 'needs_confirmation', 'supporting_claim_ids' => ['literal:1'], 'evidence_refs' => ['literal:evidence:kitchen']],
            ['claim_id' => 'literal:1', 'status' => 'ambiguous', 'reason_code' => 'conflicting_observation', 'supporting_claim_ids' => ['literal:1'], 'evidence_refs' => ['literal:evidence:kitchen']],
            ['claim_id' => 'literal:1', 'status' => 'rejected', 'reason_code' => 'unsafe_conflict', 'supporting_claim_ids' => ['literal:1'], 'evidence_refs' => ['literal:evidence:kitchen']],
        ];
        $encoded = [];

        foreach ($this->permutations($decisions) as $permutation) {
            $result = (new VisualInventoryProjector)->project(
                ['observer_literal' => $observer],
                ['decisions' => $permutation],
                $this->scope(),
            );
            $encoded[] = json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        self::assertCount(1, array_unique($encoded));
        $result = json_decode($encoded[0], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('rejected', $result['items'][0]['arbitration']['status']);
        self::assertSame('unsafe_conflict', $result['items'][0]['arbitration']['reason_code']);
        self::assertSame(['literal:1'], $result['items'][0]['lineage']['supporting_claim_ids']);
        self::assertSame(['literal:evidence:kitchen'], $result['items'][0]['lineage']['evidence_refs']);
    }

    #[Test]
    public function accepted_and_conditional_duplicate_decisions_cannot_be_promoted_by_order(): void
    {
        $observer = $this->observer('observer_literal', [
            $this->claim('room.kitchen.sink', 'kitchen_fixture', 'Кухонная мойка', 'evidence:kitchen'),
        ]);
        $accepted = ['claim_id' => 'literal:1', 'status' => 'accepted', 'reason_code' => 'accepted_fixture'];
        $conditional = ['claim_id' => 'literal:1', 'status' => 'conditional', 'reason_code' => 'needs_confirmation'];
        $projector = new VisualInventoryProjector;
        $forward = $projector->project(
            ['observer_literal' => $observer],
            ['decisions' => [$accepted, $conditional]],
            $this->scope(),
        );
        $reverse = $projector->project(
            ['observer_literal' => $observer],
            ['decisions' => [$conditional, $accepted]],
            $this->scope(),
        );

        self::assertSame($forward, $reverse);
        self::assertSame('conditional', $forward['items'][0]['arbitration']['status']);
        self::assertSame('needs_confirmation', $forward['items'][0]['arbitration']['reason_code']);
    }

    #[Test]
    public function reversing_same_identity_claims_with_distinct_evidence_keeps_primary_and_canonical_bytes(): void
    {
        $claims = [
            $this->claim('room.kitchen.sink', 'kitchen_fixture', 'Кухонная мойка', 'evidence:first'),
            $this->claim('room.кухня.мойка', 'kitchen_fixture', 'Kitchen sink', 'evidence:second'),
        ];
        $forward = $this->observer('observer_literal', $claims);
        $reverse = $this->observer('observer_literal', array_reverse($claims));
        foreach ([&$forward, &$reverse] as &$observer) {
            foreach ($observer['evidence'] as &$item) {
                $item['locator']['processing_unit_id'] = $item['key'] === 'evidence:first' ? 10 : 20;
            }
        }

        $projector = new VisualInventoryProjector;
        $forwardResult = $projector->project(['observer_literal' => $forward], null, $this->scope());
        $reverseResult = $projector->project(['observer_literal' => $reverse], null, $this->scope());

        self::assertSame(
            json_encode($forwardResult, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            json_encode($reverseResult, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        );
        self::assertSame(10, $forwardResult['items'][0]['evidence_locator']['processing_unit_id']);
    }

    #[Test]
    public function accepted_support_is_primary_and_minority_cannot_represent_the_group(): void
    {
        $result = (new VisualInventoryProjector)->project([
            'observer_literal' => $this->observer('observer_literal', [
                $this->claim('room.kitchen.sink', 'kitchen_fixture', 'Кухонная мойка', 'evidence:kitchen'),
            ]),
            'observer_construction' => $this->observer('observer_construction', [
                $this->claim('room.кухня.мойка', 'kitchen_fixture', 'Kitchen sink', 'evidence:kitchen'),
            ]),
        ], ['decisions' => [
            ['claim_id' => 'literal:1', 'status' => 'accepted', 'reason_code' => 'accepted_fixture'],
            ['claim_id' => 'construction:1', 'status' => 'conditional', 'reason_code' => 'minority_evidence_preserved'],
        ]], $this->scope());

        self::assertSame('accepted', $result['items'][0]['arbitration']['status']);
        self::assertSame('accepted_fixture', $result['items'][0]['arbitration']['reason_code']);
        self::assertSame('literal:1', $result['items'][0]['lineage']['claim_id']);
    }

    #[Test]
    public function same_object_type_in_two_rooms_or_two_instances_stays_separate(): void
    {
        $result = (new VisualInventoryProjector)->project([
            'observer_literal' => $this->observer('observer_literal', [
                $this->claim('room.bathroom.toilet.1', 'sanitary_fixture', 'Унитаз', 'evidence:bathroom'),
                $this->claim('room.bathroom.toilet.2', 'sanitary_fixture', 'Унитаз', 'evidence:bathroom'),
                $this->claim('room.guest_bathroom.toilet.1', 'sanitary_fixture', 'Унитаз', 'evidence:bathroom'),
            ]),
        ], null, $this->scope());

        self::assertCount(3, $result['items']);
        self::assertCount(3, array_unique(array_column($result['items'], 'key')));
        self::assertSame(['Унитаз', 'Унитаз', 'Унитаз'], array_column($result['items'], 'label'));
    }

    private function observer(string $role, array $claims): array
    {
        $evidence = [];
        foreach ($claims as $claim) {
            if (! is_string($claim['evidenceRef'] ?? null)) {
                continue;
            }
            $evidence[$claim['evidenceRef']] = [
                'key' => $claim['evidenceRef'],
                'locator' => [
                    'page_id' => 905,
                    'page_number' => 5,
                    'processing_unit_id' => 500,
                    'source_version' => 'sha256:'.str_repeat('a', 64),
                    'coordinate_space' => 'normalized_source_v1',
                ],
            ];
        }

        return [
            'role' => $role,
            'source' => ['document_id' => 904, 'page_id' => 905, 'page_number' => 5, 'source_version' => 'sha256:'.str_repeat('a', 64)],
            'claims' => $claims,
            'evidence' => array_values($evidence),
        ];
    }

    private function claim(string $entity, string $type, string $value, string $evidence): array
    {
        return ['entityKey' => $entity, 'factType' => $type, 'value' => ['type' => 'string', 'data' => $value], 'unit' => null, 'confidence' => 0.9, 'evidenceRef' => $evidence];
    }

    private function scope(): array
    {
        return ['document_id' => 904, 'page_id' => 905, 'page_number' => 5, 'source_version' => 'sha256:'.str_repeat('a', 64)];
    }

    /** @template T @param list<T> $items @return list<list<T>> */
    private function permutations(array $items): array
    {
        if (count($items) <= 1) {
            return [$items];
        }
        $result = [];
        foreach ($items as $index => $item) {
            $remaining = $items;
            array_splice($remaining, $index, 1);
            foreach ($this->permutations(array_values($remaining)) as $permutation) {
                $result[] = [$item, ...$permutation];
            }
        }

        return $result;
    }
}
