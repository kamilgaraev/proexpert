<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Jobs\PrepareAssistantNativeAttachmentsJob;
use App\BusinessModules\Features\AIAssistant\Models\AIAssistantDocument;
use App\BusinessModules\Features\AIAssistant\Models\AIAssistantDocumentUnit;
use App\BusinessModules\Features\AIAssistant\Models\RagIndexRun;
use App\BusinessModules\Features\AIAssistant\Models\RagSource;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\AIAssistant\Services\Documents\AssistantLegalNativeFileAdapter;
use App\BusinessModules\Features\AIAssistant\Services\Documents\AssistantLegalNativeFileMetadata;
use App\BusinessModules\Features\AIAssistant\Services\Documents\AssistantLegalNativeFileIndexer;
use App\BusinessModules\Features\AIAssistant\Services\Documents\AssistantNativeAttachmentPreparationQueue;
use App\BusinessModules\Features\AIAssistant\Services\Documents\AssistantOperationsNativeFileIndexer;
use App\BusinessModules\Features\AIAssistant\Services\Documents\AssistantDocumentCoverageService;
use App\BusinessModules\Features\AIAssistant\Services\Documents\AssistantDocumentBudgetService;
use App\BusinessModules\Features\AIAssistant\Services\Documents\AssistantDocumentOcrClient;
use App\BusinessModules\Features\AIAssistant\Services\Documents\AssistantDocumentOcrRenderer;
use App\BusinessModules\Features\AIAssistant\Services\Documents\AssistantDocumentService;
use App\Jobs\ProcessAssistantDocumentOcr;
use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocument;
use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocumentApprovedList;
use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocumentSet;
use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocumentVersion;
use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument;
use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocumentFile;
use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocumentVersion;
use App\BusinessModules\Features\LegalArchive\Models\LegalDocumentAccessGrant;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\File;
use App\Models\Project;
use App\Models\Credits\AICreditProviderUsage;
use App\Models\Credits\AICreditReservation;
use App\Services\Credits\AICreditService;
use App\Services\Storage\FileService;
use Aws\Command;
use Aws\S3\Exception\S3Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Mockery;
use RuntimeException;
use GuzzleHttp\Psr7\Response;
use Tests\Support\AssistantRealAuthorizationFixture;
use Tests\TestCase;

final class AssistantLegalNativeFileAdapterTest extends TestCase
{
    use RefreshDatabase;

    private string $content = 'Invoice total 1234.56';
    private int $reads = 0;
    private ?S3Exception $storageFailure = null;

    public function test_nonfinancial_legal_pdf_maps_without_finance_or_download_permission_under_current_preview_grant(): void
    {
        [$fixture,$version] = $this->fixture(true);
        $adapter = $this->adapter();
        $file = $adapter->map($fixture->member,$fixture->organization->id,'legal_document_version',$version->id);
        self::assertSame($file->id,$adapter->map($fixture->member,$fixture->organization->id,'legal_document_version',$version->id)->id);
        self::assertSame($file->id,$adapter->mapForIndexing($fixture->organization->id,$version->id)?->id);
        self::assertSame(hash('sha256',$this->content),$file->additional_info['native_source_sha256']);
        $adapter->assertReadable($fixture->member,$fixture->organization->id,$file);
        $query = File::query(); $adapter->constrainMappings($query);
        self::assertSame($file->id,$query->firstOrFail()->id);
        self::assertSame(1,File::query()->where('additional_info->assistant_native_source',AssistantLegalNativeFileMetadata::SOURCE)->count());
    }

    public function test_same_path_changed_body_denies_previous_cached_mapping_and_current_row_filters_before_limit(): void
    {
        [$fixture,$version] = $this->fixture();
        $adapter = $this->adapter();
        $file = $adapter->map($fixture->member,$fixture->organization->id,'legal_document_version',$version->id);
        $this->content = str_replace('1234.56','9876.54',$this->content);
        try { $adapter->assertReadable($fixture->member,$fixture->organization->id,$file); self::fail('Changed body must never use old source/cache.'); }
        catch (RuntimeException $exception) { self::assertSame('ai_assistant_document_checksum_changed',$exception->getMessage()); }
        $this->content = str_replace('9876.54','1234.56',$this->content);
        $version->document->delete();
        $query = File::query(); $adapter->constrainMappings($query);
        self::assertSame(0,$query->limit(1)->count());
        $before = $this->reads;
        try { $adapter->assertReadable($fixture->member,$fixture->organization->id,$file); self::fail('Deleted parent must deny before bytes.'); }
        catch (RuntimeException $exception) { self::assertSame('ai_assistant_document_access_denied',$exception->getMessage()); }
        self::assertSame($before,$this->reads);
    }

