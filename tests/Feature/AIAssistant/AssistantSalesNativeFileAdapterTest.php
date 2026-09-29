<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Models\AIAssistantDocument;
use App\BusinessModules\Features\AIAssistant\Models\AIAssistantDocumentUnit;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\AIAssistant\Services\Documents\AssistantDocumentService;
use App\BusinessModules\Features\AIAssistant\Services\Documents\AssistantSalesNativeFileAdapter;
use App\BusinessModules\Features\CommercialProposals\Models\CommercialProposal;
use App\BusinessModules\Features\CommercialProposals\Models\CommercialProposalVersion;
use App\BusinessModules\Features\CommercialProposals\Models\CommercialProposalFile;
use App\BusinessModules\Features\CommercialProposals\Models\CommercialProposalExport;
use App\Models\Project;
use App\BusinessModules\Features\Crm\Models\CrmImportBatch;
use App\BusinessModules\Features\Procurement\Models\PurchaseOrder;
use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Supplier;
use App\Services\Modules\PackageCatalogService;
use App\Services\Storage\FileService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Support\AssistantRealAuthorizationFixture;
use Tests\TestCase;

final class AssistantSalesNativeFileAdapterTest extends TestCase
{
    public function test_native_crm_uuid_document_is_mapped_without_fake_file_id_and_processed_by_actual_pipeline(): void
    {
        [$fixture, $batch, $adapter] = $this->fixture();
        $document = $adapter->map($fixture->owner, $fixture->organization->id, 'crm_import_batch', $batch->id);
        self::assertNull($document->file_id);
        self::assertSame($batch->id, $document->parent_entity_id);
        self::assertSame('crm_import_batch', $document->parent_entity_type);
        self::assertSame('sales_native', $document->metadata['assistant_native_source']);
        self::assertSame(hash('sha256', "name,email\nQA,qa@example.test\n"), $document->checksum);
        $processed = app(AssistantDocumentService::class)->process($document->id);
        self::assertSame('ready', $processed->status);
        self::assertStringContainsString('qa@example.test', $processed->extracted_text);
        self::assertGreaterThan(0, AIAssistantDocumentUnit::query()->where('document_id', $document->id)->count());
        self::assertSame('sales_native', $processed->metadata['assistant_native_source']);
        $again = $adapter->map($fixture->owner, $fixture->organization->id, 'crm_import_batch', $batch->id);
        self::assertSame($document->id, $again->id);
        self::assertSame(1, AIAssistantDocument::query()->where('parent_entity_id', $batch->id)->count());
    }

    public function test_current_role_revocation_blocks_cached_native_body_before_storage(): void
    {
        [$fixture, $batch, $adapter] = $this->fixture();
        $document = $adapter->map($fixture->owner, $fixture->organization->id, 'crm_import_batch', $batch->id);
        UserRoleAssignment::query()->where('user_id', $fixture->owner->id)->update(['is_active' => false]);
        $denied = $this->noStorageAdapter();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ai_assistant_document_access_denied');
        $denied->content($fixture->owner, $fixture->organization->id, $document);
    }

    public function test_changed_source_path_invalidates_both_current_read_and_sql_mapping_before_storage(): void
    {
        [$fixture, $batch, $adapter] = $this->fixture();
        $document = $adapter->map($fixture->owner, $fixture->organization->id, 'crm_import_batch', $batch->id);
        $batch->update(['stored_path' => 'org-'.$fixture->organization->id.'/crm/imports/'.Str::uuid().'.csv']);
        $query = AIAssistantDocument::query()->whereKey($document->id);
        $adapter->constrainDocuments($query);
        self::assertSame(0, $query->count());
        $denied = $this->noStorageAdapter();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ai_assistant_document_native_source_invalid');
        $denied->content($fixture->owner, $fixture->organization->id, $document);
    }

    public function test_changed_native_bytes_are_rejected_even_when_database_source_is_unchanged(): void
    {
        [$fixture, $batch, $adapter] = $this->fixture();
        $document = $adapter->map($fixture->owner, $fixture->organization->id, 'crm_import_batch', $batch->id);
        $changed = $this->adapterWithContent("name,email\nTampered,other@example.test\n");
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ai_assistant_document_checksum_changed');
        $changed->content($fixture->owner, $fixture->organization->id, $document);
    }

