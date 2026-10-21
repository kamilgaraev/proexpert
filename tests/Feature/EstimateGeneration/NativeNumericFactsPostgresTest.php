<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Analysis\Arbitration\ArbitrationDecision;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Arbitration\ObservationClaim;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\AtomicDocumentUnitPublicationWriter;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitExecutionContext;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitPublication;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitType;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\NativeNumericFactFactory;
use App\BusinessModules\Addons\EstimateGeneration\Application\Apply\CurrentDraftQuantityProofGuard;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\NativeNumericFactProof;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceRepository;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\CurrentProjectDerivedQuantityService;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\DerivedQuantityFactory;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\QuantitySource;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\WorkItemQuantityMapper;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\EstimateGeneration\EstimateGenerationCanonicalPostgresTestCase;

final class NativeNumericFactsPostgresTest extends EstimateGenerationCanonicalPostgresTestCase
{
    public function test_native_equipment_count_keeps_original_proof_and_exact_entity_mapping_until_evidence_is_invalidated(): void
    {
        $context = $this->context();
        $publication = $this->equipmentCount($context);
        self::assertCount(1, $publication->claims);
        self::assertTrue($publication->numericSources->certifies($publication->claims[0]));
        $this->publish($context, $publication);
        $models = app(ProjectModelRepository::class);
        $capture = $models->snapshotForPlanning($context->organizationId, $context->projectId, $context->sessionId, 100);
        $snapshot = $capture['snapshot'];
        self::assertCount(1, $snapshot->facts);
        self::assertCount(1, $snapshot->entities);
        self::assertCount(1, $snapshot->evidence);
        $fact = $snapshot->facts[0];
        $entity = $snapshot->entities[0];
        $evidence = $snapshot->evidence[0];
        self::assertSame('equipment_count', $fact->type);
        self::assertSame('3', $fact->value);
        self::assertSame('шт', $fact->unit);
        self::assertSame('document', $fact->origin);
        self::assertSame('confirmed', $fact->status);
        self::assertSame($entity->id, $fact->entityId);
        self::assertSame('equipment', $entity->attributes['semantic_type'] ?? $entity->attributes['properties']['semantic_type'] ?? $entity->type);
        self::assertSame('xlsx:sheet:Оборудование!C2', $evidence->nativeReference);
        self::assertSame('native_numeric_parser', $evidence->producerName);
        self::assertNotEmpty($evidence->fingerprint);
        self::assertSame($evidence->fingerprint, $fact->nativeNumericProofs[$evidence->id]['evidence_fingerprint']);
        self::assertTrue((new NativeNumericFactProof)->matches($fact, $evidence));
        $request = $this->countRequest($entity->id, $fact->id, $capture['token']);
        $readiness = (new DerivedQuantityFactory)->derive($snapshot, [], $request);
        self::assertTrue($readiness->isReady(), json_encode($readiness->unresolvedInputs, JSON_THROW_ON_ERROR));
        self::assertNotNull($readiness->quantity);
        self::assertSame('3', $readiness->quantity->value);
        self::assertSame('count', $readiness->quantity->unit);
        self::assertSame('confirmed', $readiness->quantity->status);
        self::assertSame($fact->id, $readiness->quantity->operands[0]['fact_id']);
        self::assertSame($fact->version, $readiness->quantity->operands[0]['fact_version']);
        self::assertSame($context->sourceVersion, $readiness->quantity->operands[0]['source_version']);
        self::assertSame([$evidence->id], $readiness->quantity->evidenceIds);
        self::assertSame($readiness->quantity->exactIdentity, (new DerivedQuantityFactory)->derive($snapshot, [], $request)->quantity->exactIdentity);
        $preview = app(CurrentProjectDerivedQuantityService::class)->previewInput($context->organizationId, $context->projectId, $context->sessionId);
        $mapper = new WorkItemQuantityMapper;
        $mapped = $mapper->mapForEntity('equipment.install', $preview['quantities'], $entity->id);
        self::assertNotNull($mapped);
        self::assertSame('3', $mapped->amount);
        self::assertSame('count', $mapped->unit);
        self::assertSame(QuantitySource::Evidenced, $mapped->source);
        self::assertSame([], $mapped->assumptions);
        self::assertSame($entity->id, $mapped->formulaInputs['entity_id']);
        self::assertSame($fact->id, $mapped->formulaInputs['operands'][0]['fact_id']);
        self::assertNull($mapper->mapForEntity('equipment.install', $preview['quantities'], 'equipment:other'));
        $draft = ['local_estimates' => [['sections' => [['work_items' => [[
            'key' => 'pump-install', 'item_type' => 'priced_work', 'quantity' => '3', 'unit' => 'pcs',
            'metadata' => ['quantity_key' => 'equipment.count', 'scope_entity_id' => $entity->id], 'quantity_evidence' => $mapped->toArray(),
        ]]]]]]];
        DB::transaction(fn () => app(CurrentDraftQuantityProofGuard::class)->assertCurrent($context->organizationId, $context->projectId, $context->sessionId, $draft));
        self::addToAssertionCount(1);
        $wrong = $draft;
        $wrong['local_estimates'][0]['sections'][0]['work_items'][0]['quantity'] = '4';
        $this->assertCountProofRejected($context, $wrong);
        $this->publish($context, $publication);
        self::assertCount(1, $models->currentFacts($context->organizationId, $context->projectId, $context->sessionId));
        app(EvidenceRepository::class)->invalidate($context->organizationId, $context->projectId, $context->sessionId,
            [(int) substr($evidence->id, strlen('evidence:'))], 'native_count_source_changed');
        $current = $models->snapshotForPlanning($context->organizationId, $context->projectId, $context->sessionId, 100)['snapshot'];
        self::assertSame([], $current->facts);
        self::assertFalse((new DerivedQuantityFactory)->derive($current, [], $request)->isReady());
        self::assertSame([], app(CurrentProjectDerivedQuantityService::class)->previewInput($context->organizationId, $context->projectId, $context->sessionId)['quantities']);
        $this->assertCountProofRejected($context, $draft);
        self::assertSame(0, DB::table('estimate_generation_ai_usage')->where('session_id', $context->sessionId)->count());
    }

