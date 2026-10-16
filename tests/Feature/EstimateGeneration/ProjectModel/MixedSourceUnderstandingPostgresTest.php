<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration\ProjectModel;

use App\BusinessModules\Addons\EstimateGeneration\Application\Understanding\CrossDocumentFactLinker;
use App\BusinessModules\Addons\EstimateGeneration\Application\Understanding\TargetedConflictResolver;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Conflict;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Decision;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Entity;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Evidence;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EloquentEvidenceRepository;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceData;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceSourceType;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceType;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\Support\EstimateGeneration\EstimateGenerationCanonicalPostgresTestCase;

final class MixedSourceUnderstandingPostgresTest extends EstimateGenerationCanonicalPostgresTestCase
{
    public function test_mixed_document_links_round_trip_and_replay_with_original_endpoint_versions(): void
    {
        [$models, $scope, , $facts] = $this->fixture('18.4');
        $run = $this->linker()->link(...array_values($this->linkInputs($models, $scope)));
        self::assertCount(1, $run->links);
        $version = $run->links[0]['source_version'];
        $capture = $models->snapshotForUnderstanding(...[...$scope, 100]);
        $fingerprint = hash('sha256', 'mixed-source-links');
        self::assertTrue($models->replaceUnderstanding(...[...$scope, $version, $fingerprint, $capture['token'],
            $run->links, [], [], [], 0]));
        self::assertEquals($run->links, $models->currentUnderstanding(...$scope)['links']);
        self::assertNotNull($models->replayUnderstanding(...[...$scope, $version, $fingerprint, $capture['token']]));
        $stored = DB::table('estimate_generation_project_model_cross_document_links')->where('session_id', $scope[2])->first();
        self::assertSame($facts[0]->sourceVersion, $stored->left_fact_source_version);
        self::assertSame($facts[1]->sourceVersion, $stored->right_fact_source_version);
        self::assertSame($version, $stored->source_version);
        self::assertSame($facts[0]->sourceVersion, $models->fact(...[...$scope, $facts[0]->id])->sourceVersion);
        self::assertSame($facts[1]->sourceVersion, $models->fact(...[...$scope, $facts[1]->id])->sourceVersion);
        (require app_path('BusinessModules/Addons/EstimateGeneration/migrations/2026_10_14_002200_separate_cross_document_fact_versions.php'))->up();
        self::assertEquals($run->links, $models->currentUnderstanding(...$scope)['links']);
        self::assertSame(1, DB::table('estimate_generation_project_model_cross_document_links')->where('session_id', $scope[2])->count());
    }