    public function test_metadata_only_purchase_order_right_never_opens_the_financial_pdf(): void
    {
        [$fixture] = $this->fixture();
        $order = Model::withoutEvents(function () use ($fixture): PurchaseOrder {
            $supplier = Supplier::query()->create(['organization_id' => $fixture->organization->id, 'name' => 'РџРѕСЃС‚Р°РІС‰РёРє QA']);
            $order = PurchaseOrder::query()->create(['organization_id' => $fixture->organization->id, 'supplier_id' => $supplier->id,
                'order_number' => 'QA-'.Str::uuid(), 'order_date' => '2026-09-29', 'total_amount' => '100.00',
                'metadata' => []]);
            $order->update(['metadata' => ['pdf_path' => 'org-'.$fixture->organization->id.'/procurement/purchase-orders/system/order-'.$order->id.'/'.Str::uuid().'.pdf', 'pdf_sha256' => str_repeat('a', 64), 'pdf_size_bytes' => 10, 'pdf_mime' => 'application/pdf']]);
            return $order;
        });
        $member = $fixture->addMember(['ai-assistant' => ['ai_assistant.chat'], 'procurement' => ['procurement.purchase_orders.view']]);
        self::assertTrue(app(AuthorizationService::class)->canCurrent($member, 'procurement.purchase_orders.view', ['organization_id' => $fixture->organization->id]));
        self::assertFalse(app(AuthorizationService::class)->canCurrent($member, 'finance.view', ['organization_id' => $fixture->organization->id]));
        $adapter = $this->noStorageAdapter();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ai_assistant_document_access_denied');
        $adapter->map($member, $fixture->organization->id, 'purchase_order', $order->id);
    }

    public function test_actual_proposal_attachment_and_ready_export_use_current_parent_scope_and_real_uuid(): void
    {
        [$fixture] = $this->fixture();
        [$file, $export] = Model::withoutEvents(function () use ($fixture): array {
            $project = Project::factory()->create(['organization_id' => $fixture->organization->id]);
            $proposal = CommercialProposal::query()->create(['organization_id' => $fixture->organization->id, 'project_id' => $project->id, 'number' => 'QA-'.Str::uuid(), 'title' => 'Предложение QA']);
            $version = CommercialProposalVersion::query()->create(['organization_id' => $fixture->organization->id, 'commercial_proposal_id' => $proposal->id, 'version_number' => 1, 'title' => 'Версия QA', 'content_hash' => str_repeat('a', 64)]);
            $prefix = 'org-'.$fixture->organization->id.'/commercial-proposals/'.$proposal->id.'/versions/'.$version->id.'/';
            $file = CommercialProposalFile::query()->create(['organization_id' => $fixture->organization->id, 'commercial_proposal_id' => $proposal->id, 'commercial_proposal_version_id' => $version->id, 'category' => 'attachment', 'original_name' => 'qa.txt', 'storage_path' => $prefix.'attachment/'.Str::uuid().'.txt', 'mime_type' => 'text/plain', 'size_bytes' => 10, 'uploaded_by_user_id' => $fixture->owner->id]);
            $export = CommercialProposalExport::query()->create(['organization_id' => $fixture->organization->id, 'commercial_proposal_id' => $proposal->id, 'commercial_proposal_version_id' => $version->id, 'requested_by_user_id' => $fixture->owner->id, 'format' => 'html', 'status' => 'ready', 'content_hash' => str_repeat('b', 64), 'storage_path' => $prefix.'generated_export/qa-v1-0123456789ab.html', 'generated_at' => now()]);
            return [$file, $export];
        });
        $adapter = $this->adapterWithContent('Native QA.');
        foreach (['commercial_proposal_file' => $file, 'commercial_proposal_export' => $export] as $type => $source) {
            $document = $adapter->map($fixture->owner, $fixture->organization->id, $type, $source->id);
            self::assertNull($document->file_id);
            self::assertSame($source->id, $document->parent_entity_id);
            self::assertNotNull($document->project_id);
            $query = AIAssistantDocument::query()->whereKey($document->id);
            $adapter->constrainDocuments($query);
            self::assertSame(1, $query->count());
            $processed = app(AssistantDocumentService::class)->process($document->id);
            self::assertSame('ready', $processed->status);
            self::assertStringContainsString('Native QA.', $processed->extracted_text);
        }
    }