    public function test_native_equipment_count_is_not_visible_in_foreign_organization_project_or_session(): void
    {
        $context = $this->context();
        $this->publish($context, $this->equipmentCount($context));
        $models = app(ProjectModelRepository::class);
        $capture = $models->snapshotForPlanning($context->organizationId, $context->projectId, $context->sessionId, 100);
        $fact = $capture['snapshot']->facts[0];
        $request = $this->countRequest($fact->entityId, $fact->id, $capture['token']);
        $foreign = $this->context();
        foreach ([
            [$foreign->organizationId, $context->projectId, $context->sessionId],
            [$context->organizationId, $foreign->projectId, $context->sessionId],
            [$context->organizationId, $context->projectId, $foreign->sessionId],
        ] as [$organizationId, $projectId, $sessionId]) {
            $snapshot = $models->snapshotForPlanning($organizationId, $projectId, $sessionId, 100)['snapshot'];
            self::assertSame([], $snapshot->facts);
            self::assertSame([], $snapshot->evidence);
            self::assertFalse((new DerivedQuantityFactory)->derive($snapshot, [], $request)->isReady());
            $preview = app(CurrentProjectDerivedQuantityService::class)->previewInput($organizationId, $projectId, $sessionId);
            self::assertNull((new WorkItemQuantityMapper)->mapForEntity('equipment.install', $preview['quantities'], $fact->entityId));
        }
    }

    #[DataProvider('invalidEquipmentCounts')]
    public function test_native_equipment_count_quarantines_fractional_nonpositive_or_incompatible_measurements(string $value, string $unit, string $reason): void
    {
        $context = $this->context();
        $publication = $this->equipmentCount($context, $value, $unit);
        self::assertSame([], $publication->claims);
        self::assertSame([$reason], array_column($publication->quarantinedItems, 'reason_code'));
        $this->publish($context, $publication);
        self::assertSame([], app(ProjectModelRepository::class)->snapshotForPlanning($context->organizationId, $context->projectId, $context->sessionId, 100)['snapshot']->facts);
        $preview = app(CurrentProjectDerivedQuantityService::class)->previewInput($context->organizationId, $context->projectId, $context->sessionId);
        self::assertSame([], $preview['quantities']);
    }

    public static function invalidEquipmentCounts(): array
    {
        return [
            'fractional count' => ['1.5', 'шт', 'native_engineering_measurement_invalid'],
            'wrong dimension' => ['3', 'm', 'native_engineering_measurement_invalid'],
            'zero count' => ['0', 'count', 'native_numeric_precision_or_value_requires_confirmation'],
        ];
    }

