<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Jobs\PrepareAssistantNativeAttachmentsJob;
use App\BusinessModules\Features\AIAssistant\Models\AIAssistantDocument;
use App\BusinessModules\Features\AIAssistant\Models\RagIndexRun;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\AIAssistant\Services\Documents\AssistantLegalNativeFileAdapter;
use App\BusinessModules\Features\AIAssistant\Services\Documents\AssistantLegalNativeFileMetadata;
use App\BusinessModules\Features\AIAssistant\Services\Documents\AssistantLegalNativeFileIndexer;
use App\BusinessModules\Features\AIAssistant\Services\Documents\AssistantNativeAttachmentPreparationQueue;
use App\BusinessModules\Features\AIAssistant\Services\Documents\AssistantOperationsNativeFileIndexer;
use App\BusinessModules\Features\AIAssistant\Services\Documents\AssistantDocumentCoverageService;
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
use App\Services\Storage\FileService;
use Aws\Command;
use Aws\S3\Exception\S3Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Cache;
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

    public function test_coverage_counts_native_path_from_visible_ids_without_exposing_private_projection(): void
    {
        [$fixture] = $this->fixture(true);
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
            self::assertSame(0, AIAssistantDocument::query()->where('organization_id', $fixture->organization->id)->count());
        }

        $currentFailure = $this->s3Exception('NoSuchKey', 404);
        $job->handle($legal, $operations, $queue);

        self::assertTrue($queue->isComplete((int) $run->id));
        self::assertSame('complete', $queue->pageProgress((int) $run->id, 'legal_business', 0, null)['state']);
        self::assertSame(0, AIAssistantDocument::query()
            ->where('organization_id', $fixture->organization->id)
            ->where('parent_entity_id', (string) $missingVersion->id)
            ->count());
        self::assertSame(1, AIAssistantDocument::query()
            ->where('organization_id', $fixture->organization->id)
            ->where('parent_entity_id', (string) $secondVersion->id)
            ->where('status', AIAssistantDocument::STATUS_QUEUED)
            ->count());

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

    private function fixture(bool $nonfinancial = false): array
    {
        $this->content = $this->pdf($nonfinancial ? 'Confidentiality obligations only' : 'Invoice total 1234.56');
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
            'file_path'=>'org-'.$fixture->organization->id.'/legal-archive/files/'.$documentFile->id.'/versions/12345678-1234-1234-1234-123456789abc.pdf',
            'original_filename'=>'Договор.pdf','mime_type'=>'application/pdf','size_bytes'=>strlen($this->content),'content_hash'=>hash('sha256',$this->content)]));
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
            $this->reads++; $stream = fopen('php://temp','w+b');
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
}
