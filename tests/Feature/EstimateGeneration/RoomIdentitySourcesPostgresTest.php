<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Analysis\Arbitration\ObservationClaim;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\ConfirmRoomIdentity;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\GetRoomIdentitySources;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\ProjectModelEvidenceWriter;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\StaleEstimateGenerationState;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceRepository;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Mockery;
use Tests\Support\EstimateGeneration\EstimateGenerationCanonicalPostgresTestCase;

final class RoomIdentitySourcesPostgresTest extends EstimateGenerationCanonicalPostgresTestCase
{
    public function test_the_same_room_label_on_separate_photographs_does_not_merge_entities(): void
    {
        [$actor, $session, $first] = $this->fixture();
        $version = 'sha256:'.str_repeat('b', 64);
        $second = EstimateGenerationDocument::query()->create(['session_id' => $session->id, 'organization_id' => $session->organization_id,
            'project_id' => $session->project_id, 'user_id' => $actor->id, 'filename' => 'another-room.jpg', 'mime_type' => 'image/jpeg',
            'status' => 'ready', 'source_version' => $version, 'checksum_sha256' => str_repeat('b', 64)]);
        $claim = new ObservationClaim('literal:room:1', 'observer_literal', 'room.kitchen', 'room_name',
            ['type' => 'string', 'data' => 'Кухня'], null, 'literal:room:1', true,
            (int) $session->organization_id, (int) $session->project_id, (int) $session->id, $version,
            ['page' => 1, 'unit_type' => 'raster_image', 'unit_index' => 1, 'floor_id' => '1',
                'region_key' => 'region:'.str_repeat('1', 64), 'bbox' => [10, 20, 30, 40]]);
        app(ProjectModelEvidenceWriter::class)->writeIndependentObservations([$claim], (int) $second->id, 1);
        $rows = app(GetRoomIdentitySources::class)->handle($actor, (int) $session->project_id, (int) $session->id)['sources'];
        self::assertCount(3, $rows);
        self::assertCount(3, array_unique(array_column($rows, 'entity_id')));
        $documentIds = array_values(array_unique(array_column(array_column($rows, 'evidence'), 'document_id')));
        sort($documentIds, SORT_NUMERIC);
        self::assertSame([(int) $first->id, (int) $second->id], $documentIds);
        self::assertSame(0, DB::table('estimate_generation_quantity_takeoffs')->where('session_id', $session->id)->count());
    }

    public function test_paginated_names_are_confirmed_on_the_same_entity_without_dimensions_or_ai_cost(): void
    {
        [$actor, $session] = $this->fixture();
        $reader = app(GetRoomIdentitySources::class);
        $before = $reader->handle($actor, (int) $session->project_id, (int) $session->id, perPage: 1);
        self::assertTrue($before['available']);
        self::assertSame(2, $before['pagination']['total']);
        self::assertSame(2, $before['pagination']['last_page']);
        self::assertCount(1, $before['sources']);
        self::assertSame('candidate', $before['sources'][0]['status']);
        self::assertSame('Кухня', $before['sources'][0]['name']);
        self::assertSame(0, $before['state_version']);
        self::assertSame('0', $before['cost_units']);
        $input = $this->input($before['sources'][0]);
        $save = app(ConfirmRoomIdentity::class);
        $result = $save->handle($actor, (int) $session->project_id, (int) $session->id, $input);
        self::assertSame(CanonicalPipelineJson::encode($result), CanonicalPipelineJson::encode($save->handle($actor, (int) $session->project_id, (int) $session->id, $input)));
        self::assertSame('0', $result['cost_units']);
        self::assertSame(1, $result['state_version']);
        self::assertTrue($result['identity_confirmed']);
        self::assertFalse($result['dimensions_confirmed']);
        self::assertFalse($result['recalculation_required']);
        self::assertSame($before['sources'][0]['entity_id'], $result['entity_id']);

        $after = $reader->handle($actor, (int) $session->project_id, (int) $session->id);
        self::assertCount(2, $after['sources']);
        self::assertSame(1, $after['state_version']);
        $confirmed = array_values(array_filter($after['sources'], static fn (array $row): bool => $row['status'] === 'confirmed'));
        self::assertCount(1, $confirmed);
        self::assertSame($result['fact_id'], $confirmed[0]['source_fact_id']);
        self::assertSame(2, $confirmed[0]['source_fact_version']);
        self::assertFalse($confirmed[0]['dimensions_confirmed']);
        self::assertSame(1, $confirmed[0]['evidence']['page']);
        self::assertSame(1, DB::table('estimate_generation_project_model_corrections')->where('session_id', $session->id)->count());
        self::assertSame(0, DB::table('estimate_generation_quantity_takeoffs')->where('session_id', $session->id)->count());
        self::assertSame(0, DB::table('ai_credit_reservations')->where('organization_id', $session->organization_id)->count());
        foreach (app(ProjectModelRepository::class)->snapshot((int) $session->organization_id, (int) $session->project_id, (int) $session->id)->facts as $fact) {
            self::assertSame('room_name', $fact->type);
            self::assertNull($fact->unit);
        }
    }

