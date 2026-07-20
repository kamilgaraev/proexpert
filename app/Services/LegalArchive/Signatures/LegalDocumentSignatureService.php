<?php

declare(strict_types=1);

namespace App\Services\LegalArchive\Signatures;

use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument;
use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocumentVersion;
use App\BusinessModules\Features\LegalArchive\Models\LegalDocumentSignature;
use App\BusinessModules\Features\LegalArchive\Models\LegalSignatureRequest;
use App\BusinessModules\Features\LegalArchive\Models\LegalSignatureVerification;
use App\Models\Organization;
use App\Models\User;
use App\Services\LegalArchive\Access\LegalDocumentAbility;
use App\Services\LegalArchive\Access\LegalDocumentAuthorizer;
use App\Services\LegalArchive\Audit\LegalDocumentAudit;
use App\Services\LegalArchive\CanonicalJson;
use App\Services\LegalArchive\LegalDocumentAggregateLock;
use App\Services\Storage\FileService;
use DateTimeImmutable;
use DomainException;
use Illuminate\Container\Container;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class LegalDocumentSignatureService
{
    public function __construct(
        private readonly ElectronicSignatureProvider $provider,
        private readonly LegalDocumentAuthorizer $authorizer,
        private readonly LegalDocumentAudit $audit,
        private readonly FileService $fileService,
        private readonly ConnectionInterface $connection,
        private readonly LegalDocumentAggregateLock $aggregateLock = new LegalDocumentAggregateLock,
    ) {}

    public function createRequest(
        LegalArchiveDocument $document,
        LegalArchiveDocumentVersion $version,
        User $actor,
        string $method,
        array $signers,
        string $idempotencyKey,
        ?int $partyId = null,
        ?string $provider = null,
        ?DateTimeImmutable $expiresAt = null,
    ): LegalSignatureRequest {
        $this->authorizer->authorize($actor, $document, LegalDocumentAbility::REQUEST_SIGNATURE->value);
        $method = trim($method);
        if (! in_array($method, ['paper', 'external_electronic', 'provider_electronic'], true)) {
            throw new DomainException('legal_signature_method_invalid');
        }
        $provider = $provider === null ? null : trim($provider);
        if (($method === 'paper' && $provider !== null)
            || ($method !== 'paper' && ($provider === null || $provider === ''))) {
            throw new DomainException('legal_signature_provider_required');
        }
        $signers = $this->validSigners($signers);
        if ($expiresAt !== null && $expiresAt <= new DateTimeImmutable('now')) {
            throw new DomainException('legal_signature_expiry_invalid');
        }
        $key = $this->validKey($idempotencyKey);
        $requestHash = CanonicalJson::fingerprint([
            'document_id' => (int) $document->id,
            'version_id' => (int) $version->id,
            'method' => $method,
            'provider' => $provider,
            'party_id' => $partyId,
            'signers' => $signers,
            'expires_at' => $expiresAt?->format(DATE_ATOM),
        ]);

        return $this->connection->transaction(function () use ($document, $version, $actor, $method, $provider, $partyId, $signers, $key, $requestHash, $expiresAt): LegalSignatureRequest {
            $lockedDocument = $this->aggregateLock->lockDocument($this->connection, (int) $document->organization_id, (int) $document->id);
            $lockedVersion = $this->aggregateLock->lockVersion($this->connection, $lockedDocument, (int) $version->id);
            $this->authorizer->authorize($actor, $lockedDocument, LegalDocumentAbility::REQUEST_SIGNATURE->value);
            $existing = $this->requests()->where('organization_id', $lockedDocument->organization_id)
                ->where('requested_by_user_id', $actor->id)->where('idempotency_key', $key)->lockForUpdate()->first();
            if ($existing instanceof LegalSignatureRequest) {
                $this->assertSameRequest($existing->request_hash, $requestHash);

                return $existing;
            }
            $this->assertSigningAllowed($lockedDocument, $lockedVersion);
            $this->assertParty($lockedDocument, $lockedVersion, $partyId);
            $this->freezeVersion($lockedVersion);
            $request = $this->newRequest()->newQuery()->create([
                'organization_id' => (int) $lockedDocument->organization_id,
                'document_id' => (int) $lockedDocument->id,
                'document_version_id' => (int) $lockedVersion->id,
                'party_id' => $partyId,
                'method' => $method,
                'provider' => $provider,
                'status' => 'pending',
                'signed_content_hash' => (string) $lockedVersion->content_hash,
                'signers' => $signers,
                'correlation_id' => hash('sha256', Str::uuid()->toString().Str::random(64)),
                'idempotency_key' => $key,
                'request_hash' => $requestHash,
                'requested_by_user_id' => (int) $actor->id,
                'requested_at' => now(),
                'expires_at' => $expiresAt,
            ]);
            $lockedDocument->forceFill([
                'signature_status' => 'pending',
                'lifecycle_status' => 'signing',
                'lock_version' => ((int) $lockedDocument->lock_version) + 1,
            ])->save();
            $this->audit->record('signature_requested', $lockedDocument, $actor, [
                'source_event_id' => "signature-request:{$request->id}",
                'idempotency_key' => $key,
                'signature_request_id' => (int) $request->id,
                'document_version_id' => (int) $lockedVersion->id,
                'signed_content_hash' => (string) $lockedVersion->content_hash,
                'method' => $method,
            ]);

            return $request;
        }, 3);
    }

    public function registerPaperOriginal(
        LegalSignatureRequest $request,
        User $actor,
        PaperOriginalData $data,
    ): LegalDocumentSignature {
        if (trim($data->storageLocation) === '' || mb_strlen($data->storageLocation) > 2000) {
            throw new DomainException('legal_signature_storage_location_required');
        }
        if ($data->signedAt > new DateTimeImmutable('now')) {
            throw new DomainException('legal_signature_signed_at_invalid');
        }

        return $this->registerOriginal($request, $actor, 'paper', $data->idempotencyKey, [
            'signed_at' => $data->signedAt,
            'signers' => $this->validSigners($data->signers),
            'party_id' => $data->partyId,
            'provider' => null,
            'signature_path' => null,
            'signature_content_hash' => null,
            'certificate_metadata' => [],
            'provider_metadata' => $data->metadata,
            'storage_location' => trim($data->storageLocation),
            'verification_status' => 'registered',
            'verified_at' => null,
            'revocation_reason' => null,
        ]);
    }

    public function registerExternalOriginal(
        LegalSignatureRequest $request,
        UploadedFile $upload,
        User $actor,
        ExternalOriginalData $metadata,
    ): LegalDocumentSignature {
        $document = $this->documentForRequest($request);
        $this->authorizer->authorize($actor, $document, LegalDocumentAbility::SIGN->value);
        $this->assertSignatureUpload($upload);
        if (! in_array($metadata->verificationStatus, ['verified', 'failed', 'revoked'], true)) {
            throw new DomainException('legal_signature_verification_status_invalid');
        }
        if ($metadata->signedAt > new DateTimeImmutable('now') || $metadata->verifiedAt < $metadata->signedAt) {
            throw new DomainException('legal_signature_verified_at_invalid');
        }
        if ($metadata->certificateMetadata === [] || trim($metadata->provider) === '') {
            throw new DomainException('legal_signature_certificate_metadata_required');
        }
        if ($metadata->verificationStatus === 'revoked' && trim((string) $metadata->revocationReason) === '') {
            throw new DomainException('legal_signature_revocation_reason_required');
        }
        $realPath = $upload->getRealPath();
        $containerHash = is_string($realPath) ? hash_file('sha256', $realPath) : false;
        if (! is_string($containerHash)) {
            throw new DomainException('legal_signature_container_invalid');
        }
        $organization = new Organization;
        $organization->forceFill(['id' => (int) $request->organization_id]);
        $storedPath = $this->fileService->upload(
            $upload,
            "legal-archive/signatures/requests/{$request->id}",
            null,
            'private',
            $organization,
            true,
            true,
        );
        if (! is_string($storedPath) || $storedPath === '') {
            throw new RuntimeException('legal_signature_container_upload_failed');
        }
        try {
            return $this->registerOriginal($request, $actor, 'external_electronic', $metadata->idempotencyKey, [
                'signed_at' => $metadata->signedAt,
                'signers' => $this->validSigners($metadata->signers),
                'party_id' => $metadata->partyId,
                'provider' => trim($metadata->provider),
                'signature_path' => $storedPath,
                'signature_content_hash' => $containerHash,
                'certificate_metadata' => $metadata->certificateMetadata,
                'provider_metadata' => $metadata->providerMetadata,
                'storage_location' => null,
                'verification_status' => $metadata->verificationStatus,
                'verified_at' => $metadata->verifiedAt,
                'revocation_reason' => $metadata->revocationReason,
            ]);
        } catch (Throwable $exception) {
            $this->cleanupUploadedContainer((int) $request->organization_id, $storedPath, $organization, $exception);
            throw $exception;
        }
    }

    public function startElectronicSession(LegalSignatureRequest $request, User $actor, string $callbackUrl): SignatureSession
    {
        $document = $this->documentForRequest($request);
        $this->authorizer->authorize($actor, $document, LegalDocumentAbility::SIGN->value);
        $this->assertRequestMethod($request, 'provider_electronic');
        if (filter_var($callbackUrl, FILTER_VALIDATE_URL) === false || parse_url($callbackUrl, PHP_URL_SCHEME) !== 'https') {
            throw new DomainException('legal_signature_callback_url_invalid');
        }
        $request = $this->connection->transaction(function () use ($request, $document, $actor): LegalSignatureRequest {
            $lockedDocument = $this->aggregateLock->lockDocument($this->connection, (int) $document->organization_id, (int) $document->id);
            $version = $this->aggregateLock->lockVersion($this->connection, $lockedDocument, (int) $request->document_version_id);
            $this->authorizer->authorize($actor, $lockedDocument, LegalDocumentAbility::SIGN->value);
            $lockedRequest = $this->lockRequest($request);
            $this->assertRequestReady($lockedDocument, $version, $lockedRequest);

            return $lockedRequest;
        }, 3);
        $session = $this->provider->start(new SignatureContext(
            (int) $request->organization_id,
            (int) $request->document_id,
            (int) $request->document_version_id,
            (string) $request->signed_content_hash,
            (string) $request->correlation_id,
            $callbackUrl,
            (array) $request->signers,
        ));
        if (! hash_equals((string) $request->correlation_id, $session->correlationId)
            || ! hash_equals((string) $request->provider, $session->provider)
            || trim($session->providerRequestId) === '') {
            throw new DomainException('legal_signature_provider_session_invalid');
        }
        $this->connection->transaction(function () use ($request, $session, $document, $actor): void {
            $lockedDocument = $this->aggregateLock->lockDocument($this->connection, (int) $document->organization_id, (int) $document->id);
            $version = $this->aggregateLock->lockVersion($this->connection, $lockedDocument, (int) $request->document_version_id);
            $this->authorizer->authorize($actor, $lockedDocument, LegalDocumentAbility::SIGN->value);
            $locked = $this->lockRequest($request);
            $this->assertRequestReady($lockedDocument, $version, $locked);
            if ($locked->provider_request_id !== null && ! hash_equals((string) $locked->provider_request_id, $session->providerRequestId)) {
                throw new DomainException('legal_signature_provider_request_conflict');
            }
            $this->authorizeSignatureMutation();
            LegalSignatureRequest::serviceMutation(function () use ($locked, $session): void {
                $locked->forceFill([
                    'provider_request_id' => $session->providerRequestId,
                    'session_metadata' => $session->metadata,
                ])->save();
            });
        }, 3);

        return $session;
    }

    public function completeElectronic(SignatureCallback $callback): LegalDocumentSignature
    {
        if (trim($callback->provider) === '' || trim($callback->providerRequestId) === ''
            || preg_match('/^[a-f0-9]{64}$/D', $callback->correlationId) !== 1
            || trim($callback->replayToken) === '') {
            throw new DomainException('legal_signature_callback_invalid');
        }
        $request = $this->requests()->where('provider', $callback->provider)
            ->where('provider_request_id', $callback->providerRequestId)
            ->where('correlation_id', $callback->correlationId)->first();
        if (! $request instanceof LegalSignatureRequest) {
            throw new DomainException('legal_signature_request_not_found');
        }
        $this->assertRequestMethod($request, 'provider_electronic', false);
        $result = $this->provider->complete($callback);
        if (! $result->callbackAuthentic || ! hash_equals((string) $request->signed_content_hash, $result->signedContentHash)) {
            throw new DomainException('legal_signature_callback_invalid');
        }
        if (! in_array($result->status, ['verified', 'failed', 'revoked'], true)) {
            throw new DomainException('legal_signature_verification_status_invalid');
        }
        if (! $result->verifiedAt instanceof DateTimeImmutable || $result->certificateMetadata === []) {
            throw new DomainException('legal_signature_certificate_metadata_required');
        }
        if ($result->status === 'revoked' && trim((string) $result->revocationReason) === '') {
            throw new DomainException('legal_signature_revocation_reason_required');
        }
        $replayHash = hash('sha256', $callback->replayToken);
        $payloadHash = CanonicalJson::fingerprint($callback->payload);
        if ($request->callback_replay_hash !== null) {
            if (! hash_equals((string) $request->callback_replay_hash, $replayHash)
                || ! hash_equals((string) $request->callback_payload_hash, $payloadHash)) {
                throw new DomainException('legal_signature_callback_replay_conflict');
            }
            $existing = $this->signatures()->where('signature_request_id', $request->id)->first();
            if (! $existing instanceof LegalDocumentSignature) {
                throw new DomainException('legal_signature_callback_incomplete');
            }

            return $existing;
        }
        $systemActor = new User;
        $systemActor->forceFill(['current_organization_id' => (int) $request->organization_id]);

        return $this->registerOriginal($request, $systemActor, 'provider_electronic', "callback:{$replayHash}", [
            'signed_at' => $result->verifiedAt ?? new DateTimeImmutable('now'),
            'signers' => $result->signerName === null ? (array) $request->signers : [['name' => $result->signerName]],
            'party_id' => $request->party_id,
            'provider' => $result->provider,
            'signature_path' => $result->signaturePath,
            'signature_content_hash' => $result->signatureContentHash,
            'certificate_metadata' => $result->certificateMetadata,
            'provider_metadata' => [...$result->providerMetadata, 'callback_payload_hash' => $payloadHash],
            'storage_location' => null,
            'verification_status' => $result->status,
            'verified_at' => $result->verifiedAt,
            'revocation_reason' => $result->revocationReason,
            'callback_replay_hash' => $replayHash,
            'callback_payload_hash' => $payloadHash,
        ], false);
    }

    public function verify(LegalDocumentSignature $signature, User $actor, string $idempotencyKey): LegalSignatureVerification
    {
        $document = $this->documents()->whereKey($signature->document_id)
            ->where('organization_id', $signature->organization_id)->first();
        if (! $document instanceof LegalArchiveDocument) {
            throw new DomainException('legal_signature_not_found');
        }
        $this->authorizer->authorize($actor, $document, LegalDocumentAbility::VERIFY_SIGNATURE->value);
        $result = $signature->method === 'external_electronic'
            ? new SignatureVerificationResult(
                status: (string) $signature->verification_status,
                provider: (string) $signature->provider,
                signedContentHash: (string) $signature->signed_content_hash,
                signerName: $signature->signer_name,
                verifiedAt: $signature->verified_at?->toDateTimeImmutable(),
                revocationReason: $signature->revocation_reason,
                signaturePath: $signature->signature_path,
                certificateMetadata: (array) $signature->certificate_metadata,
                providerMetadata: (array) $signature->provider_metadata,
            )
            : $this->provider->verify($signature);
        if (! hash_equals((string) $signature->signed_content_hash, $result->signedContentHash)) {
            throw new DomainException('legal_signature_content_hash_mismatch');
        }
        $key = $this->validKey($idempotencyKey);
        $requestHash = CanonicalJson::fingerprint([
            'signature_id' => (int) $signature->id,
            'status' => $result->status,
            'signed_content_hash' => $result->signedContentHash,
            'revocation_reason' => $result->revocationReason,
            'certificate_metadata' => $result->certificateMetadata,
            'provider_metadata' => $result->providerMetadata,
        ]);

        return $this->connection->transaction(function () use ($signature, $actor, $document, $result, $key, $requestHash): LegalSignatureVerification {
            $lockedDocument = $this->aggregateLock->lockDocument($this->connection, (int) $document->organization_id, (int) $document->id);
            $this->authorizer->authorize($actor, $lockedDocument, LegalDocumentAbility::VERIFY_SIGNATURE->value);
            $existing = $this->verifications()->where('signature_id', $signature->id)->where('idempotency_key', $key)->lockForUpdate()->first();
            if ($existing instanceof LegalSignatureVerification) {
                $this->assertSameRequest($existing->request_hash, $requestHash);

                return $existing;
            }
            $verification = $this->newVerification()->newQuery()->create([
                'organization_id' => (int) $signature->organization_id,
                'document_id' => (int) $signature->document_id,
                'document_version_id' => (int) $signature->document_version_id,
                'signature_id' => (int) $signature->id,
                'provider' => $result->provider,
                'status' => $result->status,
                'signed_content_hash' => $result->signedContentHash,
                'certificate_metadata' => $result->certificateMetadata,
                'provider_metadata' => $result->providerMetadata,
                'revocation_reason' => $result->revocationReason,
                'verified_by_user_id' => (int) $actor->id,
                'verified_at' => $result->verifiedAt ?? now(),
                'idempotency_key' => $key,
                'request_hash' => $requestHash,
            ]);
            if ($result->status === 'revoked') {
                $lockedDocument->forceFill(['signature_status' => 'revoked', 'lifecycle_status' => 'signature_failed', 'lock_version' => ((int) $lockedDocument->lock_version) + 1])->save();
            }
            $this->audit->record('signature_verified', $lockedDocument, $actor, [
                'source_event_id' => "signature-verification:{$verification->id}",
                'idempotency_key' => $key,
                'signature_id' => (int) $signature->id,
                'status' => $result->status,
                'signed_content_hash' => $result->signedContentHash,
            ]);

            return $verification;
        }, 3);
    }

    private function registerOriginal(LegalSignatureRequest $request, User $actor, string $method, string $idempotencyKey, array $data, bool $authorize = true): LegalDocumentSignature
    {
        $key = $this->validKey($idempotencyKey);
        $requestHash = CanonicalJson::fingerprint([
            'request_id' => (int) $request->id,
            'method' => $method,
            ...array_diff_key($data, ['callback_replay_hash' => true, 'callback_payload_hash' => true]),
        ]);
        $document = $this->documentForRequest($request);
        if ($authorize) {
            $this->authorizer->authorize($actor, $document, LegalDocumentAbility::SIGN->value);
        }

        return $this->connection->transaction(function () use ($request, $actor, $method, $key, $requestHash, $data, $document, $authorize): LegalDocumentSignature {
            $lockedDocument = $this->aggregateLock->lockDocument($this->connection, (int) $document->organization_id, (int) $document->id);
            $lockedVersion = $this->aggregateLock->lockVersion($this->connection, $lockedDocument, (int) $request->document_version_id);
            if ($authorize) {
                $this->authorizer->authorize($actor, $lockedDocument, LegalDocumentAbility::SIGN->value);
            }
            $lockedRequest = $this->lockRequest($request);
            $existing = $this->signatures()->where('signature_request_id', $lockedRequest->id)->where('idempotency_key', $key)->lockForUpdate()->first();
            if ($existing instanceof LegalDocumentSignature) {
                $this->assertSameRequest($existing->request_hash, $requestHash);

                return $existing;
            }
            if ($lockedRequest->status !== 'pending' || $lockedRequest->method !== $method) {
                throw new DomainException('legal_signature_request_not_pending');
            }
            if ($lockedRequest->expires_at !== null && $lockedRequest->expires_at->isPast()) {
                throw new DomainException('legal_signature_request_expired');
            }
            if ($method !== 'paper' && ! hash_equals((string) $lockedRequest->provider, (string) $data['provider'])) {
                throw new DomainException('legal_signature_provider_mismatch');
            }
            if ($method !== 'paper') {
                $signaturePath = trim((string) $data['signature_path']);
                $containerHash = (string) $data['signature_content_hash'];
                if (! str_starts_with($signaturePath, "org-{$lockedDocument->organization_id}/")
                    || preg_match('/^[a-f0-9]{64}$/D', $containerHash) !== 1
                    || ! $data['verified_at'] instanceof DateTimeImmutable
                    || (array) $data['certificate_metadata'] === []) {
                    throw new DomainException('legal_signature_container_invalid');
                }
            }
            if ((int) $lockedDocument->current_primary_version_id !== (int) $lockedVersion->id
                || ! (bool) $lockedVersion->is_current
                || ! hash_equals((string) $lockedRequest->signed_content_hash, (string) $lockedVersion->content_hash)
                || ! in_array((string) $lockedVersion->status, ['frozen', 'signed'], true)
                || (string) $lockedVersion->processing_status !== 'ready') {
                throw new DomainException('legal_signature_version_changed');
            }
            $this->assertNoActiveWorkflow((int) $lockedDocument->id);
            $partyId = $data['party_id'] ?? $lockedRequest->party_id;
            if ($lockedRequest->party_id !== null && (int) $partyId !== (int) $lockedRequest->party_id) {
                throw new DomainException('legal_signature_party_mismatch');
            }
            if ($method !== 'provider_electronic'
                && ! hash_equals(
                    CanonicalJson::fingerprint($this->signerNames((array) $lockedRequest->signers)),
                    CanonicalJson::fingerprint($this->signerNames((array) $data['signers'])),
                )) {
                throw new DomainException('legal_signature_signers_mismatch');
            }
            if (! $data['signed_at'] instanceof DateTimeImmutable || $data['signed_at'] > new DateTimeImmutable('now')) {
                throw new DomainException('legal_signature_signed_at_invalid');
            }
            if ($data['verified_at'] instanceof DateTimeImmutable && $data['verified_at'] < $data['signed_at']) {
                throw new DomainException('legal_signature_verified_at_invalid');
            }
            $this->assertParty($lockedDocument, $lockedVersion, $partyId === null ? null : (int) $partyId);
            $status = (string) $data['verification_status'];
            $signature = $this->newSignature()->newQuery()->create([
                'organization_id' => (int) $lockedDocument->organization_id,
                'document_id' => (int) $lockedDocument->id,
                'document_version_id' => (int) $lockedVersion->id,
                'signature_request_id' => (int) $lockedRequest->id,
                'party_id' => $partyId,
                'method' => $method,
                'provider' => $data['provider'],
                'signer_name' => $data['signers'][0]['name'] ?? null,
                'signers' => $data['signers'],
                'signed_content_hash' => (string) $lockedVersion->content_hash,
                'signature_path' => $data['signature_path'],
                'signature_content_hash' => $data['signature_content_hash'],
                'certificate_metadata' => $data['certificate_metadata'],
                'provider_metadata' => $data['provider_metadata'],
                'storage_location' => $data['storage_location'],
                'signed_at' => $data['signed_at'],
                'verified_at' => $data['verified_at'],
                'verification_status' => $status,
                'revocation_reason' => $data['revocation_reason'],
                'registered_by_user_id' => $actor->exists ? (int) $actor->id : null,
                'idempotency_key' => $key,
                'request_hash' => $requestHash,
            ]);
            $this->authorizeSignatureMutation();
            LegalSignatureRequest::serviceMutation(function () use ($lockedRequest, $data, $status): void {
                $lockedRequest->forceFill([
                    'status' => in_array($status, ['registered', 'verified'], true) ? 'completed' : ($status === 'revoked' ? 'revoked' : 'failed'),
                    'callback_replay_hash' => $data['callback_replay_hash'] ?? null,
                    'callback_payload_hash' => $data['callback_payload_hash'] ?? null,
                    'completed_at' => now(),
                ])->save();
            });
            if (in_array($status, ['registered', 'verified'], true)) {
                $this->markVersionSigned($lockedVersion);
            }
            $this->projectDocument($lockedDocument, $status);
            $auditContext = [
                'source_event_id' => "signature:{$signature->id}",
                'idempotency_key' => $key,
                'signature_id' => (int) $signature->id,
                'signature_request_id' => (int) $lockedRequest->id,
                'document_version_id' => (int) $lockedVersion->id,
                'signed_content_hash' => (string) $lockedVersion->content_hash,
                'method' => $method,
                'verification_status' => $status,
            ];
            if ($actor->exists) {
                $this->audit->record('signature_registered', $lockedDocument, $actor, $auditContext);
            } else {
                $this->audit->recordForActorId('signature_registered', $lockedDocument, null, $auditContext);
            }

            return $signature;
        }, 3);
    }

    private function assertSigningAllowed(LegalArchiveDocument $document, LegalArchiveDocumentVersion $version): void
    {
        if ($document->approval_status !== 'approved'
            || ! in_array((string) $document->lifecycle_status, ['approved', 'signing', 'partially_signed', 'signature_failed'], true)
            || $document->archived_at !== null || $document->deleted_at !== null) {
            throw new DomainException('legal_signature_lifecycle_invalid');
        }
        if ((int) $document->current_primary_version_id !== (int) $version->id || ! (bool) $version->is_current
            || $version->processing_status !== 'ready' || preg_match('/^[a-f0-9]{64}$/D', (string) $version->content_hash) !== 1) {
            throw new DomainException('legal_signature_version_not_ready');
        }
        $this->assertNoActiveWorkflow((int) $document->id);
    }

    private function assertNoActiveWorkflow(int $documentId): void
    {
        if ($this->connection->getSchemaBuilder()->hasTable('legal_workflow_instances')
            && $this->connection->table('legal_workflow_instances')->where('document_id', $documentId)->where('status', 'in_progress')->exists()) {
            throw new DomainException('legal_signature_active_workflow_exists');
        }
    }

    private function assertRequestReady(
        LegalArchiveDocument $document,
        LegalArchiveDocumentVersion $version,
        LegalSignatureRequest $request,
    ): void {
        if ($request->status !== 'pending') {
            throw new DomainException('legal_signature_request_not_pending');
        }
        if ($request->expires_at !== null && $request->expires_at->isPast()) {
            throw new DomainException('legal_signature_request_expired');
        }
        if ((int) $document->current_primary_version_id !== (int) $version->id
            || ! (bool) $version->is_current
            || $version->processing_status !== 'ready'
            || ! in_array((string) $version->status, ['frozen', 'signed'], true)
            || ! hash_equals((string) $request->signed_content_hash, (string) $version->content_hash)) {
            throw new DomainException('legal_signature_version_changed');
        }
    }

    private function freezeVersion(LegalArchiveDocumentVersion $version): void
    {
        if (in_array((string) $version->status, ['frozen', 'signed'], true)) {
            return;
        }
        $this->authorizeVersionMutation();
        LegalArchiveDocumentVersion::technicalMutation(function () use ($version): void {
            $version->forceFill(['status' => 'frozen'])->save();
        });
    }

    private function markVersionSigned(LegalArchiveDocumentVersion $version): void
    {
        if ($version->status === 'signed') {
            return;
        }
        $this->authorizeVersionMutation();
        LegalArchiveDocumentVersion::technicalMutation(function () use ($version): void {
            $version->forceFill(['status' => 'signed'])->save();
        });
    }

    private function authorizeVersionMutation(): void
    {
        if ($this->connection->getDriverName() === 'pgsql') {
            $this->connection->statement("SET LOCAL most.legal_archive_version_mutation = 'signature_service'");
        }
    }

    private function authorizeSignatureMutation(): void
    {
        if ($this->connection->getDriverName() === 'pgsql') {
            $this->connection->statement("SET LOCAL most.legal_signature_mutation = 'service'");
        }
    }

    private function projectDocument(LegalArchiveDocument $document, string $verificationStatus): void
    {
        if (! in_array($verificationStatus, ['registered', 'verified'], true)) {
            $signatureStatus = $verificationStatus === 'revoked' ? 'revoked' : 'verification_failed';
            $lifecycle = 'signature_failed';
        } else {
            $hasPending = $this->requests()->where('document_id', $document->id)->where('status', 'pending')->exists();
            $signatureStatus = $hasPending ? 'partially_signed' : 'signed';
            $lifecycle = $hasPending ? 'partially_signed' : 'signed';
        }
        $document->forceFill([
            'signature_status' => $signatureStatus,
            'lifecycle_status' => $lifecycle,
            'legal_significance_status' => in_array($verificationStatus, ['registered', 'verified'], true)
                ? ($verificationStatus === 'registered' ? 'paper_original' : 'edo_original')
                : ($document->legal_significance_status ?? 'not_confirmed'),
            'lock_version' => ((int) $document->lock_version) + 1,
        ])->save();
    }

    private function assertParty(LegalArchiveDocument $document, LegalArchiveDocumentVersion $version, ?int $partyId): void
    {
        if ($partyId === null) {
            return;
        }
        if (! $this->connection->table('legal_document_parties')->where('id', $partyId)
            ->where('organization_id', $document->organization_id)->where('document_id', $document->id)
            ->where('document_version_id', $version->id)->exists()) {
            throw new DomainException('legal_signature_party_not_found');
        }
    }

    private function validSigners(array $signers): array
    {
        if ($signers === [] || count($signers) > 50) {
            throw new DomainException('legal_signature_signers_required');
        }
        foreach ($signers as $signer) {
            if (! is_array($signer) || trim((string) ($signer['name'] ?? '')) === '' || mb_strlen((string) $signer['name']) > 255) {
                throw new DomainException('legal_signature_signers_required');
            }
        }

        return array_values($signers);
    }

    private function signerNames(array $signers): array
    {
        return array_map(
            static fn (array $signer): string => mb_strtolower(trim((string) $signer['name'])),
            $signers,
        );
    }

    private function assertSignatureUpload(UploadedFile $upload): void
    {
        $container = Container::getInstance();
        $configuredMax = $container->bound('config')
            ? $container->make('config')->get('legal-document-signatures.external_original.max_bytes', 20 * 1024 * 1024)
            : 20 * 1024 * 1024;
        $configuredExtensions = $container->bound('config')
            ? $container->make('config')->get('legal-document-signatures.external_original.extensions', ['sig', 'p7s', 'p7m', 'xml'])
            : ['sig', 'p7s', 'p7m', 'xml'];
        $maxBytes = max(1, (int) $configuredMax);
        if (! $upload->isValid() || $upload->getSize() < 1 || $upload->getSize() > $maxBytes) {
            throw new DomainException('legal_signature_container_invalid');
        }
        $extension = strtolower($upload->getClientOriginalExtension());
        if (! in_array($extension, (array) $configuredExtensions, true)) {
            throw new DomainException('legal_signature_container_invalid');
        }
    }

    private function cleanupUploadedContainer(int $organizationId, string $path, Organization $organization, Throwable $exception): void
    {
        if ($this->fileService->delete($path, $organization)) {
            return;
        }
        if (! $this->connection->getSchemaBuilder()->hasTable('legal_archive_file_cleanup_debts')) {
            throw new RuntimeException('legal_signature_cleanup_debt_storage_unavailable', previous: $exception);
        }
        $now = now();
        $this->connection->table('legal_archive_file_cleanup_debts')->upsert([[
            'organization_id' => $organizationId,
            'storage_path' => $path,
            'reason' => 'signature_registration_failed',
            'attempts' => 1,
            'next_attempt_at' => $now,
            'last_error' => $exception::class,
            'resolved_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]], ['organization_id', 'storage_path'], ['reason', 'next_attempt_at', 'last_error', 'resolved_at', 'updated_at']);
    }

    private function lockRequest(LegalSignatureRequest $request): LegalSignatureRequest
    {
        $locked = $this->requests()->whereKey($request->id)->where('organization_id', $request->organization_id)
            ->where('document_id', $request->document_id)->where('document_version_id', $request->document_version_id)
            ->lockForUpdate()->first();
        if (! $locked instanceof LegalSignatureRequest) {
            throw new DomainException('legal_signature_request_not_found');
        }

        return $locked;
    }

    private function documentForRequest(LegalSignatureRequest $request): LegalArchiveDocument
    {
        $document = $this->documents()->whereKey($request->document_id)->where('organization_id', $request->organization_id)->first();
        if (! $document instanceof LegalArchiveDocument) {
            throw new DomainException('legal_signature_request_not_found');
        }

        return $document;
    }

    private function assertRequestMethod(LegalSignatureRequest $request, string $method, bool $pending = true): void
    {
        if ($request->method !== $method || ($pending && $request->status !== 'pending')) {
            throw new DomainException('legal_signature_request_not_pending');
        }
    }

    private function validKey(string $key): string
    {
        $key = trim($key);
        if ($key === '' || mb_strlen($key) > 191) {
            throw new DomainException('legal_signature_idempotency_key_invalid');
        }

        return $key;
    }

    private function assertSameRequest(mixed $stored, string $expected): void
    {
        if (! is_string($stored) || ! hash_equals($stored, $expected)) {
            throw new DomainException('legal_signature_idempotency_conflict');
        }
    }

    private function requests(): Builder
    {
        return $this->newRequest()->newQuery();
    }

    private function signatures(): Builder
    {
        return $this->newSignature()->newQuery();
    }

    private function verifications(): Builder
    {
        return $this->newVerification()->newQuery();
    }

    private function documents(): Builder
    {
        return (new LegalArchiveDocument)->setConnection($this->connection->getName())->newQuery();
    }

    private function newRequest(): LegalSignatureRequest
    {
        return (new LegalSignatureRequest)->setConnection($this->connection->getName());
    }

    private function newSignature(): LegalDocumentSignature
    {
        return (new LegalDocumentSignature)->setConnection($this->connection->getName());
    }

    private function newVerification(): LegalSignatureVerification
    {
        return (new LegalSignatureVerification)->setConnection($this->connection->getName());
    }
}
