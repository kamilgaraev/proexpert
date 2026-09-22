<?php

declare(strict_types=1);

namespace Tests\Feature\ExecutiveDocumentation;

use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocument;
use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocumentLegalArchiveVersion;
use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocumentSet;
use App\BusinessModules\Features\ExecutiveDocumentation\Services\ExecutiveDocumentLegalArchiveService;
use App\BusinessModules\Features\ExecutiveDocumentation\Services\ExecutiveDocumentationService;
use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocumentVersion;
use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Project;
use App\Modules\Core\AccessController;
use App\Services\LegalArchive\Audit\LegalDocumentAudit;
use Dompdf\Dompdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;
use Illuminate\Database\Eloquent\ModelNotFoundException;

final class ExecutiveDocumentLegalArchiveTest extends TestCase
{
    use RefreshDatabase;

    public function test_link_creates_an_unsigned_legal_archive_version_and_replays_by_source_version(): void
    {
        Storage::fake('s3');
        [$context, $document, $source] = $this->fixture($this->pdfContent('source-content'));
        $service = app(ExecutiveDocumentLegalArchiveService::class);

        $linked = $service->link($source->id, $context->user->id);
        $replayed = $service->link($source->id, $context->user->id);

        self::assertSame($linked->id, $replayed->id);
        self::assertSame('uploaded', (string) $linked->status);
        self::assertSame('ready', (string) $linked->processing_status);
        self::assertNotSame('signed', (string) $linked->status);
        self::assertNotSame('frozen', (string) $linked->status);
        self::assertSame(1, ExecutiveDocumentLegalArchiveVersion::query()
            ->where('executive_document_version_id', $source->id)->count());
        self::assertSame($document->organization_id, $linked->organization_id);
    }

    public function test_link_rejects_a_changed_source_object(): void
    {
        Storage::fake('s3');
        [$context, $document, $source] = $this->fixture($this->pdfContent('original-content'));
        Storage::disk('s3')->put($source->file_url, 'tampered-content');

        try {
            app(ExecutiveDocumentLegalArchiveService::class)->link($source->id, $context->user->id);
            self::fail('Tampered source must be rejected.');
        } catch (\App\Exceptions\BusinessLogicException) {
            self::assertTrue(true);
        }

        self::assertSame(0, ExecutiveDocumentLegalArchiveVersion::query()->count());
        self::assertSame(0, LegalArchiveDocumentVersion::query()->count());
    }

    public function test_link_requires_legal_archive_create_permission_before_registry_side_effects(): void
    {
        Storage::fake('s3');
        [$context, $document, $source] = $this->fixture($this->pdfContent('permission'), false);

        try {
            app(ExecutiveDocumentLegalArchiveService::class)->link($source->id, $context->user->id);
            self::fail('LegalArchive permission must be enforced.');
        } catch (\App\Exceptions\BusinessLogicException $exception) {
            self::assertSame(403, $exception->getCode());
        }

        self::assertSame(0, LegalArchiveDocument::query()->count());
        self::assertSame(0, LegalArchiveDocumentVersion::query()->count());
        self::assertSame(0, ExecutiveDocumentLegalArchiveVersion::query()->count());
    }

    public function test_mapping_failure_is_recovered_without_second_legal_archive_upload(): void
    {
        Storage::fake('s3');
        [$context, $document, $source] = $this->fixture($this->pdfContent('mapping-retry'));
        $failed = true;
        Event::listen('eloquent.creating: '.ExecutiveDocumentLegalArchiveVersion::class, static function () use (&$failed): void {
            if ($failed) {
                $failed = false;
                throw new \RuntimeException('mapping_insert_failed');
            }
        });

        try {
            app(ExecutiveDocumentLegalArchiveService::class)->link($source->id, $context->user->id);
            self::fail('The first mapping insert must fail.');
        } catch (\RuntimeException $exception) {
            self::assertSame('mapping_insert_failed', $exception->getMessage());
        } finally {
            Event::forget('eloquent.creating: '.ExecutiveDocumentLegalArchiveVersion::class);
        }

        $storedBeforeReplay = Storage::disk('s3')->allFiles();
        $replayed = app(ExecutiveDocumentLegalArchiveService::class)->link($source->id, $context->user->id);
        $storedAfterReplay = Storage::disk('s3')->allFiles();

        self::assertSame(1, LegalArchiveDocumentVersion::query()->count());
        self::assertSame(1, ExecutiveDocumentLegalArchiveVersion::query()->count());
        self::assertSame($source->content_hash, $replayed->content_hash);
        self::assertSame($storedBeforeReplay, $storedAfterReplay);
    }

