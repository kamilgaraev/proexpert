<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Review;

use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Conflict;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Decision;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Entity;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelSnapshot;
use App\BusinessModules\Addons\EstimateGeneration\Infrastructure\Review\PersistedTechnologyReviewSelections;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Planning\OrganizationPreferenceContext;
use App\BusinessModules\Addons\EstimateGeneration\Planning\TechnologyRecommendation;
use App\BusinessModules\Addons\EstimateGeneration\Planning\TechnologyRecommendationService;
use App\BusinessModules\Addons\EstimateGeneration\Planning\TechnologySystemCatalog;
use App\BusinessModules\Addons\EstimateGeneration\Planning\TechnologySystemOption;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PersistedTechnologyReviewSelectionsTest extends TestCase
{
    public function test_reselected_choices_read_previous_decisions_in_batches_of_at_most_one_hundred(): void
    {
        $source = 'sha256:'.str_repeat('a', 64);
        $catalog = TechnologySystemCatalog::fromArray(require dirname(__DIR__, 4).'/config/estimate-generation-technology-systems.php');
        $facts = $history = $entities = $decisions = $previousDecisions = $mirror = [];
        foreach (range(1, 101) as $index) {
            $entityId = 'entity:roof:'.$index;
            $key = 'roof_covering_system.'.substr(hash('sha256', $entityId), 0, 24);
            $entities[] = new Entity($entityId, 10, 20, 30, $source, 'material', 'roof:'.$index, []);
            $target = new Fact('fact:target:'.$index, 10, 20, 30, $source, $entityId, 'material', null, null, 0, 'unresolved', 'unresolved', []);
            $previous = new Fact('fact:previous:'.$index, 10, 20, 30, $source, $entityId, 'material',
                ['kind' => 'catalog_system', 'decision_key' => $key], null, 1, 'user_assumption', 'confirmed', [], 2, $target->id);
            $oldDecision = new Decision('decision:previous:'.$index, 10, 20, 30, $source, 'fact', $target->id, $previous->id, 'user', '7', 'Первый выбор', 2);
            $previousDecisions[$oldDecision->id] = $oldDecision;
            $choice = new Fact('fact:choice:'.$index, 10, 20, 30, $source, $entityId, 'material',
                ['kind' => 'catalog_system', 'system_id' => 'pitched_roof.metal_tile', 'decision_key' => $key,
                    'catalog_version' => $catalog->version, 'catalog_hash' => $catalog->contentHash,
                    'previous_fact_id' => $previous->id, 'previous_decision_id' => $oldDecision->id],
                null, 1, 'user_assumption', 'confirmed', [], 3, $previous->id);
            $facts[] = $choice;
            foreach ([['roof_type', 'pitched', null], ['roof_slope_degrees', '28', 'degree'],
                ['roof_geometry', 'simple_gable', null], ['roof_area', '120', 'm2']] as [$type, $value, $unit]) {
                $facts[] = new Fact('fact:'.$type.':'.$index, 10, 20, 30, $source, $entityId, $type, $value, $unit, 1, 'user_assumption', 'confirmed', []);
            }
            array_push($history, $target, $previous);
            $decisions[] = new Decision('decision:choice:'.$index, 10, 20, 30, $source, 'fact', $target->id, $choice->id, 'user', '7', 'Новый выбор', 3);
            $mirror[$entityId] = 'pitched_roof.metal_tile';
        }
        $models = $this->createMock(ProjectModelRepository::class);
        $models->expects(self::once())->method('decisionsForSelectedFacts')->willReturn($decisions);
        $models->expects(self::once())->method('factsByIds')->willReturn($history);
        $batches = [];
        $models->expects(self::exactly(2))->method('decisions')->willReturnCallback(
            static function (int $organizationId, int $projectId, int $sessionId, array $ids) use (&$batches, $previousDecisions): array {
                if (count($ids) > 100) {
                    throw new \InvalidArgumentException('Decision read batch exceeds its limit.');
                }
                self::assertSame([10, 20, 30], [$organizationId, $projectId, $sessionId]);
                $batches[] = count($ids);

                return array_values(array_intersect_key($previousDecisions, array_flip($ids)));
            });
        $session = (new EstimateGenerationSession)->forceFill(['id' => 30, 'organization_id' => 10, 'project_id' => 20,
            'input_payload' => ['technology_selections' => ['roofing' => $mirror]]]);
        $service = new PersistedTechnologyReviewSelections($models, $catalog, new TechnologyRecommendationService($catalog, static fn (string $key): string => $key));
        $result = $service->current($session, new ProjectModelSnapshot($entities, $facts, [], []), 1001);
        self::assertSame([100, 1], $batches);
        self::assertFalse($result['truncated']);
        self::assertCount(101, $result['items']);
        self::assertSame(['selected'], array_values(array_unique(array_column($result['items'], 'selection_state'))));
    }

    public function test_in_memory_fact_batch_keeps_history_and_scope_without_changing_the_current_projection(): void
    {
        $source = 'sha256:'.str_repeat('a', 64);
        $target = new Fact('fact:target', 10, 20, 30, $source, 'entity:roof', 'material', null, null, 0, 'unresolved', 'unresolved', []);
        $choice = new Fact('fact:choice', 10, 20, 30, $source, 'entity:roof', 'material', 'metal', null, 1, 'user_assumption', 'confirmed', [], 2, $target->id);
        $models = new \Tests\Support\EstimateGeneration\InMemoryProjectModelRepository;
        $models->saveSourceModel([new Entity('entity:roof', 10, 20, 30, $source, 'material', 'roof:main', [])], [$target, $choice], []);
        $before = $models->currentFacts(10, 20, 30);
        self::assertSame(['fact:choice'], array_column($before, 'id'));
        $history = $models->factsByIds(10, 20, 30, ['fact:target', 'fact:choice', 'fact:choice']);
        self::assertSame(['fact:target', 'fact:choice'], array_column($history, 'id'));
        self::assertSame('fact:target', $history[1]->supersedesFactId);
        self::assertSame($before, $models->currentFacts(10, 20, 30));
        self::assertSame([], $models->factsByIds(11, 20, 30, ['fact:target', 'fact:choice']));
        self::assertSame([], $models->factsByIds(10, 21, 30, ['fact:target', 'fact:choice']));
        self::assertSame([], $models->factsByIds(10, 20, 31, ['fact:target', 'fact:choice']));
        self::assertSame([], $models->factsByIds(10, 20, 30, []));
    }

    #[DataProvider('validContexts')]
    public function test_current_choice_is_reconstructed_from_batched_historical_target_and_exact_decision(string $context): void
    {
        [$service, $session, $snapshot, $recommendations] = $this->fixture($context);
        self::assertNotContains('fact:target', array_column($snapshot->facts, 'id'));
        $result = $service->current($session, $snapshot, 100, $recommendations);
        self::assertFalse($result['truncated']);
        self::assertCount(1, $result['items']);
        self::assertSame('selected', $result['items'][0]['selection_state']);
        self::assertSame('pitched_roof.metal_tile', $result['items'][0]['selected_option']);
        self::assertSame('fact:target', $result['items'][0]['recommendation']->targetFactId);
        self::assertSame('Проверенная комплектация', $result['items'][0]['reason']);
    }

    public static function validContexts(): array
    {
        return [['valid'], ['other_source'], ['unrelated_conflict'], ['reselection']];
    }

    #[DataProvider('unverifiedContexts')]
    public function test_unverified_or_ambiguous_choices_remain_visible_without_a_selected_option(string $context): void
    {
        [$service, $session, $snapshot, $recommendations] = $this->fixture($context);
        $result = $service->current($session, $snapshot, 100, $recommendations);
        self::assertCount(1, $result['items']);
        self::assertSame('requires_review', $result['items'][0]['selection_state']);
        self::assertNull($result['items'][0]['selected_option']);
        self::assertSame($context === 'overflow', $result['truncated']);
    }

    public static function unverifiedContexts(): array
    {
        return array_map(static fn (string $context): array => [$context], [
            'missing_decision', 'duplicate_decision', 'target_type', 'target_entity', 'target_source',
            'decision_source', 'decision_scope', 'fact_status', 'fact_origin', 'catalog_version', 'catalog_hash',
            'decision_key', 'missing_mirror', 'wrong_mirror', 'missing_entity', 'unknown_system',
            'inapplicable', 'conflict', 'duplicate_choice', 'overflow',
            'current_target', 'current_source', 'current_catalog', 'current_inapplicable',
            'superseded_target',
            'reselection_missing_decision', 'reselection_target_mismatch', 'reselection_source',
            'reselection_incomplete', 'reselection_bad_id', 'reselection_supersedes', 'reselection_decision_selected',
        ]);
    }

    private function fixture(string $context): array
    {
        $source = 'sha256:'.str_repeat('a', 64);
        $otherSource = 'sha256:'.str_repeat('b', 64);
        $catalog = TechnologySystemCatalog::fromArray(require dirname(__DIR__, 4).'/config/estimate-generation-technology-systems.php');
        $key = 'roof_covering_system.'.substr(hash('sha256', 'entity:roof'), 0, 24);
        $reselection = str_starts_with($context, 'reselection');
        $value = ['kind' => 'catalog_system', 'system_id' => $context === 'unknown_system' ? 'roof.unavailable' : 'pitched_roof.metal_tile',
            'catalog_version' => $context === 'catalog_version' ? 'old' : $catalog->version,
            'catalog_hash' => $context === 'catalog_hash' ? str_repeat('0', 64) : $catalog->contentHash,
            'decision_key' => $context === 'decision_key' ? 'other.choice' : $key, 'planning_run_id' => 7];
        if ($reselection) {
            $value['previous_fact_id'] = $context === 'reselection_bad_id' ? '' : 'fact:previous';
            if ($context !== 'reselection_incomplete') {
                $value['previous_decision_id'] = 'decision:previous';
            }
        }
        $target = new Fact('fact:target', 10, 20, 30, $context === 'target_source' ? $otherSource : $source,
            $context === 'target_entity' ? 'entity:other' : 'entity:roof', 'material', null, null, 0, 'unresolved', 'unresolved', []);
        $choice = new Fact('fact:selected', 10, 20, 30, $source, 'entity:roof', 'material', $value, null, 1,
            $context === 'fact_origin' ? 'ai_inference' : 'user_assumption', $context === 'fact_status' ? 'candidate' : 'confirmed',
            $context === 'fact_origin' ? ['evidence:1'] : [], 2, $context === 'superseded_target' ? 'fact:other-target'
                : ($reselection && $context !== 'reselection_supersedes' ? 'fact:previous' : 'fact:target'));
        $facts = [$choice];
        foreach ([['type', 'roof_type', 'pitched', null], ['slope', 'roof_slope_degrees', $context === 'inapplicable' ? '2' : '28', 'degree'],
            ['geometry', 'roof_geometry', 'simple_gable', null], ['area', 'roof_area', '120', 'm2']] as [$id, $type, $number, $unit]) {
            $facts[] = new Fact('fact:'.$id, 10, 20, 30, $source, 'entity:roof', $type, $number, $unit, 1, 'user_assumption', 'confirmed', []);
        }
        $decision = new Decision('decision:choice', $context === 'decision_scope' ? 11 : 10, 20, 30,
            $context === 'decision_source' ? $otherSource : $source, $context === 'target_type' ? 'conflict' : 'fact',
            'fact:target', 'fact:selected', 'user', '7', 'Проверенная комплектация', 2);
        $decisions = $context === 'missing_decision' ? [] : [$decision];
        if ($context === 'duplicate_decision') {
            $decisions[] = new Decision('decision:duplicate', 10, 20, 30, $source, 'fact', 'fact:target', 'fact:selected', 'user', '7', 'Другой выбор', 2);
        }
        if ($context === 'duplicate_choice') {
            $facts[] = new Fact('fact:duplicate', 10, 20, 30, $source, 'entity:roof', 'material', $value, null, 1, 'user_assumption', 'confirmed', [], 2, 'fact:target');
        }
        if ($context === 'other_source') {
            $facts[] = new Fact('fact:other-document', 10, 20, 30, $otherSource, 'entity:roof', 'room_area', '20', 'm2', 1, 'user_assumption', 'confirmed', []);
        }
        $conflicts = [];
        if (in_array($context, ['conflict', 'unrelated_conflict'], true)) {
            $entity = $context === 'conflict' ? 'entity:roof' : 'entity:other';
            $a = new Fact('fact:conflict-a', 10, 20, 30, $source, $entity, 'material', 'metal', null, 1, 'user_assumption', 'confirmed', ['evidence:1']);
            $b = new Fact('fact:conflict-b', 10, 20, 30, $source, $entity, 'material', 'tile', null, 1, 'user_assumption', 'confirmed', ['evidence:2']);
            $conflicts[] = Conflict::between('conflict:material', [$a, $b], 'Материал требует проверки');
        }
        if ($context === 'overflow') {
            $facts = array_pad($facts, 10001, $facts[1]);
        }
        $entities = $context === 'missing_entity' ? [] : [new Entity('entity:roof', 10, 20, 30, $source, 'material', 'roof:main', [])];
        $snapshot = new ProjectModelSnapshot($entities, $facts, [], $conflicts);
        $models = $this->createMock(ProjectModelRepository::class);
        $models->expects($context === 'overflow' ? self::never() : self::once())->method('decisionsForSelectedFacts')->willReturn($decisions);
        $hasTargets = $context !== 'overflow' && ! in_array($context, ['missing_decision', 'target_type', 'decision_scope'], true);
        $previous = new Fact('fact:previous', 10, 20, 30, $context === 'reselection_source' ? $otherSource : $source,
            'entity:roof', 'material', ['kind' => 'catalog_system', 'decision_key' => $key], null, 1, 'user_assumption', 'confirmed', [], 2, $target->id);
        $models->expects($hasTargets ? self::once() : self::never())->method('factsByIds')->willReturn([$target, $previous]);
        $previousDecision = new Decision('decision:previous', 10, 20, 30, $source, 'fact',
            $context === 'reselection_target_mismatch' ? 'fact:wrong-target' : $target->id,
            $context === 'reselection_decision_selected' ? 'fact:wrong-selection' : $previous->id, 'user', '7', 'Предыдущий выбор', 2);
        $hasPrevious = $reselection && ! in_array($context, ['reselection_incomplete', 'reselection_bad_id'], true);
        $models->expects($hasPrevious ? self::once() : self::never())->method('decisions')
            ->willReturn($context === 'reselection_missing_decision' ? [] : [$previousDecision]);
        $session = (new EstimateGenerationSession)->forceFill(['id' => 30, 'organization_id' => 10, 'project_id' => 20,
            'input_payload' => ['technology_selections' => ['roofing' => ['entity:roof' => $context === 'wrong_mirror' ? 'roof.other' : $value['system_id']]]]]);
        if ($context === 'missing_mirror') {
            $session->input_payload = [];
        }

        $factory = new TechnologyRecommendationService($catalog, static fn (string $key): string => $key);
        $recommendations = [];
        if (str_starts_with($context, 'current_')) {
            $current = $factory->recommend($snapshot, $target, new OrganizationPreferenceContext(10, []));
            $options = $current->options;
            if ($context === 'current_inapplicable') {
                $options = array_map(static fn (TechnologySystemOption $option): TechnologySystemOption => new TechnologySystemOption(
                    $option->system, $option->score, $option->scoreContributions, false, $option->label, $option->explanation,
                    'unavailable', $option->applicabilityReasons, $option->applicabilityEvidence), $options);
            }
            $recommendations[] = new TechnologyRecommendation($key, $context === 'current_target' ? 'fact:another-target' : $target->id,
                10, 20, 30, $context === 'current_source' ? $otherSource : $source,
                $context === 'current_catalog' ? 'old' : $catalog->version, $catalog->contentHash,
                $options, $current->responseOptions, $current->question, $current->conditional, $current->missingFacts);
        }

        return [new PersistedTechnologyReviewSelections($models, $catalog, $factory), $session, $snapshot, $recommendations];
    }
}
