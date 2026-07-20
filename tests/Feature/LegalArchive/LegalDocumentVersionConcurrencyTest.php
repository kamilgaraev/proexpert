<?php

declare(strict_types=1);

namespace Tests\Feature\LegalArchive;

use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument;
use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocumentFile;
use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocumentVersion;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Exceptions\ImmutableDataException;
use App\Http\Controllers\Api\V1\Admin\LegalArchive\LegalArchiveDocumentController;
use App\Http\Requests\Api\V1\Admin\LegalArchive\RecoverLegalArchiveDocumentRequest;
use App\Models\Project;
use App\Models\User;
use App\Services\LegalArchive\Access\LegalDocumentAccessService;
use App\Services\LegalArchive\Audit\LegalDocumentAudit;
use App\Services\LegalArchive\CanonicalJson;
use App\Services\LegalArchive\Files\LegalDocumentDownloadService;
use App\Services\LegalArchive\Files\LegalDocumentFilePolicy;
use App\Services\LegalArchive\Files\LegalDocumentFileService;
use App\Services\LegalArchive\Files\LegalDocumentScanFailed;
use App\Services\LegalArchive\Files\LegalDocumentScanner;
use App\Services\LegalArchive\Files\LegalDocumentVersionAttempt;
use App\Services\LegalArchive\Files\LegalDocumentVersionLeaseLost;
use App\Services\LegalArchive\Files\VersionInput;
use App\Services\LegalArchive\LegalArchiveLifecycleService;
use App\Services\LegalArchive\LegalArchiveRegistryService;
use App\Services\LegalArchive\LegalDocumentCreateFailureReporter;
use App\Services\LegalArchive\Sources\LegalDocumentSourceResolver;
use App\Services\LegalArchive\Workflow\LegalWorkflowActionResolver;
use App\Services\Project\UserProjectAccessService;
use App\Services\Storage\FileService;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

final class LegalDocumentVersionConcurrencyTest extends TestCase
{
    private Capsule $database;

    private TestHandler $logHandler;

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
        $container = new Container;
        $container->instance('db', $this->database->getDatabaseManager());
        $this->logHandler = new TestHandler;
        $container->instance('log', new Logger('test', [$this->logHandler]));
        $container->instance('app', new class
        {
            public function getLocale(): string
            {
                return 'ru';
            }
        });
        $container->instance('config', new Repository(['app' => ['fallback_locale' => 'ru']]));
        $translator = new Translator(new ArrayLoader, 'ru');
        $translator->addLines([
            'legal_archive.messages.create_recovery_completed' => 'Создание завершено',
            'legal_archive.messages.create_recovery_error' => 'Ошибка восстановления',
        ], 'ru');
        $container->instance('translator', $translator);
        $responses = $this->createMock(ResponseFactory::class);
        $responses->method('json')->willReturnCallback(
            static fn (mixed $data = [], int $status = 200, array $headers = [], int $options = 0): JsonResponse => new JsonResponse(
                $data,
                $status,
                $headers,
                $options,
            ),
        );
        $container->instance(ResponseFactory::class, $responses);
        Container::setInstance($container);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($container);
        Model::clearBootedModels();

