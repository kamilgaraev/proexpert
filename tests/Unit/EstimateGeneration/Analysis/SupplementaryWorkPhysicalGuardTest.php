<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Analysis;

use App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\EstimateCompositionProjector;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Composition\SupplementaryWorkPhysicalGuard;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\UniversalEvaluationBuilder;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Entity;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelSnapshot;
use PHPUnit\Framework\TestCase;

final class SupplementaryWorkPhysicalGuardTest extends TestCase
{
    public function test_all_original_estimates_cover_supplementary_intents_without_changing_existing_work(): void
    {
        $original = ['key' => 'baseline:floor', 'metadata' => ['scope_entity_id' => 'room:a', 'composition_work_key' => 'finish.floor']];
        $estimates = [['sections' => [['work_items' => []]]], ['sections' => [['work_items' => [$original]]]]];
        $existing = ['kind' => 'existing', 'candidate_id' => 'existing:floor'];
        $supplementary = $this->intent('duplicate', 'room:a');
        $composition = ['intents' => [$existing, $supplementary], 'quarantined' => [['reason' => 'older_notice']]];
        $result = (new SupplementaryWorkPhysicalGuard)->filter($estimates, $composition, [], $this->facts());

        self::assertSame([$existing], $result['intents']);
        self::assertSame('older_notice', $result['quarantined'][0]['reason']);
        self::assertSame('estimate_composer_physical_duplicate_covered', $result['quarantined'][1]['reason']);
        self::assertSame('baseline:floor', $result['quarantined'][1]['covered_by_position_key']);
        self::assertSame(['fact:a'], $result['quarantined'][1]['source_fact_ids']);
        self::assertSame($original, $estimates[1]['sections'][0]['work_items'][0]);
    }

    public function test_global_supplementary_identity_keeps_other_rooms_technologies_and_unknown_entities(): void
    {
        $first = $this->intent('first', 'room:a');
        $duplicate = $this->intent('later-batch', 'room:a');
        $otherRoom = $this->intent('other-room', 'room:b');
        $unknown = $this->intent('unknown', null);
        $otherTechnology = [...$this->intent('other-technology', 'room:a'), 'technology_package_candidate' => 'technology:2'];
        $composition = ['intents' => [$first, $otherRoom, $unknown, $otherTechnology, $duplicate], 'quarantined' => []];
        $result = (new SupplementaryWorkPhysicalGuard)->filter([], $composition, [], $this->facts());

        self::assertSame([$first, $otherRoom, $otherTechnology], $result['intents']);
        self::assertCount(2, $result['quarantined']);
        self::assertSame('estimate_composer_supplementary_duplicate', $result['quarantined'][0]['reason']);
        self::assertSame('first', $result['quarantined'][1]['covered_by_candidate_id']);
    }

    public function test_different_rooms_keep_unique_stable_row_keys_and_their_canonical_work(): void
    {
        $intents = ['room-a' => $this->intent('room-a', 'room:a'), 'room-b' => $this->intent('room-b', 'room:b')];
        $projector = new EstimateCompositionProjector;
        $keys = new \ReflectionMethod($projector, 'supplementaryPositionKeys');
        $projection = new \ReflectionMethod($projector, 'supplementaryItems');
        $items = $projection->invoke($projector, $intents, [], $this->facts(), $keys->invoke($projector, [], $intents));
        self::assertCount(2, $items);
        self::assertCount(2, array_unique(array_column($items, 'key')));
        self::assertSame(['finish.floor', 'finish.floor'], array_column($items, 'work_key'));
        self::assertSame(['room:a', 'room:b'], array_column(array_column($items, 'metadata'), 'scope_entity_id'));
        self::assertSame(['finish.floor', 'finish.floor'], array_column(array_column($items, 'metadata'), 'composition_work_key'));
        $reversedIntents = array_reverse($intents, true);
        $reversed = $projection->invoke($projector, $reversedIntents, [], $this->facts(), $keys->invoke($projector, [], $reversedIntents));
        self::assertEqualsCanonicalizing(array_column($items, 'key'), array_column($reversed, 'key'));
        $entities = array_map(static fn (string $id): Entity => new Entity($id, 1, 2, 3, 'sha256:'.str_repeat('a', 64), 'room', $id), ['room:a', 'room:b']);
        $evaluation = (new UniversalEvaluationBuilder)->build(['evaluation_policy' => ['selected_sections' => ['finishing']],
            'local_estimates' => [['key' => 'finishing', 'sections' => [['work_items' => $items]]]]], new ProjectModelSnapshot($entities, [], [], []));
        self::assertCount(2, $evaluation['scenarios'][0]['positions']);
        self::assertSame(array_column($items, 'key'), array_column($evaluation['scenarios'][0]['positions'], 'key'));
        self::assertFalse($evaluation['can_confirm_for_apply']);
    }

