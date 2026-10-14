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
        $selected = $facts[1];
        $decision = new Decision('decision:cross-source-choice', ...[...$scope, $selected->sourceVersion,
            'conflict', $conflict->id, $selected->id, 'user', (string) $actor->id,
            'Пользователь выбирает значение из второго документа.', 1, $selected->evidenceIds]);
        $models->applyDecision($decision, $selected);
        self::assertSame([], $models->snapshot(...$scope)->conflicts);
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