    public function test_literal_quantity_is_published_by_real_writer_with_exact_cell_and_original_unit(): void
    {
        $context = $this->context();
        $native = ['sheet' => 'Объемы', 'header_cells' => [
            ['address' => 'B1', 'value' => 'Количество'], ['address' => 'C1', 'value' => 'Ед. изм.'],
        ], 'cells' => [
            ['address' => 'B2', 'value' => '3', 'raw_value' => 3, 'formula' => null], ['address' => 'C2', 'value' => 'м³'],
        ]];
        $publication = (new NativeNumericFactFactory)->spreadsheet($context, $native);
        self::assertNotNull($publication);
        $writer = app(AtomicDocumentUnitPublicationWriter::class);
        $writer->transaction($context->organizationId, $context->sessionId, fn () => $writer->write($publication,
            $context->organizationId, $context->projectId, $context->sessionId, $context->documentId, $context->index, $context->sourceVersion));
        $snapshot = app(ProjectModelRepository::class)->snapshotForUnderstanding($context->organizationId, $context->projectId, $context->sessionId, 100)['snapshot'];
        self::assertCount(1, $snapshot->facts);
        self::assertSame('3', $snapshot->facts[0]->value);
        self::assertSame('м³', $snapshot->facts[0]->unit);
        self::assertSame('document', $snapshot->facts[0]->origin);
        self::assertSame('confirmed', $snapshot->facts[0]->status);
        self::assertCount(1, $snapshot->evidence);
        self::assertSame('xlsx:sheet:Объемы!B2', $snapshot->evidence[0]->nativeReference);
        self::assertSame('quantity', $snapshot->entities[0]->type);
        self::assertSame('quantity', $snapshot->entities[0]->attributes['semantic_type']);
        self::assertTrue((new \App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\NativeNumericFactProof)->matches($snapshot->facts[0], $snapshot->evidence[0]));
        self::assertNotEmpty($snapshot->facts[0]->nativeNumericProofs);
        $session = EstimateGenerationSession::query()->findOrFail($context->sessionId);
        $actor = User::query()->findOrFail($session->user_id);
        $actor->organizations()->attach($context->organizationId, ['is_active' => true, 'project_access_mode' => 'all_projects']);
        $permissions = \Mockery::mock(\App\Domain\Authorization\Services\AuthorizationService::class);
        $permissions->allows('canCurrent')->andReturn(true);
        $this->app->instance(\App\Domain\Authorization\Services\AuthorizationService::class, $permissions);
        $draft = ['local_estimates' => [['key' => 'foundations', 'sections' => [['work_items' => [
            ['key' => 'work:concrete', 'item_type' => 'priced_work', 'name' => 'Бетонирование', 'quantity' => null, 'unit' => 'm3'],
        ]]]]]];
        $draft['source_input_version'] = 'sha256:'.(new \App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\EvaluationInputFingerprint)->fromSession($session);
        $draft = (new \App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\UniversalReviewSummaryProjector)->project($draft);
        $session->update(['draft_payload' => $draft]);
        $reader = app(\App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\GetEvaluationQuantitySources::class);
        $sources = $reader->handle($actor, $context->projectId, $context->sessionId, 'work:concrete');
        self::assertSame('0', $sources['cost_units']);
        self::assertSame(1, $sources['pagination']['total']);
        self::assertSame($snapshot->facts[0]->id, $sources['sources'][0]['source_fact_id']);
        self::assertSame('xlsx:sheet:Объемы!B2', $sources['sources'][0]['evidence']['native_reference']);
        $foreignContext = $this->context();
        $foreignSession = EstimateGenerationSession::query()->findOrFail($foreignContext->sessionId);
        $foreignActor = User::query()->findOrFail($foreignSession->user_id);
        try {
            $reader->handle($foreignActor, $context->projectId, $context->sessionId, 'work:concrete');
            self::fail('A native quantity source crossed organization scope.');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            self::assertTrue(true);
        }
        $choice = $sources['sources'][0];
        app(\App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\SaveManualProjectFacts::class)
            ->handle($actor, $context->projectId, $context->sessionId, 0, (string) \Illuminate\Support\Str::uuid(),
                ['entities' => [['key' => 'room-a', 'type' => 'room', 'name' => 'Помещение A']]]);
        $current = app(ProjectModelRepository::class)->snapshotForPlanning($context->organizationId, $context->projectId, $context->sessionId, 100)['snapshot'];
        $room = array_values(array_filter($current->entities, static fn ($entity): bool => $entity->type === 'room'))[0];
        $scoped = $draft;
        $scoped['local_estimates'][0]['sections'][0]['work_items'][0]['metadata']['scope_entity_id'] = $room->id;
        $scoped['source_input_version'] = 'sha256:'.app(\App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\EvaluationInputFingerprint::class)->fromSession($session->fresh());
        $scoped = app(\App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\UniversalReviewSummaryProjector::class)->project($scoped);
        $session->fresh()->update(['draft_payload' => $scoped]);
        self::assertSame([], $reader->handle($actor, $context->projectId, $context->sessionId, 'work:concrete')['sources']);
        try {
            app(\App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\SaveEvaluationWorkQuantity::class)
                ->handle($actor, $context->projectId, $context->sessionId, ['request_id' => (string) \Illuminate\Support\Str::uuid(),
                    'state_version' => 1, 'work_item_key' => 'work:concrete', 'source_fact_id' => $choice['source_fact_id'],
                    'source_fact_version' => $choice['source_fact_version'], 'source_value_hash' => $choice['source_value_hash'], 'reason' => 'Объём другого объекта не применяется']);
            self::fail('A native volume of another entity was selected.');
        } catch (\Illuminate\Validation\ValidationException) {
            self::assertSame(1, $session->fresh()->state_version);
        }
        $draft['source_input_version'] = $scoped['source_input_version'];
        $draft = app(\App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\UniversalReviewSummaryProjector::class)->project($draft);
        $session->fresh()->update(['draft_payload' => $draft]);
        $receipt = app(\App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\SaveEvaluationWorkQuantity::class)
            ->handle($actor, $context->projectId, $context->sessionId, ['request_id' => (string) \Illuminate\Support\Str::uuid(),
                'state_version' => 1, 'work_item_key' => 'work:concrete', 'source_fact_id' => $choice['source_fact_id'],
                'source_fact_version' => $choice['source_fact_version'], 'source_value_hash' => $choice['source_value_hash'], 'reason' => 'Подтверждённая ведомость объёмов']);
        self::assertSame(2, $receipt['state_version']);
        self::assertTrue($receipt['recalculation_required']);
        $draft['local_estimates'][0]['sections'][0]['work_items'][0]['unit'] = 'm2';
        $draft['source_input_version'] = 'sha256:'.(new \App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\EvaluationInputFingerprint)->fromSession($session->fresh());
        $draft = (new \App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\UniversalReviewSummaryProjector)->project($draft);
        $session->fresh()->update(['draft_payload' => $draft]);
        self::assertSame([], $reader->handle($actor, $context->projectId, $context->sessionId, 'work:concrete')['sources']);
        $draft['local_estimates'][0]['sections'][0]['work_items'][0]['unit'] = 'm3';
        $draft = (new \App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\UniversalReviewSummaryProjector)->project($draft);
        $session->fresh()->update(['draft_payload' => $draft]);
        $evidenceId = (int) DB::table('estimate_generation_evidence')->where('session_id', $context->sessionId)->value('id');
        app(\App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceRepository::class)
            ->invalidate($context->organizationId, $context->projectId, $context->sessionId, [$evidenceId], 'source_changed');
        self::assertSame([], $reader->handle($actor, $context->projectId, $context->sessionId, 'work:concrete')['sources']);
        self::assertSame(0, DB::table('estimate_generation_ai_usage')->count());
    }