    public function test_mixed_source_conflicts_round_trip_and_resolve_using_the_selected_fact_version(): void
    {
        [$models, $scope, $actor, $facts] = $this->fixture('19.1');
        $run = $this->linker()->link(...array_values($this->linkInputs($models, $scope)));
        self::assertCount(1, $run->conflicts);
        $conflict = $run->conflicts[0];
        $capture = $models->snapshotForUnderstanding(...[...$scope, 100]);
        self::assertTrue($models->replaceUnderstanding(...[...$scope, $conflict->sourceVersion,
            hash('sha256', 'mixed-source-conflicts'), $capture['token'], [], $run->conflicts, $run->questions, [], 0]));
        $snapshot = $models->snapshot(...$scope);
        self::assertCount(1, $snapshot->conflicts);
        self::assertSame($conflict->sourceVersion, $snapshot->conflicts[0]->sourceVersion);
        self::assertSame(array_column($facts, 'sourceVersion'), array_column($snapshot->conflicts[0]->facts, 'sourceVersion'));
        $bindings = DB::table('estimate_generation_project_model_conflict_facts')->where('session_id', $scope[2])->orderBy('fact_id')->pluck('source_version')->all();
        self::assertSame(array_column($facts, 'sourceVersion'), $bindings);
        $draft = ['evaluation_policy' => ['selected_sections' => ['finishing']], 'local_estimates' => [['key' => 'finishing',
            'sections' => [['work_items' => [['key' => 'finish.floor', 'name' => 'Отделка пола', 'quantity' => '18.4', 'unit' => 'm2',
                'metadata' => ['scope_entity_id' => $facts[0]->entityId],
                'quantity_basis_details' => ['formula_inputs' => ['operands' => [['fact_id' => $facts[0]->id]]]],
            ]]]]]]];
        $builder = app(\App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\UniversalEvaluationBuilder::class);
        $evaluation = $builder->build($draft, $snapshot);
        self::assertContains('source_conflict', array_column($evaluation['missing_requirements'], 'requirement'));
        self::assertSame('review_source_questions', $evaluation['priority_questions'][0]['next_action']);
        self::assertSame($conflict->id, $evaluation['priority_questions'][0]['conflict_id']);
        self::assertNull($evaluation['scenarios'][0]['positions'][0]['quantity']);
        self::assertFalse($evaluation['can_confirm_for_apply']);
        $selected = $facts[1];
        $decision = new Decision('decision:cross-source-choice', ...[...$scope, $selected->sourceVersion,
            'conflict', $conflict->id, $selected->id, 'user', (string) $actor->id,
            'Пользователь выбирает значение из второго документа.', 1, $selected->evidenceIds]);
        $models->applyDecision($decision, $selected);
        self::assertSame([], $models->snapshot(...$scope)->conflicts);
        $draft['local_estimates'][0]['sections'][0]['work_items'][0]['quantity'] = null;
        $after = $builder->build($draft, $models->snapshot(...$scope));
        self::assertNotContains('source_conflict', array_column($after['missing_requirements'], 'requirement'));
        self::assertSame($selected->sourceVersion, $models->decisionsForSelectedFacts(...[...$scope, [$selected->id]])[0]->sourceVersion);
        self::assertSame(0, DB::table('estimate_generation_ai_usage')->where('session_id', $scope[2])->count());
        try {
            Conflict::between('conflict:strict-old-factory', $facts, 'incompatible');
            self::fail('The original same-source factory must remain strict.');
        } catch (InvalidArgumentException) {
            self::addToAssertionCount(1);
        }
    }

