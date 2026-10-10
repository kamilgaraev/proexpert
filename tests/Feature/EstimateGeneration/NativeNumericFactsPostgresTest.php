<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\AtomicDocumentUnitPublicationWriter;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitExecutionContext;
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
}