    public function test_changed_document_and_invalidated_evidence_cannot_confirm_a_name(): void
    {
        [$actor, $session, $document] = $this->fixture();
        $reader = app(GetRoomIdentitySources::class);
        $source = $reader->handle($actor, (int) $session->project_id, (int) $session->id)['sources'][0];
        $document->update(['checksum_sha256' => str_repeat('b', 64)]);
        self::assertSame([], $reader->handle($actor, (int) $session->project_id, (int) $session->id)['sources']);
        $this->assertRejected($actor, $session, $this->input($source));
        $document->update(['checksum_sha256' => str_repeat('a', 64)]);
        app(EvidenceRepository::class)->invalidate((int) $session->organization_id, (int) $session->project_id, (int) $session->id,
            [(int) substr($source['evidence']['id'], strlen('evidence:'))], 'source_changed');
        $this->assertRejected($actor, $session, $this->input($source));
        self::assertSame(0, (int) $session->fresh()->state_version);
        self::assertSame(0, DB::table('estimate_generation_project_model_corrections')->where('session_id', $session->id)->count());
    }

    public function test_wrong_hash_dimensions_and_active_processing_fail_without_state_changes(): void
    {
        [$actor, $session] = $this->fixture();
        $source = app(GetRoomIdentitySources::class)->handle($actor, (int) $session->project_id, (int) $session->id)['sources'][0];
        $input = $this->input($source);
        $this->assertRejected($actor, $session, [...$input, 'source_value_hash' => str_repeat('b', 64)]);
        $this->assertRejected($actor, $session, [...$input, 'quantity' => '20', 'unit' => 'm2']);
        $session->update(['status' => 'generating']);
        try {
            app(ConfirmRoomIdentity::class)->handle($actor, (int) $session->project_id, (int) $session->id, $input);
            self::fail('A running operation admitted identity changes.');
        } catch (StaleEstimateGenerationState) {
            self::assertSame(0, (int) $session->fresh()->state_version);
        }
    }

    public function test_foreign_organization_and_revoked_permissions_cannot_read_or_confirm_names(): void
    {
        [$actor, $session] = $this->fixture();
        $source = app(GetRoomIdentitySources::class)->handle($actor, (int) $session->project_id, (int) $session->id)['sources'][0];
        $foreign = User::factory()->create(['current_organization_id' => Organization::factory()->create()->id]);
        try {
            app(GetRoomIdentitySources::class)->handle($foreign, (int) $session->project_id, (int) $session->id);
            self::fail('Foreign names were disclosed.');
        } catch (ModelNotFoundException) {
            self::assertTrue(true);
        }
        $authorization = Mockery::mock(AuthorizationService::class);
        $authorization->allows('canCurrent')->andReturn(false);
        $this->app->instance(AuthorizationService::class, $authorization);
        $this->app->forgetInstance(ConfirmRoomIdentity::class);
        try {
            app(ConfirmRoomIdentity::class)->handle($actor, (int) $session->project_id, (int) $session->id, $this->input($source));
            self::fail('Revoked access admitted identity confirmation.');
        } catch (AuthorizationException) {
            self::assertSame(0, (int) $session->fresh()->state_version);
        }
    }

