<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration\Analysis;

use App\BusinessModules\Addons\EstimateGeneration\Analysis\Arbitration\ArbitrationDecision;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Arbitration\ObservationClaim;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\ProjectModelEvidenceWriter;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\EloquentProjectModelRepository;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EloquentEvidenceRepository;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\EstimateGeneration\EstimateGenerationCanonicalPostgresTestCase;

#[Group('postgres-contract')]
final class ObserverEvidenceProjectionPostgresTest extends EstimateGenerationCanonicalPostgresTestCase
{
    #[Test]
    public function photo_room_names_remain_visible_candidates_with_evidence_and_without_dimensions(): void
    {
        self::assertSame('pgsql', DB::getDriverName());
        DB::beginTransaction();
        try {
            $organization = Organization::factory()->create();
            $project = Project::factory()->for($organization)->create();
            $session = EstimateGenerationSession::query()->create([
                'organization_id' => $organization->id, 'project_id' => $project->id,
                'user_id' => User::factory()->create()->id, 'status' => 'draft',
                'processing_stage' => 'draft', 'processing_progress' => 0, 'input_payload' => [],
            ]);
            $sourceVersion = 'sha256:'.str_repeat('b', 64);
            $scope = [(int) $organization->id, (int) $project->id, (int) $session->id];
            $models = new EloquentProjectModelRepository(app('db'));
            $writer = new ProjectModelEvidenceWriter($models, new EloquentEvidenceRepository(DB::connection()));
            $claims = [];
            foreach (['first-floor', 'second-floor'] as $index => $floor) {
                $claims[] = new ObservationClaim('literal:room:'.($index + 1), 'observer_literal', 'room.kitchen', 'room_name',
                    ['type' => 'string', 'data' => 'Кухня'], null, 'literal:room:'.($index + 1), true,
                    ...[...$scope, $sourceVersion, ['page' => 1, 'unit_type' => 'raster_image', 'unit_index' => 1,
                        'source_version' => $sourceVersion, 'floor_id' => $floor,
                        'region_key' => 'region:'.str_repeat((string) ($index + 1), 64), 'bbox' => [10, 20, 30, 40]], 0.9]);
            }
            $writer->writeIndependentObservations($claims, 173, 1);
            $writer->writeIndependentObservations($claims, 173, 1);
            $writer->writeArbitration($claims, array_map(static fn (ObservationClaim $claim): ArbitrationDecision => new ArbitrationDecision(
                $claim->id, 'accepted', [$claim->id], [$claim->evidenceRef], 'fixture_label_observed', null,
            ), $claims), 173, 1);

            $snapshot = $models->snapshot(...$scope);
            self::assertCount(2, $snapshot->entities);
            self::assertCount(2, $snapshot->facts);
            self::assertSame(['room_name'], array_values(array_unique(array_map(static fn ($fact): string => $fact->type, $snapshot->facts))));
            self::assertCount(2, array_unique(array_map(static fn ($fact): string => $fact->entityId, $snapshot->facts)));
            foreach ($snapshot->facts as $fact) {
                self::assertSame('room_name', $fact->type);
                self::assertSame('Кухня', $fact->value);
                self::assertSame('candidate', $fact->status);
                self::assertNull($fact->unit);
                self::assertCount(1, $fact->evidenceIds);
            }
            foreach ($snapshot->entities as $entity) {
                self::assertSame('room', $entity->type);
                self::assertSame('Кухня', $entity->attributes['name']);
                self::assertTrue($entity->attributes['properties']['identity_only']);
                foreach (['area', 'length', 'width', 'height', 'polygon'] as $parameter) {
                    self::assertArrayNotHasKey($parameter, $entity->attributes);
                }
            }
            $evidence = DB::table('estimate_generation_evidence')->where('session_id', $scope[2])->get();
            self::assertCount(2, $evidence);
            foreach ($evidence as $row) {
                $value = json_decode($row->value, true, flags: JSON_THROW_ON_ERROR);
                $locator = json_decode($row->locator, true, flags: JSON_THROW_ON_ERROR);
                self::assertSame(['fact_key' => 'room_name', 'fact_value' => hash('sha256', 'Кухня')], $value);
                self::assertSame(1, $locator['page']);
                self::assertSame([10, 20, 30, 40], $locator['bbox']);
                self::assertSame($sourceVersion, $row->source_version);
            }
            self::assertSame(0, DB::table('estimate_generation_quantity_takeoffs')->where('session_id', $scope[2])->count());
            foreach ([['fact_key' => 'room_name', 'fact_value' => 'material:'.str_repeat('a', 64)],
                ['fact_key' => 'room_name', 'fact_value' => str_repeat('a', 64), 'unit' => 'm2']] as $invalidValue) {
                try {
                    DB::transaction(function () use ($evidence, $invalidValue): void {
                        $row = (array) $evidence[0];
                        unset($row['id']);
                        $row['value'] = json_encode($invalidValue, JSON_THROW_ON_ERROR);
                        DB::table('estimate_generation_evidence')->insert($row);
                    });
                    self::fail('SQL accepted a monetary/material or numeric room-name proof.');
                } catch (QueryException $exception) {
                    self::assertStringContainsString('room_identity_evidence_invalid', $exception->getMessage());
                }
            }
        } finally {
            DB::rollBack();
        }
    }