    public function test_wrong_endpoint_version_and_foreign_scope_roll_back_the_entire_understanding_write(): void
    {
        [$models, $scope] = $this->fixture('18.4');
        $run = $this->linker()->link(...array_values($this->linkInputs($models, $scope)));
        foreach (['endpoint', 'organization', 'project', 'session'] as $case) {
            $links = $run->links;
            if ($case === 'endpoint') {
                $links[0]['right_fact_source_version'] = 'sha256:'.str_repeat('d', 64);
            } else {
                $links[0][$case.'_id'] += 100000;
            }
            $capture = $models->snapshotForUnderstanding(...[...$scope, 100]);
            try {
                $models->replaceUnderstanding(...[...$scope, $run->links[0]['source_version'], hash('sha256', $case),
                    $capture['token'], $links, [], [], [], 0]);
                self::fail('An out-of-scope or wrong-version endpoint was accepted: '.$case);
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
            self::assertSame(0, DB::table('estimate_generation_project_model_cross_document_links')->where('session_id', $scope[2])->count());
            self::assertNull($models->currentUnderstanding(...$scope));
        }
    }

    public function test_understanding_retains_each_current_facts_original_entity_version(): void
    {
        [$models, $scope, , $originalFacts] = $this->fixture('18.4');
        $version = 'sha256:'.str_repeat('d', 64);
        $stored = (new EloquentEvidenceRepository(DB::connection()))->insertOrGet(new EvidenceData(...[...$scope,
            EvidenceType::Extracted, EvidenceSourceType::DocumentUnit, 'document:3', $version,
            ['document_id' => 3, 'page' => 1, 'element_key' => 'element:3'],
            ['field_key' => 'wall_height', 'field_value' => '3', 'unit' => 'm'], 1, 'native_numeric_parser', 'extractor:v1']));
        $entity = new Entity('entity:left', ...[...$scope, $version, 'room', 'entity:left',
            ['semantic_type' => 'room', 'document_role' => 'facade']]);
        $evidence = new Evidence('evidence:'.$stored->id, ...[...$scope, $version, 'document:3', 'xlsx', 1, null, 'xlsx:sheet:Rooms!C3']);
        $fact = new Fact('fact:new-version', ...[...$scope, $version, $entity->id, 'height', '3', 'm',
            0.0, 'document', 'confirmed', [$evidence->id]]);
        try {
            $models->saveSourceModel([$entity], [$fact], [$evidence]);
            self::fail('The current writer must reject changed attributes under its immutable canonical key.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('project_model_entity_exact_identity_collision', $exception->getMessage());
        }
        // Historical rows can use separate model namespaces after the 2026-08-14
        // detach migration. Insert that legal storage state with every FK/guard on;
        // do not weaken the current writer to manufacture an old-version fixture.
        $original = (array) DB::table('estimate_generation_project_model_assertions')
            ->where('organization_id', $scope[0])->where('session_id', $scope[2])
            ->where('stable_key', $originalFacts[0]->id)->where('source_version', $originalFacts[0]->sourceVersion)->first();
        $historicalEntity = (array) DB::table('estimate_generation_project_model_entities')->where('id', $original['entity_id'])->first();
        unset($historicalEntity['id']);
        $historicalScope = (int) DB::table('estimate_generation_project_model_entities')->max('building_model_id') + 1;
        $historicalEntity['building_model_id'] = $historicalScope;
        $historicalEntity['source_version'] = $version;
        $historicalEntity['payload'] = json_encode(['kind' => 'room', 'key' => $entity->id, ...$entity->attributes], JSON_THROW_ON_ERROR);
        $entityDatabaseId = DB::table('estimate_generation_project_model_entities')->insertGetId($historicalEntity);
        $historicalFact = $original;
        unset($historicalFact['id']);
        $historicalFact['building_model_id'] = $historicalScope;
        $historicalFact['entity_id'] = $entityDatabaseId;
        $historicalFact['source_version'] = $version;
        $historicalFact['stable_key'] = $fact->id;
        $historicalFact['assertion_type'] = 'height';
        $historicalPayload = json_decode($original['payload'], true, 64, JSON_THROW_ON_ERROR);
        $historicalFact['payload'] = json_encode([...$historicalPayload, 'value' => '3', 'unit' => 'm'], JSON_THROW_ON_ERROR);
        $historicalFact['fact_value'] = json_encode(['value' => '3'], JSON_THROW_ON_ERROR);
        $historicalFact['fact_unit'] = 'm';
        $factDatabaseId = DB::table('estimate_generation_project_model_assertions')->insertGetId($historicalFact);
        DB::table('estimate_generation_project_model_fact_evidence')->insert([
            'organization_id' => $scope[0], 'project_id' => $scope[1], 'session_id' => $scope[2],
            'fact_id' => $factDatabaseId, 'evidence_id' => $stored->id, 'source_version' => $version,
            'evidence_source_version' => $version,
            'evidence_invalidation_version' => DB::table('estimate_generation_evidence')->where('id', $stored->id)->value('invalidation_version'),
            'created_at' => now(),
        ]);
        $projection = (array) DB::table('estimate_generation_project_model_fact_projections')->where('fact_id', $original['id'])->first();
        unset($projection['id']);
        $projection['fact_id'] = $factDatabaseId;
        $projection['source_version'] = $version;
        $projection['fact_type'] = 'height';
        DB::table('estimate_generation_project_model_fact_projections')->insert($projection);
        $capture = $models->snapshotForUnderstanding(...[...$scope, 100, true]);
        self::assertCount(3, $capture['snapshot']->facts);
        self::assertCount(3, $capture['source_entities']);
        $legacy = array_values(array_filter($capture['snapshot']->entities, static fn (Entity $row): bool => $row->id === 'entity:left'));
        self::assertCount(1, $legacy);
        self::assertSame($version, $legacy[0]->sourceVersion);
        self::assertSame('facade', $legacy[0]->attributes['document_role']);
        $byVersion = [];
        foreach ($capture['source_entities'] as $row) {
            $byVersion[$row->id.'|'.$row->sourceVersion] = $row;
        }
        $oldEntity = $byVersion['entity:left|'.$originalFacts[0]->sourceVersion];
        self::assertSame(['semantic_type' => 'room'], $oldEntity->attributes);
        self::assertSame('facade', $byVersion['entity:left|'.$version]->attributes['document_role']);
        foreach ($capture['snapshot']->facts as $row) {
            self::assertArrayHasKey($row->entityId.'|'.$row->sourceVersion, $byVersion);
        }
        // Matching labels are still synthetic; this regression proves version lineage,
        // not production extraction of room numbers or document roles.
        $described = array_map(static fn (Entity $row): Entity => new Entity($row->id, $row->organizationId,
            $row->projectId, $row->sessionId, $row->sourceVersion, $row->type, $row->stableKey,
            ['document_role' => $row->sourceVersion === $version ? 'facade' : ($row->id === 'entity:left' ? 'plan' : 'room_schedule'),
                'room_number' => $row->sourceVersion === $version ? '999' : '101']), $capture['source_entities']);
        $linked = $this->linker()->link($described, $capture['snapshot']->facts, $capture['snapshot']->evidence);
        self::assertCount(1, $linked->links);
        self::assertSame('fact:left', $linked->links[0]['left_fact_id']);
        self::assertSame($originalFacts[0]->sourceVersion, $linked->links[0]['left_fact_source_version']);
        self::assertArrayNotHasKey('source_entities', $models->snapshotForUnderstanding(...[...$scope, 100]));
        self::assertSame([], $models->snapshotForUnderstanding($scope[0] + 99999, $scope[1], $scope[2], 100, true)['source_entities']);
    }

    private function linker(): CrossDocumentFactLinker
    {
        return new CrossDocumentFactLinker(new TargetedConflictResolver(static fn (string $key, array $replace): string => $key));
    }

    private function linkInputs(ProjectModelRepository $models, array $scope): array
    {
        $snapshot = $models->snapshot(...$scope);

        // Matching descriptors are synthetic transport inputs. Persisted entities keep
        // the production fact-based shape; this test qualifies repository round trips.
        $entities = array_map(static fn (Entity $entity): Entity => new Entity($entity->id, $entity->organizationId,
            $entity->projectId, $entity->sessionId, $entity->sourceVersion, $entity->type, $entity->stableKey,
            ['document_role' => $entity->id === 'entity:left' ? 'plan' : 'room_schedule', 'room_number' => '101']), $snapshot->entities);

        return ['entities' => $entities, 'facts' => $snapshot->facts, 'evidence' => $snapshot->evidence];
    }

    private function fixture(string $rightValue): array
    {
        $organization = Organization::factory()->create();
        $project = Project::factory()->for($organization)->create();
        $actor = User::factory()->create();
        $session = EstimateGenerationSession::query()->create(['organization_id' => $organization->id,
            'project_id' => $project->id, 'user_id' => $actor->id, 'status' => 'draft', 'processing_stage' => 'draft',
            'processing_progress' => 0, 'input_payload' => [], 'state_version' => 0]);
        $scope = [(int) $organization->id, (int) $project->id, (int) $session->id];
        $models = app(ProjectModelRepository::class);
        $facts = [];
        foreach ([['left', 'a', '18.4'], ['right', 'c', $rightValue]] as [$side, $character, $value]) {
            $version = 'sha256:'.str_repeat($character, 64);
            $artifact = 'document:'.($side === 'left' ? '1' : '2');
            $stored = (new EloquentEvidenceRepository(DB::connection()))->insertOrGet(new EvidenceData(...[...$scope,
                EvidenceType::Extracted, EvidenceSourceType::DocumentUnit, $artifact, $version,
                ['document_id' => $side === 'left' ? 1 : 2, 'page' => 1, 'element_key' => 'element:'.($side === 'left' ? '1' : '2')],
                ['field_key' => 'area', 'field_value' => $value, 'unit' => 'm2'],
                1, 'native_numeric_parser', 'extractor:v1']));
            $entity = new Entity('entity:'.$side, ...[...$scope, $version, 'room', 'entity:'.$side,
                ['semantic_type' => 'room']]);
            $evidence = new Evidence('evidence:'.$stored->id, ...[...$scope, $version, $artifact,
                'xlsx', 1, null, 'xlsx:sheet:Rooms!C2']);
            $fact = new Fact('fact:'.$side, ...[...$scope, $version, $entity->id, 'area', $value, 'm2',
                0.0, 'document', 'confirmed', [$evidence->id]]);
            $models->saveSourceModel([$entity], [$fact], [$evidence]);
            $facts[] = $fact;
        }

        return [$models, $scope, $actor, $facts];
    }
}
