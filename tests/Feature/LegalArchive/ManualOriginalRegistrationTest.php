<?php

declare(strict_types=1);

namespace Tests\Feature\LegalArchive;

use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument;
use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocumentFile;
use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocumentVersion;
use App\Models\Contract;
use App\Models\User;
use App\Services\LegalArchive\Access\LegalDocumentAuthorizer;
use App\Services\LegalArchive\Audit\LegalDocumentAudit;
use App\Services\LegalArchive\Signatures\DisabledElectronicSignatureProvider;
use App\Services\LegalArchive\Signatures\ElectronicSignatureProvider;
use App\Services\LegalArchive\Signatures\ExternalOriginalData;
use App\Services\LegalArchive\Signatures\LegalDocumentSignatureService;
use App\Services\LegalArchive\Signatures\PaperOriginalData;
use App\Services\LegalArchive\Signatures\SignatureCallback;
use App\Services\LegalArchive\Signatures\SignatureContext;
use App\Services\LegalArchive\Signatures\SignatureSession;
use App\Services\LegalArchive\Signatures\SignatureVerificationResult;
use App\Services\Storage\FileService;
use DateTimeImmutable;
use DomainException;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\TestCase;

final class ManualOriginalRegistrationTest extends TestCase
{
    private Capsule $database;

    private LegalDocumentSignatureService $service;

    private RecordingSignatureAudit $audit;

    private FileService $storage;

    private LegalDocumentAuthorizer $access;

    protected function setUp(): void
    {
        parent::setUp();
        $this->database = new Capsule;
        $this->database->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $this->database->setAsGlobal();
        $this->database->setEventDispatcher(new Dispatcher(new Container));
        $this->database->bootEloquent();
        Model::clearBootedModels();
        $this->schema();
        $this->access = $this->createMock(LegalDocumentAuthorizer::class);
        $this->access->expects(self::any())->method('authorize')->willReturnCallback(static function (User $actor, LegalArchiveDocument $document, string $ability): void {
            if ($ability !== 'sign' || (int) $actor->current_organization_id !== (int) $document->organization_id) {
                throw new DomainException('denied');
            }
        });
        $this->audit = new RecordingSignatureAudit;
        $this->storage = $this->createMock(FileService::class);
        $this->service = new LegalDocumentSignatureService(
            new DisabledElectronicSignatureProvider,
            $this->access,
            $this->audit,
            $this->storage,
            $this->database->getConnection(),
        );
    }

    public function test_paper_original_freezes_exact_current_version_and_preserves_hash(): void
    {
        [$document, $version, $actor] = $this->fixture();
        $request = $this->service->createRequest($document, $version, $actor, 'paper', [['name' => 'Иван Иванов']], 'request-1');
        self::assertSame('frozen', $version->refresh()->status);
        self::assertSame($version->content_hash, $request->signed_content_hash);

        $signature = $this->service->registerPaperOriginal($request, $actor, new PaperOriginalData(
            new DateTimeImmutable('-1 day'),
            [['name' => 'Иван Иванов', 'position' => 'Директор']],
            'Архив, шкаф 2, папка 18',
            'paper-1',
        ));

        self::assertSame($version->content_hash, $signature->signed_content_hash);
        self::assertSame('signed', $version->refresh()->status);
        self::assertSame('signed', $document->refresh()->signature_status);
        self::assertSame('paper_original', $document->legal_significance_status);
        self::assertSame(['signature_requested', 'signature_registered'], $this->audit->events);
    }

    public function test_registration_is_idempotent_and_rejects_payload_drift(): void
    {
        [$document, $version, $actor] = $this->fixture();
        $request = $this->service->createRequest($document, $version, $actor, 'paper', [['name' => 'Иван']], 'request');
        $data = new PaperOriginalData(new DateTimeImmutable('-1 day'), [['name' => 'Иван']], 'Архив', 'same');
        $first = $this->service->registerPaperOriginal($request, $actor, $data);
        $replay = $this->service->registerPaperOriginal($request->refresh(), $actor, $data);
        self::assertSame($first->id, $replay->id);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('legal_signature_idempotency_conflict');
        $this->service->registerPaperOriginal($request->refresh(), $actor, new PaperOriginalData(
            new DateTimeImmutable('-1 day'), [['name' => 'Иван']], 'Другое место', 'same',
        ));
    }

