<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\UniversalEvaluationBuilder;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Entity;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Evidence;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelSnapshot;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\DTO\AcceptedNormativeDecisionData;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\DTO\NormativeCandidateData;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\DTO\NormativeCandidateSetData;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\DTO\NormativeWorkflowResultData;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\EstimateNormativeMatcher;
use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\NormativeCandidatePresenter;
use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\NormativeMatchDecisionService;
use App\BusinessModules\Addons\EstimateGeneration\Services\ResourceAssemblyService;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class EvaluationWorkContextTest extends TestCase
{
    public function test_existing_composer_context_is_visible_without_confirming_scope_quantity_or_completeness(): void
    {
        [$draft, $snapshot] = $this->fixture();
        $before = $draft;
        $result = (new UniversalEvaluationBuilder)->build($draft, $snapshot);
        $position = $result['scenarios'][0]['positions'][0];
        self::assertSame(['status' => 'proposed', 'entity_id' => 'room:1', 'entity_type' => 'room',
            'entity_name' => 'Кухня', 'section' => 'finishing', 'basis_fact_ids' => ['fact:identity']], $position['proposed_entity_context'] ?? null);
        self::assertNull($position['entity_id']);
        self::assertNull($position['quantity']);
        self::assertNull($position['price_snapshot']);
        self::assertNull($position['total_cost']);
        self::assertFalse($result['can_confirm_for_apply']);
        self::assertSame($before, $draft);
        self::assertArrayNotHasKey('scope_entity_id', $draft['local_estimates'][0]['sections'][0]['work_items'][0]['metadata'] ?? []);
    }

    public function test_inapplicable_ambiguous_missing_or_unconfirmed_context_never_becomes_a_proposal(): void
    {
        [$original, $snapshot] = $this->fixture();
        $variants = [];
        $draft = $original;
        $draft['local_estimates'][0]['sections'][0]['work_items'][0]['composition_intent']['source_fact_ids'] = ['fact:missing'];
        $variants[] = [$draft, $snapshot];
        $draft = $original;
        $draft['local_estimates'][0]['sections'][0]['work_items'][0]['composition_intent']['candidate_id'] = 'work:wrong';
        $variants[] = [$draft, $snapshot];
        $draft = $original;
        $draft['local_estimates'][0]['sections'][0]['work_items'][0]['work_key'] = 'unregistered.custom';
        $variants[] = [$draft, $snapshot];
        $draft = $original;
        unset($draft['local_estimates'][0]['sections'][0]['work_items'][0]['composition_intent']);
        $variants[] = [$draft, $snapshot];
        foreach (['scope_entity_id', 'entity_id'] as $field) {
            $draft = $original;
            $draft['local_estimates'][0]['sections'][0]['work_items'][0]['metadata'][$field] = 'room:removed';
            $variants[] = [$draft, $snapshot];
        }
        $draft = $original;
        $draft['local_estimates'][0]['sections'][0]['work_items'][0]['work_key'] = 'heating.pipe';
        $variants[] = [$draft, $snapshot];
        foreach (['candidate', 'conflicted', 'invalidated'] as $status) {
            $fact = $snapshot->facts[0];
            $changed = new Fact($fact->id, 1, 2, 3, $fact->sourceVersion, $fact->entityId, $fact->type,
                $fact->value, null, 0, 'document', $status, $fact->evidenceIds);
            $variants[] = [$original, new ProjectModelSnapshot($snapshot->entities, [$changed], $snapshot->evidence, [])];
        }
        $variants[] = [$original, new ProjectModelSnapshot($snapshot->entities, $snapshot->facts, [], [])];
        $variants[] = [$original, new ProjectModelSnapshot([], $snapshot->facts, $snapshot->evidence, [])];
        $proof = $snapshot->evidence[0];
        $changed = new Evidence($proof->id, 1, 2, 3, 'sha256:'.str_repeat('b', 64), 'document:42', 'document', 2, null, null, 'native_parser', 'document:42');
        $variants[] = [$original, new ProjectModelSnapshot($snapshot->entities, $snapshot->facts, [$changed], [])];
        $other = new Entity('room:2', 1, 2, 3, $proof->sourceVersion, 'room', 'room:2');
        $fact = new Fact('fact:other', 1, 2, 3, $proof->sourceVersion, $other->id, 'entity_identity', ['name' => 'Вторая кухня'], null, 0, 'document', 'confirmed', [$proof->id]);
        $draft = $original;
        $draft['local_estimates'][0]['sections'][0]['work_items'][0]['composition_intent']['source_fact_ids'][] = $fact->id;
        $variants[] = [$draft, new ProjectModelSnapshot([...$snapshot->entities, $other], [...$snapshot->facts, $fact], $snapshot->evidence, [])];
        foreach ($variants as [$draft, $model]) {
            $result = (new UniversalEvaluationBuilder)->build($draft, $model);
            self::assertArrayNotHasKey('proposed_entity_context', $result['scenarios'][0]['positions'][0]);
            self::assertNull($result['scenarios'][0]['positions'][0]['entity_id']);
        }
    }

    public function test_current_accepted_entity_suppresses_proposal_and_retains_existing_scope(): void
    {
        [$draft, $snapshot] = $this->fixture();
        $draft['local_estimates'][0]['sections'][0]['work_items'][0]['metadata']['scope_entity_id'] = 'room:1';
        $result = (new UniversalEvaluationBuilder)->build($draft, $snapshot);
        self::assertSame('room:1', $result['scenarios'][0]['positions'][0]['entity_id']);
        self::assertArrayNotHasKey('proposed_entity_context', $result['scenarios'][0]['positions'][0]);
    }

    public function test_manual_name_is_read_from_the_confirmed_identity_fact(): void
    {
        [$draft, $snapshot] = $this->manualFixture('room', 'finish.floor', 'finishing');
        $result = (new UniversalEvaluationBuilder)->build($draft, $snapshot);
        self::assertSame('Кухня', $result['scenarios'][0]['positions'][0]['proposed_entity_context']['entity_name']);
    }

    public function test_semantic_roof_type_is_preserved_instead_of_its_storage_type(): void
    {
        [$draft, $snapshot] = $this->manualFixture('roof', 'roof_covering', 'roofing');
        $result = (new UniversalEvaluationBuilder)->build($draft, $snapshot);
        self::assertSame('roof', $result['scenarios'][0]['positions'][0]['proposed_entity_context']['entity_type']);
    }

    public function test_conflicting_identity_names_are_not_selected_arbitrarily(): void
    {
        [$draft, $snapshot] = $this->manualFixture('room', 'finish.floor', 'finishing');
        $fact = $snapshot->facts[0];
        $other = new Fact('fact:other-name', 1, 2, 3, $fact->sourceVersion, $fact->entityId, 'entity_identity',
            [...$fact->value, 'name' => 'Спальня'], null, 0, 'user_input', 'confirmed', $fact->evidenceIds);
        $draft['local_estimates'][0]['sections'][0]['work_items'][0]['composition_intent']['source_fact_ids'][] = $other->id;
        $result = (new UniversalEvaluationBuilder)->build($draft,
            new ProjectModelSnapshot($snapshot->entities, [$fact, $other], $snapshot->evidence, []));
        self::assertNull($result['scenarios'][0]['positions'][0]['proposed_entity_context']['entity_name']);
    }

    public function test_legacy_planner_work_key_uses_canonical_metadata_only_for_proposed_context(): void
    {
        [$draft, $snapshot] = $this->legacyPlannerFixture();
        $before = hash('sha256', json_encode($draft, JSON_THROW_ON_ERROR));
        $result = (new UniversalEvaluationBuilder)->build($draft, $snapshot);
        $position = $result['scenarios'][0]['positions'][0];
        self::assertSame('room:1', $position['proposed_entity_context']['entity_id'] ?? null);
        self::assertSame('proposed', $position['proposed_entity_context']['status']);
        self::assertSame('Кухня', $position['proposed_entity_context']['entity_name']);
        self::assertSame('finishing-norm-intent-1', $position['key']);
        self::assertSame('finishing-norm-intent-1', $position['work_key']);
        self::assertNull($position['entity_id']);
        self::assertNull($position['quantity']);
        self::assertNull($position['price_snapshot']);
        self::assertNull($position['total_cost']);
        self::assertFalse($result['can_confirm_for_apply']);
        self::assertSame($before, hash('sha256', json_encode($draft, JSON_THROW_ON_ERROR)));
    }

    public function test_normative_enrichment_preserves_legacy_planner_context(): void
    {
        foreach (['normative_intent_catalog', 'scope_inference'] as $source) {
            [$draft, $snapshot] = $this->legacyPlannerFixture();
            $item = &$draft['local_estimates'][0]['sections'][0]['work_items'][0];
            $item['metadata']['generation_source'] = $source;
            $item['quantity'] = '20';
            $item = $this->assembleLegacyItem($item);
            self::assertSame('fsnb_norm', $item['metadata']['composition_source']);
            self::assertSame($source, $item['metadata']['generation_source']);
            self::assertSame('finish.floor', $item['metadata']['composition_work_key']);
            self::assertSame('matched', $item['normative_match']['status']);
            $before = $draft;
            $result = (new UniversalEvaluationBuilder)->build($draft, $snapshot);
            $position = $result['scenarios'][0]['positions'][0];
            self::assertSame('room:1', $position['proposed_entity_context']['entity_id'] ?? null, $source);
            self::assertNull($position['entity_id']);
            self::assertSame('20', $position['quantity']);
            self::assertSame('unknown', $position['quantity_status']);
            self::assertNull($position['price_snapshot']);
            self::assertNull($position['total_cost']);
            self::assertFalse($result['can_confirm_for_apply']);
            self::assertSame($before, $draft);
            unset($item);
        }
    }

    public function test_legacy_metadata_never_overrides_explicit_work_key_or_unknown_source_contract(): void
    {
        [$original, $snapshot] = $this->legacyPlannerFixture();
        $variants = [
            ['work_key' => null], ['work_key' => ''], ['work_key' => 'unregistered.custom'], ['work_key' => 'heating.pipe'],
            ['metadata' => ['composition_work_key' => 'finish.floor']],
            ['metadata' => ['composition_source' => 'scope_inference', 'composition_work_key' => 'finish.floor']],
            ['metadata' => ['composition_source' => 'planner_intent', 'composition_work_key' => 'unregistered.custom']],
            ['metadata' => ['composition_source' => 'planner_intent', 'composition_work_key' => 42]],
            ['metadata' => ['composition_source' => 'fsnb_norm', 'composition_work_key' => 'finish.floor']],
            ['metadata' => ['composition_source' => 'fsnb_norm', 'composition_work_key' => 'finish.floor', 'generation_source' => 'normative_intent_catalog']],
            ['metadata' => ['composition_source' => 'fsnb_norm', 'composition_work_key' => 'finish.floor', 'generation_source' => 'normative_intent_catalog'], 'normative_match' => ['status' => 'rejected']],
            ['metadata' => ['composition_source' => 'fsnb_norm', 'composition_work_key' => 'finish.floor', 'generation_source' => 'project_document_normative_reference'], 'normative_match' => ['status' => 'matched']],
        ];
        foreach ($variants as $override) {
            $draft = $original;
            $draft['local_estimates'][0]['sections'][0]['work_items'][0] = array_replace(
                $draft['local_estimates'][0]['sections'][0]['work_items'][0], $override);
            $result = (new UniversalEvaluationBuilder)->build($draft, $snapshot);
            $position = $result['scenarios'][0]['positions'][0];
            self::assertArrayNotHasKey('proposed_entity_context', $position);
            self::assertNull($position['entity_id']);
            self::assertNull($position['quantity']);
            self::assertNull($position['price_snapshot']);
            self::assertFalse($result['can_confirm_for_apply']);
        }
    }

    private function assembleLegacyItem(array $item): array
    {
        $candidate = new NormativeCandidateData(
            'candidate-101', 101, 77, 'fsnb-2026.1', 'parsed', '10-01-001-01', 'Чистовое покрытие пола',
            'm2', 'area', 'tile', 'flooring', 'floor', '10-01', 'residential', '77',
            new DateTimeImmutable('2026-01-01'), null, 0.91, null, 'lexical-v1', null, ['evidence:norm-101'],
        );
        $workflow = new NormativeWorkflowResultData('retrieval_only', new NormativeCandidateSetData(
            1, 2, 3, $item['key'], 'fsnb-2026.1', 'lexical-v1', null, [$candidate],
        ), null, []);
        $decision = AcceptedNormativeDecisionData::fromWorkflowResult($workflow, [
            'candidate_id' => 'candidate-101', 'normative_id' => 101, 'dataset_id' => 77,
            'dataset_version' => 'fsnb-2026.1', 'dataset_status' => 'parsed', 'code' => '10-01-001-01',
            'name' => 'Чистовое покрытие пола', 'unit' => 'm2',
            'collection' => ['code' => 'gesn', 'name' => 'ГЭСН', 'norm_type' => 'gesn'],
            'section' => ['code' => '10-01', 'name' => 'Полы'], 'work_composition' => ['Монтаж покрытия'],
            'resources' => ['materials' => [[
                'code' => '01.7.01', 'name' => 'Покрытие пола', 'unit' => 'm2', 'quantity' => 1,
                'price_id' => 9001, 'price_source' => 'fsbc', 'linked_resource_id' => 501, 'price_unit' => 'm2',
            ]], 'labor' => [], 'machinery' => [], 'other' => []],
        ]);
        $service = new ResourceAssemblyService($this->createMock(EstimateNormativeMatcher::class),
            new NormativeMatchDecisionService, new NormativeCandidatePresenter);

        return $service->assembleFromDecision($item, $decision, [
            'dataset_id' => 77, 'dataset_version' => 'fsnb-2026.1', 'region_id' => 77,
            'price_zone_id' => 1, 'period_id' => 202606, 'price_version' => 'prices-2026.06',
        ]);
    }

    private function legacyPlannerFixture(): array
    {
        [$draft, $snapshot] = $this->fixture();
        $item = &$draft['local_estimates'][0]['sections'][0]['work_items'][0];
        $item['key'] = 'finishing-norm-intent-1';
        $item['composition_intent']['candidate_id'] = 'work:'.hash('sha256', "finishing\0finish\0finishing-norm-intent-1");
        $item['metadata'] = ['composition_source' => 'planner_intent', 'composition_work_key' => 'finish.floor'];

        return [$draft, $snapshot];
    }

    private function manualFixture(string $type, string $work, string $section): array
    {
        [$draft, $snapshot] = $this->fixture();
        $version = $snapshot->facts[0]->sourceVersion;
        $entity = new Entity('manual:1', 1, 2, 3, $version, $type === 'room' ? 'room' : 'quantity', 'manual:1',
            ['semantic_type' => $type, 'identity' => ['manual_key' => 'first']]);
        $proof = new Evidence('evidence:manual', 1, 2, 3, $version, 'input:3', 'user_input', null, null,
            'input_payload.manual_facts', 'user_input_normalizer');
        $fact = new Fact('fact:manual', 1, 2, 3, $version, $entity->id, 'entity_identity',
            ['answer_kind' => 'entity_identity', 'entity_id' => $entity->id, 'entity_type' => $type, 'name' => 'Кухня', 'identity_confirmed' => true],
            null, 0, 'user_input', 'confirmed', [$proof->id]);
        $item = &$draft['local_estimates'][0]['sections'][0]['work_items'][0];
        $item['key'] = $work;
        $item['composition_intent']['source_fact_ids'] = [$fact->id];
        $item['composition_intent']['candidate_id'] = 'work:'.hash('sha256', $section."\0finish\0".$work);
        $draft['local_estimates'][0]['key'] = $section;
        $draft['evaluation_policy']['selected_sections'] = [$section];

        return [$draft, new ProjectModelSnapshot([$entity], [$fact], [$proof], [])];
    }

    private function fixture(): array
    {
        $version = 'sha256:'.str_repeat('a', 64);
        $entity = new Entity('room:1', 1, 2, 3, $version, 'room', 'room:1', ['name' => 'Кухня']);
        $proof = new Evidence('evidence:identity', 1, 2, 3, $version, 'document:42', 'document', 2, null, null, 'native_parser', 'document:42');
        $fact = new Fact('fact:identity', 1, 2, 3, $version, $entity->id, 'entity_identity', ['name' => 'Кухня'], null, 0, 'document', 'confirmed', [$proof->id]);
        $item = ['key' => 'finish.floor', 'name' => 'Чистовое покрытие пола', 'unit' => 'm2', 'quantity' => null,
            'composition_intent' => ['kind' => 'existing', 'candidate_id' => 'work:'.hash('sha256', "finishing\0finish\0finish.floor"),
                'source_fact_ids' => [$fact->id]]];
        $draft = ['evaluation_policy' => ['selected_sections' => ['finishing']],
            'local_estimates' => [['key' => 'finishing', 'sections' => [['key' => 'finish', 'work_items' => [$item]]]]]];

        return [$draft, new ProjectModelSnapshot([$entity], [$fact], [$proof], [])];
    }
}