    public function test_database_rejects_native_reference_url_or_nonstring(): void
    {
        $context = $this->context();
        $repository = app(\App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceRepository::class);
        $node = $repository->insertOrGet(new \App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceData(
            $context->organizationId, $context->projectId, $context->sessionId,
            \App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceType::SourceFact,
            \App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceSourceType::Document,
            'document:'.$context->documentId, $context->sourceVersion,
            ['document_id' => $context->documentId, 'page' => 1, 'native_reference' => 'cad:dimension:AB'],
            ['fact_key' => 'quantity', 'fact_value' => '3'], 0.0, 'ocr_fact_extractor', $context->sourceVersion,
        ));
        foreach (['https://foreign.example/secret', 123] as $invalid) {
            $row = (array) DB::table('estimate_generation_evidence')->where('id', $node->id)->first();
            unset($row['id']);
            $row['fingerprint'] = hash('sha256', 'invalid-native-reference:'.json_encode($invalid, JSON_THROW_ON_ERROR));
            $row['locator'] = json_encode(['document_id' => $context->documentId, 'native_reference' => $invalid], JSON_THROW_ON_ERROR);
            try {
                DB::transaction(fn () => DB::table('estimate_generation_evidence')->insert($row));
                self::fail('Untrusted native reference was accepted.');
            } catch (\Illuminate\Database\QueryException $exception) {
                self::assertStringContainsString('evidence_locator_invalid', $exception->getMessage());
            }
        }
    }