    public function test_external_electronic_original_works_with_disabled_online_provider_and_stores_container_in_s3(): void
    {
        [$document, $version, $actor] = $this->fixture();
        $request = $this->service->createRequest(
            $document, $version, $actor, 'external_electronic', [['name' => 'Иван']], 'external-request', provider: 'external-edo',
        );
        $upload = UploadedFile::fake()->createWithContent('signature.p7s', 'signed-container');
        $this->storage->expects(self::once())->method('upload')->willReturn('org-10/legal-archive/signatures/requests/1/signature.p7s');
        $signature = $this->service->registerExternalOriginal($request, $upload, $actor, new ExternalOriginalData(
            'external-edo',
            new DateTimeImmutable('-1 day'),
            [['name' => 'Иван']],
            'external-signature',
            'verified',
            new DateTimeImmutable('now'),
            ['serial' => '01AB', 'issuer' => 'УЦ'],
            ['source' => 'import'],
        ));

        self::assertSame($version->content_hash, $signature->signed_content_hash);
        self::assertSame(hash('sha256', 'signed-container'), $signature->signature_content_hash);
        self::assertStringStartsWith('org-10/', (string) $signature->signature_path);
        self::assertSame('verified', $signature->verification_status);
        self::assertSame('edo_original', $document->refresh()->legal_significance_status);
    }

    public function test_provider_callback_is_correlated_authenticated_hash_bound_and_replay_safe(): void
    {
        [$document, $version, $actor] = $this->fixture();
        $provider = new FixedElectronicSignatureProvider;
        $service = new LegalDocumentSignatureService(
            $provider, $this->access, $this->audit, $this->storage, $this->database->getConnection(),
        );
        $request = $service->createRequest(
            $document, $version, $actor, 'provider_electronic', [['name' => 'Иван']], 'provider-request', provider: 'fixed',
        );
        $session = $service->startElectronicSession($request, $actor, 'https://most.test/callback');
        self::assertSame($request->correlation_id, $session->correlationId);
        $provider->contentHash = (string) $version->content_hash;
        $callback = new SignatureCallback('fixed', $session->providerRequestId, $session->correlationId, 'provider-event-1', ['status' => 'signed']);
        $signature = $service->completeElectronic($callback);
        $replay = $service->completeElectronic($callback);
        self::assertSame($signature->id, $replay->id);
        self::assertSame($version->content_hash, $signature->signed_content_hash);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('legal_signature_request_not_found');
        $service->completeElectronic(new SignatureCallback('fixed', $session->providerRequestId, str_repeat('0', 64), 'provider-event-2', ['status' => 'signed']));
    }

    public function test_current_pointer_or_content_race_is_rejected(): void
    {
        [$document, $version, $actor, $file] = $this->fixture();
        $request = $this->service->createRequest($document, $version, $actor, 'paper', [['name' => 'Иван']], 'request');
        $replacement = LegalArchiveDocumentVersion::query()->create([
            'organization_id' => 10, 'document_id' => $document->id, 'document_file_id' => $file->id,
            'version_number' => '2', 'is_current' => true, 'status' => 'uploaded', 'processing_status' => 'ready',
            'file_path' => 'org-10/two.pdf', 'original_filename' => 'two.pdf', 'size_bytes' => 3,
            'content_hash' => str_repeat('b', 64),
        ]);
        $document->forceFill(['current_primary_version_id' => $replacement->id])->save();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('legal_signature_version_changed');
        $this->service->registerPaperOriginal($request, $actor, new PaperOriginalData(
            new DateTimeImmutable('-1 day'), [['name' => 'Иван']], 'Архив', 'paper',
        ));
    }