    #[Test]
    public function production_shaped_completed_observer_batch_is_atomic_and_keeps_only_canonical_project_model_candidates(): void
    {
        self::assertSame('pgsql', DB::getDriverName());

        DB::beginTransaction();
        try {
            $organization = Organization::factory()->create();
            $project = Project::factory()->for($organization)->create();
            $session = EstimateGenerationSession::query()->create([
                'organization_id' => $organization->id,
                'project_id' => $project->id,
                'user_id' => User::factory()->create()->id,
                'status' => 'draft',
                'processing_stage' => 'draft',
                'processing_progress' => 20,
                'input_payload' => [],
            ]);
            $sourceVersion = 'sha256:'.str_repeat('5', 64);
            $scope = [(int) $organization->id, (int) $project->id, (int) $session->id, $sourceVersion];
            $locator = [
                'page' => 1,
                'unit_type' => 'pdf_page',
                'unit_index' => 1,
                'source_version' => $sourceVersion,
                'explicit' => true,
            ];
            $claims = [
                new ObservationClaim(
                    'construction:material', 'observer_construction', 'material.1', 'material',
                    ['type' => 'string', 'data' => 'Обезличенный материал'], null, 'construction:material', true,
                    $scope[0], $scope[1], $scope[2], $scope[3], $locator,
                ),
                new ObservationClaim(
                    'literal:1', 'observer_literal', 'building.roof', 'roof_geometry',
                    ['type' => 'enum', 'data' => 'gable'], null, 'literal:roof', true,
                    $scope[0], $scope[1], $scope[2], $scope[3], $locator,
                ),
                new ObservationClaim(
                    'literal:2', 'observer_literal', 'finish.1', 'finish_zone',
                    ['type' => 'string', 'data' => 'Обезличенная зона отделки'], null, 'literal:finish-zone', true,
                    $scope[0], $scope[1], $scope[2], $scope[3], $locator,
                ),
            ];
            $writer = new ProjectModelEvidenceWriter(
                new EloquentProjectModelRepository(app('db')),
                new EloquentEvidenceRepository(DB::connection()),
            );

            $writer->writeIndependentObservations($claims, 173, 1);
            $writer->writeIndependentObservations($claims, 173, 1);

            self::assertSame(1, DB::table('estimate_generation_project_model_entities')
                ->where('organization_id', $scope[0])
                ->where('project_id', $scope[1])
                ->where('session_id', $scope[2])
                ->where('source_version', $sourceVersion)
                ->count());
            self::assertSame(1, DB::table('estimate_generation_project_model_assertions')
                ->where('organization_id', $scope[0])
                ->where('project_id', $scope[1])
                ->where('session_id', $scope[2])
                ->where('source_version', $sourceVersion)
                ->count());
            self::assertSame(1, DB::table('estimate_generation_evidence')
                ->where('organization_id', $scope[0])
                ->where('project_id', $scope[1])
                ->where('session_id', $scope[2])
                ->count());
            self::assertSame('object', DB::selectOne(
                "SELECT jsonb_typeof(payload->'properties') AS type FROM estimate_generation_project_model_entities WHERE entity_kind = 'material'",
            )->type);
        } finally {
            DB::rollBack();
        }
    }

