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
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Support\EstimateGeneration\EstimateGenerationCanonicalPostgresTestCase;

final class NativeNumericFactsPostgresTest extends EstimateGenerationCanonicalPostgresTestCase
{
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
            'filename' => 'source.xlsx', 'mime_type' => 'application/octet-stream', 'source_version' => $version]);
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