    public function test_approval_and_no_active_workflow_are_required(): void
    {
        [$document, $version, $actor] = $this->fixture();
        $document->forceFill(['approval_status' => 'pending', 'lifecycle_status' => 'under_review'])->save();
        try {
            $this->service->createRequest($document, $version, $actor, 'paper', [['name' => 'Иван']], 'not-approved');
            self::fail('Unsigned approval boundary was bypassed.');
        } catch (DomainException $exception) {
            self::assertSame('legal_signature_lifecycle_invalid', $exception->getMessage());
        }
        $document->forceFill(['approval_status' => 'approved', 'lifecycle_status' => 'approved'])->save();
        $this->database->getConnection()->table('legal_workflow_instances')->insert([
            'organization_id' => 10, 'document_id' => $document->id, 'status' => 'in_progress',
        ]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('legal_signature_active_workflow_exists');
        $this->service->createRequest($document, $version, $actor, 'paper', [['name' => 'Иван']], 'workflow');
    }

    private function fixture(): array
    {
        $document = LegalArchiveDocument::query()->create([
            'organization_id' => 10, 'title' => 'Договор', 'approval_status' => 'approved',
            'lifecycle_status' => 'approved', 'signature_status' => 'unsigned', 'lock_version' => 0,
        ]);
        $file = LegalArchiveDocumentFile::query()->create([
            'organization_id' => 10, 'document_id' => $document->id, 'role' => 'primary', 'title' => 'Основной',
        ]);
        $version = LegalArchiveDocumentVersion::query()->create([
            'organization_id' => 10, 'document_id' => $document->id, 'document_file_id' => $file->id,
            'version_number' => '1', 'is_current' => true, 'status' => 'uploaded', 'processing_status' => 'ready',
            'file_path' => 'org-10/one.pdf', 'original_filename' => 'one.pdf', 'size_bytes' => 3,
            'content_hash' => str_repeat('a', 64),
        ]);
        $file->forceFill(['current_version_id' => $version->id])->save();
        $document->forceFill(['current_primary_version_id' => $version->id])->save();
        $actor = new User;
        $actor->forceFill(['id' => 7, 'current_organization_id' => 10]);
        $actor->exists = true;

        return [$document, $version, $actor, $file];
    }

    private function schema(): void
    {
        $schema = $this->database->schema();
        $schema->create('legal_archive_documents', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->string('title');
            $table->unsignedBigInteger('current_primary_version_id')->nullable();
            $table->string('approval_status')->nullable();
            $table->string('lifecycle_status')->nullable();
            $table->string('signature_status')->nullable();
            $table->string('legal_significance_status')->nullable();
            $table->unsignedInteger('lock_version')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        $schema->create('legal_archive_document_files', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('document_id');
            $table->string('role');
            $table->string('title');
            $table->unsignedBigInteger('current_version_id')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_required')->default(false);
            $table->timestamps();
        });
        $schema->create('legal_archive_document_versions', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('document_id');
            $table->unsignedBigInteger('document_file_id');
            $table->string('version_number');
            $table->string('version_label')->nullable();
            $table->boolean('is_current');
            $table->string('status');
            $table->string('processing_status');
            $table->text('file_path');
            $table->string('original_filename');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size_bytes');
            $table->string('content_hash', 64);
            $table->string('metadata_hash', 64)->nullable();
            $table->unsignedBigInteger('uploaded_by_user_id')->nullable();
            $table->timestamp('uploaded_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
        $schema->create('legal_workflow_instances', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('document_id');
            $table->string('status');
        });
        $schema->create('legal_document_parties', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('document_id');
            $table->unsignedBigInteger('document_version_id');
        });
        $schema->create('legal_signature_requests', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('document_id');
            $table->unsignedBigInteger('document_version_id');
            $table->unsignedBigInteger('party_id')->nullable();
            $table->string('method');
            $table->string('provider')->nullable();
            $table->string('status');
            $table->string('signed_content_hash', 64);
            $table->json('signers');
            $table->string('correlation_id', 64);
            $table->string('provider_request_id')->nullable();
            $table->string('callback_replay_hash', 64)->nullable();
            $table->string('callback_payload_hash', 64)->nullable();
            $table->json('session_metadata')->nullable();
            $table->string('idempotency_key');
            $table->string('request_hash', 64);
            $table->unsignedBigInteger('requested_by_user_id');
            $table->timestamp('requested_at');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'requested_by_user_id', 'idempotency_key']);
        });
        $schema->create('legal_document_signatures', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('document_id');
            $table->unsignedBigInteger('document_version_id');
            $table->unsignedBigInteger('signature_request_id');
            $table->unsignedBigInteger('party_id')->nullable();
            $table->string('method');
            $table->string('provider')->nullable();
            $table->string('signer_name')->nullable();
            $table->json('signers');
            $table->string('signed_content_hash', 64);
            $table->text('signature_path')->nullable();
            $table->string('signature_content_hash', 64)->nullable();
            $table->json('certificate_metadata');
            $table->json('provider_metadata');
            $table->text('storage_location')->nullable();
            $table->timestamp('signed_at');
            $table->timestamp('verified_at')->nullable();
            $table->string('verification_status');
            $table->text('revocation_reason')->nullable();
            $table->unsignedBigInteger('registered_by_user_id')->nullable();
            $table->string('idempotency_key');
            $table->string('request_hash', 64);
            $table->timestamps();
            $table->unique('signature_request_id');
            $table->unique(['signature_request_id', 'idempotency_key']);
        });
        $schema->create('legal_signature_verifications', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('document_id');
            $table->unsignedBigInteger('document_version_id');
            $table->unsignedBigInteger('signature_id');
            $table->string('provider');
            $table->string('status');
            $table->string('signed_content_hash', 64);
            $table->json('certificate_metadata');
            $table->json('provider_metadata');
            $table->text('revocation_reason')->nullable();
            $table->unsignedBigInteger('verified_by_user_id')->nullable();
            $table->timestamp('verified_at');
            $table->string('idempotency_key');
            $table->string('request_hash', 64);
            $table->timestamps();
        });
    }
}