    public function test_native_table_keeps_rooms_on_different_floors_in_separate_geometry_scopes(): void
    {
        $context = $this->context();
        $headers = [['address' => 'A1', 'value' => 'entity_key'], ['address' => 'B1', 'value' => 'parameter'],
            ['address' => 'C1', 'value' => 'value'], ['address' => 'D1', 'value' => 'unit'], ['address' => 'E1', 'value' => 'Этаж']];
        $cells = [];
        foreach ([2 => '1', 3 => '2'] as $row => $floor) {
            foreach (['A' => 'room:kitchen', 'B' => 'area', 'C' => '12', 'D' => 'm2', 'E' => $floor] as $column => $value) {
                $cells[] = ['address' => $column.$row, 'value' => $value, 'raw_value' => $value, 'formula' => null];
            }
        }
        $publication = (new NativeNumericFactFactory)->spreadsheet($context, ['sheet' => 'Помещения', 'header_cells' => $headers, 'cells' => $cells]);
        $writer = app(AtomicDocumentUnitPublicationWriter::class);
        $writer->transaction($context->organizationId, $context->sessionId, fn () => $writer->write($publication,
            $context->organizationId, $context->projectId, $context->sessionId, $context->documentId, $context->index, $context->sourceVersion));
        $snapshot = app(ProjectModelRepository::class)->snapshot($context->organizationId, $context->projectId, $context->sessionId);
        self::assertCount(2, $snapshot->facts);
        self::assertCount(2, $snapshot->entities);
        $floors = array_map(static fn ($entity): string => $entity->attributes['floor_id'], $snapshot->entities);
        sort($floors);
        self::assertSame(['1', '2'], $floors);
    }

    private function context(): DocumentUnitExecutionContext
    {
        $organization = Organization::factory()->create();
        $project = Project::factory()->for($organization)->create();
        $user = User::factory()->create(['current_organization_id' => $organization->id]);
        $session = EstimateGenerationSession::query()->create(['organization_id' => $organization->id,
            'project_id' => $project->id, 'user_id' => $user->id, 'status' => 'draft', 'processing_stage' => 'draft',
            'processing_progress' => 0, 'state_version' => 0, 'input_payload' => []]);
        $version = 'sha256:'.str_repeat('a', 64);
        $document = EstimateGenerationDocument::query()->create(['organization_id' => $organization->id,
            'project_id' => $project->id, 'session_id' => $session->id, 'user_id' => $user->id,
            'filename' => 'source.xlsx', 'mime_type' => 'application/octet-stream', 'source_version' => $version, 'checksum_sha256' => substr($version, 7)]);
        $page = \App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocumentPage::query()->create([
            'organization_id' => $organization->id, 'project_id' => $project->id, 'session_id' => $session->id,
            'document_id' => $document->id, 'page_number' => 1, 'source_version' => $version,
        ]);

        return new DocumentUnitExecutionContext(1, (int) $organization->id, (int) $project->id, (int) $session->id,
            (int) $document->id, DocumentUnitType::SpreadsheetSheet, 1, $version, [],
            'source.xlsx', 'application/octet-stream', 'source.xlsx', 'claim-token', 1, 0, 'draft', (int) $page->id);
    }

