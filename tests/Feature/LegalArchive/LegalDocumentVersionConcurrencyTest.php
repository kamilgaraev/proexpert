<?php

declare(strict_types=1);

namespace Tests\Feature\LegalArchive;

use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument;
use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocumentFile;
use App\Exceptions\ImmutableDataException;
use App\Services\LegalArchive\Files\LegalDocumentFilePolicy;
use App\Services\LegalArchive\Files\LegalDocumentFileService;
use App\Services\LegalArchive\Files\LegalDocumentScanFailed;
use App\Services\LegalArchive\Files\LegalDocumentScanner;
use App\Services\LegalArchive\Files\VersionInput;
use App\Services\Storage\FileService;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class LegalDocumentVersionConcurrencyTest extends TestCase
{
    private Capsule $database;

    /** @var array<string, mixed> */
    private array $configuration;

    protected function setUp(): void
    {
        parent::setUp();

        $this->database = new Capsule;
        $this->database->addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        $this->database->setAsGlobal();
        $this->database->setEventDispatcher(new Dispatcher(new Container));
        $this->database->bootEloquent();
        Model::clearBootedModels();

        $this->database->schema()->create('legal_archive_documents', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('current_primary_version_id')->nullable();
            $table->string('title');
            $table->timestamps();
            $table->softDeletes();
        });
        $this->database->schema()->create('legal_archive_document_files', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('document_id');
            $table->unsignedBigInteger('organization_id');
            $table->string('role');
            $table->string('title');
            $table->unsignedBigInteger('current_version_id')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_required')->default(false);
            $table->timestamps();
        });
        $this->database->schema()->create('legal_archive_document_versions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('document_id');
            $table->unsignedBigInteger('document_file_id')->nullable();
            $table->unsignedBigInteger('organization_id');
            $table->string('version_number');
            $table->string('version_label')->nullable();
            $table->boolean('is_current')->default(false);
            $table->string('status');
            $table->string('processing_status');
            $table->text('file_path');
            $table->string('original_filename');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->string('content_hash', 64)->nullable();
            $table->string('metadata_hash', 64)->nullable();
            $table->unsignedBigInteger('uploaded_by_user_id')->nullable();
            $table->timestamp('uploaded_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['document_file_id', 'version_number']);
        });
        $this->database->schema()->create('legal_archive_file_cleanup_debts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->text('storage_path');
            $table->string('reason');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('next_attempt_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });
        $this->database->schema()->create('legal_workflow_instances', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('document_id');
            $table->string('status');
        });

        $this->configuration = [
            'max_size_bytes' => 1024 * 1024,
            'allowed_extensions' => ['pdf'],
            'allowed_mime_types' => ['pdf' => ['application/pdf']],
        ];
    }

    public function test_adds_versions_append_only_and_keeps_exactly_one_current_version(): void
    {
        LegalArchiveDocument::query()->forceCreate(['id' => 10, 'organization_id' => 20, 'title' => 'Договор']);
        $file = LegalArchiveDocumentFile::query()->create([
            'document_id' => 10,
            'organization_id' => 20,
            'role' => 'primary',
            'title' => 'Договор',
        ]);
        $storage = $this->createMock(FileService::class);
        $storage->expects(self::exactly(2))->method('upload')->willReturnOnConsecutiveCalls(
            'org-20/legal-archive/files/1/a.pdf',
            'org-20/legal-archive/files/1/b.pdf',
        );
        $scanner = $this->createMock(LegalDocumentScanner::class);
        $scanner->method('assertClean');
        $service = new LegalDocumentFileService(
            $storage,
            new LegalDocumentFilePolicy($this->configuration),
            $scanner,
            $this->connection(),
        );

        $service->addVersion($file, $this->pdf('first.pdf'), new VersionInput(uploadedByUserId: 30));
        $service->addVersion($file->fresh(), $this->pdf('second.pdf'), new VersionInput(uploadedByUserId: 30));

        self::assertSame(2, $file->versions()->count());
        self::assertSame(1, $file->versions()->where('is_current', true)->count());
        self::assertSame('2', $file->versions()->where('is_current', true)->value('version_number'));
    }

    public function test_deletes_only_newly_uploaded_object_when_persistence_fails(): void
    {
        LegalArchiveDocument::query()->forceCreate(['id' => 10, 'organization_id' => 20, 'title' => 'Договор']);
        $file = LegalArchiveDocumentFile::query()->create([
            'document_id' => 10,
            'organization_id' => 20,
            'role' => 'primary',
            'title' => 'Договор',
        ]);
        $failedPath = 'org-20/legal-archive/files/1/failed.pdf';
        $storage = $this->createMock(FileService::class);
        $storage->method('upload')->willReturn($failedPath);
        $storage->expects(self::once())->method('delete')->with($failedPath, self::callback(
            static fn ($organization): bool => (int) $organization->id === 20,
        ));
        $scanner = $this->createMock(LegalDocumentScanner::class);
        $scanner->method('assertClean');
        $service = new LegalDocumentFileService(
            $storage,
            new LegalDocumentFilePolicy($this->configuration),
            $scanner,
            $this->connection(),
        );

        $this->connection()->statement("CREATE TRIGGER reject_version BEFORE INSERT ON legal_archive_document_versions BEGIN SELECT RAISE(FAIL, 'persistence failed'); END");

        try {
            $service->addVersion($file, $this->pdf('failed.pdf'), new VersionInput(uploadedByUserId: 30));
            self::fail('Persistence failure was expected.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('persistence failed', $exception->getMessage());
        }

        self::assertSame(0, $file->versions()->count());
    }

    public function test_current_version_rotation_is_rejected_while_workflow_is_active(): void
    {
        LegalArchiveDocument::query()->forceCreate(['id' => 10, 'organization_id' => 20, 'title' => 'Contract']);
        $file = LegalArchiveDocumentFile::query()->create([
            'document_id' => 10,
            'organization_id' => 20,
            'role' => 'primary',
            'title' => 'Contract',
        ]);
        $this->connection()->table('legal_workflow_instances')->insert([
            'document_id' => 10,
            'status' => 'in_progress',
        ]);
        $storage = $this->createMock(FileService::class);
        $storage->method('upload')->willReturn('org-20/legal-archive/files/1/blocked.pdf');
        $storage->expects(self::once())->method('delete');
        $scanner = $this->createMock(LegalDocumentScanner::class);
        $scanner->expects(self::never())->method('assertClean');

        try {
            $this->service($storage, $scanner)->addVersion(
                $file,
                $this->pdf('blocked.pdf'),
                new VersionInput(uploadedByUserId: 30),
            );
            self::fail('Current version rotation was allowed during an active workflow.');
        } catch (RuntimeException $exception) {
            self::assertSame('legal_document_active_workflow_exists', $exception->getMessage());
        }

        self::assertSame(0, $file->versions()->count());
    }

    public function test_persists_quarantine_before_scanning_and_transitions_to_ready(): void
    {
        LegalArchiveDocument::query()->forceCreate(['id' => 10, 'organization_id' => 20, 'title' => 'Договор']);
        $file = LegalArchiveDocumentFile::query()->create([
            'document_id' => 10, 'organization_id' => 20, 'role' => 'primary', 'title' => 'Договор',
        ]);
        $storage = $this->createMock(FileService::class);
        $storage->method('upload')->willReturn('org-20/legal-archive/files/1/a.pdf');
        $scanner = $this->createMock(LegalDocumentScanner::class);
        $scanner->expects(self::once())->method('assertClean')->willReturnCallback(function () use ($file): void {
            $quarantine = $file->versions()->firstOrFail();
            self::assertSame('quarantine', $quarantine->processing_status);
            self::assertTrue((bool) $quarantine->is_current);
        });

        $version = $this->service($storage, $scanner)->addVersion(
            $file,
            $this->pdf('ready.pdf'),
            new VersionInput(uploadedByUserId: 30, makeCurrent: false),
        );

        self::assertSame('ready', $version->fresh()->processing_status);
        self::assertTrue((bool) $version->fresh()->is_current);
        self::assertSame($version->id, $file->fresh()->current_version_id);
        self::assertSame($version->id, LegalArchiveDocument::query()->findOrFail(10)->current_primary_version_id);
    }

    public function test_scanner_failure_persists_failed_evidence_without_s3_compensation(): void
    {
        LegalArchiveDocument::query()->forceCreate(['id' => 10, 'organization_id' => 20, 'title' => 'Договор']);
        $file = LegalArchiveDocumentFile::query()->create([
            'document_id' => 10, 'organization_id' => 20, 'role' => 'primary', 'title' => 'Договор',
        ]);
        $storage = $this->createMock(FileService::class);
        $storage->method('upload')->willReturn('org-20/legal-archive/files/1/rejected.pdf');
        $storage->expects(self::never())->method('delete');
        $scanner = $this->createMock(LegalDocumentScanner::class);
        $scanner->method('assertClean')->willThrowException(new RuntimeException('malware'));

        try {
            $this->service($storage, $scanner)->addVersion(
                $file,
                $this->pdf('rejected.pdf'),
                new VersionInput(uploadedByUserId: 30),
            );
            self::fail('Scanner failure was expected.');
        } catch (LegalDocumentScanFailed $exception) {
            self::assertSame('malware', $exception->getPrevious()?->getMessage());
            self::assertSame('failed', $exception->version->processing_status);
        }

        $failed = $file->versions()->sole();
        self::assertSame('failed', $failed->processing_status);
        self::assertFalse((bool) $failed->is_current);
        self::assertNull($file->fresh()->current_version_id);
        self::assertNull(LegalArchiveDocument::query()->findOrFail(10)->current_primary_version_id);
    }

    public function test_scanner_failure_status_and_current_reconciliation_are_atomic(): void
    {
        LegalArchiveDocument::query()->forceCreate(['id' => 10, 'organization_id' => 20, 'title' => 'Договор']);
        $file = LegalArchiveDocumentFile::query()->create([
            'document_id' => 10, 'organization_id' => 20, 'role' => 'primary', 'title' => 'Договор',
        ]);
        $storage = $this->createMock(FileService::class);
        $storage->method('upload')->willReturn('org-20/legal-archive/files/1/rejected.pdf');
        $scanner = $this->createMock(LegalDocumentScanner::class);
        $scanner->method('assertClean')->willThrowException(new RuntimeException('malware'));
        $this->connection()->statement(
            'CREATE TRIGGER reject_failed_current_clear BEFORE UPDATE ON legal_archive_document_versions '
            ."WHEN NEW.is_current = 0 BEGIN SELECT RAISE(FAIL, 'current reconciliation failed'); END"
        );

        try {
            $this->service($storage, $scanner)->addVersion($file, $this->pdf('rejected.pdf'), new VersionInput);
            self::fail('Scanner failure was expected.');
        } catch (RuntimeException) {
            self::assertTrue(true);
        }

        $persisted = $file->versions()->sole();
        self::assertFalse(
            $persisted->processing_status === 'failed' && (bool) $persisted->is_current,
            'A partial failure must not leave a failed version current.',
        );
    }

    public function test_failed_s3_compensation_creates_durable_cleanup_debt(): void
    {
        LegalArchiveDocument::query()->forceCreate(['id' => 10, 'organization_id' => 20, 'title' => 'Договор']);
        $file = LegalArchiveDocumentFile::query()->create([
            'document_id' => 10, 'organization_id' => 20, 'role' => 'primary', 'title' => 'Договор',
        ]);
        $failedPath = 'org-20/legal-archive/files/1/orphan.pdf';
        $storage = $this->createMock(FileService::class);
        $storage->method('upload')->willReturn($failedPath);
        $storage->expects(self::once())->method('delete')->willReturn(false);
        $scanner = $this->createMock(LegalDocumentScanner::class);
        $this->connection()->statement(
            'CREATE TRIGGER reject_version_for_cleanup BEFORE INSERT ON legal_archive_document_versions '
            ."BEGIN SELECT RAISE(FAIL, 'persistence failed'); END"
        );

        try {
            $this->service($storage, $scanner)->addVersion($file, $this->pdf('orphan.pdf'), new VersionInput);
            self::fail('Persistence failure was expected.');
        } catch (RuntimeException) {
            self::assertTrue(true);
        }

        $debt = $this->connection()->table('legal_archive_file_cleanup_debts')->sole();
        self::assertSame(20, (int) $debt->organization_id);
        self::assertSame($failedPath, $debt->storage_path);
        self::assertSame('version_persistence_failed', $debt->reason);
        self::assertNull($debt->resolved_at);
    }

    public function test_direct_version_mutation_and_deletion_are_prohibited(): void
    {
        LegalArchiveDocument::query()->forceCreate(['id' => 10, 'organization_id' => 20, 'title' => 'Договор']);
        $file = LegalArchiveDocumentFile::query()->create([
            'document_id' => 10, 'organization_id' => 20, 'role' => 'primary', 'title' => 'Договор',
        ]);
        $storage = $this->createMock(FileService::class);
        $storage->method('upload')->willReturn('org-20/legal-archive/files/1/a.pdf');
        $scanner = $this->createMock(LegalDocumentScanner::class);
        $scanner->method('assertClean');
        $version = $this->service($storage, $scanner)->addVersion($file, $this->pdf('a.pdf'), new VersionInput);

        foreach (['update', 'delete', 'forceDelete'] as $operation) {
            try {
                if ($operation === 'update') {
                    $version->update(['file_path' => 'org-20/changed.pdf']);
                } else {
                    $version->{$operation}();
                }
                self::fail("{$operation} must be rejected.");
            } catch (ImmutableDataException) {
                self::assertTrue(true);
            }
        }

        self::assertSame('org-20/legal-archive/files/1/a.pdf', $version->fresh()->file_path);
    }

    public function test_each_logical_file_has_own_current_and_document_points_to_primary_current(): void
    {
        $document = LegalArchiveDocument::query()->forceCreate(['id' => 10, 'organization_id' => 20, 'title' => 'Досье']);
        $primary = LegalArchiveDocumentFile::query()->create([
            'document_id' => 10, 'organization_id' => 20, 'role' => 'primary', 'title' => 'Основной',
        ]);
        $attachment = LegalArchiveDocumentFile::query()->create([
            'document_id' => 10, 'organization_id' => 20, 'role' => 'attachment', 'title' => 'Приложение',
        ]);
        $storage = $this->createMock(FileService::class);
        $storage->method('upload')->willReturnOnConsecutiveCalls(
            'org-20/legal-archive/files/1/primary.pdf',
            'org-20/legal-archive/files/2/attachment.pdf',
        );
        $scanner = $this->createMock(LegalDocumentScanner::class);
        $scanner->method('assertClean');
        $service = $this->service($storage, $scanner);

        $primaryVersion = $service->addVersion($primary, $this->pdf('primary.pdf'), new VersionInput(makeCurrent: false));
        $attachmentVersion = $service->addVersion($attachment, $this->pdf('attachment.pdf'), new VersionInput(makeCurrent: false));

        self::assertTrue((bool) $primaryVersion->fresh()->is_current);
        self::assertTrue((bool) $attachmentVersion->fresh()->is_current);
        self::assertSame($primaryVersion->id, $document->fresh()->current_primary_version_id);
        self::assertSame($primaryVersion->id, $document->fresh()->currentVersion()->firstOrFail()->id);
    }

    public function test_migration_keeps_legacy_rows_nullable_and_enforces_logical_file_invariants(): void
    {
        $schema = file_get_contents(__DIR__.'/../../../database/migrations/2026_07_19_000200_create_legal_document_files_and_harden_versions.php');
        $indexes = file_get_contents(__DIR__.'/../../../database/migrations/2026_07_19_000210_create_legal_document_file_indexes.php');
        $constraints = file_get_contents(__DIR__.'/../../../database/migrations/2026_07_19_000220_add_legal_document_file_constraints.php');
        $validation = file_get_contents(__DIR__.'/../../../database/migrations/2026_07_19_000230_validate_legal_document_file_constraints.php');
        $cleanupDebts = file_get_contents(__DIR__.'/../../../database/migrations/2026_07_19_000240_create_legal_archive_file_cleanup_debts.php');

        foreach ([$schema, $indexes, $constraints, $validation, $cleanupDebts] as $phase) {
            self::assertIsString($phase);
        }
        self::assertStringContainsString("->unsignedBigInteger('document_file_id')->nullable()", $schema);
        self::assertStringContainsString("Schema::hasTable('legal_archive_document_files')", $schema);
        self::assertStringContainsString("Schema::hasColumn('legal_archive_document_versions', 'document_file_id')", $schema);
        self::assertStringNotContainsString('CREATE INDEX CONCURRENTLY', $schema);
        self::assertStringContainsString('public $withinTransaction = false;', $indexes);
        self::assertStringContainsString('CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS', $indexes);
        self::assertStringContainsString('indisvalid', $indexes);
        self::assertStringContainsString('assertLegacyRollbackCompatible', $indexes);
        self::assertStringContainsString('legal_archive_document_file_versions_unique', $indexes);
        self::assertStringContainsString('legal_archive_document_file_current_unique', $indexes);
        self::assertStringContainsString("processing_status IN ('quarantine', 'ready', 'failed')", $constraints);
        self::assertStringContainsString('legal_archive_versions_immutable_guard', $constraints);
        self::assertStringContainsString("current_setting('most.legal_archive_version_mutation', true)", $constraints);
        self::assertStringContainsString('NOT VALID', $constraints);
        self::assertStringContainsString('VALIDATE CONSTRAINT', $validation);
        self::assertStringContainsString("Schema::hasTable('legal_archive_file_cleanup_debts')", $cleanupDebts);
        self::assertStringContainsString('legal_archive_cleanup_debts_pending_idx', $cleanupDebts);
        self::assertStringContainsString('legal_archive_cleanup_debts_object_unique', $cleanupDebts);
        self::assertStringContainsString('legal_archive_cleanup_debts_rollback_blocked', $cleanupDebts);
        self::assertStringNotContainsString('UPDATE legal_archive_document_versions', $schema.$indexes.$constraints.$validation);
    }

    private function pdf(string $name): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "%PDF-1.7\ncontract");
    }

    private function connection(): ConnectionInterface
    {
        return $this->database->getConnection();
    }

    private function service(FileService $storage, LegalDocumentScanner $scanner): LegalDocumentFileService
    {
        return new LegalDocumentFileService(
            $storage,
            new LegalDocumentFilePolicy($this->configuration),
            $scanner,
            $this->connection(),
        );
    }
}
