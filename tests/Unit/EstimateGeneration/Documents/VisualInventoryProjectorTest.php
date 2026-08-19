<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Documents;

use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\VisualInventoryProjector;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class VisualInventoryProjectorTest extends TestCase
{
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
                $this->claim('room.bathroom.sink', 'sanitary_fixture', 'Умывальник', 'evidence:bath'),
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
        self::assertSame(
            ['requires_confirmation', 'excluded_by_document_note', 'requires_confirmation', 'requires_confirmation', 'excluded_by_document_note'],
            array_column($result['items'], 'scope'),
        );
        self::assertSame(2, $result['items'][1]['quantity']);
        self::assertContains('literal:1', $result['items'][2]['lineage']['supporting_claim_ids']);
        self::assertSame('minority_evidence_preserved', $result['items'][0]['arbitration']['reason_code']);
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
}