    public function test_photo_area_guessed_by_agreeing_observers_is_not_a_confirmed_fact_or_accepted_document_takeoff(): void
    {
        $context = $this->context();
        EstimateGenerationDocument::query()->whereKey($context->documentId)->update(['filename' => 'photo.jpg', 'mime_type' => 'image/jpeg']);
        $claims = [];
        $decisions = [];
        foreach (['observer_literal', 'observer_construction', 'observer_risk'] as $role) {
            $id = str_replace('observer_', '', $role).':1';
            $ref = str_replace('observer_', '', $role).':source:1';
            $claims[] = new ObservationClaim($id, $role, 'room.kitchen', 'area', ['type' => 'number', 'data' => '25.97'], 'm2', $ref, true,
                $context->organizationId, $context->projectId, $context->sessionId, $context->sourceVersion,
                ['document_id' => $context->documentId, 'page' => 1, 'explicit' => true, 'coordinate_space' => 'raster_image_normalized'], 1);
            $decisions[] = new ArbitrationDecision($id, 'accepted', [$id], [$ref], 'observers_agree', null);
        }
        $publication = new DocumentUnitPublication($claims, $decisions);
        $writer = app(AtomicDocumentUnitPublicationWriter::class);
        $writer->transaction($context->organizationId, $context->sessionId, fn () => $writer->write($publication,
            $context->organizationId, $context->projectId, $context->sessionId, $context->documentId, 1, $context->sourceVersion));
        $snapshot = app(ProjectModelRepository::class)->snapshot($context->organizationId, $context->projectId, $context->sessionId);
        self::assertSame([], $snapshot->facts);
        $assertions = DB::table('estimate_generation_project_model_assertions')->where('session_id', $context->sessionId)->get();
        self::assertNotEmpty($assertions);
        self::assertSame(['candidate'], $assertions->pluck('fact_status')->unique()->values()->all());
        self::assertSame(['ai_inference'], $assertions->pluck('fact_origin')->unique()->values()->all());
        self::assertSame(0, DB::table('estimate_generation_document_facts')->where('document_id', $context->documentId)->count());
        $preview = app(\App\BusinessModules\Addons\EstimateGeneration\Quantities\CurrentProjectDerivedQuantityService::class)
            ->previewInput($context->organizationId, $context->projectId, $context->sessionId);
        foreach ($preview['quantities'] as $quantity) {
            self::assertNotSame('25.97', $quantity->amount);
        }
        self::assertSame(0, DB::table('estimate_generation_ai_usage')->where('session_id', $context->sessionId)->count());
    }

    public function test_same_source_candidate_or_empty_publication_retracts_old_numeric_projection_without_erasing_history(): void
    {
        $context = $this->context();
        $publication = $this->roomArea($context);
        $this->publish($context, $publication);
        $repository = app(ProjectModelRepository::class);
        self::assertCount(1, $repository->currentFacts($context->organizationId, $context->projectId, $context->sessionId));
        DB::table('estimate_generation_document_facts')->where('document_id', $context->documentId)->update(['normalized_payload' => '{}']);
        $this->publish($context, new DocumentUnitPublication($publication->claims, $publication->decisions));
        self::assertSame([], $repository->currentFacts($context->organizationId, $context->projectId, $context->sessionId));
        self::assertSame(0, DB::table('estimate_generation_document_facts')->where('document_id', $context->documentId)->count());
        self::assertSame(2, DB::table('estimate_generation_project_model_assertions')->where('session_id', $context->sessionId)->count());
        $this->publish($context, $publication);
        self::assertCount(1, $repository->currentFacts($context->organizationId, $context->projectId, $context->sessionId));
        $this->publish($context, new DocumentUnitPublication([], [], [['role' => 'native', 'index' => null, 'reason_code' => 'source_unreadable']]));
        self::assertSame([], $repository->currentFacts($context->organizationId, $context->projectId, $context->sessionId));
        $preview = app(\App\BusinessModules\Addons\EstimateGeneration\Quantities\CurrentProjectDerivedQuantityService::class)->previewInput($context->organizationId, $context->projectId, $context->sessionId);
        self::assertSame([], $preview['quantities']);
    }

