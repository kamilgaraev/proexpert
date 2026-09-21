<?php

declare(strict_types=1);

namespace Tests\Feature\ConstructionJournal;

use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocument;
use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocumentLegalArchiveVersion;
use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocumentSet;
use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocumentVersion;
use App\BusinessModules\Features\ExecutiveDocumentation\Services\ExecutiveDocumentationService;
use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument;
use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocumentVersion;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Exceptions\BusinessLogicException;
use App\Models\ConstructionJournal;
use App\Models\Project;
use App\Modules\Core\AccessController;
use App\Services\ConstructionJournal\GeneralJournalSignedDocumentRows;
use App\Services\LegalArchive\Audit\LegalDocumentAudit;
use App\Services\LegalArchive\Signatures\SignerIdentitySet;
use Dompdf\Dompdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class GeneralJournalSignedDocumentRowsTest extends TestCase
{
    use RefreshDatabase;

    public function test_same_project_document_without_journal_entry_is_printed_with_all_signers(): void
    {
        Storage::fake('s3');
        [$context, $journal, $source] = $this->fixture(['signatures' => true]);

        $result = app(GeneralJournalSignedDocumentRows::class)->rows($journal, [$source->id]);

        self::assertCount(1, $result['rows']);
        self::assertIsString($result['rows'][0]['document']);
        self::assertIsString($result['rows'][0]['signed']);
        self::assertStringContainsString('Иван Петров', $result['rows'][0]['signed']);
        self::assertStringContainsString('Анна Сидорова', $result['rows'][0]['signed']);
        self::assertStringContainsString('Главный инженер', $result['rows'][0]['signed']);
        self::assertStringContainsString('20.09.2026', $result['rows'][0]['signed']);
        self::assertStringNotContainsString('working_drawing_set', $result['rows'][0]['document']);
        self::assertStringNotContainsString('2026-09-20T', $result['rows'][0]['document']);
        self::assertCount(2, $result['sources'][0]['signature_ids']);
        self::assertCount(2, $result['sources'][0]['signatures']);
        self::assertSame($source->id, $result['sources'][0]['source_version_id']);
    }

    public function test_approved_but_unsigned_archive_version_is_rejected(): void
    {
        Storage::fake('s3');
        [, $journal, $source] = $this->fixture(['signatures' => false]);

        try {
            app(GeneralJournalSignedDocumentRows::class)->rows($journal, [$source->id]);
            self::fail('Unsigned archive version must be rejected.');
        } catch (BusinessLogicException $exception) {
            self::assertSame(422, $exception->getCode());
        }
    }

    public function test_foreign_organization_or_project_version_is_rejected(): void
    {
        Storage::fake('s3');
        [, $journal] = $this->fixture(['signatures' => true]);
        [, , $foreignSource] = $this->fixture(['signatures' => true]);

        try {
            app(GeneralJournalSignedDocumentRows::class)->rows($journal, [$foreignSource->id]);
            self::fail('Foreign source version must be rejected.');
        } catch (BusinessLogicException $exception) {
            self::assertSame(404, $exception->getCode());
        }
    }

    public function test_same_organization_other_project_version_is_rejected(): void
    {
        Storage::fake('s3');
        [$context, $journal] = $this->fixture(['signatures' => true]);
        $otherProject = Project::factory()->create(['organization_id' => $context->organization->id]);
        [, , $source] = $this->fixture(['signatures' => true], $context, $otherProject);

        try {
            app(GeneralJournalSignedDocumentRows::class)->rows($journal, [$source->id]);
            self::fail('Same organization foreign project must be rejected.');
        } catch (BusinessLogicException $exception) {
            self::assertSame(404, $exception->getCode());
        }
    }

    public function test_archive_metadata_or_hash_mismatch_is_rejected(): void
    {
        Storage::fake('s3');
        [, $journal, $source] = $this->fixture([
            'signatures' => true,
            'metadata_hash' => str_repeat('f', 64),
        ]);

        try {
            app(GeneralJournalSignedDocumentRows::class)->rows($journal, [$source->id]);
            self::fail('Archive metadata mismatch must be rejected.');
        } catch (BusinessLogicException $exception) {
            self::assertSame(422, $exception->getCode());
        }
    }

    private function fixture(array $options, ?AdminApiTestContext $existingContext = null, ?Project $existingProject = null): array
    {
        $this->mock(AccessController::class, function ($mock): void {
            $mock->shouldReceive('hasModuleAccess')->andReturnTrue();
        });
        $this->mock(AuthorizationService::class, function ($mock): void {
            $mock->shouldReceive('can')->andReturnTrue();
            $mock->shouldReceive('canAccessInterface')->andReturnTrue();
            $mock->shouldReceive('hasRole')->andReturnTrue();
            $mock->shouldReceive('getUserRoleSlugs')->andReturn(['organization_owner']);
        });
        $this->app->instance(LegalDocumentAudit::class, Mockery::mock(LegalDocumentAudit::class)->shouldIgnoreMissing());
        $context = $existingContext ?? AdminApiTestContext::create(roleSlug: 'organization_owner');
        $project = $existingProject ?? Project::factory()->create(['organization_id' => $context->organization->id]);
        $journal = ConstructionJournal::query()->create([
            'organization_id' => $context->organization->id,
            'project_id' => $project->id,
            'name' => 'Общий журнал работ',
            'journal_number' => 'ОЖР-'.uniqid(),
            'start_date' => '2026-09-20',
            'status' => 'active',
            'created_by_user_id' => $context->user->id,
        ]);
        $set = ExecutiveDocumentSet::query()->create([
            'organization_id' => $context->organization->id,
            'project_id' => $project->id,
            'created_by' => $context->user->id,
            'set_number' => 'SET-'.uniqid(),
            'title' => 'Комплект исполнительной документации',
            'status' => 'draft',
        ]);
        $document = ExecutiveDocument::query()->create([
            'organization_id' => $context->organization->id,
            'project_id' => $project->id,
            'document_set_id' => $set->id,
            'created_by' => $context->user->id,
            'document_type' => 'working_drawing_set',
            'title' => 'Акт освидетельствования скрытых работ',
            'status' => 'draft',
            'section_name' => 'Секция 1',
            'work_type_name' => 'Монтаж сетей',
            'document_date' => '2026-09-20',
            'profile_data' => ['drawing_set_code' => 'РД-1'],
        ]);
        $source = app(ExecutiveDocumentationService::class)->addVersion($document, $context->user->id, [
            'version_number' => '1.0',
            'operation_key' => 'journal-rows-'.uniqid(),
            'file' => UploadedFile::fake()->createWithContent('source.pdf', $this->pdfContent()),
        ]);
        $hash = (string) $source->content_hash;
        $archive = LegalArchiveDocument::query()->create([
            'organization_id' => $document->organization_id,
            'primary_project_id' => $document->project_id,
            'title' => $document->title,
            'document_type' => 'executive_document',
            'source_type' => 'executive_document',
            'source_id' => (string) $document->id,
            'source_create_status' => 'completed',
        ]);
        $archiveVersion = LegalArchiveDocumentVersion::query()->create([
            'document_id' => $archive->id,
            'organization_id' => $document->organization_id,
            'version_number' => '1.0',
            'status' => 'uploaded',
            'processing_status' => 'ready',
            'file_path' => 'org-'.$document->organization_id.'/journal-rows.pdf',
            'original_filename' => 'journal-rows.pdf',
            'size_bytes' => 1,
            'content_hash' => $hash,
            'metadata' => [
                'executive_document_version_id' => $source->id,
                'executive_document_content_hash' => $options['metadata_hash'] ?? $hash,
            ],
        ]);
        ExecutiveDocumentLegalArchiveVersion::query()->create([
            'organization_id' => $document->organization_id,
            'executive_document_version_id' => $source->id,
            'legal_archive_document_version_id' => $archiveVersion->id,
            'source_content_hash' => $hash,
        ]);
        if (($options['signatures'] ?? false) === true) {
            $this->paperSignatures($document->organization_id, $archive->id, $archiveVersion->id, $hash, $context->user->id);
        }

        return [$context, $journal, $source];
    }

    private function paperSignatures(int $organizationId, int $documentId, int $versionId, string $hash, int $userId): void
    {
        $signers = [
            ['kind' => 'manual', 'name' => 'Иван Петров', 'position' => 'Главный инженер', 'authority_basis' => 'Устав'],
            ['kind' => 'manual', 'name' => 'Анна Сидорова', 'position' => 'Представитель заказчика', 'authority_basis' => 'Доверенность'],
        ];
        foreach ($signers as $index => $signer) {
            $key = 'journal-paper-'.$versionId.'-'.$index;
            $identitySet = SignerIdentitySet::fromSnapshot([$signer]);
            $signedAt = '2026-09-20 14:30:00';
            $requestId = DB::table('legal_signature_requests')->insertGetId([
                'organization_id' => $organizationId,
                'document_id' => $documentId,
                'document_version_id' => $versionId,
                'method' => 'paper',
                'status' => 'completed',
                'signed_content_hash' => $hash,
                'signers' => json_encode($identitySet->snapshot(), JSON_THROW_ON_ERROR),
                'signer_snapshot_hash' => $identitySet->hash(),
                'profile_code' => 'executive_document',
                'profile_lock_version' => 1,
                'allowed_signature_kinds' => json_encode(['paper_original'], JSON_THROW_ON_ERROR),
                'required_signature_kinds' => json_encode([], JSON_THROW_ON_ERROR),
                'allowed_signature_formats' => json_encode([], JSON_THROW_ON_ERROR),
                'requirement_snapshot_hash' => str_repeat('f', 64),
                'requirement_group_key' => str_repeat((string) ($index + 1), 64),
                'correlation_id' => hash('sha256', $key),
                'idempotency_key' => $key,
                'request_hash' => str_repeat('d', 64),
                'requested_by_user_id' => $userId,
                'requested_at' => $signedAt,
                'completed_at' => $signedAt,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('legal_document_signatures')->insert([
                'organization_id' => $organizationId,
                'document_id' => $documentId,
                'document_version_id' => $versionId,
                'signature_request_id' => $requestId,
                'method' => 'paper',
                'signer_name' => $signer['name'],
                'signers' => json_encode($identitySet->snapshot(), JSON_THROW_ON_ERROR),
                'signed_content_hash' => $hash,
                'certificate_metadata' => '{}',
                'provider_metadata' => '{}',
                'storage_location' => 'Архив организации',
                'signed_at' => $signedAt,
                'verification_status' => 'registered',
                'signature_kind' => 'paper_original',
                'signer_snapshot_hash' => $identitySet->hash(),
                'authority_confirmed' => true,
                'time_source' => 'operator',
                'diagnostic_code' => 'paper_original_registered',
                'registered_by_user_id' => $userId,
                'idempotency_key' => $key,
                'request_hash' => str_repeat('b', 64),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function pdfContent(): string
    {
        $dompdf = new Dompdf;
        $dompdf->loadHtml('<p>journal rows</p>');
        $dompdf->render();

        return $dompdf->output();
    }
}