    public function test_initial_native_indexing_keeps_every_uuid_beyond_fifty_and_dispatches_extraction_after_commit(): void
    {
        [$fixture, $first] = $this->fixture();
        $ids = [$first->id];
        Model::withoutEvents(function () use ($fixture, &$ids): void {
            for ($i = 0; $i < 52; $i++) {
                $ids[] = CrmImportBatch::query()->create(['organization_id' => $fixture->organization->id,
                    'entity_type' => 'companies', 'source_format' => 'csv', 'status' => 'previewed', 'original_filename' => 'qa.csv',
                    'stored_path' => 'org-'.$fixture->organization->id.'/crm/imports/'.Str::uuid().'.csv', 'total_rows' => 1,
                    'uploaded_by_user_id' => $fixture->owner->id])->id;
            }
        });
        \Illuminate\Support\Facades\Queue::fake([\App\Jobs\ProcessAssistantDocument::class]);
        \Illuminate\Support\Facades\DB::beginTransaction();
        app(\App\BusinessModules\Features\AIAssistant\Services\Documents\AssistantSalesNativeFileIndexer::class)->prepare($fixture->organization->id);
        \Illuminate\Support\Facades\Queue::assertNotPushed(\App\Jobs\ProcessAssistantDocument::class);
        \Illuminate\Support\Facades\DB::commit();
        $documents = AIAssistantDocument::query()->where('organization_id', $fixture->organization->id)->where('parent_entity_type', 'crm_import_batch')->get();
        self::assertCount(53, $documents);
        self::assertEqualsCanonicalizing($ids, $documents->pluck('parent_entity_id')->all());
        foreach ($documents as $document) {
            self::assertNull($document->file_id);
            \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\ProcessAssistantDocument::class, static fn (\App\Jobs\ProcessAssistantDocument $job): bool => $job->documentId === $document->id);
        }
    }

    private function noStorageAdapter(): AssistantSalesNativeFileAdapter
    {
        $storage = $this->createMock(FileService::class);
        $storage->expects(self::never())->method('readCurrentBounded');
        return new AssistantSalesNativeFileAdapter(app(AssistantDataAccessPolicy::class), app(AuthorizationService::class), $storage);
    }

    private function adapterWithContent(string $content): AssistantSalesNativeFileAdapter
    {
        $storage = $this->createMock(FileService::class);
        $storage->method('readCurrentBounded')->willReturnCallback(static function () use ($content) {
            $stream = fopen('php://temp', 'w+b');
            fwrite($stream, $content);
            rewind($stream);
            return $stream;
        });
        $this->app->instance(FileService::class, $storage);
        $adapter = new AssistantSalesNativeFileAdapter(app(AssistantDataAccessPolicy::class), app(AuthorizationService::class), $storage);
        $this->app->instance(AssistantSalesNativeFileAdapter::class, $adapter);
        return $adapter;
    }

    private function fixture(): array
    {
        $fixture = AssistantRealAuthorizationFixture::create(array_column(app(PackageCatalogService::class)->allPackages(), 'slug'));
        $batch = Model::withoutEvents(fn () => CrmImportBatch::query()->create(['organization_id' => $fixture->organization->id,
            'entity_type' => 'companies', 'source_format' => 'csv', 'status' => 'previewed', 'original_filename' => 'qa.csv',
            'stored_path' => 'org-'.$fixture->organization->id.'/crm/imports/'.Str::uuid().'.csv', 'total_rows' => 1, 'uploaded_by_user_id' => $fixture->owner->id]));
        return [$fixture, $batch, $this->adapterWithContent("name,email\nQA,qa@example.test\n")];
    }
}