final class RecordingSignatureAudit implements LegalDocumentAudit
{
    public array $events = [];

    public function record(string $event, LegalArchiveDocument $document, User $actor, array $context = []): void
    {
        $this->events[] = $event;
    }

    public function recordForActorId(string $event, LegalArchiveDocument $document, ?int $actorId, array $context = []): void {}

    public function recordContractForActorId(string $event, Contract $contract, ?int $actorId, array $context = []): void {}
}

final class FixedElectronicSignatureProvider implements ElectronicSignatureProvider
{
    public string $contentHash = '';

    public function start(SignatureContext $context): SignatureSession
    {
        return new SignatureSession('fixed', 'provider-request-1', $context->correlationId, 'https://fixed.test/session');
    }

    public function complete(SignatureCallback $callback): SignatureVerificationResult
    {
        return new SignatureVerificationResult(
            'verified', 'fixed', $this->contentHash, true, 'Иван', '01AB', new DateTimeImmutable('now'),
            signaturePath: 'org-10/legal-archive/signatures/provider/one.p7s',
            signatureContentHash: str_repeat('c', 64),
            certificateMetadata: ['serial' => '01AB'],
            providerMetadata: ['provider_request_id' => $callback->providerRequestId],
        );
    }

    public function verify(\App\BusinessModules\Features\LegalArchive\Models\LegalDocumentSignature $signature): SignatureVerificationResult
    {
        return new SignatureVerificationResult('verified', 'fixed', (string) $signature->signed_content_hash, verifiedAt: new DateTimeImmutable('now'));
    }
}