    public function test_disabled_rollout_returns_no_candidates_and_does_not_accept_confirmation(): void
    {
        [$actor, $session] = $this->fixture();
        $source = app(GetRoomIdentitySources::class)->handle($actor, (int) $session->project_id, (int) $session->id)['sources'][0];
        config(['estimate-generation.universal_enabled' => false]);
        $response = app(GetRoomIdentitySources::class)->handle($actor, (int) $session->project_id, (int) $session->id);
        self::assertFalse($response['available']);
        self::assertSame([], $response['sources']);
        $this->assertRejected($actor, $session, $this->input($source));
    }

    private function assertRejected(User $actor, EstimateGenerationSession $session, array $input): void
    {
        try {
            app(ConfirmRoomIdentity::class)->handle($actor, (int) $session->project_id, (int) $session->id, $input);
            self::fail('Invalid identity confirmation was accepted.');
        } catch (ValidationException) {
            self::assertTrue(true);
        }
    }

    private function input(array $source): array
    {
        return ['request_id' => (string) Str::uuid(), 'state_version' => 0, 'source_fact_id' => $source['source_fact_id'],
            'source_fact_version' => $source['source_fact_version'], 'source_value_hash' => $source['source_value_hash'],
            'reason' => 'Подтверждаю название помещения по выбранному источнику.'];
    }

    private function fixture(): array
    {
        config(['estimate-generation.universal_enabled' => true]);
        $organization = Organization::factory()->create();
        $project = Project::factory()->for($organization)->create();
        $actor = User::factory()->create(['current_organization_id' => $organization->id, 'is_active' => true]);
        $actor->organizations()->attach($organization->id, ['is_active' => true, 'project_access_mode' => 'all_projects']);
        $authorization = Mockery::mock(AuthorizationService::class);
        $authorization->allows('canCurrent')->andReturn(true);
        $this->app->instance(AuthorizationService::class, $authorization);
        $session = EstimateGenerationSession::query()->create(['organization_id' => $organization->id, 'project_id' => $project->id,
            'user_id' => $actor->id, 'status' => 'draft', 'input_payload' => ['evaluation_mode' => 'universal'], 'state_version' => 0]);
        $version = 'sha256:'.str_repeat('a', 64);
        $document = EstimateGenerationDocument::query()->create(['session_id' => $session->id, 'organization_id' => $organization->id,
            'project_id' => $project->id, 'user_id' => $actor->id, 'filename' => 'room-fixture.jpg', 'mime_type' => 'image/jpeg',
            'status' => 'ready', 'source_version' => $version, 'checksum_sha256' => str_repeat('a', 64)]);
        foreach (['1', '2'] as $index => $floor) {
            $claim = new ObservationClaim('literal:room:'.($index + 1), 'observer_literal', 'room.kitchen', 'room_name',
                ['type' => 'string', 'data' => 'Кухня'], null, 'literal:room:'.($index + 1), true,
                (int) $organization->id, (int) $project->id, (int) $session->id, $version,
                ['page' => 1, 'unit_type' => 'raster_image', 'unit_index' => 1, 'floor_id' => $floor,
                    'region_key' => 'region:'.str_repeat((string) ($index + 1), 64), 'bbox' => [10, 20, 30, 40]]);
            app(ProjectModelEvidenceWriter::class)->writeIndependentObservations([$claim], (int) $document->id, 1);
        }

        return [$actor, $session, $document];
    }
}
