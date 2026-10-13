<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Analysis\Arbitration\ObservationClaim;
use App\BusinessModules\Addons\EstimateGeneration\Application\Apply\CurrentDraftQuantityProofGuard;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\BindEvaluationWorkEntity;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\ConfirmRoomIdentity;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\EvaluationDraftArithmeticRecalculator;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\EvaluationInputFingerprint;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\GetRoomIdentitySources;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\SaveEvaluationWorkQuantity;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\ProjectModelEvidenceWriter;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\StaleEstimateGenerationState;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceRepository;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineContext;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\CurrentProjectDerivedQuantityService;
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
    public function test_numeric_answer_on_a_document_room_keeps_its_identity_and_source_version_in_a_mixed_batch(): void
    {
        [$actor, $session, $document] = $this->fixture();
        $scope = [(int) $session->organization_id, (int) $session->project_id, (int) $session->id];
        $source = app(GetRoomIdentitySources::class)->handle($actor, $scope[1], $scope[2])['sources'][0];
        app(ConfirmRoomIdentity::class)->handle($actor, $scope[1], $scope[2], $this->input($source));
        $models = app(ProjectModelRepository::class);
        $before = $models->snapshotForPlanning(...[...$scope, 10001])['snapshot'];
        $room = array_values(array_filter($before->entities, static fn ($entity): bool => $entity->id === $source['entity_id']))[0];
        $input = ['entities' => [
            ['key' => 'parameter-answer', 'type' => 'room', 'existing_entity_id' => $room->id,
                'parameters' => ['area' => ['value' => '20', 'unit' => 'm2', 'basis' => 'measurement']]],
            ['key' => 'new-room', 'type' => 'room', 'parameters' => [
                'length' => ['value' => '2', 'unit' => 'm', 'basis' => 'measurement'],
                'width' => ['value' => '3', 'unit' => 'm', 'basis' => 'measurement'],
            ]],
        ]];
        $save = app(\App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\SaveManualProjectFacts::class);
        $request = (string) Str::uuid();
        $stateVersion = (int) $session->fresh()->state_version;
        $result = $save->handle($actor, $scope[1], $scope[2], $stateVersion, $request, $input);
        self::assertSame('0', $result['cost_units']);
        self::assertSame(CanonicalPipelineJson::encode($result), CanonicalPipelineJson::encode(
            $save->handle($actor, $scope[1], $scope[2], $stateVersion, $request, $input)));
        $after = $models->snapshotForPlanning(...[...$scope, 10001])['snapshot'];
        $sameRoom = array_values(array_filter($after->entities, static fn ($entity): bool => $entity->id === $room->id))[0];
        self::assertEquals($room, $sameRoom);
        $area = array_values(array_filter($after->facts, static fn ($fact): bool => $fact->entityId === $room->id && $fact->type === 'area'))[0];
        self::assertSame($document->source_version, $area->sourceVersion);
        self::assertSame('20', $area->value);
        $amounts = array_column($result['quantities'], 'amount');
        self::assertContains('20', $amounts);
        self::assertContains('6', $amounts);
        self::assertSame(0, DB::table('estimate_generation_ai_usage')->where('session_id', $session->id)->count());
    }

    public function test_work_binding_revokes_only_the_old_quantity_assignment_and_preserves_its_source_and_history(): void
    {
        [$actor, $session] = $this->fixture(['local_estimates' => [['key' => 'finishing', 'sections' => [['work_items' => [
            ['key' => 'finishing:floor', 'name' => 'Устройство пола', 'quantity' => null, 'unit' => 'm2'],
        ]]]]]]);
        $scope = [(int) $session->organization_id, (int) $session->project_id, (int) $session->id];
        $reader = app(GetRoomIdentitySources::class);
        $candidate = $reader->handle($actor, $scope[1], $scope[2])['sources'][0];
        app(ConfirmRoomIdentity::class)->handle($actor, $scope[1], $scope[2], $this->input($candidate));
        $selected = array_values(array_filter($reader->handle($actor, $scope[1], $scope[2])['sources'], static fn (array $row): bool => $row['status'] === 'confirmed'))[0];
        $quantityResult = app(SaveEvaluationWorkQuantity::class)->handle($actor, $scope[1], $scope[2], [
            'request_id' => (string) Str::uuid(), 'state_version' => 1, 'work_item_key' => 'finishing:floor',
            'value' => '25', 'unit' => 'm2', 'reason' => 'Объём первоначального состава введён пользователем.',
        ]);
        $quantity = app(CurrentProjectDerivedQuantityService::class)->previewInput(...$scope)['quantities'][$quantityResult['quantity_key']];
        self::assertSame('25', $quantity->amount);
        $oldDraft = ['local_estimates' => [['sections' => [['work_items' => [[
            'key' => 'finishing:floor', 'quantity' => '25', 'unit' => 'm2',
            'metadata' => ['quantity_key' => $quantityResult['quantity_key']], 'quantity_evidence' => $quantity->toArray(),
        ]]]]]]];
        DB::transaction(fn () => app(CurrentDraftQuantityProofGuard::class)->assertCurrent(...[...$scope, $oldDraft]));
        $input = [...$this->input($selected), 'state_version' => 2, 'work_item_key' => 'finishing:floor'];
        $binding = app(BindEvaluationWorkEntity::class);
        $result = $binding->handle($actor, $scope[1], $scope[2], $input);
        self::assertSame(CanonicalPipelineJson::encode($result), CanonicalPipelineJson::encode($binding->handle($actor, $scope[1], $scope[2], $input)));
        self::assertTrue($result['quantity_reset']);
        self::assertSame('0', $result['cost_units']);
        self::assertSame($selected['entity_id'], $result['scope_entity_id']);
        self::assertNotSame($result['entity_id'], $result['scope_entity_id']);
        $fresh = $session->fresh();
        self::assertArrayNotHasKey('finishing:floor', $fresh->input_payload['quantity_selections']);
        self::assertSame($selected['entity_id'], $fresh->input_payload['work_entity_selections']['finishing:floor']['entity_id']);
        $item = $fresh->draft_payload['local_estimates'][0]['sections'][0]['work_items'][0];
        self::assertNull($item['quantity']);
        self::assertSame('unknown', $item['quantity_status']);
        self::assertNull($item['total_cost']);
        self::assertTrue($item['metadata']['quantity_reset_required']);
        self::assertArrayNotHasKey($quantityResult['quantity_key'], app(CurrentProjectDerivedQuantityService::class)->previewInput(...$scope)['quantities']);
        self::assertNotNull(app(ProjectModelRepository::class)->fact(...[...$scope, $quantityResult['source_fact_id']]));
        self::assertSame(0, DB::table('ai_credit_reservations')->where('organization_id', $scope[0])->count());
        try {
            DB::transaction(fn () => app(CurrentDraftQuantityProofGuard::class)->assertCurrent(...[...$scope, $oldDraft]));
            self::fail('Old quantity proof survived a changed physical scope.');
        } catch (ValidationException) {
            self::assertTrue(true);
        }
        $sameScope = [...$input, 'request_id' => (string) Str::uuid(), 'state_version' => 3];
        self::assertFalse($binding->handle($actor, $scope[1], $scope[2], $sameScope)['quantity_reset']);
        $reentered = app(SaveEvaluationWorkQuantity::class)->handle($actor, $scope[1], $scope[2], [
            'request_id' => (string) Str::uuid(), 'state_version' => 4, 'work_item_key' => 'finishing:floor',
            'value' => '30', 'unit' => 'm2', 'reason' => 'Подтверждён новый объём для выбранного помещения.',
        ]);
        self::assertSame('30', app(CurrentProjectDerivedQuantityService::class)->previewInput(...$scope)['quantities'][$reentered['quantity_key']]->amount);
        self::assertSame($selected['entity_id'], $session->fresh()->input_payload['work_entity_selections']['finishing:floor']['entity_id']);
        $ready = $session->fresh();
        $snapshot = app(ProjectModelRepository::class)->snapshotForPlanning(...[...$scope, 10001])['snapshot'];
        $decisions = app(ProjectModelRepository::class)->decisionsForSelectedFacts(...[...$scope, array_column($snapshot->facts, 'id')]);
        $version = 'sha256:'.app(EvaluationInputFingerprint::class)->fromSession($ready);
        $context = new PipelineContext($scope[2], $scope[0], $scope[1], (int) $ready->state_version,
            $version, $ready->status->value, baseInputVersion: $version);
        $updated = app(EvaluationDraftArithmeticRecalculator::class)->recalculate($ready->draft_payload, $context, $snapshot, $decisions, $actor, $ready);
        self::assertSame('30', $updated['local_estimates'][0]['sections'][0]['work_items'][0]['quantity']);
        $withoutPointer = clone $ready;
        $payload = $ready->input_payload;
        unset($payload['quantity_selections']);
        $withoutPointer->input_payload = $payload;
        $resetDraft = $ready->draft_payload;
        $resetDraft['local_estimates'][0]['sections'][0]['work_items'][0]['metadata']['quantity_key'] = $reentered['quantity_key'];
        $suppressed = app(EvaluationDraftArithmeticRecalculator::class)->recalculate($resetDraft, $context, $snapshot, $decisions, $actor, $withoutPointer);
        self::assertNull($suppressed['local_estimates'][0]['sections'][0]['work_items'][0]['quantity']);
    }

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

    private function fixture(?array $draft = null): array
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
            'user_id' => $actor->id, 'status' => 'draft', 'input_payload' => ['evaluation_mode' => 'universal'], 'state_version' => 0,
            'draft_payload' => $draft]);
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