    #[Test]
    public function candidate_replay_is_idempotent_and_distinct_observations_do_not_collide(): void
    {
        self::assertSame('pgsql', DB::getDriverName());

        $organization = Organization::factory()->create();
        $project = Project::factory()->for($organization)->create();
        $session = EstimateGenerationSession::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'user_id' => User::factory()->create()->id,
            'status' => 'draft',
            'processing_stage' => 'draft',
            'processing_progress' => 20,
            'input_payload' => [],
        ]);
        $sourceVersion = 'sha256:'.str_repeat('6', 64);
        $scope = [(int) $organization->id, (int) $project->id, (int) $session->id, $sourceVersion];
        $locator = ['page' => 1, 'unit_type' => 'pdf_page', 'unit_index' => 1, 'source_version' => $sourceVersion, 'explicit' => true];
        $writer = new ProjectModelEvidenceWriter(
            new EloquentProjectModelRepository(app('db')),
            new EloquentEvidenceRepository(DB::connection()),
        );
        $first = new ObservationClaim(
            'construction:material:first', 'observer_construction', 'material.same', 'material',
            ['type' => 'string', 'data' => 'Материал А'], null, 'construction:material:first', true,
            $scope[0], $scope[1], $scope[2], $scope[3], $locator,
        );
        $collision = new ObservationClaim(
            'construction:material:second', 'observer_construction', 'material.same', 'material',
            ['type' => 'string', 'data' => 'Материал Б'], null, 'construction:material:second', true,
            $scope[0], $scope[1], $scope[2], $scope[3], $locator,
        );

        $writer->writeIndependentObservations([$first], 173, 1);
        $writer->writeIndependentObservations([$first], 173, 1);
        $writer->writeIndependentObservations([$collision], 173, 1);

        self::assertSame(2, DB::table('estimate_generation_project_model_entities')
            ->where('organization_id', $scope[0])->where('project_id', $scope[1])
            ->where('session_id', $scope[2])->where('source_version', $sourceVersion)->count());
        self::assertSame(2, DB::table('estimate_generation_project_model_assertions')
            ->where('organization_id', $scope[0])->where('project_id', $scope[1])
            ->where('session_id', $scope[2])->where('source_version', $sourceVersion)->count());
        self::assertSame(2, DB::table('estimate_generation_evidence')
            ->where('organization_id', $scope[0])->where('project_id', $scope[1])
            ->where('session_id', $scope[2])->count());
    }

    #[Test]
    public function visual_confirmation_candidate_is_current_while_an_ordinary_candidate_remains_non_current(): void
    {
        self::assertSame('pgsql', DB::getDriverName());

        DB::beginTransaction();
        try {
            $organization = Organization::factory()->create();
            $project = Project::factory()->for($organization)->create();
            $session = EstimateGenerationSession::query()->create([
                'organization_id' => $organization->id,
                'project_id' => $project->id,
                'user_id' => User::factory()->create()->id,
                'status' => 'draft',
                'processing_stage' => 'draft',
                'processing_progress' => 20,
                'input_payload' => [],
            ]);
            $sourceVersion = 'sha256:'.str_repeat('7', 64);
            $scope = [(int) $organization->id, (int) $project->id, (int) $session->id, $sourceVersion];
            $locator = ['page' => 5, 'unit_type' => 'pdf_page', 'unit_index' => 5, 'source_version' => $sourceVersion, 'explicit' => true];
            $models = new EloquentProjectModelRepository(app('db'));
            $writer = new ProjectModelEvidenceWriter($models, new EloquentEvidenceRepository(DB::connection()));
            $writer->writeIndependentObservations([new ObservationClaim(
                'construction:toilet', 'observer_construction', 'room.bathroom.toilet', 'sanitary_fixture',
                ['type' => 'string', 'data' => 'Унитаз'], null, 'construction:toilet', true,
                $scope[0], $scope[1], $scope[2], $scope[3], $locator,
            )], 173, 5);
            $writer->writeIndependentObservations([new ObservationClaim(
                'construction:material', 'observer_construction', 'material.wall', 'material',
                ['type' => 'string', 'data' => 'Не подтверждённый материал'], null, 'construction:material', true,
                $scope[0], $scope[1], $scope[2], $scope[3], $locator,
            )], 173, 5);

            $facts = $models->currentFacts($scope[0], $scope[1], $scope[2]);

            self::assertCount(1, $facts);
            self::assertSame('sanitary_fixture', $facts[0]->type);
            self::assertSame('candidate', $facts[0]->status);
            self::assertSame(1, DB::table('estimate_generation_project_model_fact_projections')
                ->where('organization_id', $scope[0])
                ->where('project_id', $scope[1])
                ->where('session_id', $scope[2])
                ->where('is_current', true)
                ->count());
        } finally {
            DB::rollBack();
        }
    }
}