    public function test_replayed_evidence_keeps_epoch_and_new_binding_without_reviving_old_binding(): void
    {
        $context = $this->context();
        $publication = $this->roomArea($context);
        $this->publish($context, $publication);
        $repository = app(ProjectModelRepository::class);
        $id = (int) DB::table('estimate_generation_evidence')->where('session_id', $context->sessionId)->value('id');
        try {
            DB::transaction(fn () => DB::table('estimate_generation_evidence')->where('id', $id)->update(['invalidation_version' => 1]));
            self::fail('An active epoch was changed without invalidation.');
        } catch (\Illuminate\Database\QueryException $exception) {
            self::assertStringContainsString('evidence_epoch_not_monotonic', $exception->getMessage());
        }
        app(\App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceRepository::class)->invalidate($context->organizationId, $context->projectId, $context->sessionId, [$id], 'test_revalidation');
        self::assertSame([], $repository->currentFacts($context->organizationId, $context->projectId, $context->sessionId));
        $this->publish($context, $publication);
        self::assertSame(1, (int) DB::table('estimate_generation_evidence')->where('id', $id)->value('invalidation_version'));
        self::assertCount(1, $repository->currentFacts($context->organizationId, $context->projectId, $context->sessionId));
        self::assertSame([0, 1], DB::table('estimate_generation_project_model_fact_evidence')->where('evidence_id', $id)->orderBy('evidence_invalidation_version')->pluck('evidence_invalidation_version')->map(fn ($version) => (int) $version)->all());
        try {
            DB::transaction(fn () => DB::table('estimate_generation_evidence')->where('id', $id)->update(['invalidation_version' => 0]));
            self::fail('Epoch rollback was accepted.');
        } catch (\Illuminate\Database\QueryException $exception) {
            self::assertStringContainsString('evidence_epoch_not_monotonic', $exception->getMessage());
        }
    }

    private function equipmentCount(DocumentUnitExecutionContext $context, string $value = '3', string $unit = 'шт'): DocumentUnitPublication
    {
        return (new NativeNumericFactFactory)->spreadsheet($context, ['sheet' => 'Оборудование',
            'header_cells' => [['address' => 'A1', 'value' => 'entity_key'], ['address' => 'B1', 'value' => 'parameter'], ['address' => 'C1', 'value' => 'value'], ['address' => 'D1', 'value' => 'unit']],
            'cells' => [['address' => 'A2', 'value' => 'equipment:pump-1'], ['address' => 'B2', 'value' => 'equipment_count'],
                ['address' => 'C2', 'value' => $value, 'raw_value' => $value, 'formula' => null], ['address' => 'D2', 'value' => $unit]],
        ]) ?? throw new \LogicException('Native equipment fixture is invalid.');
    }

    private function countRequest(string $entityId, string $factId, string $token): array
    {
        return ['quantity_id' => 'quantity:equipment_count:'.$entityId, 'formula_identity' => 'equipment_count', 'formula_version' => '1',
            'entity_id' => $entityId, 'operands' => ['measurement' => $factId], 'output_unit' => 'count', 'rounding_mode' => 'half_up',
            'rounding_scale' => 0, 'snapshot' => ['input_fingerprint' => $token]];
    }

    private function assertCountProofRejected(DocumentUnitExecutionContext $context, array $draft): void
    {
        try {
            DB::transaction(fn () => app(CurrentDraftQuantityProofGuard::class)->assertCurrent($context->organizationId, $context->projectId, $context->sessionId, $draft));
            self::fail('A changed or stale native equipment count was accepted.');
        } catch (ValidationException) {
            self::addToAssertionCount(1);
        }
    }

    private function roomArea(DocumentUnitExecutionContext $context): DocumentUnitPublication
    {
        return (new NativeNumericFactFactory)->spreadsheet($context, ['sheet' => 'Помещения',
            'header_cells' => [['address' => 'A1', 'value' => 'entity_key'], ['address' => 'B1', 'value' => 'parameter'], ['address' => 'C1', 'value' => 'value'], ['address' => 'D1', 'value' => 'unit']],
            'cells' => [['address' => 'A2', 'value' => 'room:kitchen'], ['address' => 'B2', 'value' => 'area'], ['address' => 'C2', 'value' => '22.10', 'raw_value' => '22.10', 'formula' => null], ['address' => 'D2', 'value' => 'm2']],
        ]) ?? throw new \LogicException('Native test fixture is invalid.');
    }

    private function publish(DocumentUnitExecutionContext $context, DocumentUnitPublication $publication): void
    {
        $writer = app(AtomicDocumentUnitPublicationWriter::class);
        $writer->transaction($context->organizationId, $context->sessionId, fn () => $writer->write($publication,
            $context->organizationId, $context->projectId, $context->sessionId, $context->documentId, 1, $context->sourceVersion));
    }
}
