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
use App\Services\LegalArchive\Files\LegalDocumentFileCleanupDebtService;
use App\Services\LegalArchive\Signatures\DisabledElectronicSignatureProvider;
use App\Services\LegalArchive\Signatures\ElectronicSignatureEvidence;
use App\Services\LegalArchive\Signatures\ElectronicSignatureProvider;
use App\Services\LegalArchive\Signatures\ExternalOriginalData;
use App\Services\LegalArchive\Signatures\LegalDocumentSignatureService;
use App\Services\LegalArchive\Signatures\LegalSignatureArtifactReconciler;
use App\Services\LegalArchive\Signatures\LegalSignatureCleanupDebtService;
use App\Services\LegalArchive\Signatures\LegalSignatureCleanupMetrics;
use App\Services\LegalArchive\Signatures\LegalSignatureExpiryService;
use App\Services\LegalArchive\Signatures\LegalSignatureProjection;
use App\Services\LegalArchive\Signatures\PaperOriginalData;
use App\Services\LegalArchive\Signatures\SignatureArtifact;
use App\Services\LegalArchive\Signatures\SignatureCallback;
use App\Services\LegalArchive\Signatures\SignatureContext;
use App\Services\LegalArchive\Signatures\SignatureSession;
use App\Services\LegalArchive\Signatures\SignatureVerificationContext;
use App\Services\LegalArchive\Signatures\SignatureVerificationResult;
use App\Services\LegalArchive\Signatures\SignerIdentity;
use App\Services\LegalArchive\Signatures\SignerIdentitySet;
use App\Services\Storage\Exceptions\VersionedObjectIntegrityException;
use App\Services\Storage\FileService;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DomainException;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

final class ManualOriginalRegistrationTest extends TestCase
{
    private Capsule $database;

    private LegalDocumentSignatureService $service;

    private RecordingSignatureAudit $audit;

    private FileService $storage;

    private LegalDocumentAuthorizer $access;

    private mixed $previousConfig = null;

    private bool $hadConfig = false;

    protected function setUp(): void
    {
        parent::setUp();
        TrustedExternalSignatureProvider::$status = 'verified';
        $this->database = new Capsule;
        $this->database->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $this->database->setAsGlobal();
        $this->database->setEventDispatcher(new Dispatcher(new Container));
        $this->database->bootEloquent();
        Model::clearBootedModels();
        $this->schema();
        $container = Container::getInstance();
        $this->hadConfig = $container->bound('config');
        $this->previousConfig = $this->hadConfig ? $container->make('config') : null;
        $container->instance('config', new class
        {
            public function get(string $key, mixed $default = null): mixed
            {
                return match ($key) {
                    'legal-document-signatures.callback_url' => 'https://most.test/callback',
                    'legal-document-signatures.redirect_hosts' => ['fixed.test'],
                    'legal-document-signatures.start_lease_seconds' => 90,
                    'legal-document-signatures.max_session_seconds' => 900,
                    'legal-document-signatures.driver' => 'disabled',
                    'legal-document-signatures.drivers' => [
                        'disabled' => DisabledElectronicSignatureProvider::class,
                        'external-edo' => TrustedExternalSignatureProvider::class,
                    ],
                    default => $default,
                };
            }
        });
        $this->access = $this->createMock(LegalDocumentAuthorizer::class);
        $this->access->expects(self::any())->method('authorize')->willReturnCallback(static function (User $actor, LegalArchiveDocument $document, string $ability): void {
            if (! in_array($ability, ['request_signature', 'sign', 'verify_signature'], true)
                || (int) $actor->current_organization_id !== (int) $document->organization_id) {
                throw new DomainException('denied');
            }
        });
        $this->audit = new RecordingSignatureAudit;
        $this->storage = $this->createMock(FileService::class);
        $this->storage->method('putImmutable')->willReturnCallback(static function (string $path, string $body, string $mime): array {
            return [
                'path' => $path,
                'body' => $body,
                'size' => strlen($body),
                'sha256' => hash('sha256', $body),
                'etag' => 'etag',
                'version_id' => 'version-1',
                'content_type' => $mime,
                'created' => true,
            ];
        });
        $this->storage->method('describeVersion')->willReturnCallback(static function (string $path, string $versionId): array {
            $body = pack('H*', '3082010006092a864886f70d010702a0820100308200fc');

            return [
                'body' => $body,
                'version_id' => $versionId,
                'etag' => 'etag',
                'content_type' => 'application/pkcs7-signature',
            ];
        });
        $this->service = new LegalDocumentSignatureService(
            new DisabledElectronicSignatureProvider,
            $this->access,
            $this->audit,
            $this->storage,
            $this->database->getConnection(),
        );
    }

    protected function tearDown(): void
    {
        $container = Container::getInstance();
        if ($this->hadConfig) {
            $container->instance('config', $this->previousConfig);
        } else {
            $container->forgetInstance('config');
        }
        parent::tearDown();
    }