    public function test_revoked_file_permission_grant_and_foreign_actor_never_touch_storage(): void
    {
        [$fixture,$version,$grant] = $this->fixture();
        $adapter = $this->adapter();
        self::assertTrue($adapter->canReadNativeContent($fixture->member,$fixture->organization->id,'legal_document_version',$version->id));
        self::assertSame(0,$this->reads);
        foreach ([$fixture->foreignOwner] as $actor) {
            self::assertFalse($adapter->canReadNativeContent($actor,$fixture->organization->id,'legal_document_version',$version->id));
            try { $adapter->map($actor,$fixture->organization->id,'legal_document_version',$version->id); self::fail('Foreign actor must deny before bytes.'); }
            catch (RuntimeException $exception) { self::assertSame('ai_assistant_document_access_denied',$exception->getMessage()); }
        }
        self::assertSame(0,$this->reads); self::assertSame(0,File::query()->count());
        $file = $adapter->map($fixture->member,$fixture->organization->id,'legal_document_version',$version->id);
        $before = $this->reads;
        $fixture->memberRole->update(['system_permissions'=>['legal_archive.view']]);
        self::assertFalse($adapter->canReadNativeContent($fixture->member,$fixture->organization->id,'legal_document_version',$version->id));
        try { $adapter->assertReadable($fixture->member,$fixture->organization->id,$file); self::fail('Revoked file permission must deny before bytes.'); }
        catch (RuntimeException $exception) { self::assertSame('ai_assistant_document_access_denied',$exception->getMessage()); }
        self::assertSame($before,$this->reads);
        $fixture->memberRole->update(['system_permissions'=>['legal_archive.view','legal_archive.files.view']]);
        $grant->update(['revoked_at'=>now(),'revoked_by_user_id'=>$fixture->owner->id,'revocation_reason'=>'Отзыв текущего доступа']);
        self::assertNull($adapter->mapForIndexing($fixture->organization->id,$version->id));
        try { $adapter->assertReadable($fixture->member,$fixture->organization->id,$file); self::fail('Revoked grant must deny before bytes.'); }
        catch (RuntimeException $exception) { self::assertSame('ai_assistant_document_access_denied',$exception->getMessage()); }
        self::assertSame($before,$this->reads);
    }
    public function test_executive_native_sources_use_actual_project_fullview_without_finance_and_revoke_before_bytes(): void
    {
        $this->content = $this->pdf();
        $fixture = AssistantRealAuthorizationFixture::create();
        $fixture->memberRole->update(['module_permissions'=>['ai-assistant'=>['ai_assistant.chat'],'project-management'=>['projects.view'],
            'executive-documentation'=>['executive-documentation.view']],'system_permissions'=>[]]);
        $project = Project::withoutEvents(fn () => Project::factory()->create(['organization_id'=>$fixture->organization->id]));
        $fixture->member->assignedProjects()->attach($project->id,['is_active'=>true,'role'=>'member']);
        $set = ExecutiveDocumentSet::withoutEvents(fn () => ExecutiveDocumentSet::query()->create(['organization_id'=>$fixture->organization->id,
            'project_id'=>$project->id,'set_number'=>'AI-NATIVE','title'=>'Комплект','status'=>'draft']));
        $document = ExecutiveDocument::withoutEvents(fn () => ExecutiveDocument::query()->create(['organization_id'=>$fixture->organization->id,
            'project_id'=>$project->id,'document_set_id'=>$set->id,'document_type'=>'hidden_work_act','title'=>'Акт','status'=>'draft']));
        $prefix = 'org-'.$fixture->organization->id.'/executive-documentation/project-'.$project->id.'/';
        $version = ExecutiveDocumentVersion::withoutEvents(fn () => ExecutiveDocumentVersion::query()->create(['organization_id'=>$fixture->organization->id,
            'document_id'=>$document->id,'uploaded_by'=>$fixture->member->id,'version_number'=>'1','status'=>'draft',
            'file_url'=>$prefix.'set-'.$set->id.'/12345678-1234-1234-1234-123456789abc.pdf','content_hash'=>hash('sha256',$this->content)]));
        $list = ExecutiveDocumentApprovedList::withoutEvents(fn () => ExecutiveDocumentApprovedList::query()->create(['organization_id'=>$fixture->organization->id,
            'project_id'=>$project->id,'revision'=>'1','uploaded_by'=>$fixture->member->id,'approved_by_party'=>'Подрядчик','approved_at'=>'2026-09-29',
            'file_url'=>$prefix.'approved-lists/12345678-1234-1234-1234-123456789abc.pdf','file_hash'=>hash('sha256',$this->content),'original_name'=>'Перечень.pdf','items'=>[]]));
        $adapter = $this->adapter(); $files = [];
        foreach (['executive_version'=>$version,'executive_approved_list'=>$list] as $type=>$row) {
            self::assertTrue($adapter->canReadNativeContent($fixture->member,$fixture->organization->id,$type,$row->id));
            $files[] = $file = $adapter->map($fixture->member,$fixture->organization->id,$type,$row->id);
            self::assertSame($project->id,$adapter->projectId($file));
            self::assertSame($file->id,$adapter->mapForIndexing($fixture->organization->id,$row->id,$type)?->id);
        }
        $query = File::query(); $adapter->constrainMappings($query);
        self::assertSame(2,$query->count());
        $fixture->member->assignedProjects()->detach($project->id);
        $before = $this->reads;
        foreach ($files as $file) {
            try { $adapter->assertReadable($fixture->member,$fixture->organization->id,$file); self::fail('Revoked project must deny before bytes.'); }
            catch (RuntimeException $exception) { self::assertSame('ai_assistant_document_access_denied',$exception->getMessage()); }
        }
        self::assertSame($before,$this->reads);
    }

