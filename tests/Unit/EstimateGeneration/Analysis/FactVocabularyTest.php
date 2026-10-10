<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Analysis;

use App\BusinessModules\Addons\EstimateGeneration\Analysis\Arbitration\ArbitrationIntentIngestor;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Arbitration\ClaimSemanticMatcher;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Arbitration\ObservationClaim;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\ProjectModelEvidenceWriter;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\FactVocabulary;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\InMemoryEvidenceRepository;
use PHPUnit\Framework\TestCase;
use Tests\Support\EstimateGeneration\InMemoryProjectModelRepository;

final class FactVocabularyTest extends TestCase
{
    public function test_numeric_evidence_must_match_entity_parameter_value_and_floor(): void
    {
        $target = $this->claim('literal:1', 'wall:1', 'height', '3', 'm', false);
        foreach ([
            $this->claim('construction:1', 'wall:2', 'height', '3', 'm'),
            $this->claim('construction:1', 'wall:1', 'length', '3', 'm'),
            $this->claim('construction:1', 'wall:1', 'height', '4', 'm'),
            $this->claim('construction:1', 'wall:1', 'height', '3', 'm', true, 'floor:2'),
        ] as $unrelated) {
            $result = (new ArbitrationIntentIngestor)->ingest([$this->intent()], [$target, $unrelated]);
            self::assertSame([], $result->accepted);
            self::assertNotEmpty($result->quarantined);
        }
    }

    public function test_equivalent_metric_units_and_parameter_aliases_are_relevant_evidence(): void
    {
        $target = $this->claim('literal:1', 'wall:1', 'height', '3.000', 'м', false);
        $support = $this->claim('construction:1', 'wall:1', 'wall_height', '3000', 'мм');
        self::assertTrue((new ClaimSemanticMatcher)->equivalent($target, $support));
        $result = (new ArbitrationIntentIngestor)->ingest([$this->intent()], [$target, $support]);
        self::assertCount(1, $result->accepted);
        self::assertSame('accepted', $result->accepted[0]->status);
        self::assertSame('m2:12', FactVocabulary::measurementSignature('120000', 'см²'));
        self::assertFalse((new ClaimSemanticMatcher)->equivalent($target,
            $this->claim('construction:1', 'wall:1', 'height', '3', 'м²')));
    }

    public function test_geometry_entities_project_without_unsupported_database_kinds(): void
    {
        $models = new InMemoryProjectModelRepository;
        $writer = new ProjectModelEvidenceWriter($models, new InMemoryEvidenceRepository);
        foreach ([
            ['roof:1', 'plan_area', '12', 'м²', 'roof'],
            ['roof_facet:1', 'slope_run', '4000', 'мм', 'roof_facet'],
            ['roof_opening:1', 'area', '1', 'm2', 'roof_opening'],
            ['site:1', 'excavation_depth', '80', 'cm', 'site'],
            ['wall:1', 'length', '5000', 'mm', 'wall'],
            ['opening:1', 'height', '2', 'm', 'opening'],
        ] as [$entity, $parameter, $value, $unit, $semantic]) {
            $claim = $this->claim('literal:1', $entity, $parameter, $value, $unit);
            $result = (new ArbitrationIntentIngestor)->ingest([[
                'claim_id' => $claim->id, 'status' => 'accepted', 'supporting_claim_ids' => [$claim->id],
                'evidence_refs' => [$claim->evidenceRef], 'reason' => 'Число прочитано из указанного источника',
            ]], [$claim]);
            $writer->writeArbitration([$claim], $result->accepted, 13, 1);
            $created = array_values(array_filter($models->entities, static fn ($item): bool => ($item->attributes['semantic_type'] ?? null) === $semantic));
            self::assertNotEmpty($created);
            self::assertSame(in_array($semantic, ['room', 'wall', 'opening'], true) ? $semantic : 'quantity', $created[0]->type);
            $facts = array_values(array_filter($models->facts, static fn ($fact): bool => $fact->entityId === $created[0]->id));
            self::assertNotEmpty($facts);
            self::assertSame($unit, $facts[0]->unit);
        }
    }

    public function test_entity_key_boundaries_are_not_erased_when_matching_evidence(): void
    {
        self::assertFalse((new ClaimSemanticMatcher)->equivalent(
            $this->claim('literal:1', 'room:a:b', 'width', '3', 'm'),
            $this->claim('construction:1', 'room:ab', 'width', '3', 'm'),
        ));
    }

    public function test_equal_room_names_on_different_floors_remain_separate_entities_and_facts(): void
    {
        $claims = [$this->claim('literal:1', 'room:kitchen', 'area', '12', 'm2', true, 'floor:1'),
            $this->claim('literal:2', 'room:kitchen', 'area', '12', 'm2', true, 'floor:2')];
        $intents = array_map(static fn ($claim): array => ['claim_id' => $claim->id, 'status' => 'accepted',
            'supporting_claim_ids' => [$claim->id], 'evidence_refs' => [$claim->evidenceRef], 'reason' => 'Размер из источника'], $claims);
        $intents[0]['supporting_claim_ids'] = array_column($claims, 'id');
        $decisions = (new ArbitrationIntentIngestor)->ingest($intents, $claims)->accepted;
        self::assertCount(1, $decisions[0]->supportingClaimIds);
        $reduced = (new \App\BusinessModules\Addons\EstimateGeneration\Analysis\Arbitration\CanonicalFactReducer)->reduce($claims, $decisions);
        self::assertCount(2, $reduced);
        $models = new InMemoryProjectModelRepository;
        (new ProjectModelEvidenceWriter($models, new InMemoryEvidenceRepository))->writeArbitration($claims, $reduced, 13, 1);
        self::assertCount(2, $models->entities);
        self::assertCount(2, $models->facts);
        $floors = array_values(array_unique(array_map(static fn ($entity): string => $entity->attributes['floor_id'], $models->entities)));
        sort($floors);
        self::assertSame(['floor:1', 'floor:2'], $floors);
    }

    private function intent(): array
    {
        return ['claim_id' => 'literal:1', 'status' => 'accepted',
            'supporting_claim_ids' => ['construction:1'], 'evidence_refs' => ['construction:source'],
            'reason' => 'Проверено по указанному размеру'];
    }

    private function claim(string $id, string $entity, string $parameter, string $value, string $unit, bool $explicit = true, string $floor = 'floor:1'): ObservationClaim
    {
        $role = str_starts_with($id, 'literal:') ? 'literal' : 'construction';

        return new ObservationClaim($id, 'observer_'.$role, $entity, $parameter,
            ['type' => 'number', 'data' => $value], $unit, $role.':source', $explicit,
            7, 9, 11, 'sha256:'.str_repeat('a', 64), ['page' => 1, 'floor_id' => $floor]);
    }
}