    public function test_a_confirmed_other_room_survives_a_raw_work_key_collision_with_an_original_row(): void
    {
        $baseline = ['key' => 'finish.floor', 'metadata' => ['scope_entity_id' => 'room:a', 'composition_work_key' => 'finish.floor']];
        $estimates = [['sections' => [['work_items' => [$baseline]]]]];
        $intent = $this->intent('room-b-floor', 'room:b');
        $result = (new SupplementaryWorkPhysicalGuard)->filter($estimates, ['intents' => [$intent], 'quarantined' => []], [], $this->facts());
        self::assertSame([$intent], $result['intents']);
        self::assertSame([], $result['quarantined']);
        $projector = new EstimateCompositionProjector;
        $keys = (new \ReflectionMethod($projector, 'supplementaryPositionKeys'))->invoke($projector, $estimates, ['room-b-floor' => $intent]);
        self::assertNotSame($baseline['key'], $keys['room-b-floor']);
    }

    public function test_only_unambiguous_current_entity_and_canonical_work_can_prove_a_duplicate(): void
    {
        $supplementary = $this->intent('floor', 'room:a');
        $metadata = ['composition_work_key' => 'finish.floor'];
        $basis = ['formula_inputs' => ['entity_id' => 'room:a']];
        $cases = [
            [['metadata' => $metadata, 'quantity_basis_details' => $basis], true],
            [['metadata' => $metadata, 'quantity_evidence' => $basis], true],
            [['metadata' => $metadata, 'quantity_evidence' => ['formula_inputs' => ['entity_id' => 'room:b']], 'quantity_basis_details' => $basis], false],
            [['metadata' => $metadata], false],
            [['metadata' => [...$metadata, 'scope_entity_id' => 'room:unknown']], false],
            [['metadata' => [...$metadata, 'scope_entity_id' => 'room:b'], 'quantity_basis_details' => $basis], false],
            [['metadata' => [...$metadata, 'scope_entity_id' => 'room:a', 'material_scenario_work_key' => 'another.layer']], false],
            [['metadata' => ['scope_entity_id' => 'room:a']], false],
            [['metadata' => [...$metadata, 'scope_entity_id' => 'room:a', 'technology_formula_id' => 'formula:2']], false],
        ];
        foreach ($cases as [$item, $covered]) {
            $item['key'] = 'baseline';
            $result = (new SupplementaryWorkPhysicalGuard)->filter([['sections' => [['work_items' => [$item]]]]],
                ['intents' => [$supplementary], 'quarantined' => []], [], $this->facts());
            self::assertCount($covered ? 0 : 1, $result['intents']);
            self::assertCount($covered ? 1 : 0, $result['quarantined']);
        }
    }

    private function intent(string $id, ?string $entity): array
    {
        return ['kind' => 'supplementary', 'candidate_id' => $id, 'work_key' => 'finish.floor', 'name' => 'Отделка пола',
            'entity_id' => $entity, 'derived_quantity_id' => null, 'source_fact_ids' => [$entity === 'room:b' ? 'fact:b' : 'fact:a'],
            'technology_package_candidate' => null, 'unit' => 'm2', 'work_section' => 'finishing',
            'assumptions' => [], 'exclusions' => [], 'missing_document_recommendations' => []];
    }

    private function facts(): array
    {
        return [['id' => 'fact:a', 'entity_id' => 'room:a'], ['id' => 'fact:b', 'entity_id' => 'room:b']];
    }
}