    public function test_paper_original_freezes_exact_current_version_and_preserves_hash(): void
    {
        [$document, $version, $actor] = $this->fixture();
        $request = $this->service->createRequest($document, $version, $actor, 'paper', $this->signerSet('Иван Иванов', 'Директор'), 'request-1');
        self::assertSame('frozen', $version->refresh()->status);
        self::assertSame($version->content_hash, $request->signed_content_hash);

        $signature = $this->service->registerPaperOriginal($request, $actor, new PaperOriginalData(
            new DateTimeImmutable('-1 day'),
            $this->signerSet('Иван Иванов', 'Директор'),
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
        $request = $this->service->createRequest($document, $version, $actor, 'paper', $this->signerSet('Иван'), 'request');
        $data = new PaperOriginalData(new DateTimeImmutable('-1 day'), $this->signerSet('Иван'), 'Архив', 'same');
        $first = $this->service->registerPaperOriginal($request, $actor, $data);
        $replay = $this->service->registerPaperOriginal($request->refresh(), $actor, $data);
        self::assertSame($first->id, $replay->id);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('legal_signature_idempotency_conflict');
        $this->service->registerPaperOriginal($request->refresh(), $actor, new PaperOriginalData(
            new DateTimeImmutable('-1 day'), $this->signerSet('Иван'), 'Другое место', 'same',
        ));
    }

    public function test_external_electronic_import_stays_pending_until_trusted_verification(): void
    {
        [$document, $version, $actor] = $this->fixture();
        $request = $this->service->createRequest(
            $document, $version, $actor, 'external_electronic', $this->signerSet('Иван'), 'external-request', provider: 'external-edo',
        );
        $container = pack('H*', '3082010006092a864886f70d010702a0820100308200fc');
        $upload = UploadedFile::fake()->createWithContent('signature.p7s', $container);
        $evidence = $this->evidence($this->signerSet('Иван'));
        $metadata = new ExternalOriginalData(
            'external-edo',
            $evidence,
            'external-signature',
            ['source' => 'import'],
        );
        $signature = $this->service->registerExternalOriginal($request, $upload, $actor, $metadata);

        self::assertSame($version->content_hash, $signature->signed_content_hash);
        self::assertSame(hash('sha256', $container), $signature->signature_content_hash);
        self::assertStringStartsWith('org-10/', (string) $signature->signature_path);
        self::assertSame('pending_verification', $signature->verification_status);
        self::assertNull($signature->verified_at);
        self::assertSame('completed', $request->refresh()->status);
        self::assertSame('pending', $document->refresh()->signature_status);
        self::assertNotSame('edo_original', $document->legal_significance_status);
        $replay = $this->service->registerExternalOriginal(
            $request->refresh(),
            UploadedFile::fake()->createWithContent('signature.p7s', $container),
            $actor,
            $metadata,
        );
        self::assertSame($signature->id, $replay->id);
        try {
            $this->service->registerExternalOriginal(
                $request->refresh(),
                UploadedFile::fake()->createWithContent('signature.p7s', $container),
                $actor,
                new ExternalOriginalData('external-edo', $evidence, 'external-signature', ['source' => 'changed']),
            );
            self::fail('External import replay accepted a changed command.');
        } catch (DomainException $exception) {
            self::assertSame('legal_signature_idempotency_conflict', $exception->getMessage());
        }
        try {
            $this->service->registerExternalOriginal(
                $request->refresh(),
                UploadedFile::fake()->createWithContent('signature.p7s', $container),
                $actor,
                new ExternalOriginalData(
                    'external-edo',
                    $this->evidence($this->signerSet('Иван'), $evidence->signedAt->modify('+1 minute')),
                    'external-signature',
                    ['source' => 'import'],
                ),
            );
            self::fail('External import replay accepted a changed signing time.');
        } catch (DomainException $exception) {
            self::assertSame('legal_signature_idempotency_conflict', $exception->getMessage());
        }

        TrustedExternalSignatureProvider::$status = 'failed';
        $failed = $this->service->verify($signature, $actor, 'external-verification-failed');
        self::assertSame('failed', $failed->status);
        self::assertSame('verification_failed', $document->refresh()->signature_status);
        self::assertSame('frozen', $version->refresh()->status);
        TrustedExternalSignatureProvider::$status = 'verified';
        $verification = $this->service->verify($signature, $actor, 'external-verification');
        self::assertSame('verified', $verification->status);
        self::assertSame('completed', $request->refresh()->status);
        self::assertSame('signed', $version->refresh()->status);
        self::assertSame('signed', $document->refresh()->signature_status);
    }

    public function test_provider_callback_is_correlated_authenticated_hash_bound_and_replay_safe(): void
    {
        [$document, $version, $actor] = $this->fixture();
        $provider = new FixedElectronicSignatureProvider;
        $service = new LegalDocumentSignatureService(
            $provider, $this->access, $this->audit, $this->storage, $this->database->getConnection(),
        );
        $request = $service->createRequest(
            $document, $version, $actor, 'provider_electronic', $this->signerSet('Иван'), 'provider-request', provider: 'fixed',
        );
        $session = $service->startElectronicSession($request, $actor);
        $replayedSession = $service->startElectronicSession($request->refresh(), $actor);
        self::assertSame($session->providerRequestId, $replayedSession->providerRequestId);
        self::assertSame(1, $provider->startCalls);
        self::assertSame($request->correlation_id, $session->correlationId);
        $provider->contentHash = (string) $version->content_hash;
        $callback = new SignatureCallback('fixed', $session->providerRequestId, $session->correlationId, 'provider-event-1', ['status' => 'signed']);
        $signature = $service->completeElectronic($callback);
        $replay = $service->completeElectronic($callback);
        self::assertSame($signature->id, $replay->id);
        self::assertSame($version->content_hash, $signature->signed_content_hash);
        try {
            $service->completeElectronic(new SignatureCallback(
                'fixed', $session->providerRequestId, $session->correlationId, 'provider-event-1', ['status' => 'changed'],
            ));
            self::fail('Changed replay payload was accepted.');
        } catch (DomainException $exception) {
            self::assertSame('legal_signature_callback_replay_conflict', $exception->getMessage());
        }

        try {
            $service->completeElectronic(new SignatureCallback('fixed', $session->providerRequestId, str_repeat('0', 64), 'provider-event-2', ['status' => 'signed']));
            self::fail('Correlation attack was accepted.');
        } catch (DomainException $exception) {
            self::assertSame('legal_signature_callback_invalid', $exception->getMessage());
        }
        self::assertContains('signature_callback_rejected', $this->audit->events);
    }

    public function test_provider_session_replay_precedes_stale_document_lock_check(): void
    {
        [$document, $version, $actor] = $this->fixture();
        $provider = new FixedElectronicSignatureProvider;
        $service = new LegalDocumentSignatureService(
            $provider, $this->access, $this->audit, $this->storage, $this->database->getConnection(),
        );
        $request = $service->createRequest(
            $document, $version, $actor, 'provider_electronic', $this->signerSet('Иван'),
            'provider-replay-before-lock', provider: 'fixed', expectedDocumentLockVersion: (int) $document->lock_version,
        );
        $expectedLockVersion = (int) $document->fresh()->lock_version;
        $session = $service->startElectronicSession($request, $actor, $expectedLockVersion);
        $this->database->getConnection()->table('legal_archive_documents')->where('id', $document->id)
            ->update(['lock_version' => $expectedLockVersion + 1]);

        $replay = $service->startElectronicSession($request->refresh(), $actor, $expectedLockVersion);

        self::assertSame($session->providerRequestId, $replay->providerRequestId);
        self::assertSame(1, $provider->startCalls);
    }

    public function test_provider_side_effect_is_durably_tracked_when_document_changes_before_finalize(): void
    {
        [$document, $version, $actor] = $this->fixture();
        $provider = new FixedElectronicSignatureProvider;
        $provider->onStart = function () use ($document): void {
            $this->database->getConnection()->table('legal_archive_documents')->where('id', $document->id)
                ->increment('lock_version');
        };
        $service = new LegalDocumentSignatureService(
            $provider, $this->access, $this->audit, $this->storage, $this->database->getConnection(),
        );
        $request = $service->createRequest(
            $document, $version, $actor, 'provider_electronic', $this->signerSet('Иван'),
            'provider-race-after-reservation', provider: 'fixed', expectedDocumentLockVersion: (int) $document->lock_version,
        );
        $expectedLockVersion = (int) $document->fresh()->lock_version;

        try {
            $service->startElectronicSession($request, $actor, $expectedLockVersion);
            self::fail('Concurrent document mutation was not reported.');
        } catch (\App\Services\LegalArchive\LegalArchiveLockConflict $conflict) {
            self::assertSame($expectedLockVersion + 1, $conflict->currentLockVersion);
        }

        $operation = $this->database->getConnection()->table('legal_signature_provider_operations')->sole();
        self::assertSame('started', $operation->status);
        self::assertSame('provider-request-1', $operation->provider_request_id);
        self::assertSame('provider-request-1', $request->refresh()->provider_request_id);

        $replay = $service->startElectronicSession($request->refresh(), $actor, $expectedLockVersion);
        self::assertSame('provider-request-1', $replay->providerRequestId);
        self::assertSame(1, $provider->startCalls);
    }

    public function test_provider_callback_rejects_result_and_evidence_signer_drift(): void
    {
        [$document, $version, $actor] = $this->fixture();
        $provider = new FixedElectronicSignatureProvider;
        $provider->evidenceSignerMismatch = true;
        $service = new LegalDocumentSignatureService(
            $provider, $this->access, $this->audit, $this->storage, $this->database->getConnection(),
        );
        $request = $service->createRequest(
            $document, $version, $actor, 'provider_electronic', $this->signerSet('Иван'), 'strict-callback', provider: 'fixed',
        );
        $session = $service->startElectronicSession($request, $actor);
        $provider->contentHash = (string) $version->content_hash;

        try {
            $service->completeElectronic(new SignatureCallback(
                'fixed', $session->providerRequestId, $session->correlationId, 'strict-event', ['status' => 'signed'],
            ));
            self::fail('Signer/evidence drift was accepted.');
        } catch (DomainException $exception) {
            self::assertSame('legal_signature_verification_result_invalid', $exception->getMessage());
        }
        self::assertContains('signature_callback_rejected', $this->audit->events);
    }

    public function test_callback_rechecks_provider_generation_inside_registration_transaction(): void
    {
        [$document, $version, $actor] = $this->fixture();
        $provider = new FixedElectronicSignatureProvider;
        $service = new LegalDocumentSignatureService(
            $provider, $this->access, $this->audit, $this->storage, $this->database->getConnection(),
        );
        $request = $service->createRequest(
            $document, $version, $actor, 'provider_electronic', $this->signerSet('Signer'),
            'transaction-fence', provider: 'fixed',
        );
        $session = $service->startElectronicSession($request, $actor);
        $provider->contentHash = (string) $version->content_hash;
        $provider->onComplete = function () use ($request): void {
            $first = $this->database->getConnection()->table('legal_signature_provider_operations')
                ->where('signature_request_id', $request->id)->sole();
            $this->database->getConnection()->table('legal_signature_provider_operations')->insert([
                'id' => 'superseding-operation', 'organization_id' => 10, 'document_id' => $request->document_id,
                'document_version_id' => $request->document_version_id, 'signature_request_id' => $request->id,
                'provider' => 'fixed', 'status' => 'started', 'correlation_id' => $request->correlation_id,
                'provider_idempotency_key' => str_repeat('9', 64),
                'request_idempotency_key' => $first->request_idempotency_key, 'generation' => 2,
                'supersedes_operation_id' => $first->id, 'attempt_count' => 1,
                'provider_request_id' => 'provider-request-2', 'redirect_url' => 'https://fixed.test/session-2',
                'session_expires_at' => now()->addMinutes(5), 'started_at' => now(), 'completed_at' => now(),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        };

        try {
            $service->completeElectronic(new SignatureCallback(
                'fixed', $session->providerRequestId, $session->correlationId, 'transaction-fence-event', ['status' => 'signed'],
            ));
            self::fail('A superseded operation committed a signature.');
        } catch (DomainException $exception) {
            self::assertSame('legal_signature_callback_stale_generation', $exception->getMessage());
        }
        self::assertSame(0, $this->database->getConnection()->table('legal_document_signatures')->count());
        self::assertContains('signature_callback_rejected', $this->audit->events);
        self::assertContains('stale_provider_generation', array_column($this->audit->contexts, 'reason'));
    }

    public function test_expired_provider_session_starts_new_generation(): void
    {
        [$document, $version, $actor] = $this->fixture();
        $provider = new FixedElectronicSignatureProvider;
        $service = new LegalDocumentSignatureService(
            $provider, $this->access, $this->audit, $this->storage, $this->database->getConnection(),
        );
        $request = $service->createRequest(
            $document, $version, $actor, 'provider_electronic', $this->signerSet('Signer'), 'restart-request', provider: 'fixed',
        );
        $first = $service->startElectronicSession($request, $actor);
        $firstOperation = $this->database->getConnection()->table('legal_signature_provider_operations')->sole();
        $this->database->getConnection()->table('legal_signature_provider_operations')->where('id', $firstOperation->id)
            ->update(['session_expires_at' => now()->subMinute()]);

        $second = $service->startElectronicSession($request->refresh(), $actor);
        $operations = $this->database->getConnection()->table('legal_signature_provider_operations')->orderBy('generation')->get();
        $preservedFirst = $operations->first();
        $secondOperation = $operations->last();

        self::assertNotSame($first->providerRequestId, $second->providerRequestId);
        self::assertSame(2, $provider->startCalls);
        self::assertCount(2, $operations);
        self::assertSame(2, (int) $secondOperation->generation);
        self::assertNotSame($firstOperation->provider_idempotency_key, $secondOperation->provider_idempotency_key);
        self::assertSame($first->providerRequestId, $preservedFirst->provider_request_id);
        self::assertSame($first->redirectUrl, $preservedFirst->redirect_url);
        self::assertSame($firstOperation->id, $secondOperation->supersedes_operation_id);
        $provider->contentHash = (string) $version->content_hash;
        try {
            $service->completeElectronic(new SignatureCallback(
                'fixed', $first->providerRequestId, $first->correlationId, 'stale-event', ['status' => 'signed'],
            ));
            self::fail('A callback from a stale provider generation was accepted.');
        } catch (DomainException $exception) {
            self::assertSame('legal_signature_callback_stale_generation', $exception->getMessage());
        }
        self::assertContains('signature_callback_rejected', $this->audit->events);
    }

    public function test_post_provider_storage_rejection_is_durably_audited(): void
    {
        [$document, $version, $actor] = $this->fixture();
        $provider = new FixedElectronicSignatureProvider;
        $storage = $this->createMock(FileService::class);
        $storage->method('putImmutable')->willThrowException(new \RuntimeException('storage unavailable'));
        $storage->method('describeVersion')->willReturnCallback(static function (string $path, ?string $versionId): array {
            return [
                'path' => $path,
                'version_id' => $versionId ?? 'committed-before-timeout',
                'sha256' => hash('sha256', pack('H*', '3082010006092a864886f70d010702a0820100308200fc')),
                'etag' => 'timeout-etag',
            ];
        });
        $service = new LegalDocumentSignatureService(
            $provider, $this->access, $this->audit, $storage, $this->database->getConnection(),
        );
        $request = $service->createRequest(
            $document, $version, $actor, 'provider_electronic', $this->signerSet('Signer'), 'storage-audit', provider: 'fixed',
        );
        $session = $service->startElectronicSession($request, $actor);
        $provider->contentHash = (string) $version->content_hash;

        try {
            $service->completeElectronic(new SignatureCallback(
                'fixed', $session->providerRequestId, $session->correlationId, 'storage-event', ['status' => 'signed'],
            ));
            self::fail('Storage failure was hidden.');
        } catch (\RuntimeException $exception) {
            self::assertSame('storage unavailable', $exception->getMessage());
        }
        self::assertContains('signature_callback_rejected', $this->audit->events);
        $artifact = $this->database->getConnection()->table('legal_signature_artifacts')->sole();
        self::assertSame('ambiguous', $artifact->state);
        self::assertNull($artifact->storage_version_id);
        $this->database->getConnection()->table('legal_signature_artifacts')->where('id', $artifact->id)
            ->update(['next_reconcile_at' => now()]);
        self::assertSame(1, (new LegalSignatureArtifactReconciler(
            $storage, $this->database->getConnection(), $this->audit, new RecordingCleanupMetrics,
        ))->reconcile());
        $artifact = $this->database->getConnection()->table('legal_signature_artifacts')->where('id', $artifact->id)->sole();
        self::assertSame('deleting', $artifact->state);
        self::assertSame('committed-before-timeout', $artifact->storage_version_id);
        self::assertSame(1, $this->database->getConnection()->table('legal_archive_file_cleanup_debts')
            ->where('storage_version_id', 'committed-before-timeout')->count());
    }

    public function test_failed_external_registration_removes_exact_storage_version(): void
    {
        [$document, $version, $actor] = $this->fixture();
        $request = $this->service->createRequest(
            $document, $version, $actor, 'external_electronic', $this->signerSet('Иван'), 'cleanup-request', provider: 'external-edo',
        );
        $container = pack('H*', '3082010006092a864886f70d010702a0820100308200fc');
        $storage = $this->createMock(FileService::class);
        $storage->method('putImmutable')->willReturnCallback(static fn (string $path, string $body, string $mime): array => [
            'path' => $path, 'body' => $body, 'size' => strlen($body), 'sha256' => hash('sha256', $body),
            'etag' => 'exact-etag', 'version_id' => 'exact-version', 'content_type' => $mime, 'created' => true,
        ]);
        $storage->expects(self::once())->method('removeImmutable')->with(
            self::stringContains("/{$request->id}/"),
            'exact-version',
        );
        $service = new LegalDocumentSignatureService(
            new DisabledElectronicSignatureProvider, $this->access, $this->audit, $storage, $this->database->getConnection(),
        );

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('legal_signature_signers_mismatch');
        $service->registerExternalOriginal(
            $request,
            UploadedFile::fake()->createWithContent('signature.p7s', $container),
            $actor,
            new ExternalOriginalData('external-edo', $this->evidence($this->signerSet('Другой')), 'cleanup-import'),
        );
    }

    public function test_signature_cleanup_debt_retries_exact_version_then_resolves_idempotently(): void
    {
        [$document, $version] = $this->fixture();
        $debtId = $this->database->getConnection()->table('legal_archive_file_cleanup_debts')->insertGetId([
            'organization_id' => 10, 'document_id' => $document->id, 'document_version_id' => $version->id,
            'storage_path' => 'org-10/signature.p7s', 'storage_version_id' => 'version-exact',
            'debt_key' => str_repeat('a', 64), 'reason' => 'signature_registration_failed', 'attempts' => 1,
            'next_attempt_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->database->getConnection()->table('legal_signature_artifacts')->insert([
            'organization_id' => 10, 'document_id' => $document->id, 'document_version_id' => $version->id,
            'signature_request_id' => 99, 'artifact_key' => str_repeat('1', 64),
            'storage_path' => 'org-10/signature.p7s', 'storage_version_id' => 'version-exact',
            'content_hash' => str_repeat('a', 64), 'state' => 'deleting', 'claim_count' => 0,
            'cleanup_owned' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $calls = 0;
        $storage = $this->createMock(FileService::class);
        $storage->method('removeImmutable')->willReturnCallback(static function (string $path, ?string $versionId) use (&$calls): void {
            self::assertSame('org-10/signature.p7s', $path);
            self::assertSame('version-exact', $versionId);
            $calls++;
            if ($calls === 1) {
                throw new \RuntimeException('temporary');
            }
        });
        $metrics = new RecordingCleanupMetrics;
        $service = new LegalSignatureCleanupDebtService(
            $storage, $this->database->getConnection(), $this->audit, $metrics,
        );

        self::assertSame(0, $service->processDue());
        $failed = $this->database->getConnection()->table('legal_archive_file_cleanup_debts')->where('id', $debtId)->first();
        self::assertSame(2, (int) $failed->attempts);
        self::assertNull($failed->resolved_at);
        $this->database->getConnection()->table('legal_archive_file_cleanup_debts')->where('id', $debtId)
            ->update(['next_attempt_at' => now()]);
        self::assertSame(1, $service->processDue());
        self::assertNotNull($this->database->getConnection()->table('legal_archive_file_cleanup_debts')->where('id', $debtId)->value('resolved_at'));
        self::assertSame(0, $service->processDue());
        self::assertSame(2, $calls);
        self::assertContains('legal_signature_cleanup_resolved_total', $metrics->metrics);
        $cleanupSources = array_values(array_filter(array_map(
            static fn (array $context): mixed => $context['source_event_id'] ?? null,
            $this->audit->contexts,
        ), static fn (mixed $source): bool => is_string($source) && str_starts_with($source, 'signature-cleanup-debt:')));
        self::assertCount(2, array_unique($cleanupSources));
    }

    public function test_signature_cleanup_debt_dead_letters_after_bounded_attempts(): void
    {
        [$document, $version] = $this->fixture();
        $debtId = $this->database->getConnection()->table('legal_archive_file_cleanup_debts')->insertGetId([
            'organization_id' => 10, 'document_id' => $document->id, 'document_version_id' => $version->id,
            'storage_path' => 'org-10/dead.p7s', 'storage_version_id' => 'version-dead',
            'debt_key' => str_repeat('b', 64), 'reason' => 'signature_registration_failed', 'attempts' => 7,
            'next_attempt_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->database->getConnection()->table('legal_signature_artifacts')->insert([
            'organization_id' => 10, 'document_id' => $document->id, 'document_version_id' => $version->id,
            'signature_request_id' => 99, 'artifact_key' => str_repeat('2', 64),
            'storage_path' => 'org-10/dead.p7s', 'storage_version_id' => 'version-dead',
            'content_hash' => str_repeat('a', 64), 'state' => 'deleting', 'claim_count' => 0,
            'cleanup_owned' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $storage = $this->createMock(FileService::class);
        $storage->method('removeImmutable')->willThrowException(new \RuntimeException('permanent'));
        $metrics = new RecordingCleanupMetrics;
        $service = new LegalSignatureCleanupDebtService(
            $storage, $this->database->getConnection(), $this->audit, $metrics,
        );

        self::assertSame(0, $service->processDue());
        $dead = $this->database->getConnection()->table('legal_archive_file_cleanup_debts')->where('id', $debtId)->first();
        self::assertSame(8, (int) $dead->attempts);
        self::assertNotNull($dead->dead_lettered_at);
        self::assertNull($dead->next_attempt_at);
        self::assertContains('legal_signature_storage_cleanup_dead_lettered_total', $metrics->metrics);
    }

    public function test_signature_cleanup_never_calls_storage_for_an_incompatible_artifact(): void
    {
        [$document, $version] = $this->fixture();
        $this->database->getConnection()->table('legal_signature_artifacts')->insert([
            'organization_id' => 10, 'document_id' => $document->id, 'document_version_id' => $version->id,
            'signature_request_id' => 99, 'artifact_key' => str_repeat('7', 64),
            'storage_path' => 'org-10/incompatible.p7s', 'storage_version_id' => 'wrong-version',
            'content_hash' => str_repeat('a', 64), 'state' => 'uploaded', 'claim_count' => 0,
            'cleanup_owned' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $debtId = $this->database->getConnection()->table('legal_archive_file_cleanup_debts')->insertGetId([
            'organization_id' => 10, 'document_id' => $document->id, 'document_version_id' => $version->id,
            'storage_path' => 'org-10/incompatible.p7s', 'storage_version_id' => 'expected-version',
            'debt_key' => str_repeat('8', 64), 'reason' => 'signature_registration_failed', 'attempts' => 1,
            'next_attempt_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $storage = $this->createMock(FileService::class);
        $storage->expects(self::never())->method('removeImmutable');
        $metrics = new RecordingCleanupMetrics;

        self::assertSame(0, (new LegalSignatureCleanupDebtService(
            $storage, $this->database->getConnection(), $this->audit, $metrics,
        ))->processDue());
        self::assertNotNull($this->database->getConnection()->table('legal_archive_file_cleanup_debts')
            ->where('id', $debtId)->value('dead_lettered_at'));
        self::assertContains('legal_signature_cleanup_authorization_rejected_total', $metrics->metrics);
    }

    public function test_signature_cleanup_defers_exact_late_version_while_artifact_reconciliation_is_pending(): void
    {
        [$document, $version] = $this->fixture();
        $path = 'org-10/late-pending.p7s';
        $this->database->getConnection()->table('legal_signature_artifacts')->insert([
            'organization_id' => 10, 'document_id' => $document->id, 'document_version_id' => $version->id,
            'signature_request_id' => 99, 'artifact_key' => str_repeat('d', 64),
            'storage_path' => $path, 'storage_version_id' => null,
            'content_hash' => str_repeat('a', 64), 'state' => 'ambiguous', 'claim_count' => 0,
            'cleanup_owned' => false, 'first_ambiguous_at' => now(), 'next_reconcile_at' => now()->addMinute(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $debtId = $this->database->getConnection()->table('legal_archive_file_cleanup_debts')->insertGetId([
            'organization_id' => 10, 'document_id' => $document->id, 'document_version_id' => $version->id,
            'storage_path' => $path, 'storage_version_id' => 'late-pending-version',
            'debt_key' => str_repeat('e', 64), 'reason' => 'signature_registration_failed', 'attempts' => 0,
            'next_attempt_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $storage = $this->createMock(FileService::class);
        $storage->expects(self::never())->method('removeImmutable');

        self::assertSame(0, (new LegalSignatureCleanupDebtService(
            $storage, $this->database->getConnection(), $this->audit, new RecordingCleanupMetrics,
        ))->processDue());
        $debt = $this->database->getConnection()->table('legal_archive_file_cleanup_debts')->where('id', $debtId)->sole();
        self::assertNull($debt->dead_lettered_at);
        self::assertSame('legal_signature_cleanup_reconciliation_pending', $debt->last_error);
        self::assertTrue(now()->lt($debt->next_attempt_at));
    }

    public function test_artifact_reconciler_recovers_put_before_bind_and_creates_cleanup_debt(): void
    {
        [$document, $version] = $this->fixture();
        $content = 'interrupted-signature';
        $artifactId = $this->database->getConnection()->table('legal_signature_artifacts')->insertGetId([
            'organization_id' => 10, 'document_id' => $document->id, 'document_version_id' => $version->id,
            'signature_request_id' => 99, 'artifact_key' => str_repeat('3', 64),
            'storage_path' => 'org-10/interrupted.p7s', 'storage_version_id' => null,
            'content_hash' => hash('sha256', $content), 'state' => 'uploading', 'claim_count' => 1,
            'cleanup_owned' => false, 'upload_lease_token_hash' => str_repeat('4', 64),
            'upload_lease_expires_at' => now()->subMinute(), 'attempt_count' => 1,
            'created_at' => now()->subMinutes(20), 'updated_at' => now()->subMinutes(20),
        ]);
        $storage = $this->createMock(FileService::class);
        $storage->expects(self::once())->method('describeVersion')->with('org-10/interrupted.p7s', null)
            ->willReturn([
                'path' => 'org-10/interrupted.p7s', 'body' => $content, 'size' => strlen($content),
                'sha256' => hash('sha256', $content), 'etag' => 'etag', 'version_id' => 'recovered-version',
                'content_type' => 'application/pkcs7-signature',
            ]);
        $metrics = new RecordingCleanupMetrics;

        self::assertSame(1, (new LegalSignatureArtifactReconciler(
            $storage, $this->database->getConnection(), $this->audit, $metrics,
        ))->reconcile());
        $artifact = $this->database->getConnection()->table('legal_signature_artifacts')->where('id', $artifactId)->sole();
        self::assertSame('deleting', $artifact->state);
        self::assertSame('recovered-version', $artifact->storage_version_id);
        self::assertSame(0, (int) $artifact->claim_count);
        self::assertTrue((bool) $artifact->cleanup_owned);
        self::assertSame(1, $this->database->getConnection()->table('legal_archive_file_cleanup_debts')
            ->where('storage_path', 'org-10/interrupted.p7s')->count());
    }

    public function test_artifact_reconciler_confirms_absence_only_after_repeated_checks_and_grace(): void
    {
        [$document, $version] = $this->fixture();
        $artifactId = $this->database->getConnection()->table('legal_signature_artifacts')->insertGetId([
            'organization_id' => 10, 'document_id' => $document->id, 'document_version_id' => $version->id,
            'signature_request_id' => 99, 'artifact_key' => str_repeat('5', 64),
            'storage_path' => 'org-10/not-uploaded.p7s', 'storage_version_id' => null,
            'content_hash' => str_repeat('a', 64), 'state' => 'uploading', 'claim_count' => 1,
            'cleanup_owned' => false, 'upload_lease_token_hash' => str_repeat('6', 64),
            'upload_lease_expires_at' => now()->subMinute(), 'attempt_count' => 1,
            'created_at' => now()->subMinutes(20), 'updated_at' => now()->subMinutes(20),
        ]);
        $storage = $this->createMock(FileService::class);
        $storage->expects(self::exactly(3))->method('describeVersion')
            ->willThrowException(new VersionedObjectIntegrityException('s3_pinned_object_unavailable'));
        $storage->expects(self::never())->method('removeImmutable');

        self::assertSame(1, (new LegalSignatureArtifactReconciler(
            $storage, $this->database->getConnection(), $this->audit, new RecordingCleanupMetrics,
        ))->reconcile());
        $artifact = $this->database->getConnection()->table('legal_signature_artifacts')->where('id', $artifactId)->sole();
        self::assertSame('ambiguous', $artifact->state);
        self::assertSame(1, (int) $artifact->absence_check_count);
        self::assertSame(0, (int) $artifact->claim_count);
        self::assertSame(0, $this->database->getConnection()->table('legal_archive_file_cleanup_debts')->count());
        self::assertContains('signature_artifact_reconcile_absence_observed', $this->audit->events);

        CarbonImmutable::setTestNow(now()->addMinutes(10));
        $this->database->getConnection()->table('legal_signature_artifacts')->where('id', $artifactId)
            ->update(['next_reconcile_at' => now()]);
        self::assertSame(1, (new LegalSignatureArtifactReconciler(
            $storage, $this->database->getConnection(), $this->audit, new RecordingCleanupMetrics,
        ))->reconcile());
        self::assertSame('ambiguous', $this->database->getConnection()->table('legal_signature_artifacts')->where('id', $artifactId)->value('state'));

        CarbonImmutable::setTestNow(now()->addMinutes(31));
        $this->database->getConnection()->table('legal_signature_artifacts')->where('id', $artifactId)
            ->update(['next_reconcile_at' => now()]);
        self::assertSame(1, (new LegalSignatureArtifactReconciler(
            $storage, $this->database->getConnection(), $this->audit, new RecordingCleanupMetrics,
        ))->reconcile());
        $artifact = $this->database->getConnection()->table('legal_signature_artifacts')->where('id', $artifactId)->sole();
        self::assertSame('confirmed_absent', $artifact->state);
        self::assertSame(3, (int) $artifact->absence_check_count);
        self::assertNull($artifact->next_reconcile_at);
        self::assertContains('signature_artifact_reconcile_absence_confirmed', $this->audit->events);
        CarbonImmutable::setTestNow();
    }

    public function test_late_put_after_absence_observation_creates_exact_cleanup_debt_and_is_deleted(): void
    {
        [$document, $version] = $this->fixture();
        $path = 'org-10/late-commit.p7s';
        $contentHash = str_repeat('a', 64);
        $storage = $this->createMock(FileService::class);
        $storage->expects(self::once())->method('describeVersion')
            ->with($path, null)
            ->willThrowException(new VersionedObjectIntegrityException('s3_pinned_object_unavailable'));
        $storage->expects(self::once())->method('removeImmutable')->with($path, 'late-version');
        $service = new LegalDocumentSignatureService(
            new DisabledElectronicSignatureProvider, $this->access, $this->audit, $storage, $this->database->getConnection(),
        );
        $reserve = new \ReflectionMethod($service, 'reserveSignatureArtifact');
        $heartbeat = new \ReflectionMethod($service, 'heartbeatSignatureArtifact');
        $recover = new \ReflectionMethod($service, 'recoverLateArtifactVersion');
        $reservation = $reserve->invoke(
            $service, 10, (int) $document->id, (int) $version->id, 99,
            $path, $contentHash, 'application/pkcs7-signature',
        );
        $this->database->getConnection()->table('legal_signature_artifacts')->update([
            'upload_lease_expires_at' => now()->subMinute(),
            'updated_at' => now()->subMinutes(20),
        ]);
        self::assertSame(1, (new LegalSignatureArtifactReconciler(
            $storage, $this->database->getConnection(), $this->audit, new RecordingCleanupMetrics,
        ))->reconcile());
        self::assertSame('ambiguous', $this->database->getConnection()->table('legal_signature_artifacts')->value('state'));
        try {
            $heartbeat->invoke($service, 10, $reservation['artifact_key'], $reservation['attempt_token']);
            self::fail('The stale uploader heartbeat was accepted.');
        } catch (DomainException $exception) {
            self::assertSame('legal_signature_artifact_attempt_stale', $exception->getMessage());
        }
        $recover->invoke(
            $service, 10, (int) $document->id, (int) $version->id, $reservation['artifact_key'],
            $path, 'late-version', 'late-etag', $contentHash,
        );
        $artifact = $this->database->getConnection()->table('legal_signature_artifacts')->sole();
        self::assertSame('deleting', $artifact->state);
        self::assertSame('late-version', $artifact->storage_version_id);
        self::assertSame(1, $this->database->getConnection()->table('legal_archive_file_cleanup_debts')
            ->where('storage_version_id', 'late-version')->count());
        self::assertSame(1, (new LegalSignatureCleanupDebtService(
            $storage, $this->database->getConnection(), $this->audit, new RecordingCleanupMetrics,
        ))->processDue());
        self::assertSame('deleted', $this->database->getConnection()->table('legal_signature_artifacts')->value('state'));
        self::assertNotNull($this->database->getConnection()->table('legal_archive_file_cleanup_debts')->value('resolved_at'));
    }

    public function test_nonmatching_late_versions_have_independent_cleanup_ownership_without_mutating_canonical_artifact(): void
    {
        [$document, $version] = $this->fixture();
        $deleted = [];
        $storage = $this->createMock(FileService::class);
        $storage->method('removeImmutable')->willReturnCallback(static function (string $path, ?string $versionId) use (&$deleted): void {
            $deleted[] = [$path, $versionId];
        });
        $service = new LegalDocumentSignatureService(
            new DisabledElectronicSignatureProvider, $this->access, $this->audit, $storage, $this->database->getConnection(),
        );
        $recover = new \ReflectionMethod($service, 'recoverLateArtifactVersion');
        $cases = [
            ['state' => 'uploading', 'version' => null, 'claim' => 1, 'cleanup' => false, 'token' => str_repeat('1', 64), 'expires' => now()->addMinutes(5), 'reference' => null],
            ['state' => 'uploaded', 'version' => 'canonical-uploaded', 'claim' => 0, 'cleanup' => false, 'token' => null, 'expires' => null, 'reference' => null],
            ['state' => 'deleting', 'version' => 'canonical-deleting', 'claim' => 0, 'cleanup' => true, 'token' => null, 'expires' => null, 'reference' => null],
            ['state' => 'referenced', 'version' => 'canonical-referenced', 'claim' => 0, 'cleanup' => false, 'token' => null, 'expires' => null, 'reference' => 777],
            ['state' => 'deleted', 'version' => 'canonical-deleted', 'claim' => 0, 'cleanup' => true, 'token' => null, 'expires' => null, 'reference' => null],
        ];
        foreach ($cases as $index => $case) {
            $path = "org-10/multi-version-{$index}.p7s";
            $canonicalKey = hash('sha256', "canonical-{$index}");
            $canonicalId = $this->database->getConnection()->table('legal_signature_artifacts')->insertGetId([
                'organization_id' => 10, 'document_id' => $document->id, 'document_version_id' => $version->id,
                'signature_request_id' => 99, 'artifact_key' => $canonicalKey,
                'storage_path' => $path, 'storage_version_id' => $case['version'],
                'content_hash' => str_repeat('a', 64), 'state' => $case['state'], 'claim_count' => $case['claim'],
                'cleanup_owned' => $case['cleanup'], 'upload_lease_token_hash' => $case['token'],
                'upload_lease_expires_at' => $case['expires'], 'referenced_signature_id' => $case['reference'],
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $before = (array) $this->database->getConnection()->table('legal_signature_artifacts')->where('id', $canonicalId)->sole();
            $lateVersion = "late-version-{$index}";
            $recover->invoke(
                $service, 10, (int) $document->id, (int) $version->id, $canonicalKey,
                $path, $lateVersion, "late-etag-{$index}", str_repeat('a', 64),
            );
            $after = (array) $this->database->getConnection()->table('legal_signature_artifacts')->where('id', $canonicalId)->sole();
            self::assertSame($before, $after);
            $lateArtifact = $this->database->getConnection()->table('legal_signature_artifacts')
                ->where('storage_path', $path)->where('storage_version_id', $lateVersion)->sole();
            self::assertSame('deleting', $lateArtifact->state);
            self::assertSame(1, (new LegalSignatureCleanupDebtService(
                $storage, $this->database->getConnection(), $this->audit, new RecordingCleanupMetrics,
            ))->processDue());
            self::assertSame('deleted', $this->database->getConnection()->table('legal_signature_artifacts')
                ->where('id', $lateArtifact->id)->value('state'));
            self::assertNull($this->database->getConnection()->table('legal_archive_file_cleanup_debts')
                ->where('storage_version_id', $lateVersion)->value('dead_lettered_at'));
        }
        self::assertSame([
            ['org-10/multi-version-0.p7s', 'late-version-0'],
            ['org-10/multi-version-1.p7s', 'late-version-1'],
            ['org-10/multi-version-2.p7s', 'late-version-2'],
            ['org-10/multi-version-3.p7s', 'late-version-3'],
            ['org-10/multi-version-4.p7s', 'late-version-4'],
        ], $deleted);
    }

    public function test_late_version_referenced_by_signature_is_retained_while_referenced_canonical_version_stays_unchanged(): void
    {
        [$document, $version, $actor] = $this->fixture();
        $signers = $this->signerSet('Signer');
        $request = $this->service->createRequest(
            $document, $version, $actor, 'external_electronic', $signers, 'late-reference-request', provider: 'external-edo',
        );
        $signature = $this->service->registerExternalOriginal(
            $request,
            UploadedFile::fake()->createWithContent(
                'signature.p7s', pack('H*', '3082010006092a864886f70d010702a0820100308200fc'),
            ),
            $actor,
            new ExternalOriginalData('external-edo', $this->evidence($signers), 'late-reference-import'),
        );
        $path = (string) $signature->signature_path;
        $lateVersion = (string) $signature->storage_version_id;
        $canonicalKey = hash('sha256', 'canonical-referenced-b');
        $canonicalId = $this->database->getConnection()->table('legal_signature_artifacts')->insertGetId([
            'organization_id' => 10, 'document_id' => $document->id, 'document_version_id' => $version->id,
            'signature_request_id' => $request->id, 'artifact_key' => $canonicalKey,
            'storage_path' => $path, 'storage_version_id' => 'canonical-version-b',
            'content_hash' => (string) $signature->signature_content_hash, 'state' => 'referenced', 'claim_count' => 0,
            'cleanup_owned' => false, 'referenced_signature_id' => $signature->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $before = (array) $this->database->getConnection()->table('legal_signature_artifacts')->where('id', $canonicalId)->sole();
        $storage = $this->createMock(FileService::class);
        $storage->expects(self::never())->method('removeImmutable');
        $service = new LegalDocumentSignatureService(
            new DisabledElectronicSignatureProvider, $this->access, $this->audit, $storage, $this->database->getConnection(),
        );
        (new \ReflectionMethod($service, 'recoverLateArtifactVersion'))->invoke(
            $service, 10, (int) $document->id, (int) $version->id, $canonicalKey,
            $path, $lateVersion, 'late-reference-etag', (string) $signature->signature_content_hash,
        );
        self::assertSame($before, (array) $this->database->getConnection()->table('legal_signature_artifacts')->where('id', $canonicalId)->sole());
        self::assertSame(1, (new LegalSignatureCleanupDebtService(
            $storage, $this->database->getConnection(), $this->audit, new RecordingCleanupMetrics,
        ))->processDue());
        $debt = $this->database->getConnection()->table('legal_archive_file_cleanup_debts')
            ->where('storage_version_id', $lateVersion)->sole();
        self::assertNotNull($debt->resolved_at);
        self::assertNull($debt->dead_lettered_at);
    }

    public function test_artifact_reconciler_repairs_deleting_state_without_cleanup_debt(): void
    {
        [$document, $version] = $this->fixture();
        $this->database->getConnection()->table('legal_signature_artifacts')->insert([
            'organization_id' => 10, 'document_id' => $document->id, 'document_version_id' => $version->id,
            'signature_request_id' => 99, 'artifact_key' => str_repeat('b', 64),
            'storage_path' => 'org-10/deleting-without-debt.p7s', 'storage_version_id' => 'orphan-version',
            'content_hash' => str_repeat('a', 64), 'state' => 'deleting', 'claim_count' => 0,
            'cleanup_owned' => true, 'attempt_count' => 1,
            'created_at' => now()->subMinutes(20), 'updated_at' => now()->subMinutes(20),
        ]);
        $storage = $this->createMock(FileService::class);
        $storage->expects(self::never())->method('describeVersion');
        $storage->expects(self::never())->method('removeImmutable');

        self::assertSame(1, (new LegalSignatureArtifactReconciler(
            $storage, $this->database->getConnection(), $this->audit, new RecordingCleanupMetrics,
        ))->reconcile());
        self::assertSame(1, $this->database->getConnection()->table('legal_archive_file_cleanup_debts')
            ->where('storage_path', 'org-10/deleting-without-debt.p7s')->count());
    }

    public function test_artifact_reconciler_preserves_object_when_signature_reference_already_exists(): void
    {
        [$document, $version, $actor] = $this->fixture();
        $signers = $this->signerSet('Signer');
        $request = $this->service->createRequest(
            $document, $version, $actor, 'external_electronic', $signers,
            'reconcile-reference-request', provider: 'external-edo',
        );
        $signature = $this->service->registerExternalOriginal(
            $request,
            UploadedFile::fake()->createWithContent(
                'signature.p7s', pack('H*', '3082010006092a864886f70d010702a0820100308200fc'),
            ),
            $actor,
            new ExternalOriginalData('external-edo', $this->evidence($signers), 'reconcile-reference-import'),
        );
        $this->database->getConnection()->table('legal_signature_artifacts')->update([
            'state' => 'uploaded', 'referenced_signature_id' => null, 'claim_count' => 1,
            'upload_lease_token_hash' => str_repeat('c', 64),
            'upload_lease_expires_at' => now()->subMinute(), 'updated_at' => now()->subMinutes(20),
        ]);
        $storage = $this->createMock(FileService::class);
        $storage->expects(self::never())->method('describeVersion');
        $storage->expects(self::never())->method('removeImmutable');

        self::assertSame(1, (new LegalSignatureArtifactReconciler(
            $storage, $this->database->getConnection(), $this->audit, new RecordingCleanupMetrics,
        ))->reconcile());
        $artifact = $this->database->getConnection()->table('legal_signature_artifacts')->sole();
        self::assertSame('referenced', $artifact->state);
        self::assertSame((int) $signature->id, (int) $artifact->referenced_signature_id);
        self::assertSame(0, $this->database->getConnection()->table('legal_archive_file_cleanup_debts')->count());
    }

    public function test_general_and_signature_cleanup_workers_consume_only_their_own_debts(): void
    {
        $debtId = $this->database->getConnection()->table('legal_archive_file_cleanup_debts')->insertGetId([
            'organization_id' => 10, 'storage_path' => 'org-10/general-file.pdf', 'storage_version_id' => null,
            'debt_key' => str_repeat('c', 64), 'reason' => 'version_fence_lost_or_persistence_failed',
            'attempts' => 1, 'next_attempt_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $storage = $this->createMock(FileService::class);
        $storage->expects(self::never())->method('removeImmutable');

        self::assertSame(0, (new LegalSignatureCleanupDebtService(
            $storage, $this->database->getConnection(), $this->audit,
        ))->processDue());
        self::assertNull($this->database->getConnection()->table('legal_archive_file_cleanup_debts')
            ->where('id', $debtId)->value('resolved_at'));

        $storage->expects(self::once())->method('delete')->with(
            'org-10/general-file.pdf',
            self::callback(static fn ($organization): bool => (int) $organization->id === 10),
        )->willReturn(true);
        self::assertSame(1, (new LegalDocumentFileCleanupDebtService(
            $storage, $this->database->getConnection(), $this->audit, new RecordingCleanupMetrics,
        ))->processDue());
        self::assertNotNull($this->database->getConnection()->table('legal_archive_file_cleanup_debts')
            ->where('id', $debtId)->value('resolved_at'));

        $unknownId = $this->database->getConnection()->table('legal_archive_file_cleanup_debts')->insertGetId([
            'organization_id' => 10, 'storage_path' => 'org-10/unknown.pdf', 'storage_version_id' => null,
            'debt_key' => str_repeat('9', 64), 'reason' => 'unknown_cleanup_reason',
            'attempts' => 0, 'next_attempt_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        self::assertSame(0, (new LegalDocumentFileCleanupDebtService(
            $storage, $this->database->getConnection(), $this->audit, new RecordingCleanupMetrics,
        ))->processDue());
        self::assertNull($this->database->getConnection()->table('legal_archive_file_cleanup_debts')
            ->where('id', $unknownId)->value('resolved_at'));
    }

    public function test_stale_upload_attempt_cannot_bind_or_release_after_reconciler_fence(): void
    {
        [$document, $version] = $this->fixture();
        $storage = $this->createMock(FileService::class);
        $storage->expects(self::once())->method('removeImmutable')->with('org-10/shared.p7s', 'shared-version');
        $service = new LegalDocumentSignatureService(
            new DisabledElectronicSignatureProvider, $this->access, $this->audit, $storage, $this->database->getConnection(),
        );
        $reserve = new \ReflectionMethod($service, 'reserveSignatureArtifact');
        $bind = new \ReflectionMethod($service, 'bindSignatureArtifact');
        $release = new \ReflectionMethod($service, 'releaseFailedArtifactClaim');
        $reservation = $reserve->invoke(
            $service, 10, (int) $document->id, (int) $version->id, 77,
            'org-10/shared.p7s', str_repeat('a', 64), 'application/pkcs7-signature',
        );
        $key = $reservation['artifact_key'];
        $staleToken = $reservation['attempt_token'];
        $storedTokenHash = $this->database->getConnection()->table('legal_signature_artifacts')
            ->value('upload_lease_token_hash');
        self::assertSame(hash('sha256', $staleToken), $storedTokenHash);
        self::assertNotSame($staleToken, $storedTokenHash);
        $activeToken = 'reconciler-owned-token';
        $this->database->getConnection()->table('legal_signature_artifacts')->update([
            'upload_lease_token_hash' => hash('sha256', $activeToken),
            'upload_lease_expires_at' => now()->addMinutes(5),
        ]);
        try {
            $bind->invoke($service, 10, $key, $staleToken, 'shared-version', true);
            self::fail('A stale uploader bound its storage version.');
        } catch (\ReflectionException $exception) {
            throw $exception;
        } catch (DomainException $exception) {
            self::assertSame('legal_signature_artifact_attempt_stale', $exception->getMessage());
        }
        $failure = new DomainException('registration failed');
        try {
            $release->invoke(
                $service, 10, (int) $document->id, (int) $version->id, 'org-10/shared.p7s', 'shared-version',
                'etag', str_repeat('a', 64), $failure, $key, $staleToken,
            );
            self::fail('A stale uploader released the reconciler claim.');
        } catch (DomainException $exception) {
            self::assertSame('legal_signature_artifact_attempt_stale', $exception->getMessage());
        }
        $bind->invoke($service, 10, $key, $activeToken, 'shared-version', true);
        $release->invoke(
            $service, 10, (int) $document->id, (int) $version->id, 'org-10/shared.p7s', 'shared-version',
            'etag', str_repeat('a', 64), $failure, $key, $activeToken,
        );
        $artifact = $this->database->getConnection()->table('legal_signature_artifacts')->sole();
        self::assertSame('deleted', $artifact->state);
        self::assertSame(0, (int) $artifact->claim_count);
    }

    public function test_cleanup_backlog_atomically_marks_artifact_deleted_and_replay_is_idle(): void
    {
        [$document, $version] = $this->fixture();
        $this->database->getConnection()->table('legal_signature_artifacts')->insert([
            'organization_id' => 10, 'document_id' => $document->id, 'document_version_id' => $version->id,
            'signature_request_id' => 99, 'artifact_key' => str_repeat('f', 64),
            'storage_path' => 'org-10/backlog.p7s', 'storage_version_id' => 'backlog-version',
            'content_hash' => str_repeat('a', 64), 'state' => 'deleting', 'claim_count' => 0,
            'cleanup_owned' => true, 'created_at' => now(), 'updated_at' => now()->subMinute(),
        ]);
        $debtId = $this->database->getConnection()->table('legal_archive_file_cleanup_debts')->insertGetId([
            'organization_id' => 10, 'document_id' => $document->id, 'document_version_id' => $version->id,
            'storage_path' => 'org-10/backlog.p7s', 'storage_version_id' => 'backlog-version',
            'debt_key' => str_repeat('d', 64), 'reason' => 'signature_registration_failed', 'attempts' => 1,
            'next_attempt_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $storage = $this->createMock(FileService::class);
        $storage->expects(self::once())->method('removeImmutable')->with('org-10/backlog.p7s', 'backlog-version');
        $metrics = new RecordingCleanupMetrics;
        $worker = new LegalSignatureCleanupDebtService(
            $storage, $this->database->getConnection(), $this->audit, $metrics,
        );

        self::assertSame(1, $worker->processDue());
        self::assertSame('deleted', $this->database->getConnection()->table('legal_signature_artifacts')->value('state'));
        self::assertNotNull($this->database->getConnection()->table('legal_archive_file_cleanup_debts')
            ->where('id', $debtId)->value('resolved_at'));
        self::assertSame(0, $worker->processDue());
    }

    public function test_cleanup_never_deletes_an_artifact_referenced_by_a_winning_registration(): void
    {
        [$document, $version, $actor] = $this->fixture();
        $request = $this->service->createRequest(
            $document, $version, $actor, 'external_electronic', $this->signerSet('Signer'), 'artifact-race', provider: 'external-edo',
        );
        $signature = $this->service->registerExternalOriginal(
            $request,
            UploadedFile::fake()->createWithContent('signature.p7s', pack('H*', '3082010006092a864886f70d010702a0820100308200fc')),
            $actor,
            new ExternalOriginalData('external-edo', $this->evidence($this->signerSet('Signer')), 'artifact-race-import'),
        );
        $this->database->getConnection()->table('legal_archive_file_cleanup_debts')->insert([
            'organization_id' => 10,
            'document_id' => $document->id,
            'document_version_id' => $version->id,
            'storage_path' => $signature->signature_path,
            'storage_version_id' => $signature->storage_version_id,
            'debt_key' => str_repeat('e', 64),
            'reason' => 'signature_registration_failed',
            'attempts' => 1,
            'next_attempt_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $storage = $this->createMock(FileService::class);
        $storage->expects(self::never())->method('removeImmutable');
        $metrics = new RecordingCleanupMetrics;

        self::assertSame(1, (new LegalSignatureCleanupDebtService(
            $storage, $this->database->getConnection(), $this->audit, $metrics,
        ))->processDue());
        self::assertSame('referenced', $this->database->getConnection()->table('legal_signature_artifacts')->value('state'));
        self::assertContains('signature_storage_cleanup_reference_preserved', $this->audit->events);
    }

    public function test_projection_never_marks_document_without_requests_as_signed(): void
    {
        [$document] = $this->fixture();

        (new LegalSignatureProjection($this->database->getConnection()))->apply($document);

        self::assertSame('not_signed', $document->refresh()->signature_status);
        self::assertSame('draft', $document->lifecycle_status);
    }

    public function test_current_pointer_or_content_race_is_rejected(): void
    {
        [$document, $version, $actor, $file] = $this->fixture();
        $request = $this->service->createRequest($document, $version, $actor, 'paper', $this->signerSet('Иван'), 'request');
        $replacement = LegalArchiveDocumentVersion::query()->create([
            'organization_id' => 10, 'document_id' => $document->id, 'document_file_id' => $file->id,
            'version_number' => '2', 'is_current' => true, 'status' => 'uploaded', 'processing_status' => 'ready',
            'file_path' => 'org-10/two.pdf', 'original_filename' => 'two.pdf', 'size_bytes' => 3,
            'content_hash' => str_repeat('b', 64),
        ]);
        $document->forceFill(['current_primary_version_id' => $replacement->id])->save();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('legal_signature_version_not_ready');
        $this->service->registerPaperOriginal($request, $actor, new PaperOriginalData(
            new DateTimeImmutable('-1 day'), $this->signerSet('Иван'), 'Архив', 'paper',
        ));
    }

    public function test_only_uploaded_ready_current_version_can_be_frozen(): void
    {
        [$document, $version, $actor] = $this->fixture();
        $this->database->getConnection()->table('legal_archive_document_versions')->where('id', $version->id)
            ->update(['status' => 'draft']);
        $version->refresh();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('legal_signature_version_not_freezable');
        $this->service->createRequest($document, $version, $actor, 'paper', $this->signerSet('Иван'), 'invalid-freeze');
    }

    public function test_general_mutation_scope_cannot_skip_the_exact_signature_transition(): void
    {
        [, $version] = $this->fixture();

        $this->expectException(\App\Exceptions\ImmutableDataException::class);
        LegalArchiveDocumentVersion::technicalMutation(static function () use ($version): void {
            $version->forceFill(['status' => 'signed'])->save();
        });
    }

    public function test_approval_and_no_active_workflow_are_required(): void
    {
        [$document, $version, $actor] = $this->fixture();
        $document->forceFill(['approval_status' => 'pending', 'lifecycle_status' => 'under_review'])->save();
        try {
            $this->service->createRequest($document, $version, $actor, 'paper', $this->signerSet('Иван'), 'not-approved');
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
        $this->service->createRequest($document, $version, $actor, 'paper', $this->signerSet('Иван'), 'workflow');
    }

    public function test_structured_signer_identities_match_live_and_party_snapshots_exactly(): void
    {
        [$document, $version, $actor] = $this->fixture();
        $this->database->getConnection()->table('organizations')->insert([
            'id' => 10, 'name' => 'МОСТ', 'legal_name' => 'ООО МОСТ', 'tax_number' => '123',
            'registration_number' => '456', 'is_active' => true,
        ]);
        $this->database->getConnection()->table('users')->insert([
            'id' => 8, 'name' => 'Иван Петров', 'is_active' => true,
        ]);
        $this->database->getConnection()->table('organization_user')->insert([
            'organization_id' => 10, 'user_id' => 8, 'is_active' => true,
        ]);
        $partyId = $this->database->getConnection()->table('legal_document_parties')->insertGetId([
            'organization_id' => 10, 'document_id' => $document->id, 'document_version_id' => $version->id,
            'party_role' => 'supplier', 'legal_name' => 'ООО Поставщик', 'tax_number' => '789',
            'registration_number' => '101', 'representative_position' => 'Директор',
            'authority_basis' => 'Устав',
        ]);
        try {
            $this->service->createRequest($document, $version, $actor, 'paper', new SignerIdentitySet([
                new SignerIdentity('organization', 'ООО МОСТ', organizationId: 10, taxNumber: 'wrong', registrationNumber: '456'),
            ]), 'identity-invalid');
            self::fail('Organization signer snapshot drift was accepted.');
        } catch (DomainException $exception) {
            self::assertSame('legal_signature_signer_organization_invalid', $exception->getMessage());
        }

        $request = $this->service->createRequest($document->refresh(), $version->refresh(), $actor, 'paper', new SignerIdentitySet([
            new SignerIdentity('organization', 'ООО МОСТ', organizationId: 10, taxNumber: '123', registrationNumber: '456'),
            new SignerIdentity('party', 'ООО Поставщик', partyId: $partyId, taxNumber: '789', partyRole: 'supplier', position: 'Директор', registrationNumber: '101', authorityBasis: 'Устав'),
            new SignerIdentity('user', 'Иван Петров', userId: 8, organizationId: 10),
        ]), 'identity-valid');

        self::assertSame('pending', $request->status);
    }

    public function test_signer_identity_rejects_fields_outside_its_kind_and_hashes_only_allowed_fields(): void
    {
        foreach ([
            static fn (): SignerIdentity => new SignerIdentity('user', 'User', userId: 1, organizationId: 2, taxNumber: '123'),
            static fn (): SignerIdentity => new SignerIdentity('organization', 'Org', organizationId: 2, taxNumber: '123', position: 'CEO'),
            static fn (): SignerIdentity => new SignerIdentity('role', 'Role user', userId: 1, organizationId: 2, roleSlug: 'director', authorityBasis: 'Charter'),
            static fn (): SignerIdentity => new SignerIdentity('manual', 'Manual', taxNumber: '123'),
        ] as $factory) {
            try {
                $factory();
                self::fail('Signer accepted a field outside the kind schema.');
            } catch (DomainException $exception) {
                self::assertSame('legal_signature_signer_identity_invalid', $exception->getMessage());
            }
        }
        self::assertSame(
            ['kind' => 'user', 'name' => 'User', 'user_id' => 1, 'organization_id' => 2],
            (new SignerIdentity('user', 'User', userId: 1, organizationId: 2))->canonical(),
        );
    }

    public function test_expiry_is_locked_idempotent_and_reprojects_document(): void
    {
        [$document, $version, $actor] = $this->fixture();
        $request = $this->service->createRequest(
            $document,
            $version,
            $actor,
            'paper',
            $this->signerSet('Иван'),
            'expiring-request',
            expiresAt: new DateTimeImmutable('+1 minute'),
        );
        Carbon::setTestNow(now()->addMinutes(2));
        try {
            $expiry = new LegalSignatureExpiryService(
                $this->database->getConnection(),
                $this->audit,
                new LegalSignatureProjection($this->database->getConnection()),
            );
            self::assertSame(1, $expiry->expireDue());
            self::assertSame(0, $expiry->expireDue());
            self::assertSame('expired', $request->refresh()->status);
            self::assertSame('verification_failed', $document->refresh()->signature_status);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_request_expiry_closes_provider_operation_without_erasing_session_evidence(): void
    {
        [$document, $version, $actor] = $this->fixture();
        $provider = new FixedElectronicSignatureProvider;
        $service = new LegalDocumentSignatureService(
            $provider, $this->access, $this->audit, $this->storage, $this->database->getConnection(),
        );
        $request = $service->createRequest(
            $document, $version, $actor, 'provider_electronic', $this->signerSet('Signer'), 'expire-provider',
            provider: 'fixed', expiresAt: new DateTimeImmutable('+1 minute'),
        );
        $session = $service->startElectronicSession($request, $actor);
        Carbon::setTestNow(now()->addMinutes(2));
        try {
            $expiry = new LegalSignatureExpiryService(
                $this->database->getConnection(), $this->audit, new LegalSignatureProjection($this->database->getConnection()),
            );
            self::assertSame(1, $expiry->expireDue());
            $operation = $this->database->getConnection()->table('legal_signature_provider_operations')->sole();
            self::assertSame('expired', $operation->status);
            self::assertSame($session->providerRequestId, $operation->provider_request_id);
            self::assertSame($session->redirectUrl, $operation->redirect_url);
            self::assertContains('signature_provider_operation_expired', $this->audit->events);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_expired_attempt_is_explicitly_replaced_and_successful_replacement_signs_requirement(): void
    {
        [$document, $version, $actor] = $this->fixture();
        $expired = $this->service->createRequest(
            $document, $version, $actor, 'paper', $this->signerSet('Иван'), 'attempt-one',
            expiresAt: new DateTimeImmutable('+1 minute'),
        );
        Carbon::setTestNow(now()->addMinutes(2));
        try {
            $expiry = new LegalSignatureExpiryService(
                $this->database->getConnection(), $this->audit, new LegalSignatureProjection($this->database->getConnection()),
            );
            self::assertSame(1, $expiry->expireDue());
            $replacement = $this->service->createRequest(
                $document->refresh(), $version->refresh(), $actor, 'paper', $this->signerSet('Иван'), 'attempt-two',
                replacesRequestId: (int) $expired->id,
            );
            $replay = $this->service->createRequest(
                $document->refresh(), $version->refresh(), $actor, 'paper', $this->signerSet('Иван'), 'attempt-two',
                replacesRequestId: (int) $expired->id,
            );
            self::assertSame($replacement->id, $replay->id);
            self::assertSame($expired->id, $replacement->replaces_request_id);
            self::assertSame($expired->requirement_group_key, $replacement->requirement_group_key);
            $this->service->registerPaperOriginal($replacement, $actor, new PaperOriginalData(
                new DateTimeImmutable('-1 day'), $this->signerSet('Иван'), 'Архив', 'replacement-paper',
            ));
            self::assertSame('signed', $document->refresh()->signature_status);
            self::assertSame('signed', $version->refresh()->status);
            self::assertContains('signature_requested', $this->audit->events);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_failed_verification_attempt_is_replaced_and_old_evidence_is_excluded(): void
    {
        [$document, $version, $actor] = $this->fixture();
        $signers = $this->signerSet('Signer');
        $first = $this->service->createRequest(
            $document, $version, $actor, 'external_electronic', $signers, 'failed-attempt', provider: 'external-edo',
        );
        $container = pack('H*', '3082010006092a864886f70d010702a0820100308200fc');
        $firstSignature = $this->service->registerExternalOriginal(
            $first, UploadedFile::fake()->createWithContent('signature.p7s', $container), $actor,
            new ExternalOriginalData('external-edo', $this->evidence($signers), 'failed-import'),
        );
        TrustedExternalSignatureProvider::$status = 'failed';
        self::assertSame('failed', $this->service->verify($firstSignature, $actor, 'failed-verification')->status);
        $replacement = $this->service->createRequest(
            $document->refresh(), $version->refresh(), $actor, 'external_electronic', $signers, 'corrected-attempt',
            provider: 'external-edo', replacesRequestId: (int) $first->id,
        );
        TrustedExternalSignatureProvider::$status = 'verified';
        $correctedSignature = $this->service->registerExternalOriginal(
            $replacement, UploadedFile::fake()->createWithContent('signature.p7s', $container), $actor,
            new ExternalOriginalData('external-edo', $this->evidence($signers), 'corrected-import'),
        );
        self::assertSame('verified', $this->service->verify($correctedSignature, $actor, 'corrected-verification')->status);
        self::assertSame('signed', $document->refresh()->signature_status);
        self::assertSame('signed', $version->refresh()->status);
    }

    public function test_signed_version_stays_immutable_while_revoked_attempt_is_replaced(): void
    {
        [$document, $version, $actor] = $this->fixture();
        $signers = $this->signerSet('Signer');
        $first = $this->service->createRequest(
            $document, $version, $actor, 'external_electronic', $signers, 'signed-revoked-first', provider: 'external-edo',
        );
        $container = pack('H*', '3082010006092a864886f70d010702a0820100308200fc');
        $firstSignature = $this->service->registerExternalOriginal(
            $first, UploadedFile::fake()->createWithContent('signature.p7s', $container), $actor,
            new ExternalOriginalData('external-edo', $this->evidence($signers), 'signed-revoked-import'),
        );
        self::assertSame('verified', $this->service->verify($firstSignature, $actor, 'signed-first-verification')->status);
        self::assertSame('signed', $version->refresh()->status);
        TrustedExternalSignatureProvider::$status = 'revoked';
        self::assertSame('revoked', $this->service->verify($firstSignature, $actor, 'signed-revocation')->status);
        self::assertSame('revoked', $document->refresh()->signature_status);
        self::assertSame('signature_failed', $document->lifecycle_status);
        self::assertSame('signed', $version->refresh()->status);
        $replacement = $this->service->createRequest(
            $document, $version, $actor, 'external_electronic', $signers, 'signed-revoked-replacement',
            provider: 'external-edo', replacesRequestId: (int) $first->id,
        );
        self::assertSame('signed', $version->refresh()->status);
        TrustedExternalSignatureProvider::$status = 'verified';
        $corrected = $this->service->registerExternalOriginal(
            $replacement, UploadedFile::fake()->createWithContent('signature.p7s', $container), $actor,
            new ExternalOriginalData('external-edo', $this->evidence($signers), 'signed-revoked-corrected'),
        );
        self::assertSame('verified', $this->service->verify($corrected, $actor, 'signed-revoked-corrected-result')->status);
        self::assertSame('signed', $document->refresh()->signature_status);
        self::assertSame('signed', $version->refresh()->status);
    }

    private function signerSet(string $name, ?string $position = null): SignerIdentitySet
    {
        return new SignerIdentitySet([new SignerIdentity('manual', $name, position: $position)]);
    }

    private function evidence(SignerIdentitySet $signers, ?DateTimeImmutable $signedAt = null): ElectronicSignatureEvidence
    {
        $signedAt ??= new DateTimeImmutable('-1 day');

        return new ElectronicSignatureEvidence(
            'detached_cades', 'p7s', $signers, str_repeat('d', 64), '01AB', 'УЦ',
            $signedAt->modify('-1 year'), $signedAt->modify('+1 year'), true, 'operator', 'verified',
            $signedAt, new DateTimeImmutable('now'),
        );
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
        $document->forceFill([
            'current_primary_version_id' => $version->id,
            'type_profile_code' => 'contract.work',
            'structured_fields' => [],
        ])->save();
        $actor = new User;
        $actor->forceFill(['id' => 7, 'current_organization_id' => 10]);
        $actor->exists = true;

        return [$document, $version, $actor, $file];
    }

    private function schema(): void
    {
        $schema = $this->database->schema();
        $schema->create('organizations', static function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('legal_name')->nullable();
            $table->string('tax_number')->nullable();
            $table->string('registration_number')->nullable();
            $table->boolean('is_active');
            $table->softDeletes();
        });
        $schema->create('users', static function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->boolean('is_active');
            $table->softDeletes();
        });
        $schema->create('organization_user', static function (Blueprint $table): void {
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('user_id');
            $table->boolean('is_active');
        });
        $schema->create('legal_archive_documents', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->string('title');
            $table->unsignedBigInteger('current_primary_version_id')->nullable();
            $table->string('approval_status')->nullable();
            $table->string('lifecycle_status')->nullable();
            $table->string('signature_status')->nullable();
            $table->string('legal_significance_status')->nullable();
            $table->string('type_profile_code')->nullable();
            $table->json('structured_fields')->nullable();
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
            $table->unsignedBigInteger('party_organization_id')->nullable();
            $table->string('party_role')->nullable();
            $table->string('legal_name')->nullable();
            $table->string('tax_number')->nullable();
            $table->string('registration_number')->nullable();
            $table->string('representative_position')->nullable();
            $table->string('authority_basis')->nullable();
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
            $table->string('signer_snapshot_hash', 64)->nullable();
            $table->string('profile_code');
            $table->unsignedBigInteger('profile_lock_version');
            $table->json('allowed_signature_kinds');
            $table->json('required_signature_kinds');
            $table->json('allowed_signature_formats');
            $table->string('requirement_snapshot_hash', 64);
            $table->string('requirement_group_key', 64);
            $table->unsignedBigInteger('replaces_request_id')->nullable();
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
            $table->text('storage_version_id')->nullable();
            $table->string('storage_etag')->nullable();
            $table->string('detected_mime_type')->nullable();
            $table->json('certificate_metadata');
            $table->json('provider_metadata');
            $table->text('storage_location')->nullable();
            $table->timestamp('signed_at');
            $table->timestamp('verified_at')->nullable();
            $table->string('verification_status');
            $table->string('signature_kind');
            $table->string('container_format')->nullable();
            $table->string('signer_snapshot_hash', 64);
            $table->unsignedBigInteger('signer_user_id')->nullable();
            $table->unsignedBigInteger('signer_organization_id')->nullable();
            $table->string('party_role_snapshot')->nullable();
            $table->string('certificate_fingerprint', 64)->nullable();
            $table->string('certificate_serial')->nullable();
            $table->text('certificate_issuer')->nullable();
            $table->timestamp('certificate_valid_from')->nullable();
            $table->timestamp('certificate_valid_until')->nullable();
            $table->boolean('authority_confirmed');
            $table->string('time_source');
            $table->string('diagnostic_code');
            $table->string('signing_session_id')->nullable();
            $table->string('client_ip_hash', 64)->nullable();
            $table->string('user_agent_hash', 64)->nullable();
            $table->text('revocation_reason')->nullable();
            $table->unsignedBigInteger('registered_by_user_id')->nullable();
            $table->string('idempotency_key');
            $table->string('request_hash', 64);
            $table->timestamps();
            $table->unique('signature_request_id');
            $table->unique(['signature_request_id', 'idempotency_key']);
        });
        $schema->create('legal_signature_provider_operations', static function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('document_id');
            $table->unsignedBigInteger('document_version_id');
            $table->unsignedBigInteger('signature_request_id');
            $table->string('provider');
            $table->string('status');
            $table->string('correlation_id', 64);
            $table->string('provider_idempotency_key', 64)->unique();
            $table->string('request_idempotency_key', 64);
            $table->unsignedInteger('generation');
            $table->string('supersedes_operation_id')->nullable();
            $table->string('lease_token_hash', 64)->nullable();
            $table->timestamp('lease_expires_at')->nullable();
            $table->unsignedInteger('attempt_count')->default(0);
            $table->string('provider_request_id')->nullable();
            $table->text('redirect_url')->nullable();
            $table->timestamp('session_expires_at')->nullable();
            $table->json('session_metadata')->nullable();
            $table->string('last_error_code')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
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
        $schema->create('legal_signature_artifacts', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('document_id');
            $table->unsignedBigInteger('document_version_id');
            $table->unsignedBigInteger('signature_request_id');
            $table->string('artifact_key', 64);
            $table->text('storage_path');
            $table->text('storage_version_id')->nullable();
            $table->string('content_hash', 64);
            $table->string('put_request_hash', 64)->default('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');
            $table->string('state');
            $table->unsignedInteger('claim_count')->default(0);
            $table->boolean('cleanup_owned')->default(false);
            $table->char('upload_lease_token_hash', 64)->nullable();
            $table->timestamp('upload_lease_expires_at')->nullable();
            $table->timestamp('first_ambiguous_at')->nullable();
            $table->timestamp('next_reconcile_at')->nullable();
            $table->unsignedInteger('absence_check_count')->default(0);
            $table->char('deletion_lease_token_hash', 64)->nullable();
            $table->timestamp('deletion_lease_expires_at')->nullable();
            $table->timestamp('last_attempt_at')->nullable();
            $table->unsignedInteger('attempt_count')->default(0);
            $table->timestamp('dead_lettered_at')->nullable();
            $table->string('last_error_code')->nullable();
            $table->unsignedBigInteger('referenced_signature_id')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'artifact_key']);
        });
        $schema->create('legal_archive_file_cleanup_debts', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('document_id')->nullable();
            $table->unsignedBigInteger('document_version_id')->nullable();
            $table->text('storage_path');
            $table->text('storage_version_id')->nullable();
            $table->string('storage_etag')->nullable();
            $table->string('content_hash', 64)->nullable();
            $table->string('debt_key', 64)->nullable();
            $table->string('reason');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('last_attempt_at')->nullable();
            $table->string('lease_token_hash', 64)->nullable();
            $table->timestamp('lease_expires_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('dead_lettered_at')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'storage_path']);
            $table->unique(['organization_id', 'debt_key']);
        });
    }
}

final class RecordingSignatureAudit implements LegalDocumentAudit
{
    public array $events = [];

    public array $contexts = [];

    public function record(string $event, LegalArchiveDocument $document, User $actor, array $context = []): void
    {
        $this->events[] = $event;
        $this->contexts[] = $context;
    }

    public function recordForActorId(string $event, LegalArchiveDocument $document, ?int $actorId, array $context = []): void
    {
        $this->events[] = $event;
        $this->contexts[] = $context;
    }

    public function recordContractForActorId(string $event, Contract $contract, ?int $actorId, array $context = []): void {}
}

final class RecordingCleanupMetrics implements LegalSignatureCleanupMetrics
{
    public array $metrics = [];

    public function increment(string $metric, array $labels = []): void
    {
        $this->metrics[] = $metric;
    }
}

final class FixedElectronicSignatureProvider implements ElectronicSignatureProvider
{
    public string $contentHash = '';

    public int $startCalls = 0;

    public bool $evidenceSignerMismatch = false;

    public ?\Closure $onComplete = null;

    public ?\Closure $onStart = null;

    private ?SignerIdentitySet $expectedSigners = null;

    private function signers(): SignerIdentitySet
    {
        return $this->expectedSigners ?? new SignerIdentitySet([new SignerIdentity('manual', 'Signer')]);
    }

    private function evidence(): ElectronicSignatureEvidence
    {
        $signedAt = new DateTimeImmutable('-1 minute');
        $signers = $this->evidenceSignerMismatch
            ? new SignerIdentitySet([new SignerIdentity('manual', 'Подмена')])
            : $this->signers();

        return new ElectronicSignatureEvidence(
            'detached_cades', 'p7s', $signers, str_repeat('d', 64), '01AB', 'CA',
            $signedAt->modify('-1 year'), $signedAt->modify('+1 year'), true, 'provider', 'verified',
            $signedAt, new DateTimeImmutable('now'),
        );
    }

    public function start(SignatureContext $context): SignatureSession
    {
        $this->startCalls++;
        $this->expectedSigners = $context->signers;
        ($this->onStart ?? static fn (): null => null)();

        return new SignatureSession(
            'fixed', "provider-request-{$this->startCalls}", $context->correlationId, 'https://fixed.test/session',
            (new DateTimeImmutable('+5 minutes'))->format(DATE_ATOM),
        );
    }

    public function complete(SignatureCallback $callback): SignatureVerificationResult
    {
        ($this->onComplete ?? static fn (): null => null)();

        return new SignatureVerificationResult(
            'verified', 'fixed', $callback->providerRequestId, $callback->correlationId,
            $this->contentHash, $this->signers(), $this->evidence(),
            new SignatureArtifact(pack('H*', '3082010006092a864886f70d010702a0820100308200fc'), 'signature.p7s', 'application/pkcs7-signature'),
            true, providerMetadata: ['provider_request_id' => $callback->providerRequestId],
        );
    }

    public function verify(SignatureVerificationContext $context): SignatureVerificationResult
    {
        return new SignatureVerificationResult(
            'verified', 'fixed', 'provider-request-1', str_repeat('b', 64),
            (string) $context->signature->signed_content_hash, $this->signers(), $this->evidence(), $context->artifact,
        );
    }
}

final class TrustedExternalSignatureProvider implements ElectronicSignatureProvider
{
    public static string $status = 'verified';

    public function start(SignatureContext $context): SignatureSession
    {
        throw new DomainException('external_provider_start_forbidden');
    }

    public function complete(SignatureCallback $callback): SignatureVerificationResult
    {
        throw new DomainException('external_provider_callback_forbidden');
    }

    public function verify(SignatureVerificationContext $context): SignatureVerificationResult
    {
        $request = $context->signature->request()->firstOrFail();
        $signers = SignerIdentitySet::fromSnapshot((array) $context->signature->signers);
        $signedAt = new DateTimeImmutable((string) $context->signature->signed_at);
        $evidence = new ElectronicSignatureEvidence(
            (string) $context->signature->signature_kind,
            (string) $context->signature->container_format,
            $signers,
            str_repeat('d', 64),
            '01AB',
            'Trusted CA',
            $signedAt->modify('-1 year'),
            $signedAt->modify('+1 year'),
            true,
            'trusted_timestamp',
            self::$status === 'verified' ? 'verified' : 'verification_failed',
            $signedAt,
            new DateTimeImmutable('now'),
        );

        return new SignatureVerificationResult(
            self::$status,
            (string) $context->signature->provider,
            "external-verification:{$context->signature->id}",
            (string) $request->correlation_id,
            (string) $context->signature->signed_content_hash,
            $signers,
            $evidence,
            $context->artifact,
            revocationReason: self::$status === 'revoked' ? 'certificate_revoked' : null,
            providerMetadata: ['trust_service' => 'test'],
        );
    }
}