    public function test_same_org_mapping_with_foreign_archive_identity_is_rejected(): void
    {
        Storage::fake('s3');
        [$context, $document, $source] = $this->fixture($this->pdfContent('foreign-identity'));
        $archive = LegalArchiveDocument::query()->create([
            'organization_id' => $document->organization_id,
            'primary_project_id' => $document->project_id,
            'title' => 'Foreign archive identity',
            'document_type' => 'executive_document',
            'source_type' => 'executive_document',
            'source_id' => (string) ($document->id + 1000),
            'source_create_status' => 'completed',
        ]);
        $archiveVersion = LegalArchiveDocumentVersion::query()->create([
            'document_id' => $archive->id,
            'organization_id' => $document->organization_id,
            'version_number' => '1.0',
            'status' => 'uploaded',
            'processing_status' => 'ready',
            'file_path' => 'org-'.$document->organization_id.'/foreign.pdf',
            'original_filename' => 'foreign.pdf',
            'size_bytes' => 1,
            'content_hash' => $source->content_hash,
            'metadata' => ['executive_document_version_id' => $source->id],
        ]);
        ExecutiveDocumentLegalArchiveVersion::query()->create([
            'organization_id' => $document->organization_id,
            'executive_document_version_id' => $source->id,
            'legal_archive_document_version_id' => $archiveVersion->id,
            'source_content_hash' => $source->content_hash,
        ]);

        $this->expectException(ModelNotFoundException::class);
        app(ExecutiveDocumentLegalArchiveService::class)->link($source->id, $context->user->id);
    }

    #[DataProvider('recoveryStatuses')]
    public function test_failed_or_quarantined_recovery_version_is_not_mapped(string $processingStatus): void
    {
        Storage::fake('s3');
        [$context, $document, $source] = $this->fixture($this->pdfContent('failed-recovery'));
        $archive = LegalArchiveDocument::query()->create([
            'organization_id' => $document->organization_id,
            'primary_project_id' => $document->project_id,
            'title' => $document->title,
            'document_type' => 'executive_document',
            'source_type' => 'executive_document',
            'source_id' => (string) $document->id,
            'source_create_status' => 'completed',
        ]);
        LegalArchiveDocumentVersion::query()->create([
            'document_id' => $archive->id,
            'organization_id' => $document->organization_id,
            'version_number' => '1.0',
            'status' => 'uploaded',
            'processing_status' => $processingStatus,
            'file_path' => 'org-'.$document->organization_id.'/failed.pdf',
            'original_filename' => 'failed.pdf',
            'size_bytes' => 1,
            'content_hash' => $source->content_hash,
            'metadata' => ['executive_document_version_id' => $source->id],
        ]);

        try {
            app(ExecutiveDocumentLegalArchiveService::class)->link($source->id, $context->user->id);
            self::fail('Failed recovery version must not be returned as ready.');
        } catch (\App\Exceptions\BusinessLogicException $exception) {
            self::assertSame(409, $exception->getCode());
        }

        self::assertSame(0, ExecutiveDocumentLegalArchiveVersion::query()->count());
    }

    public static function recoveryStatuses(): array
    {
        return [['failed'], ['quarantine']];
    }

    private function fixture(string $content, bool $allowLegalArchive = true): array
    {
        $this->allowAccess($allowLegalArchive);
        $this->app->instance(LegalDocumentAudit::class, Mockery::mock(LegalDocumentAudit::class)->shouldIgnoreMissing());
        $context = AdminApiTestContext::create(roleSlug: 'organization_owner');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $set = ExecutiveDocumentSet::query()->create([
            'organization_id' => $context->organization->id,
            'project_id' => $project->id,
            'created_by' => $context->user->id,
            'set_number' => 'SET-'.uniqid(),
            'title' => 'Legal archive set',
            'status' => 'draft',
        ]);
        $document = ExecutiveDocument::query()->create([
            'organization_id' => $context->organization->id,
            'project_id' => $project->id,
            'document_set_id' => $set->id,
            'created_by' => $context->user->id,
            'document_type' => 'working_drawing_set',
            'title' => 'Legal archive document',
            'status' => 'draft',
            'profile_data' => ['drawing_set_code' => 'RD-BASE'],
        ]);
        $source = app(ExecutiveDocumentationService::class)->addVersion($document, $context->user->id, [
            'version_number' => '1.0',
            'operation_key' => 'legal-archive-source-'.uniqid(),
            'file' => UploadedFile::fake()->createWithContent('source.pdf', $content),
        ]);

        return [$context, $document, $source];
    }

    private function allowAccess(bool $allowLegalArchive): void
    {
        $this->app->forgetInstance(AuthorizationService::class);
        $this->app->forgetInstance(\App\Domain\Authorization\Services\ModulePermissionChecker::class);
        $this->app->forgetInstance(\App\Domain\Authorization\Services\PermissionResolver::class);
        $this->mock(AccessController::class, function (MockInterface $mock): void {
            $mock->shouldReceive('hasModuleAccess')->andReturn(true);
        });
        $this->mock(AuthorizationService::class, function (MockInterface $mock) use ($allowLegalArchive): void {
            $mock->shouldReceive('can')->andReturnUsing(
                static fn (mixed $actor, string $permission): bool => $permission !== 'legal_archive.create' || $allowLegalArchive,
            );
        });
    }

    private function pdfContent(string $label): string
    {
        $dompdf = new Dompdf;
        $dompdf->loadHtml('<p>'.htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</p>');
        $dompdf->render();

        return $dompdf->output();
    }
}