        $this->database->schema()->create('legal_archive_documents', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('current_primary_version_id')->nullable();
            $table->unsignedBigInteger('primary_project_id')->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->unsignedBigInteger('owner_user_id')->nullable();
            $table->unsignedBigInteger('updated_by_user_id')->nullable();
            $table->unsignedBigInteger('lock_version')->default(0);
            $table->string('title');
            $table->string('status')->nullable();
            $table->string('confidentiality_level')->nullable();
            $table->string('direction')->nullable();
            $table->string('source_system')->nullable();
            $table->string('legal_significance_status')->nullable();
            $table->string('source_create_status')->default('completed');
            $table->string('source_request_fingerprint', 64)->nullable();
            $table->string('source_create_failure_fingerprint', 64)->nullable();
            $table->timestamp('source_create_failed_at')->nullable();
            $table->string('create_operation_id', 191)->nullable();
            $table->string('create_operation_key', 191)->nullable();
            $table->string('source_create_attempt_token', 191)->nullable();
            $table->unsignedInteger('source_create_attempt_count')->default(0);
            $table->timestamp('source_create_started_at')->nullable();
            $table->timestamp('source_create_heartbeat_at')->nullable();
            $table->timestamp('source_create_lease_expires_at')->nullable();
            $table->string('source_create_retry_action')->nullable();
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
            $table->unique(['organization_id', 'storage_path']);
        });
        $this->database->schema()->create('legal_archive_document_version_operations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('document_id');
            $table->unsignedBigInteger('document_file_id');
            $table->string('operation_id', 191);
            $table->unsignedInteger('operation_generation')->default(1);
            $table->string('request_fingerprint', 64);
            $table->string('reserved_version_number');
            $table->string('requested_version_number', 64)->nullable();
            $table->text('version_label')->nullable();
            $table->unsignedBigInteger('uploaded_by_user_id')->nullable();
            $table->json('version_metadata')->nullable();
            $table->text('file_original_name');
            $table->unsignedBigInteger('file_size_bytes');
            $table->string('file_content_hash', 64);
            $table->string('file_client_mime_type')->nullable();
            $table->string('file_detected_mime_type')->nullable();
            $table->boolean('make_current');
            $table->string('attempt_token', 191);
            $table->unsignedInteger('attempt_count');
            $table->string('status');
            $table->text('storage_path')->nullable();
            $table->unsignedBigInteger('document_version_id')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'document_file_id', 'operation_id', 'operation_generation'], 'version_operation_identity_unique');
            $table->unique(['document_file_id', 'reserved_version_number'], 'version_operation_slot_unique');
        });
        $this->database->schema()->create('legal_workflow_instances', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('document_id');
            $table->string('status');
        });
        $this->database->schema()->create('projects', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->string('name')->nullable();
            $table->string('status')->nullable();
            $table->boolean('is_archived')->default(false);
            $table->softDeletes();
        });
        $this->database->schema()->create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->softDeletes();
        });
        $this->database->schema()->create('legal_archive_document_links', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('document_id');
            $table->string('link_type')->nullable();
        });
        $this->database->schema()->create('legal_document_access_grants', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('document_id');
            $table->unsignedBigInteger('subject_organization_id');
            $table->string('subject_kind');
            $table->unsignedBigInteger('subject_user_id')->nullable();
            $table->string('subject_role_slug')->nullable();
            $table->json('abilities');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
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

    public function test_reclaimed_operation_fences_worker_that_loses_ownership_during_upload(): void
    {
        LegalArchiveDocument::query()->forceCreate(['id' => 10, 'organization_id' => 20, 'title' => 'Contract']);
        $file = LegalArchiveDocumentFile::query()->create([
            'document_id' => 10, 'organization_id' => 20, 'role' => 'primary', 'title' => 'Contract',
        ]);
        $owner = 'attempt-old';
        $stalePath = 'org-20/legal-archive/files/1/stale.pdf';
        $storage = $this->createMock(FileService::class);
        $storage->expects(self::exactly(2))->method('upload')->willReturnCallback(
            function () use (&$owner, $stalePath): string {
                if ($owner === 'attempt-old') {
                    $owner = 'attempt-new';

                    return $stalePath;
                }

                return 'org-20/legal-archive/files/1/winner.pdf';
            },
        );
        $storage->expects(self::once())->method('delete')->with($stalePath, self::anything())->willReturn(true);
        $scanner = $this->createMock(LegalDocumentScanner::class);
        $scanner->expects(self::once())->method('assertClean');
        $service = $this->service($storage, $scanner);
        $ownership = static function (LegalArchiveDocument $document, string $token) use (&$owner): void {
            self::assertSame(10, (int) $document->id);
            if (! hash_equals($owner, $token)) {
                throw new LegalDocumentVersionLeaseLost;
            }
        };

        try {
            $service->addVersion(
                $file,
                $this->pdf('same.pdf'),
                new VersionInput(versionNumber: '1.0'),
                new LegalDocumentVersionAttempt('create-operation', 'attempt-old', $ownership),
            );
            self::fail('The stale worker persisted after losing its fencing token.');
        } catch (LegalDocumentVersionLeaseLost) {
            self::assertSame(0, $file->versions()->count());
        }

        $winner = $service->addVersion(
            $file->fresh(),
            $this->pdf('same.pdf'),
            new VersionInput(versionNumber: '1.0'),
            new LegalDocumentVersionAttempt('create-operation', 'attempt-new', $ownership),
        );

        self::assertSame('ready', $winner->processing_status);
        self::assertSame('1.0', $winner->version_number);
        self::assertSame(1, $file->versions()->count());
        self::assertSame($winner->id, $file->fresh()->current_version_id);
        $operation = $this->connection()->table('legal_archive_document_version_operations')->sole();
        self::assertSame(2, (int) $operation->attempt_count);
        self::assertSame('completed', $operation->status);
        self::assertSame($winner->id, (int) $operation->document_version_id);
    }

    public function test_reclaimed_operation_recovers_reserved_version_and_stale_scanner_cannot_promote_it(): void
    {
        LegalArchiveDocument::query()->forceCreate(['id' => 10, 'organization_id' => 20, 'title' => 'Contract']);
        $file = LegalArchiveDocumentFile::query()->create([
            'document_id' => 10, 'organization_id' => 20, 'role' => 'primary', 'title' => 'Contract',
        ]);
        $owner = 'attempt-old';
        $storage = $this->createMock(FileService::class);
        $storage->expects(self::once())->method('upload')->willReturn('org-20/legal-archive/files/1/reserved.pdf');
        $storage->expects(self::never())->method('delete');
        $scanner = $this->createMock(LegalDocumentScanner::class);
        $scanner->expects(self::exactly(2))->method('assertClean')->willReturnCallback(
            function () use (&$owner): void {
                if ($owner === 'attempt-old') {
                    $owner = 'attempt-new';
                }
            },
        );
        $service = $this->service($storage, $scanner);
        $ownership = static function (LegalArchiveDocument $document, string $token) use (&$owner): void {
            if (! hash_equals($owner, $token)) {
                throw new LegalDocumentVersionLeaseLost;
            }
        };

        try {
            $service->addVersion(
                $file,
                $this->pdf('same.pdf'),
                new VersionInput(versionNumber: '1.0'),
                new LegalDocumentVersionAttempt('create-operation', 'attempt-old', $ownership),
            );
            self::fail('The stale scanner promoted a quarantined version.');
        } catch (LegalDocumentVersionLeaseLost) {
            $quarantine = $file->versions()->sole();
            self::assertSame('quarantine', $quarantine->processing_status);
            self::assertFalse((bool) $quarantine->is_current);
            self::assertNull($file->fresh()->current_version_id);
        }

        $winner = $service->addVersion(
            $file->fresh(),
            $this->pdf('same.pdf'),
            new VersionInput(versionNumber: '1.0'),
            new LegalDocumentVersionAttempt('create-operation', 'attempt-new', $ownership),
        );

        self::assertSame('ready', $winner->processing_status);
        self::assertTrue((bool) $winner->is_current);
        self::assertSame(1, $file->versions()->count());
    }

    public function test_failed_scan_is_recoverable_by_new_fenced_attempt_without_second_object_or_version(): void
    {
        LegalArchiveDocument::query()->forceCreate(['id' => 10, 'organization_id' => 20, 'title' => 'Contract']);
        $file = LegalArchiveDocumentFile::query()->create([
            'document_id' => 10, 'organization_id' => 20, 'role' => 'primary', 'title' => 'Contract',
        ]);
        $owner = 'attempt-old';
        $storage = $this->createMock(FileService::class);
        $storage->expects(self::once())->method('upload')->willReturn('org-20/legal-archive/files/1/recoverable.pdf');
        $storage->expects(self::never())->method('delete');
        $scan = 0;
        $scanner = $this->createMock(LegalDocumentScanner::class);
        $scanner->expects(self::exactly(2))->method('assertClean')->willReturnCallback(
            static function () use (&$scan): void {
                $scan++;
                if ($scan === 1) {
                    throw new RuntimeException('scanner_unavailable');
                }
            },
        );
        $service = $this->service($storage, $scanner);
        $ownership = static function (LegalArchiveDocument $document, string $token) use (&$owner): void {
            if (! hash_equals($owner, $token)) {
                throw new LegalDocumentVersionLeaseLost;
            }
        };

        try {
            $service->addVersion(
                $file,
                $this->pdf('same.pdf'),
                new VersionInput(versionNumber: '1.0'),
                new LegalDocumentVersionAttempt('create-operation', 'attempt-old', $ownership),
            );
            self::fail('The first scanner call must fail.');
        } catch (LegalDocumentScanFailed $exception) {
            self::assertSame('failed', $exception->version->processing_status);
        }

        $owner = 'attempt-new';
        $recovered = $service->addVersion(
            $file->fresh(),
            $this->pdf('same.pdf'),
            new VersionInput(versionNumber: '1.0'),
            new LegalDocumentVersionAttempt('create-operation', 'attempt-new', $ownership),
        );

        self::assertSame('ready', $recovered->processing_status);
        self::assertSame(1, $file->versions()->count());
        self::assertSame($recovered->id, $file->fresh()->current_version_id);
        self::assertSame(2, (int) $this->connection()->table('legal_archive_document_version_operations')->value('attempt_count'));
    }

    public function test_corrected_upload_after_deterministic_scan_rejection_uses_next_slot_and_replays(): void
    {
        LegalArchiveDocument::query()->forceCreate(['id' => 10, 'organization_id' => 20, 'title' => 'Contract']);
        $file = LegalArchiveDocumentFile::query()->create([
            'document_id' => 10, 'organization_id' => 20, 'role' => 'primary', 'title' => 'Contract',
        ]);
        $owner = 'attempt-old';
        $storage = $this->createMock(FileService::class);
        $storage->expects(self::exactly(2))->method('upload')->willReturnOnConsecutiveCalls(
            'org-20/legal-archive/files/1/rejected.pdf',
            'org-20/legal-archive/files/1/corrected.pdf',
        );
        $storage->expects(self::never())->method('delete');
        $scanner = $this->createMock(LegalDocumentScanner::class);
        $scanner->expects(self::exactly(2))->method('assertClean')->willReturnCallback(
            static function (UploadedFile $upload): void {
                if ($upload->getClientOriginalName() === 'rejected.pdf') {
                    throw new RuntimeException('malware');
                }
            },
        );
        $service = $this->service($storage, $scanner);
        $ownership = static function (LegalArchiveDocument $document, string $token) use (&$owner): void {
            if (! hash_equals($owner, $token)) {
                throw new LegalDocumentVersionLeaseLost;
            }
        };

        try {
            $service->addVersion(
                $file,
                $this->pdfWithContent('rejected.pdf', 'rejected-content'),
                new VersionInput(
                    versionNumber: '1.0',
                    versionLabel: 'Подписанный оригинал',
                    uploadedByUserId: 30,
                    metadata: ['nested' => ['b' => 2, 'a' => 1.0]],
                ),
                new LegalDocumentVersionAttempt('create-operation', 'attempt-old', $ownership),
            );
            self::fail('The rejected upload must remain failed evidence.');
        } catch (LegalDocumentScanFailed) {
            self::assertSame('failed', $file->versions()->sole()->processing_status);
        }

        $owner = 'attempt-new';
        $attempt = new LegalDocumentVersionAttempt('create-operation', 'attempt-new', $ownership);
        $recoveredInput = $service->lockVersionInputForRecovery($file->fresh(), 'create-operation');
        self::assertInstanceOf(VersionInput::class, $recoveredInput);
        self::assertSame('1.0', $recoveredInput->versionNumber);
        self::assertSame(30, $recoveredInput->uploadedByUserId);
        self::assertSame(['nested' => ['a' => 1.0, 'b' => 2]], $recoveredInput->metadata);
        $correctedInput = new VersionInput(
            versionNumber: '1.0',
            versionLabel: 'Подписанный оригинал',
            uploadedByUserId: 30,
            metadata: ['nested' => ['a' => 1.0, 'b' => 2]],
        );
        $corrected = $service->addVersion(
            $file->fresh(),
            $this->pdfWithContent('corrected.pdf', 'corrected-content'),
            $correctedInput,
            $attempt,
        );
        $replayed = $service->addVersion(
            $file->fresh(),
            $this->pdfWithContent('corrected-renamed.pdf', 'corrected-content'),
            $correctedInput,
            $attempt,
        );

        self::assertSame($corrected->id, $replayed->id);
        self::assertSame('ready', $corrected->processing_status);
        self::assertSame(2, $file->versions()->count());
        self::assertSame(['1.0', '1.0.2'], $file->versions()->reorder('id')->pluck('version_number')->all());
        self::assertSame(['Подписанный оригинал', 'Подписанный оригинал'], $file->versions()->reorder('id')->pluck('version_label')->all());
        self::assertSame(['1.0', '1.0'], $this->connection()->table('legal_archive_document_version_operations')
            ->orderBy('operation_generation')->pluck('requested_version_number')->all());
        self::assertSame(1.0, json_decode((string) $this->connection()->table('legal_archive_document_version_operations')
            ->orderByDesc('operation_generation')->value('version_metadata'), true, 512, JSON_THROW_ON_ERROR)['nested']['a']);
        self::assertSame(['failed', 'completed'], $this->connection()->table('legal_archive_document_version_operations')
            ->orderBy('operation_generation')->pluck('status')->all());
    }

    public function test_same_content_and_semantics_replay_the_operation_despite_filename_and_metadata_key_order(): void
    {
        LegalArchiveDocument::query()->forceCreate(['id' => 10, 'organization_id' => 20, 'title' => 'Contract']);
        $file = LegalArchiveDocumentFile::query()->create([
            'document_id' => 10, 'organization_id' => 20, 'role' => 'primary', 'title' => 'Contract',
        ]);
        $storage = $this->createMock(FileService::class);
        $storage->expects(self::once())->method('upload')->willReturn('org-20/legal-archive/files/1/original.pdf');
        $scanner = $this->createMock(LegalDocumentScanner::class);
        $scanner->expects(self::once())->method('assertClean');
        $attempt = new LegalDocumentVersionAttempt('create-operation', 'attempt', static function (): void {});
        $service = $this->service($storage, $scanner);

        $first = $service->addVersion($file, $this->pdfWithContent('original.pdf', 'same-content'), new VersionInput(
            versionNumber: '7.5', versionLabel: 'Оригинал', metadata: ['b' => 2, 'a' => 1.0],
        ), $attempt);
        $replayed = $service->addVersion($file->fresh(), $this->pdfWithContent('renamed.pdf', 'same-content'), new VersionInput(
            versionNumber: '7.5', versionLabel: 'Оригинал', metadata: ['a' => 1.0, 'b' => 2],
        ), $attempt);

        self::assertSame($first->id, $replayed->id);
        self::assertSame(1, $this->connection()->table('legal_archive_document_version_operations')->count());
    }

    public function test_corrupted_durable_input_is_rejected_before_recovery_state_can_be_claimed(): void
    {
        $document = LegalArchiveDocument::query()->forceCreate([
            'id' => 10,
            'organization_id' => 20,
            'title' => 'Contract',
            'source_create_status' => 'failed',
            'source_create_attempt_count' => 4,
        ]);
        $file = LegalArchiveDocumentFile::query()->create([
            'document_id' => 10, 'organization_id' => 20, 'role' => 'primary', 'title' => 'Contract',
        ]);
        $this->connection()->table('legal_archive_document_version_operations')->insert([
            'organization_id' => 20,
            'document_id' => 10,
            'document_file_id' => $file->id,
            'operation_id' => 'create-operation',
            'operation_generation' => 1,
            'request_fingerprint' => str_repeat('0', 64),
            'requested_version_number' => '7.5',
            'reserved_version_number' => '7.5',
            'version_label' => 'Оригинал',
            'uploaded_by_user_id' => 30,
            'version_metadata' => CanonicalJson::encode(['a' => 1.0]),
            'file_original_name' => 'contract.pdf',
            'file_size_bytes' => 10,
            'file_content_hash' => str_repeat('a', 64),
            'make_current' => true,
            'attempt_token' => 'old-attempt',
            'attempt_count' => 1,
            'status' => 'reserved',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $service = $this->service($this->createMock(FileService::class), $this->createMock(LegalDocumentScanner::class));

        try {
            $service->lockVersionInputForRecovery($file, 'create-operation');
            self::fail('Corrupted durable input must be rejected.');
        } catch (\DomainException $exception) {
            self::assertSame('legal_document_version_operation_input_corrupted', $exception->getMessage());
        }

        self::assertSame('failed', $document->fresh()->source_create_status);
        self::assertSame(4, (int) $document->fresh()->source_create_attempt_count);
    }

    public function test_registry_recovery_reconstructs_exact_version_input_before_claiming_a_corrected_generation(): void
    {
        $document = LegalArchiveDocument::query()->forceCreate([
            'id' => 10,
            'organization_id' => 20,
            'title' => 'Contract',
            'created_by_user_id' => 30,
            'source_create_status' => 'pending',
            'source_request_fingerprint' => str_repeat('a', 64),
            'source_create_failure_fingerprint' => str_repeat('b', 64),
            'source_create_failed_at' => now()->subMinute(),
            'create_operation_id' => 'create-operation',
            'source_create_attempt_token' => 'stale-attempt',
            'source_create_attempt_count' => 1,
            'source_create_lease_expires_at' => now()->subMinute(),
            'source_create_retry_action' => 'retry_upload',
        ]);
        $documentFile = LegalArchiveDocumentFile::query()->create([
            'document_id' => 10, 'organization_id' => 20, 'role' => 'primary', 'title' => 'Contract',
        ]);
        $failedVersion = LegalArchiveDocumentVersion::query()->forceCreate([
            'document_id' => 10,
            'document_file_id' => $documentFile->id,
            'organization_id' => 20,
            'version_number' => '7.5',
            'version_label' => 'Оригинал',
            'is_current' => false,
            'status' => 'uploaded',
            'processing_status' => 'failed',
            'file_path' => 'org-20/rejected.pdf',
            'original_filename' => 'rejected.pdf',
            'size_bytes' => 8,
        ]);
        $rejected = $this->pdfWithContent('rejected.pdf', 'rejected-content');
        $rejectedDescriptor = \App\Services\LegalArchive\Files\UploadedFileDescriptor::fromUpload($rejected);
        $input = VersionInput::fromCreateData(30, [
            'version_number' => '7.5',
            'version_label' => 'Оригинал',
            'version_metadata' => ['nested' => ['b' => 2, 'a' => 1.0]],
        ]);
        $this->connection()->table('legal_archive_document_version_operations')->insert([
            'organization_id' => 20,
            'document_id' => 10,
            'document_file_id' => $documentFile->id,
            'operation_id' => 'create-operation',
            'operation_generation' => 1,
            'request_fingerprint' => $input->semanticFingerprint(),
            'requested_version_number' => '7.5',
            'reserved_version_number' => '7.5',
            'version_label' => 'Оригинал',
            'uploaded_by_user_id' => 30,
            'version_metadata' => CanonicalJson::encode($input->metadata),
            'file_original_name' => $rejectedDescriptor->originalName,
            'file_size_bytes' => $rejectedDescriptor->sizeBytes,
            'file_content_hash' => $rejectedDescriptor->contentHash,
            'file_client_mime_type' => $rejectedDescriptor->clientMimeType,
            'file_detected_mime_type' => $rejectedDescriptor->detectedMimeType,
            'make_current' => true,
            'attempt_token' => 'old-attempt',
            'attempt_count' => 1,
            'status' => 'failed',
            'storage_path' => 'org-20/rejected.pdf',
            'document_version_id' => $failedVersion->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $storage = $this->createMock(FileService::class);
        $storage->expects(self::once())->method('upload')->willReturn('org-20/corrected.pdf');
        $scanner = $this->createMock(LegalDocumentScanner::class);
        $scanner->expects(self::once())->method('assertClean');
        $audit = $this->createMock(LegalDocumentAudit::class);
        $authorization = $this->createMock(AuthorizationService::class);
        $authorization->method('can')->willReturn(true);
        $authorization->method('getUserRoleSlugs')->willReturn([]);
        $projects = $this->createMock(UserProjectAccessService::class);
        $projects->method('queryAccessibleProjects')->willReturn(Project::query()->whereRaw('1 = 0'));
        $access = new LegalDocumentAccessService(
            $authorization,
            static fn (): bool => true,
            projectAccess: $projects,
            connection: $this->connection(),
        );
        $policy = new LegalDocumentFilePolicy($this->configuration);
        $fileService = new LegalDocumentFileService($storage, $policy, $scanner, $this->connection(), $audit);
        $download = new LegalDocumentDownloadService($storage, $access, $policy, new NullLogger, $audit);
        $registry = new LegalArchiveRegistryService(
            $fileService,
            $download,
            $audit,
            new LegalDocumentSourceResolver,
            $access,
        );
        $actor = new User;
        $actor->forceFill(['id' => 30, 'current_organization_id' => 20]);

        $request = RecoverLegalArchiveDocumentRequest::create(
            '/api/v1/admin/legal-archive/recoveries/create-operation/recover',
            'POST',
            [],
            [],
            ['file' => $this->pdfWithContent('corrected.pdf', 'corrected-content')],
        );
        $request->attributes->set('current_organization_id', 20);
        $request->setUserResolver(static fn (): User => $actor);
        Container::getInstance()->instance('request', $request);
        $failureReporter = (new \ReflectionClass(LegalDocumentCreateFailureReporter::class))
            ->newInstanceWithoutConstructor();
        $controller = new LegalArchiveDocumentController(
            $registry,
            $access,
            (new \ReflectionClass(LegalWorkflowActionResolver::class))->newInstanceWithoutConstructor(),
            (new \ReflectionClass(LegalArchiveLifecycleService::class))->newInstanceWithoutConstructor(),
            $failureReporter,
        );

        $response = $controller->recoverCreate($request, 'create-operation');

        self::assertSame(200, $response->getStatusCode(), json_encode($this->logHandler->getRecords()));
        self::assertSame('completed', $document->fresh()->source_create_status);
        $ready = $documentFile->versions()->where('processing_status', 'ready')->sole();
        self::assertSame('7.5.2', $ready->version_number);
        self::assertSame('Оригинал', $ready->version_label);
        self::assertEquals(['nested' => ['a' => 1.0, 'b' => 2]], $ready->metadata);
        self::assertSame(2, (int) $document->fresh()->source_create_attempt_count);
    }

    public function test_controller_recovers_exact_input_persisted_by_initial_registry_scan_failure(): void
    {
        $storage = $this->createMock(FileService::class);
        $storage->expects(self::exactly(2))->method('upload')->willReturnOnConsecutiveCalls(
            'org-20/rejected.pdf',
            'org-20/corrected.pdf',
        );
        $storage->expects(self::never())->method('delete');
        $scanner = $this->createMock(LegalDocumentScanner::class);
        $scanner->expects(self::exactly(2))->method('assertClean')->willReturnCallback(
            static function (UploadedFile $upload): void {
                if ($upload->getClientOriginalName() === 'rejected.pdf') {
                    throw new RuntimeException('malware');
                }
            },
        );
        $audit = $this->createMock(LegalDocumentAudit::class);
        $authorization = $this->createMock(AuthorizationService::class);
        $authorization->method('can')->willReturn(true);
        $authorization->method('getUserRoleSlugs')->willReturn([]);
        $projects = $this->createMock(UserProjectAccessService::class);
        $projects->method('queryAccessibleProjects')->willReturn(Project::query()->whereRaw('1 = 0'));
        $access = new LegalDocumentAccessService(
            $authorization,
            static fn (): bool => true,
            projectAccess: $projects,
            connection: $this->connection(),
        );
        $policy = new LegalDocumentFilePolicy($this->configuration);
        $fileService = new LegalDocumentFileService($storage, $policy, $scanner, $this->connection(), $audit);
        $registry = new LegalArchiveRegistryService(
            $fileService,
            new LegalDocumentDownloadService($storage, $access, $policy, new NullLogger, $audit),
            $audit,
            new LegalDocumentSourceResolver,
            $access,
        );
        $input = [
            'create_operation_key' => 'manual-create-1',
            'title' => 'Contract',
            'version_number' => '7.5',
            'version_label' => 'Оригинал',
            'version_metadata' => ['nested' => ['b' => 2, 'a' => 1.0]],
        ];

        try {
            $registry->create(20, null, $input, $this->pdfWithContent('rejected.pdf', 'rejected-content'));
            self::fail('Initial scan failure was expected.');
        } catch (LegalDocumentScanFailed) {
            self::assertTrue(true);
        }

        $document = LegalArchiveDocument::query()->sole();
        $documentFile = $document->files()->sole();
        $operation = $this->connection()->table('legal_archive_document_version_operations')->sole();
        self::assertSame('failed', $document->source_create_status);
        self::assertSame('failed', $operation->status);
        self::assertSame('7.5', $operation->requested_version_number);
        self::assertSame('Оригинал', $operation->version_label);
        self::assertSame(1.0, json_decode((string) $operation->version_metadata, true, 512, JSON_THROW_ON_ERROR)['nested']['a']);
        $document->forceFill(['created_by_user_id' => 30])->save();

        $actor = new User;
        $actor->forceFill(['id' => 30, 'current_organization_id' => 20]);
        $request = RecoverLegalArchiveDocumentRequest::create(
            '/api/v1/admin/legal-archive/recoveries/'.$document->create_operation_id.'/recover',
            'POST',
            [],
            [],
            ['file' => $this->pdfWithContent('corrected.pdf', 'corrected-content')],
        );
        $request->attributes->set('current_organization_id', 20);
        $request->setUserResolver(static fn (): User => $actor);
        Container::getInstance()->instance('request', $request);
        $failureReporter = (new \ReflectionClass(LegalDocumentCreateFailureReporter::class))
            ->newInstanceWithoutConstructor();
        $response = (new LegalArchiveDocumentController(
            $registry,
            $access,
            (new \ReflectionClass(LegalWorkflowActionResolver::class))->newInstanceWithoutConstructor(),
            (new \ReflectionClass(LegalArchiveLifecycleService::class))->newInstanceWithoutConstructor(),
            $failureReporter,
        ))
            ->recoverCreate($request, (string) $document->create_operation_id);

        self::assertSame(200, $response->getStatusCode(), json_encode($this->logHandler->getRecords()));
        self::assertSame('completed', $document->fresh()->source_create_status);
        $ready = $documentFile->versions()->where('processing_status', 'ready')->sole();
        self::assertSame('7.5.2', $ready->version_number);
        self::assertSame('Оригинал', $ready->version_label);
        self::assertEquals(['nested' => ['a' => 1.0, 'b' => 2]], $ready->metadata);
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
        $operations = file_get_contents(__DIR__.'/../../../database/migrations/2026_07_19_000250_create_legal_document_version_operations.php');
        $operationSchema = file_get_contents(__DIR__.'/../../../app/Services/LegalArchive/Files/Schema/LegalDocumentVersionOperationPostgresSchema.php');
        $operationConstraints = file_get_contents(__DIR__.'/../../../database/migrations/2026_07_19_000260_add_legal_document_version_operation_constraints.php');
        $operationValidation = file_get_contents(__DIR__.'/../../../database/migrations/2026_07_19_000270_validate_legal_document_version_operation_constraints.php');
        $rescanGuard = file_get_contents(__DIR__.'/../../../database/migrations/2026_07_19_000280_allow_fenced_legal_document_version_rescan.php');

        foreach ([$schema, $indexes, $constraints, $validation, $cleanupDebts, $operations, $operationSchema, $operationConstraints, $operationValidation, $rescanGuard] as $phase) {
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
        self::assertStringContainsString('LegalDocumentVersionOperationPostgresSchema::indexes()', $operations);
        self::assertStringContainsString('legal_archive_version_operation_identity_unique', $operationSchema);
        self::assertStringContainsString('legal_archive_version_operation_slot_unique', $operationSchema);
        self::assertStringContainsString("'operation_id' => ['type' => 'character varying(191)'", $operationSchema);
        self::assertStringContainsString('NOT VALID', $operationConstraints);
        self::assertStringContainsString('LegalDocumentVersionOperationPostgresSchema::constraints()', $operationConstraints);
        self::assertStringContainsString('legal_archive_version_operations_state_check', $operationSchema);
        self::assertGreaterThanOrEqual(2, substr_count($operationSchema, 'ON DELETE RESTRICT'));
        self::assertStringContainsString('VALIDATE CONSTRAINT', $operationValidation);
        self::assertStringContainsString("OLD.processing_status = 'failed' AND NEW.processing_status = 'quarantine'", $rescanGuard);
        self::assertStringNotContainsString('UPDATE legal_archive_document_versions', $schema.$indexes.$constraints.$validation);
    }

    private function pdf(string $name): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "%PDF-1.7\ncontract");
    }

    private function pdfWithContent(string $name, string $content): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "%PDF-1.7\n{$content}");
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