    public function test_coverage_counts_only_ready_legal_paths_from_visible_ids_without_exposing_private_projection(): void
    {
        [$fixture, $readyVersion] = $this->fixture(true);
        $failedVersion = LegalArchiveDocumentVersion::withoutEvents(fn () => LegalArchiveDocumentVersion::query()->create([
            'organization_id' => $fixture->organization->id,
            'document_id' => $readyVersion->document_id,
            'document_file_id' => $readyVersion->document_file_id,
            'uploaded_by_user_id' => $fixture->member->id,
            'version_number' => '2',
            'is_current' => false,
            'status' => 'uploaded',
            'processing_status' => 'failed',
            'file_path' => 'org-'.$fixture->organization->id.'/legal-archive/files/'.$readyVersion->document_file_id.'/versions/87654321-4321-4321-4321-cba987654321.pdf',
            'original_filename' => 'Договор-ошибка.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => strlen($this->content),
            'content_hash' => hash('sha256', $this->content),
        ]));
        self::assertSame('failed', $failedVersion->processing_status);
        self::assertSame(1, AssistantLegalNativeFileMetadata::sourceQuery('legal_document_version', $fixture->organization->id)->count('native_source.id'));

        $coverage = app(AssistantDocumentCoverageService::class)->coverage($fixture->organization->id, $fixture->member);

        self::assertSame(1, $coverage['native_attachment_coverage']['legal_document_version']['expected_file_count']);
        self::assertSame(1, $coverage['native_attachment_coverage']['legal_document_version']['unmapped_file_count']);
        self::assertSame(1, $coverage['document_coverage']['needs_access_review']);
        self::assertSame(0, $this->reads);
        $fixture->memberRole->update(['system_permissions' => ['legal_archive.view']]);
        $revoked = app(AssistantDocumentCoverageService::class)->coverage($fixture->organization->id, $fixture->member);
        self::assertArrayNotHasKey('legal_document_version', $revoked['native_attachment_coverage']);
        self::assertSame(0, $revoked['document_coverage']['total']);
        self::assertSame(0, $this->reads);
    }

