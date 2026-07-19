<?php

declare(strict_types=1);

namespace Tests\Feature\LegalArchive;

use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocumentFile;
use App\Services\LegalArchive\Files\LegalDocumentFilePolicy;
use App\Services\LegalArchive\Files\LegalDocumentFileService;
use App\Services\LegalArchive\Files\LegalDocumentScanner;
use App\Services\LegalArchive\Files\VersionInput;
use App\Services\Storage\FileService;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Schema\Blueprint;
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
        $this->database->bootEloquent();

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

        $this->configuration = [
            'max_size_bytes' => 1024 * 1024,
            'allowed_extensions' => ['pdf'],
            'allowed_mime_types' => ['pdf' => ['application/pdf']],
        ];
    }

    public function test_adds_versions_append_only_and_keeps_exactly_one_current_version(): void
    {
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

    public function test_migration_keeps_legacy_rows_nullable_and_enforces_logical_file_invariants(): void
    {
        $migration = file_get_contents(__DIR__.'/../../../database/migrations/2026_07_19_000200_create_legal_document_files_and_harden_versions.php');

        self::assertIsString($migration);
        self::assertStringContainsString("->unsignedBigInteger('document_file_id')->nullable()", $migration);
        self::assertStringContainsString('legal_archive_document_file_versions_unique', $migration);
        self::assertStringContainsString('legal_archive_document_file_current_unique', $migration);
        self::assertStringContainsString("processing_status IN ('quarantine', 'ready', 'failed')", $migration);
        self::assertStringContainsString('NOT VALID', $migration);
        self::assertStringNotContainsString('UPDATE legal_archive_document_versions', $migration);
    }

    private function pdf(string $name): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "%PDF-1.7\ncontract");
    }

    private function connection(): ConnectionInterface
    {
        return $this->database->getConnection();
    }
}