    public function test_missing_legal_s3_object_is_skipped_without_blocking_next_source_or_marking_coverage_ready(): void
    {
        [$fixture, $missingVersion] = $this->fixture();
        $secondDocument = LegalArchiveDocument::withoutEvents(fn () => LegalArchiveDocument::query()->create([
            'organization_id' => $fixture->organization->id,
            'title' => 'Дополнительный документ',
            'document_type' => 'nda',
            'status' => 'draft',
            'lifecycle_status' => 'draft',
            'approval_status' => 'not_started',
            'signature_status' => 'unsigned',
            'confidentiality_level' => 'restricted',
            'lock_version' => 0,
        ]));
        $secondFile = LegalArchiveDocumentFile::withoutEvents(fn () => LegalArchiveDocumentFile::query()->create([
            'organization_id' => $fixture->organization->id,
            'document_id' => $secondDocument->id,
            'role' => 'primary',
            'title' => 'Основной документ',
            'sort_order' => 0,
            'is_required' => true,
        ]));
        $secondVersion = LegalArchiveDocumentVersion::withoutEvents(fn () => LegalArchiveDocumentVersion::query()->create([
            'organization_id' => $fixture->organization->id,
            'document_id' => $secondDocument->id,
            'document_file_id' => $secondFile->id,
            'uploaded_by_user_id' => $fixture->member->id,
            'version_number' => '1',
            'is_current' => true,
            'status' => 'uploaded',
            'processing_status' => 'ready',
            'file_path' => 'org-'.$fixture->organization->id.'/legal-archive/files/'.$secondFile->id.'/versions/87654321-1234-1234-1234-123456789abc.pdf',
            'original_filename' => 'Дополнительный документ.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => strlen($this->content),
            'content_hash' => hash('sha256', $this->content),
        ]));
        LegalDocumentAccessGrant::query()->create([
            'organization_id' => $fixture->organization->id,
            'document_id' => $secondDocument->id,
            'subject_kind' => 'internal_user',
            'subject_organization_id' => $fixture->organization->id,
            'subject_user_id' => $fixture->member->id,
            'abilities' => ['view'],
            'granted_by_user_id' => $fixture->owner->id,
        ]);

        config(['cache.default' => 'array']);
        Cache::clearResolvedInstances();
        Queue::fake();
        $nativeAdapter = $this->adapter();
        $missingFileMapping = $nativeAdapter->map($fixture->member, $fixture->organization->id, 'legal_document_version', $missingVersion->id);
        $missingDocument = app(AssistantDocumentService::class)->registerFile($missingFileMapping);
        $missingDocument->update([
            'status' => AIAssistantDocument::STATUS_READY,
            'coverage_status' => 'ready',
            'extracted_text' => 'Previously indexed legal content.',
            'processed_at' => now(),
        ]);
        $missingUnit = AIAssistantDocumentUnit::query()->create([
            'document_id' => $missingDocument->id,
            'unit_type' => 'text_chunk',
            'unit_index' => 0,
            'text' => 'Previously indexed legal content.',
            'checksum' => hash('sha256', 'Previously indexed legal content.'),
        ]);
        $unrelatedFileMapping = $nativeAdapter->map($fixture->member, $fixture->organization->id, 'legal_document_version', $secondVersion->id);
        $unrelatedDocument = app(AssistantDocumentService::class)->registerFile($unrelatedFileMapping);
        $unrelatedDocument->update([
            'status' => AIAssistantDocument::STATUS_READY,
            'coverage_status' => 'ready',
            'extracted_text' => 'Unrelated indexed legal content.',
            'processed_at' => now(),
        ]);
        $missingRagSource = $this->ragSource($missingDocument);
        $unrelatedRagSource = $this->ragSource($unrelatedDocument);
        $missingEvents = [];
        Log::listen(static function (MessageLogged $event) use (&$missingEvents): void {
            if ($event->message === 'ai_assistant.native_attachment_source_missing') {
                $missingEvents[] = $event;
            }
        });
        $currentFailure = $this->s3Exception('AccessDenied', 404);
        $storage = Mockery::mock(FileService::class)->makePartial();
        $storage->shouldReceive('readCurrentBounded')->andReturnUsing(function (...$arguments) use (&$currentFailure, $missingVersion): mixed {
            if (($arguments[0] ?? null) === $missingVersion->file_path) {
                throw $currentFailure;
            }

            $stream = fopen('php://temp', 'w+b');
            if ($stream === false) {
                throw new RuntimeException('fixture_stream_unavailable');
            }
            fwrite($stream, $this->content);
            rewind($stream);

            return $stream;
        });
        $this->app->instance(FileService::class, $storage);

        $run = RagIndexRun::query()->create([
            'organization_id' => $fixture->organization->id,
            'source_type' => 'legal_business',
            'status' => RagIndexRun::STATUS_QUEUED,
            'mode' => RagIndexRun::MODE_ASYNC,
            'queued_at' => now(),
        ]);
        $queue = app(AssistantNativeAttachmentPreparationQueue::class);
        self::assertTrue($queue->dispatchQueuedRun($run));
        $job = Queue::pushed(PrepareAssistantNativeAttachmentsJob::class)->first();
        self::assertInstanceOf(PrepareAssistantNativeAttachmentsJob::class, $job);
        $legal = app(AssistantLegalNativeFileIndexer::class);
        $operations = app(AssistantOperationsNativeFileIndexer::class);

        foreach ([$this->s3Exception('AccessDenied', 404), $this->s3Exception('RequestTimeout', 503)] as $currentFailure) {
            try {
                $job->handle($legal, $operations, $queue);
                self::fail('Other S3 failures must be retried by the queue worker.');
            } catch (S3Exception $exception) {
                self::assertSame($currentFailure, $exception);
            }
            self::assertFalse($queue->isComplete((int) $run->id));
            self::assertSame(2, AIAssistantDocument::query()->where('organization_id', $fixture->organization->id)->count());
        }

        $currentFailure = $this->s3Exception('NoSuchKey', 404);
        $job->handle($legal, $operations, $queue);

        self::assertTrue($queue->isComplete((int) $run->id));
        self::assertSame('complete', $queue->pageProgress((int) $run->id, 'legal_business', 0, null)['state']);
        self::assertTrue(File::withTrashed()->findOrFail($missingFileMapping->id)->trashed());
        self::assertSame(1, File::query()->whereKey($unrelatedFileMapping->id)->count());
        self::assertSame(AIAssistantDocument::STATUS_FAILED, $missingDocument->fresh()->status);
        self::assertSame('needs_access_review', $missingDocument->fresh()->coverage_status);
        self::assertSame('native_source_missing', $missingDocument->fresh()->last_error);
        self::assertSame('Previously indexed legal content.', $missingDocument->fresh()->extracted_text);
        self::assertTrue(AIAssistantDocumentUnit::query()->whereKey($missingUnit->id)->exists());
        self::assertSame(AIAssistantDocument::STATUS_READY, $unrelatedDocument->fresh()->status);
        self::assertSame('ready', $unrelatedDocument->fresh()->coverage_status);
        self::assertFalse(RagSource::query()->whereKey($missingRagSource->id)->exists());
        self::assertTrue(RagSource::query()->whereKey($unrelatedRagSource->id)->exists());

        $coverage = app(AssistantDocumentCoverageService::class)->coverage($fixture->organization->id, $fixture->member);
        self::assertSame(2, $coverage['native_attachment_coverage']['legal_document_version']['expected_file_count']);
        self::assertSame(1, $coverage['native_attachment_coverage']['legal_document_version']['unmapped_file_count']);
        self::assertSame(1, $coverage['document_coverage']['needs_access_review']);
        self::assertFalse($queue->dispatchQueuedRun($run->fresh()));
        self::assertCount(1, Queue::pushed(PrepareAssistantNativeAttachmentsJob::class));
        self::assertCount(1, $missingEvents);
        $context = $missingEvents[0]->context;
        self::assertSame($run->id, $context['run_id']);
        self::assertSame('legal_business', $context['source_type']);
        self::assertSame('legal_document_version', $context['native_type']);
        self::assertSame((string) $missingVersion->id, $context['source_id']);
        self::assertSame('NoSuchKey', $context['storage_error_code']);
        self::assertSame(404, $context['storage_status_code']);
        self::assertArrayNotHasKey('path', $context);
        self::assertArrayNotHasKey('filename', $context);
        self::assertArrayNotHasKey('exception_message', $context);
    }

    public function test_missing_native_object_releases_ocr_and_background_reservations_and_late_failure_preserves_marker_and_units(): void
    {
        [$fixture, $version] = $this->fixture();
        LegalDocumentAccessGrant::query()->create([
            'organization_id' => $fixture->organization->id,
            'document_id' => $version->document_id,
            'subject_kind' => 'internal_user',
            'subject_organization_id' => $fixture->organization->id,
            'subject_user_id' => $fixture->owner->id,
            'abilities' => ['view'],
            'granted_by_user_id' => $fixture->owner->id,
        ]);
        config(['cache.default' => 'array', 'ai-assistant-credits.enforce' => true,
            'ai-assistant.llm.timeweb.api_key' => 'test-key', 'ai-assistant.llm.timeweb.base_uri' => 'https://example.test/v1']);
        Cache::clearResolvedInstances();
        Queue::fake();

        $adapter = $this->adapter();
        $credits = new AICreditService;
        $credits->grant($fixture->organization, 1_000_000, 'purchase', null, 'native-missing-ocr-'.$fixture->organization->id);
        $documents = app(AssistantDocumentService::class);
        $budgets = new AssistantDocumentBudgetService($documents);
        $budgets->approve($fixture->owner, $fixture->organization->id, true, 1_000_000, 'archive');

        $file = $adapter->map($fixture->member, $fixture->organization->id, 'legal_document_version', $version->id);
        $document = $documents->registerFile($file, $fixture->member);
        $previousText = 'Previously recognized legal text.';
        $document->update([
            'status' => AIAssistantDocument::STATUS_OCR_QUOTE_REQUIRED,
            'coverage_status' => 'ocr_quote_required',
            'metadata' => array_merge($document->metadata ?? [], ['page_count' => 1]),
            'extracted_text' => $previousText,
            'processed_at' => now(),
        ]);
        $unit = AIAssistantDocumentUnit::query()->create([
            'document_id' => $document->id,
            'unit_type' => 'ocr_page',
            'unit_index' => 0,
            'text' => $previousText,
            'checksum' => hash('sha256', $previousText),
        ]);
        $unrelatedProject = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $fixture->organization->id]));
        $unrelatedPath = 'org-'.$fixture->organization->id.'/assistant-test/unrelated.pdf';
        $unrelatedText = 'Unrelated document text.';
        $unrelatedFile = File::withoutEvents(fn () => File::query()->create([
            'organization_id' => $fixture->organization->id,
            'fileable_type' => $unrelatedProject->getMorphClass(),
            'fileable_id' => $unrelatedProject->id,
            'user_id' => $fixture->member->id,
            'name' => 'unrelated.pdf',
            'original_name' => 'unrelated.pdf',
            'path' => $unrelatedPath,
            'mime_type' => 'application/pdf',
            'size' => strlen($unrelatedText),
            'disk' => 's3',
            'type' => 'document',
            'category' => 'ai_assistant',
        ]));
        $unrelatedDocument = AIAssistantDocument::withoutEvents(fn () => AIAssistantDocument::query()->create([
            'organization_id' => $fixture->organization->id,
            'project_id' => $unrelatedProject->id,
            'file_id' => $unrelatedFile->id,
            'parent_entity_type' => 'project',
            'parent_entity_id' => (string) $unrelatedProject->id,
            'storage_path' => $unrelatedPath,
            'filename' => 'unrelated.pdf',
            'mime_type' => 'application/pdf',
            'checksum' => hash('sha256', $unrelatedText),
            'size_bytes' => strlen($unrelatedText),
            'status' => AIAssistantDocument::STATUS_READY,
            'coverage_status' => 'ready',
            'extracted_text' => $unrelatedText,
        ]));
        $unrelatedUnit = AIAssistantDocumentUnit::query()->create([
            'document_id' => $unrelatedDocument->id,
            'unit_type' => 'text_chunk',
            'unit_index' => 0,
            'text' => $unrelatedText,
            'checksum' => hash('sha256', $unrelatedText),
        ]);
        self::assertTrue($budgets->authorizeBackground($document->refresh()));
        $document->refresh();
        $reservationId = (int) $document->ocr_reservation_id;
        $settings = $budgets->settings($fixture->owner, $fixture->organization->id);
        self::assertGreaterThan(0, $credits->balance($fixture->organization)['reserved_minor']);
        self::assertGreaterThan(0, $settings->reserved_minor);

        $run = RagIndexRun::query()->create([
            'organization_id' => $fixture->organization->id,
            'source_type' => 'legal_business',
            'status' => RagIndexRun::STATUS_QUEUED,
            'mode' => RagIndexRun::MODE_ASYNC,
            'queued_at' => now(),
        ]);
        $queue = app(AssistantNativeAttachmentPreparationQueue::class);
        self::assertTrue($queue->dispatchQueuedRun($run));
        $job = Queue::pushed(PrepareAssistantNativeAttachmentsJob::class)->first();
        self::assertInstanceOf(PrepareAssistantNativeAttachmentsJob::class, $job);
        $missing = $this->s3Exception('NoSuchKey', 404);
        $storage = Mockery::mock(FileService::class)->makePartial();
        $storage->shouldReceive('readCurrentBounded')->andThrow($missing);
        $this->app->instance(FileService::class, $storage);

        $job->handle(app(AssistantLegalNativeFileIndexer::class), app(AssistantOperationsNativeFileIndexer::class), $queue);

        $reservation = AICreditReservation::query()->findOrFail($reservationId);
        self::assertNotSame('reserved', $reservation->status);
        self::assertSame(0, (int) $reservation->consumed_minor);
        self::assertSame(0, $credits->balance($fixture->organization)['reserved_minor']);
        self::assertSame(0, (int) $settings->fresh()->reserved_minor);
        self::assertArrayNotHasKey('background_budget_minor', $document->fresh()->metadata ?? []);

        (new ProcessAssistantDocumentOcr((int) $document->id))->failed(new RuntimeException('OCR retries exhausted'));

        $document->refresh();
        self::assertSame(AIAssistantDocument::STATUS_FAILED, $document->status);
        self::assertSame('needs_access_review', $document->coverage_status);
        self::assertSame('native_source_missing', $document->last_error);
        self::assertSame($previousText, $document->extracted_text);
        self::assertTrue(AIAssistantDocumentUnit::query()->whereKey($unit->id)->exists());
        self::assertSame(AIAssistantDocument::STATUS_READY, $unrelatedDocument->fresh()->status);
        self::assertSame($unrelatedText, $unrelatedDocument->fresh()->extracted_text);
        self::assertTrue(AIAssistantDocumentUnit::query()->whereKey($unrelatedUnit->id)->exists());
        self::assertSame(1, File::query()->whereKey($unrelatedFile->id)->count());
        self::assertNotSame('reserved', AICreditReservation::query()->findOrFail($reservationId)->status);
        self::assertSame(0, $credits->balance($fixture->organization)['reserved_minor']);
        self::assertSame(0, (int) $settings->fresh()->reserved_minor);
    }

    public function test_native_source_missing_after_ocr_response_releases_background_budget_without_charge(): void
    {
        $imageContent = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true)
            ?: throw new RuntimeException('fixture_png_unavailable');
        [$fixture, $version] = $this->fixture(false, $imageContent, 'image/png', 'png');
        LegalDocumentAccessGrant::query()->create([
            'organization_id' => $fixture->organization->id,
            'document_id' => $version->document_id,
            'subject_kind' => 'internal_user',
            'subject_organization_id' => $fixture->organization->id,
            'subject_user_id' => $fixture->owner->id,
            'abilities' => ['view'],
            'granted_by_user_id' => $fixture->owner->id,
        ]);
        config(['cache.default' => 'array', 'ai-assistant-credits.enforce' => true,
            'ai-assistant.llm.timeweb.api_key' => 'test-key', 'ai-assistant.llm.timeweb.base_uri' => 'https://example.test/v1']);
        Cache::clearResolvedInstances();
        Queue::fake();

        $adapter = $this->adapter();
        $credits = new AICreditService;
        $credits->grant($fixture->organization, 1_000_000, 'purchase', null, 'native-missing-after-ocr-'.$fixture->organization->id);
        $documents = app(AssistantDocumentService::class);
        $budgets = new AssistantDocumentBudgetService($documents);
        $budgets->approve($fixture->owner, $fixture->organization->id, true, 1_000_000, 'archive');

        $file = $adapter->map($fixture->member, $fixture->organization->id, 'legal_document_version', $version->id);
        $document = $documents->registerFile($file, $fixture->member);
        $document->update([
            'status' => AIAssistantDocument::STATUS_OCR_QUOTE_REQUIRED,
            'coverage_status' => 'ocr_quote_required',
            'metadata' => array_merge($document->metadata ?? [], ['page_count' => 1]),
        ]);
        self::assertTrue($budgets->authorizeBackground($document->refresh()));
        $document->refresh();
        $reservation = AICreditReservation::query()->findOrFail($document->ocr_reservation_id);

        Http::fake(['*' => Http::response(['choices' => [['finish_reason' => 'stop', 'message' => ['content' => 'Recognized page text']]],
            'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 10]])]);
        $client = new AssistantDocumentOcrClient(new AssistantDocumentOcrRenderer, $credits);
        self::assertTrue($client->recognize($document, $reservation, $imageContent, static fn (): bool => true));
        self::assertSame('reserved', $reservation->fresh()->status);
        self::assertSame('Recognized page text', AIAssistantDocumentUnit::query()->where('document_id', $document->id)->where('unit_type', 'ocr_page')->sole()->text);

        $this->storageFailure = $this->s3Exception('NoSuchKey', 404);
        try {
            $adapter->mapForIndexing($fixture->organization->id, $version->id);
            self::fail('The missing native source must invalidate the OCR result.');
        } catch (S3Exception $exception) {
            self::assertSame('NoSuchKey', $exception->getAwsErrorCode());
        }

        $document->refresh();
        self::assertSame(AIAssistantDocument::STATUS_FAILED, $document->status);
        self::assertSame('needs_access_review', $document->coverage_status);
        self::assertSame('native_source_missing', $document->last_error);
        $reservation->refresh();
        self::assertNotSame('reserved', $reservation->status);
        self::assertSame(0, (int) $reservation->consumed_minor);
        self::assertSame(0, $credits->balance($fixture->organization)['reserved_minor']);
        $settings = $budgets->settings($fixture->owner, $fixture->organization->id);
        self::assertSame(0, (int) $settings->reserved_minor);
        self::assertSame(0, (int) $settings->spent_minor);
        $usage = AICreditProviderUsage::query()->where('ai_credit_reservation_id', $reservation->id)->where('operation', 'ocr')->sole();
        self::assertSame($credits->costMicroRub(100, 10, $reservation), (int) $usage->cost_micro_rub);
        self::assertTrue($usage->is_successful);
    }

    public function test_native_source_missing_during_ocr_provider_call_suppresses_late_page_and_budget_charge(): void
    {
        $imageContent = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true)
            ?: throw new RuntimeException('fixture_png_unavailable');
        [$fixture, $version] = $this->fixture(false, $imageContent, 'image/png', 'png');
        LegalDocumentAccessGrant::query()->create([
            'organization_id' => $fixture->organization->id,
            'document_id' => $version->document_id,
            'subject_kind' => 'internal_user',
            'subject_organization_id' => $fixture->organization->id,
            'subject_user_id' => $fixture->owner->id,
            'abilities' => ['view'],
            'granted_by_user_id' => $fixture->owner->id,
        ]);
        config(['cache.default' => 'array', 'ai-assistant-credits.enforce' => true,
            'ai-assistant.llm.timeweb.api_key' => 'test-key', 'ai-assistant.llm.timeweb.base_uri' => 'https://example.test/v1']);
        Cache::clearResolvedInstances();
        Queue::fake();

        $adapter = $this->adapter();
        $credits = new AICreditService;
        $credits->grant($fixture->organization, 1_000_000, 'purchase', null, 'native-missing-race-'.$fixture->organization->id);
        $documents = app(AssistantDocumentService::class);
        $budgets = new AssistantDocumentBudgetService($documents);
        $budgets->approve($fixture->owner, $fixture->organization->id, true, 1_000_000, 'archive');

        $file = $adapter->map($fixture->member, $fixture->organization->id, 'legal_document_version', $version->id);
        $document = $documents->registerFile($file, $fixture->member);
        $previousText = 'Previously extracted source text.';
        $document->update([
            'status' => AIAssistantDocument::STATUS_OCR_QUOTE_REQUIRED,
            'coverage_status' => 'ocr_quote_required',
            'metadata' => array_merge($document->metadata ?? [], ['page_count' => 1]),
            'extracted_text' => $previousText,
            'processed_at' => now(),
        ]);
        $preservedUnit = AIAssistantDocumentUnit::query()->create([
            'document_id' => $document->id,
            'unit_type' => 'text_chunk',
            'unit_index' => 0,
            'text' => $previousText,
            'checksum' => hash('sha256', $previousText),
        ]);
        self::assertTrue($budgets->authorizeBackground($document->refresh()));
        $document->refresh();
        $reservationId = (int) $document->ocr_reservation_id;

        Http::fake(function () use ($adapter, $fixture, $version) {
            $this->storageFailure = $this->s3Exception('NoSuchKey', 404);
            try {
                $adapter->mapForIndexing($fixture->organization->id, $version->id);
                self::fail('The native source must be invalidated during the in-flight provider call.');
            } catch (S3Exception $exception) {
                self::assertSame('NoSuchKey', $exception->getAwsErrorCode());
            }

            return Http::response(['choices' => [['finish_reason' => 'stop', 'message' => ['content' => 'Late OCR text']]],
                'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 10]]);
        });

        $result = $documents->processOcr((int) $document->id);

        self::assertSame(AIAssistantDocument::STATUS_FAILED, $result->status);
        self::assertSame('needs_access_review', $result->coverage_status);
        self::assertSame('native_source_missing', $result->last_error);
        self::assertSame($previousText, $result->extracted_text);
        self::assertTrue(AIAssistantDocumentUnit::query()->whereKey($preservedUnit->id)->exists());
        self::assertSame(0, AIAssistantDocumentUnit::query()->where('document_id', $document->id)->where('unit_type', 'ocr_page')->count());
        $reservation = AICreditReservation::query()->findOrFail($reservationId);
        self::assertNotSame('reserved', $reservation->status);
        self::assertSame(0, (int) $reservation->consumed_minor);
        self::assertSame(0, $credits->balance($fixture->organization)['reserved_minor']);
        $settings = $budgets->settings($fixture->owner, $fixture->organization->id);
        self::assertSame(0, (int) $settings->reserved_minor);
        self::assertSame(0, (int) $settings->spent_minor);
        $usage = AICreditProviderUsage::query()->where('ai_credit_reservation_id', $reservationId)->where('operation', 'ocr')->firstOrFail();
        self::assertSame($credits->costMicroRub(100, 10, $reservation), (int) $usage->cost_micro_rub);
        self::assertTrue($usage->is_successful);
        self::assertTrue($usage->metadata['late_provider_result']);
    }

    public function test_native_source_missing_from_ocr_read_check_does_not_reenter_document_cache_lock(): void
    {
        $imageContent = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true)
            ?: throw new RuntimeException('fixture_png_unavailable');
        [$fixture, $version] = $this->fixture(false, $imageContent, 'image/png', 'png');
        LegalDocumentAccessGrant::query()->create([
            'organization_id' => $fixture->organization->id,
            'document_id' => $version->document_id,
            'subject_kind' => 'internal_user',
            'subject_organization_id' => $fixture->organization->id,
            'subject_user_id' => $fixture->owner->id,
            'abilities' => ['view'],
            'granted_by_user_id' => $fixture->owner->id,
        ]);
        config(['cache.default' => 'array', 'ai-assistant-credits.enforce' => true]);
        Cache::clearResolvedInstances();
        Http::fake();

        $adapter = $this->adapter();
        $credits = new AICreditService;
        $credits->grant($fixture->organization, 1_000_000, 'purchase', null, 'native-missing-read-'.$fixture->organization->id);
        $documents = app(AssistantDocumentService::class);
        $file = $adapter->map($fixture->member, $fixture->organization->id, 'legal_document_version', $version->id);
        $document = $documents->registerFile($file, $fixture->member);
        $document->update([
            'status' => AIAssistantDocument::STATUS_OCR_QUOTE_REQUIRED,
            'coverage_status' => 'ocr_quote_required',
            'metadata' => array_merge($document->metadata ?? [], ['page_count' => 1]),
        ]);
        $quote = $documents->quoteOcr($fixture->owner, $fixture->organization->id, $document);
        $approved = $documents->confirmOcr($fixture->owner, $fixture->organization->id, $document,
            $quote['quote_id'], $quote['request_id']);
        $this->storageFailure = $this->s3Exception('NoSuchKey', 404);

        try {
            $documents->processOcr((int) $approved->id);
            self::fail('Missing S3 source during the OCR read check must stop before the provider call.');
        } catch (S3Exception $exception) {
            self::assertSame('NoSuchKey', $exception->getAwsErrorCode());
        }

        $failed = $document->fresh();
        self::assertSame(AIAssistantDocument::STATUS_FAILED, $failed->status);
        self::assertSame('needs_access_review', $failed->coverage_status);
        self::assertSame('native_source_missing', $failed->last_error);
        self::assertNotSame('reserved', AICreditReservation::query()->findOrFail($approved->ocr_reservation_id)->status);
        self::assertSame(0, $credits->balance($fixture->organization)['reserved_minor']);
        Http::assertNothingSent();
    }

    private function fixture(bool $nonfinancial = false, ?string $content = null, string $mimeType = 'application/pdf', string $extension = 'pdf'): array
    {
        $this->content = $content ?? $this->pdf($nonfinancial ? 'Confidentiality obligations only' : 'Invoice total 1234.56');
        $fixture = AssistantRealAuthorizationFixture::create();
        $fixture->memberRole->update(['system_permissions'=>['legal_archive.view','legal_archive.files.view']]);
        $document = LegalArchiveDocument::withoutEvents(fn () => LegalArchiveDocument::query()->create(['organization_id'=>$fixture->organization->id,
            'title'=>'Соглашение о конфиденциальности','document_type'=>'nda','status'=>'draft','lifecycle_status'=>'draft','approval_status'=>'not_started',
            'signature_status'=>'unsigned','confidentiality_level'=>'restricted','lock_version'=>0]));
        $documentFile = LegalArchiveDocumentFile::withoutEvents(fn () => LegalArchiveDocumentFile::query()->create(['organization_id'=>$fixture->organization->id,
            'document_id'=>$document->id,'role'=>'primary','title'=>'Основной документ','sort_order'=>0,'is_required'=>true]));
        $version = LegalArchiveDocumentVersion::withoutEvents(fn () => LegalArchiveDocumentVersion::query()->create(['organization_id'=>$fixture->organization->id,
            'document_id'=>$document->id,'document_file_id'=>$documentFile->id,'uploaded_by_user_id'=>$fixture->member->id,
            'version_number'=>'1','is_current'=>true,'status'=>'uploaded','processing_status'=>'ready',
            'file_path'=>'org-'.$fixture->organization->id.'/legal-archive/files/'.$documentFile->id.'/versions/12345678-1234-1234-1234-123456789abc.'.$extension,
            'original_filename'=>'Договор.'.$extension,'mime_type'=>$mimeType,'size_bytes'=>strlen($this->content),'content_hash'=>hash('sha256',$this->content)]));
        $grant = LegalDocumentAccessGrant::query()->create(['organization_id'=>$fixture->organization->id,'document_id'=>$document->id,
            'subject_kind'=>'internal_user','subject_organization_id'=>$fixture->organization->id,'subject_user_id'=>$fixture->member->id,
            'abilities'=>['view'],'granted_by_user_id'=>$fixture->owner->id]);
        return [$fixture,$version,$grant];
    }

    private function pdf(string $text = 'Invoice total 1234.56'): string
    {
        $stream = 'BT /F1 12 Tf 72 720 Td ('.$text.') Tj ET';
        $objects = ['<< /Type /Catalog /Pages 2 0 R >>','<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',"<< /Length ".strlen($stream)." >>\nstream\n".$stream."\nendstream"];
        $pdf = "%PDF-1.4\n"; $offsets = [];
        foreach ($objects as $index=>$object) { $offsets[] = strlen($pdf); $pdf .= ($index+1)." 0 obj\n".$object."\nendobj\n"; }
        $xref = strlen($pdf); $pdf .= "xref\n0 6\n0000000000 65535 f \n";
        foreach ($offsets as $offset) { $pdf .= sprintf('%010d 00000 n ',$offset)."\n"; }
        return $pdf."trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF";
    }

    private function adapter(): AssistantLegalNativeFileAdapter
    {
        $storage = Mockery::mock(FileService::class)->makePartial();
        $storage->shouldReceive('readCurrentBounded')->andReturnUsing(function (): mixed {
            $this->reads++;
            if ($this->storageFailure instanceof S3Exception) { throw $this->storageFailure; }
            $stream = fopen('php://temp','w+b');
            if ($stream === false) { throw new RuntimeException('fixture_stream_unavailable'); }
            fwrite($stream,$this->content); rewind($stream); return $stream;
        });
        $this->app->instance(FileService::class,$storage);
        return new AssistantLegalNativeFileAdapter(app(AssistantDataAccessPolicy::class),app(AuthorizationService::class),$storage);
    }

    private function s3Exception(string $errorCode, int $statusCode): S3Exception
    {
        return new S3Exception($errorCode, new Command('GetObject', []), [
            'code' => $errorCode,
            'response' => new Response($statusCode),
        ]);
    }

    private function ragSource(AIAssistantDocument $document): RagSource
    {
        return RagSource::query()->create([
            'organization_id' => $document->organization_id,
            'project_id' => $document->project_id,
            'source_type' => 'file_document',
            'entity_type' => 'assistant_document',
            'entity_id' => (string) $document->id,
            'title' => $document->filename,
            'checksum' => hash('sha256', (string) $document->extracted_text),
            'metadata' => [],
            'indexed_at' => now(),
        ]);
    }
}
