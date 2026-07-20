<?php

declare(strict_types=1);

namespace App\Services\LegalArchive\Signatures;

use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument;
use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocumentVersion;
use App\BusinessModules\Features\LegalArchive\Models\LegalDocumentSignature;
use App\BusinessModules\Features\LegalArchive\Models\LegalSignatureProviderOperation;
use App\BusinessModules\Features\LegalArchive\Models\LegalSignatureRequest;
use App\BusinessModules\Features\LegalArchive\Models\LegalSignatureVerification;
use App\Models\Organization;
use App\Models\User;
use App\Services\LegalArchive\Access\LegalDocumentAbility;
use App\Services\LegalArchive\Access\LegalDocumentAuthorizer;
use App\Services\LegalArchive\Audit\LegalDocumentAudit;
use App\Services\LegalArchive\CanonicalJson;
use App\Services\LegalArchive\Comments\LegalDocumentBlockingCommentGuard;
use App\Services\LegalArchive\LegalDocumentAggregateLock;
use App\Services\LegalArchive\Profiles\LegalDocumentProfileRegistry;
use App\Services\LegalArchive\Profiles\LegalDocumentProfileValidator;
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
    private readonly LegalSignatureSigningGuard $signingGuard;

    private readonly LegalSignatureProjection $projection;

    public function __construct(
        private readonly ElectronicSignatureProvider $provider,
        private readonly LegalDocumentAuthorizer $authorizer,
        private readonly LegalDocumentAudit $audit,
        private readonly FileService $fileService,
        private readonly ConnectionInterface $connection,
        private readonly LegalDocumentAggregateLock $aggregateLock = new LegalDocumentAggregateLock,
        ?LegalSignatureSigningGuard $signingGuard = null,
        ?LegalSignatureProjection $projection = null,
    ) {
        $this->signingGuard = $signingGuard ?? new LegalSignatureSigningGuard(
            new LegalDocumentProfileRegistry,
            new LegalDocumentProfileValidator,
            new LegalDocumentBlockingCommentGuard,
            $this->connection,
        );
        $this->projection = $projection ?? new LegalSignatureProjection($this->connection);
    }

    public function createRequest(
        LegalArchiveDocument $document,
        LegalArchiveDocumentVersion $version,
        User $actor,
        string $method,
        SignerIdentitySet $signers,
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
        $signerSnapshot = $signers->snapshot();
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
            'signers' => $signerSnapshot,
            'signer_snapshot_hash' => $signers->hash(),
            'expires_at' => $expiresAt?->format(DATE_ATOM),
        ]);

        return $this->connection->transaction(function () use ($document, $version, $actor, $method, $provider, $partyId, $signers, $signerSnapshot, $key, $requestHash, $expiresAt): LegalSignatureRequest {
            $lockedDocument = $this->aggregateLock->lockDocument($this->connection, (int) $document->organization_id, (int) $document->id);
            $lockedVersion = $this->aggregateLock->lockVersion($this->connection, $lockedDocument, (int) $version->id);
            $this->authorizer->authorize($actor, $lockedDocument, LegalDocumentAbility::REQUEST_SIGNATURE->value);
            $existing = $this->requests()->where('organization_id', $lockedDocument->organization_id)
                ->where('requested_by_user_id', $actor->id)->where('idempotency_key', $key)->lockForUpdate()->first();
            if ($existing instanceof LegalSignatureRequest) {
                $existingRequirements = [
                    'profile_code' => (string) $existing->profile_code,
                    'profile_lock_version' => (int) $existing->profile_lock_version,
                    'allowed_signature_kinds' => (array) $existing->allowed_signature_kinds,
                    'required_signature_kinds' => (array) $existing->required_signature_kinds,
                ];
                $this->assertSameRequest($existing->request_hash, CanonicalJson::fingerprint([
                    'client_request_hash' => $requestHash,
                    'requirements' => $existingRequirements,
                ]));

                return $existing;
            }
            $requirements = $this->signingGuard->assertRequestAllowed($lockedDocument, $lockedVersion, $method);
            $requirementSnapshotHash = CanonicalJson::fingerprint($requirements);
            $effectiveRequestHash = CanonicalJson::fingerprint([
                'client_request_hash' => $requestHash,
                'requirements' => $requirements,
            ]);
            $this->assertParty($lockedDocument, $lockedVersion, $partyId);
            $request = $this->newRequest()->newQuery()->create([
                'organization_id' => (int) $lockedDocument->organization_id,
                'document_id' => (int) $lockedDocument->id,
                'document_version_id' => (int) $lockedVersion->id,
                'party_id' => $partyId,
                'method' => $method,
                'provider' => $provider,
                'status' => 'pending',
                'signed_content_hash' => (string) $lockedVersion->content_hash,
                'signers' => $signerSnapshot,
                'signer_snapshot_hash' => $signers->hash(),
                'profile_code' => $requirements['profile_code'],
                'profile_lock_version' => $requirements['profile_lock_version'],
                'allowed_signature_kinds' => $requirements['allowed_signature_kinds'],
                'required_signature_kinds' => $requirements['required_signature_kinds'],
                'requirement_snapshot_hash' => $requirementSnapshotHash,
                'correlation_id' => hash('sha256', Str::uuid()->toString().Str::random(64)),
                'idempotency_key' => $key,
                'request_hash' => $effectiveRequestHash,
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
            'signers' => $data->signers->snapshot(),
            'party_id' => $data->partyId,
            'provider' => null,
            'signature_path' => null,
            'signature_content_hash' => null,
            'certificate_metadata' => [],
            'provider_metadata' => [
                'party_role_snapshot' => $data->partyRoleSnapshot,
                'authority_confirmed' => $data->authorityConfirmed,
                'time_source' => $data->timeSource,
                'client_ip_hash' => $data->clientIpHash,
                'user_agent_hash' => $data->userAgentHash,
            ],
            'storage_location' => trim($data->storageLocation),
            'verification_status' => 'registered',
            'verified_at' => null,
            'revocation_reason' => null,
            'signature_kind' => 'paper_original',
            'container_format' => null,
            'signer_snapshot_hash' => $data->signers->hash(),
            'signer_user_id' => $data->signers->primary()->userId,
            'signer_organization_id' => $data->signers->primary()->organizationId,
            'party_role_snapshot' => $data->partyRoleSnapshot ?? $data->signers->primary()->partyRole,
            'certificate_fingerprint' => null,
            'certificate_serial' => null,
            'certificate_issuer' => null,
            'certificate_valid_from' => null,
            'certificate_valid_until' => null,
            'authority_confirmed' => $data->authorityConfirmed,
            'time_source' => $data->timeSource,
            'diagnostic_code' => 'paper_original_registered',
            'signing_session_id' => null,
            'client_ip_hash' => $data->clientIpHash,
            'user_agent_hash' => $data->userAgentHash,
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
        if (trim($metadata->provider) === '') {
            throw new DomainException('legal_signature_certificate_metadata_required');
        }
        if ($metadata->verificationStatus === 'revoked' && trim((string) $metadata->revocationReason) === '') {
            throw new DomainException('legal_signature_revocation_reason_required');
        }
        $content = $upload->getContent();
        if (! is_string($content) || $content === '') {
            throw new DomainException('legal_signature_container_invalid');
        }
        $detectedMimeType = (new \finfo(FILEINFO_MIME_TYPE))->buffer($content);
        if (! is_string($detectedMimeType) || $detectedMimeType === '') {
            throw new DomainException('legal_signature_container_invalid');
        }
        $artifact = new SignatureArtifact($content, $upload->getClientOriginalName(), $detectedMimeType);
        $existing = $this->signatures()->where('signature_request_id', $request->id)
            ->where('idempotency_key', $this->validKey($metadata->idempotencyKey))->first();
        if ($existing instanceof LegalDocumentSignature) {
            if (! hash_equals((string) $existing->signature_content_hash, $artifact->sha256())
                || ! hash_equals((string) $existing->provider, trim($metadata->provider))
                || ! hash_equals((string) $existing->signer_snapshot_hash, $metadata->evidence->signers->hash())
                || (string) $existing->verification_status !== $metadata->verificationStatus
                || (string) $existing->certificate_fingerprint !== $metadata->evidence->certificateFingerprint
                || $existing->signed_at?->toAtomString() !== $metadata->evidence->signedAt->format(DATE_ATOM)
                || $existing->verified_at?->toAtomString() !== $metadata->evidence->verifiedAt->format(DATE_ATOM)) {
                throw new DomainException('legal_signature_idempotency_conflict');
            }

            return $existing;
        }
        $organization = new Organization;
        $organization->forceFill(['id' => (int) $request->organization_id]);
        $extension = strtolower($upload->getClientOriginalExtension());
        $this->assertDetectedSignatureMime($extension, $detectedMimeType);
        $storedPath = "org-{$request->organization_id}/legal-archive/signatures/requests/{$request->id}/{$artifact->sha256()}.{$extension}";
        $stored = $this->fileService->putImmutable(
            $storedPath,
            $artifact->content,
            $detectedMimeType,
        );
        if (! hash_equals($artifact->sha256(), (string) $stored['sha256'])
            || (string) $stored['version_id'] === ''
            || (string) $stored['body'] !== $artifact->content) {
            throw new DomainException('legal_signature_container_invalid');
        }
        try {
            return $this->registerOriginal($request, $actor, 'external_electronic', $metadata->idempotencyKey, [
                'signed_at' => $metadata->evidence->signedAt,
                'signers' => $metadata->evidence->signers->snapshot(),
                'party_id' => $metadata->partyId,
                'provider' => trim($metadata->provider),
                'signature_path' => $storedPath,
                'signature_content_hash' => $artifact->sha256(),
                'storage_version_id' => (string) $stored['version_id'],
                'storage_etag' => is_string($stored['etag']) ? $stored['etag'] : null,
                'detected_mime_type' => $detectedMimeType,
                'certificate_metadata' => $metadata->evidence->certificateMetadata(),
                'provider_metadata' => $metadata->providerMetadata,
                'storage_location' => null,
                'verification_status' => $metadata->verificationStatus,
                'verified_at' => $metadata->evidence->verifiedAt,
                'revocation_reason' => $metadata->revocationReason,
                ...$this->evidenceColumns($metadata->evidence),
            ]);
        } catch (Throwable $exception) {
            if ((bool) $stored['created']) {
                $this->cleanupUploadedContainer((int) $request->organization_id, $storedPath, $organization, $exception);
            }
            throw $exception;
        }
    }

    public function startElectronicSession(LegalSignatureRequest $request, User $actor): SignatureSession
    {
        $document = $this->documentForRequest($request);
        $this->authorizer->authorize($actor, $document, LegalDocumentAbility::SIGN->value);
        $this->assertRequestMethod($request, 'provider_electronic');
        $callbackUrl = $this->configuredCallbackUrl();
        $leaseToken = Str::random(64);
        $reservation = $this->connection->transaction(function () use ($request, $document, $actor, $leaseToken): array {
            $lockedDocument = $this->aggregateLock->lockDocument($this->connection, (int) $document->organization_id, (int) $document->id);
            $version = $this->aggregateLock->lockVersion($this->connection, $lockedDocument, (int) $request->document_version_id);
            $this->authorizer->authorize($actor, $lockedDocument, LegalDocumentAbility::SIGN->value);
            $lockedRequest = $this->lockRequest($request);
            $this->signingGuard->assertCompletionAllowed($lockedDocument, $version, $lockedRequest);
            $operation = $this->providerOperations()->where('signature_request_id', $lockedRequest->id)->lockForUpdate()->first();
            if ($operation instanceof LegalSignatureProviderOperation && $operation->status === 'started') {
                return ['session' => $this->sessionFromOperation($operation)];
            }
            if ($operation instanceof LegalSignatureProviderOperation
                && $operation->status === 'starting'
                && $operation->lease_expires_at !== null
                && $operation->lease_expires_at->isFuture()) {
                throw new DomainException('legal_signature_provider_start_in_progress');
            }
            if (! $operation instanceof LegalSignatureProviderOperation) {
                $operation = $this->newProviderOperation()->newQuery()->create([
                    'id' => (string) Str::uuid(),
                    'organization_id' => (int) $lockedRequest->organization_id,
                    'document_id' => (int) $lockedRequest->document_id,
                    'document_version_id' => (int) $lockedRequest->document_version_id,
                    'signature_request_id' => (int) $lockedRequest->id,
                    'provider' => (string) $lockedRequest->provider,
                    'status' => 'starting',
                    'correlation_id' => (string) $lockedRequest->correlation_id,
                    'provider_idempotency_key' => hash('sha256', "signature-start:{$lockedRequest->organization_id}:{$lockedRequest->id}"),
                    'lease_token_hash' => hash('sha256', $leaseToken),
                    'lease_expires_at' => now()->addSeconds($this->startLeaseSeconds()),
                    'attempt_count' => 1,
                    'started_at' => now(),
                ]);
            } else {
                LegalSignatureProviderOperation::serviceMutation(function () use ($operation, $leaseToken): void {
                    $operation->forceFill([
                        'status' => 'starting',
                        'lease_token_hash' => hash('sha256', $leaseToken),
                        'lease_expires_at' => now()->addSeconds($this->startLeaseSeconds()),
                        'attempt_count' => ((int) $operation->attempt_count) + 1,
                        'last_error_code' => null,
                        'started_at' => now(),
                    ])->save();
                });
            }

            return ['request' => $lockedRequest, 'operation' => $operation];
        }, 3);
        if (($reservation['session'] ?? null) instanceof SignatureSession) {
            return $reservation['session'];
        }
        $request = $reservation['request'];
        $operation = $reservation['operation'];
        try {
            $session = $this->provider->start(new SignatureContext(
                (int) $request->organization_id,
                (int) $request->document_id,
                (int) $request->document_version_id,
                (string) $request->signed_content_hash,
                (string) $request->correlation_id,
                $callbackUrl,
                SignerIdentitySet::fromSnapshot((array) $request->signers),
                (string) $operation->id,
                (string) $operation->provider_idempotency_key,
            ));
            $this->assertProviderSession($request, $session);
        } catch (Throwable $exception) {
            $this->failProviderStart((string) $operation->id, $leaseToken, $exception);
            throw $exception;
        }

        return $this->connection->transaction(function () use ($request, $session, $document, $actor, $operation, $leaseToken): SignatureSession {
            $lockedDocument = $this->aggregateLock->lockDocument($this->connection, (int) $document->organization_id, (int) $document->id);
            $version = $this->aggregateLock->lockVersion($this->connection, $lockedDocument, (int) $request->document_version_id);
            $this->authorizer->authorize($actor, $lockedDocument, LegalDocumentAbility::SIGN->value);
            $lockedRequest = $this->lockRequest($request);
            $this->signingGuard->assertCompletionAllowed($lockedDocument, $version, $lockedRequest);
            $lockedOperation = $this->providerOperations()->whereKey($operation->id)->lockForUpdate()->first();
            if (! $lockedOperation instanceof LegalSignatureProviderOperation
                || $lockedOperation->status !== 'starting'
                || ! hash_equals((string) $lockedOperation->lease_token_hash, hash('sha256', $leaseToken))) {
                throw new DomainException('legal_signature_provider_lease_lost');
            }
            $this->authorizeSignatureMutation();
            LegalSignatureRequest::serviceMutation(function () use ($lockedRequest, $session): void {
                $lockedRequest->forceFill([
                    'provider_request_id' => $session->providerRequestId,
                    'session_metadata' => [...$session->metadata, 'redirect_url' => $session->redirectUrl, 'expires_at' => $session->expiresAt],
                ])->save();
            });
            LegalSignatureProviderOperation::serviceMutation(function () use ($lockedOperation, $session): void {
                $lockedOperation->forceFill([
                    'status' => 'started',
                    'provider_request_id' => $session->providerRequestId,
                    'redirect_url' => $session->redirectUrl,
                    'session_expires_at' => new DateTimeImmutable((string) $session->expiresAt),
                    'session_metadata' => $session->metadata,
                    'lease_token_hash' => null,
                    'lease_expires_at' => null,
                    'completed_at' => now(),
                ])->save();
            });

            return $session;
        }, 3);
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
        $result = $this->provider->complete($callback);
        $expectedSigners = SignerIdentitySet::fromSnapshot((array) $request->signers);
        if (! $result->callbackAuthentic
            || ! hash_equals((string) $request->signed_content_hash, $result->signedContentHash)
            || ! hash_equals((string) $request->provider, $result->provider)
            || ! hash_equals((string) $request->provider_request_id, $result->providerRequestId)
            || ! hash_equals((string) $request->correlation_id, $result->correlationId)
            || ! $expectedSigners->equals($result->signers)) {
            throw new DomainException('legal_signature_callback_invalid');
        }
        if (! in_array($result->status, ['verified', 'failed', 'revoked'], true)) {
            throw new DomainException('legal_signature_verification_status_invalid');
        }
        if ($result->status === 'revoked' && trim((string) $result->revocationReason) === '') {
            throw new DomainException('legal_signature_revocation_reason_required');
        }
        $detectedMimeType = (new \finfo(FILEINFO_MIME_TYPE))->buffer($result->artifact->content);
        if (! is_string($detectedMimeType) || $detectedMimeType === '') {
            throw new DomainException('legal_signature_container_invalid');
        }
        $this->assertDetectedSignatureMime($result->evidence->containerFormat, $detectedMimeType);
        $storedPath = "org-{$request->organization_id}/legal-archive/signatures/requests/{$request->id}/{$result->artifact->sha256()}.{$result->evidence->containerFormat}";
        $stored = $this->fileService->putImmutable($storedPath, $result->artifact->content, $detectedMimeType);
        if (! hash_equals($result->artifact->sha256(), (string) $stored['sha256'])
            || (string) $stored['version_id'] === '' || (string) $stored['body'] !== $result->artifact->content) {
            throw new DomainException('legal_signature_container_invalid');
        }
        $systemActor = new User;
        $systemActor->forceFill(['current_organization_id' => (int) $request->organization_id]);

        try {
            return $this->registerOriginal($request, $systemActor, 'provider_electronic', "callback:{$replayHash}", [
                'signed_at' => $result->evidence->signedAt,
                'signers' => $result->signers->snapshot(),
                'party_id' => $request->party_id,
                'provider' => $result->provider,
                'signature_path' => $storedPath,
                'signature_content_hash' => $result->artifact->sha256(),
                'storage_version_id' => (string) $stored['version_id'],
                'storage_etag' => is_string($stored['etag']) ? $stored['etag'] : null,
                'detected_mime_type' => $detectedMimeType,
                'certificate_metadata' => $result->evidence->certificateMetadata(),
                'provider_metadata' => [...$result->providerMetadata, 'callback_payload_hash' => $payloadHash],
                'storage_location' => null,
                'verification_status' => $result->status,
                'verified_at' => $result->evidence->verifiedAt,
                'revocation_reason' => $result->revocationReason,
                'callback_replay_hash' => $replayHash,
                'callback_payload_hash' => $payloadHash,
                ...$this->evidenceColumns($result->evidence),
            ], false);
        } catch (Throwable $exception) {
            if ((bool) $stored['created']) {
                $organization = new Organization;
                $organization->forceFill(['id' => (int) $request->organization_id]);
                $this->cleanupUploadedContainer((int) $request->organization_id, $storedPath, $organization, $exception);
            }
            throw $exception;
        }
    }

    public function verify(LegalDocumentSignature $signature, User $actor, string $idempotencyKey): LegalSignatureVerification
    {
        $document = $this->documents()->whereKey($signature->document_id)
            ->where('organization_id', $signature->organization_id)->first();
        if (! $document instanceof LegalArchiveDocument) {
            throw new DomainException('legal_signature_not_found');
        }
        $this->authorizer->authorize($actor, $document, LegalDocumentAbility::VERIFY_SIGNATURE->value);
        if ($signature->method === 'paper') {
            throw new DomainException('legal_signature_electronic_verification_required');
        }
        $key = $this->validKey($idempotencyKey);
        $existing = $this->verifications()->where('signature_id', $signature->id)
            ->where('idempotency_key', $key)->first();
        if ($existing instanceof LegalSignatureVerification) {
            return $existing;
        }
        $signature = $this->signatures()->whereKey($signature->id)
            ->where('organization_id', $signature->organization_id)
            ->where('document_id', $signature->document_id)->first();
        if (! $signature instanceof LegalDocumentSignature || trim((string) $signature->signature_path) === ''
            || trim((string) $signature->storage_version_id) === '') {
            throw new DomainException('legal_signature_container_invalid');
        }
        $request = $this->requests()->whereKey($signature->signature_request_id)->first();
        if (! $request instanceof LegalSignatureRequest) {
            throw new DomainException('legal_signature_request_not_found');
        }
        $descriptor = $this->fileService->describeVersion(
            (string) $signature->signature_path,
            (string) $signature->storage_version_id,
            20 * 1024 * 1024,
        );
        $artifact = new SignatureArtifact(
            (string) $descriptor['body'],
            basename((string) $signature->signature_path),
            (string) $descriptor['content_type'],
        );
        if (! hash_equals((string) $signature->signature_content_hash, $artifact->sha256())) {
            throw new DomainException('legal_signature_content_hash_mismatch');
        }
        $result = $this->provider->verify(new SignatureVerificationContext(
            $signature,
            $artifact,
            (string) $descriptor['version_id'],
            is_string($descriptor['etag']) ? $descriptor['etag'] : null,
        ));
        $expectedSigners = SignerIdentitySet::fromSnapshot((array) $signature->signers);
        if (! hash_equals((string) $signature->signed_content_hash, $result->signedContentHash)
            || ! hash_equals((string) $signature->provider, $result->provider)
            || ! hash_equals((string) $request->provider_request_id, $result->providerRequestId)
            || ! hash_equals((string) $request->correlation_id, $result->correlationId)
            || ! $expectedSigners->equals($result->signers)
            || ! hash_equals($artifact->sha256(), $result->artifact->sha256())) {
            throw new DomainException('legal_signature_verification_result_invalid');
        }
        $requestHash = CanonicalJson::fingerprint([
            'signature_id' => (int) $signature->id,
            'status' => $result->status,
            'signed_content_hash' => $result->signedContentHash,
            'revocation_reason' => $result->revocationReason,
            'certificate_metadata' => $result->evidence->certificateMetadata(),
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
                'certificate_metadata' => $result->evidence->certificateMetadata(),
                'provider_metadata' => $result->providerMetadata,
                'revocation_reason' => $result->revocationReason,
                'verified_by_user_id' => (int) $actor->id,
                'verified_at' => $result->evidence->verifiedAt,
                'idempotency_key' => $key,
                'request_hash' => $requestHash,
            ]);
            $this->projection->apply($lockedDocument);
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
            $this->signingGuard->assertCompletionAllowed($lockedDocument, $lockedVersion, $lockedRequest);
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
                'storage_version_id' => $data['storage_version_id'] ?? null,
                'storage_etag' => $data['storage_etag'] ?? null,
                'detected_mime_type' => $data['detected_mime_type'] ?? null,
                'certificate_metadata' => $data['certificate_metadata'],
                'provider_metadata' => $data['provider_metadata'],
                'storage_location' => $data['storage_location'],
                'signed_at' => $data['signed_at'],
                'verified_at' => $data['verified_at'],
                'verification_status' => $status,
                'signature_kind' => $data['signature_kind'],
                'container_format' => $data['container_format'],
                'signer_snapshot_hash' => $data['signer_snapshot_hash'],
                'signer_user_id' => $data['signer_user_id'],
                'signer_organization_id' => $data['signer_organization_id'],
                'party_role_snapshot' => $data['party_role_snapshot'],
                'certificate_fingerprint' => $data['certificate_fingerprint'],
                'certificate_serial' => $data['certificate_serial'],
                'certificate_issuer' => $data['certificate_issuer'],
                'certificate_valid_from' => $data['certificate_valid_from'],
                'certificate_valid_until' => $data['certificate_valid_until'],
                'authority_confirmed' => $data['authority_confirmed'],
                'time_source' => $data['time_source'],
                'diagnostic_code' => $data['diagnostic_code'],
                'signing_session_id' => $data['signing_session_id'],
                'client_ip_hash' => $data['client_ip_hash'],
                'user_agent_hash' => $data['user_agent_hash'],
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
            $this->projection->apply($lockedDocument);
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

    private function assertNoActiveWorkflow(int $documentId): void
    {
        if ($this->connection->getSchemaBuilder()->hasTable('legal_workflow_instances')
            && $this->connection->table('legal_workflow_instances')->where('document_id', $documentId)->where('status', 'in_progress')->exists()) {
            throw new DomainException('legal_signature_active_workflow_exists');
        }
    }

    private function authorizeSignatureMutation(): void
    {
        if ($this->connection->getDriverName() === 'pgsql') {
            $this->connection->statement("SET LOCAL most.legal_signature_mutation = 'service'");
        }
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

    private function signerNames(array $signers): array
    {
        return array_map(
            static fn (array $signer): string => mb_strtolower(trim((string) $signer['name'])),
            $signers,
        );
    }

    private function evidenceColumns(ElectronicSignatureEvidence $evidence): array
    {
        $primary = $evidence->signers->primary();

        return [
            'signature_kind' => $evidence->signatureKind,
            'container_format' => $evidence->containerFormat,
            'signer_snapshot_hash' => $evidence->signers->hash(),
            'signer_user_id' => $primary->userId,
            'signer_organization_id' => $primary->organizationId,
            'party_role_snapshot' => $evidence->partyRoleSnapshot ?? $primary->partyRole,
            'certificate_fingerprint' => $evidence->certificateFingerprint,
            'certificate_serial' => $evidence->certificateSerial,
            'certificate_issuer' => $evidence->certificateIssuer,
            'certificate_valid_from' => $evidence->certificateValidFrom,
            'certificate_valid_until' => $evidence->certificateValidUntil,
            'authority_confirmed' => $evidence->authorityConfirmed,
            'time_source' => $evidence->timeSource,
            'diagnostic_code' => $evidence->diagnosticCode,
            'signing_session_id' => $evidence->signingSessionId,
            'client_ip_hash' => $evidence->clientIpHash,
            'user_agent_hash' => $evidence->userAgentHash,
        ];
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

    private function assertDetectedSignatureMime(string $format, string $detectedMimeType): void
    {
        $allowed = match ($format) {
            'p7s', 'sig' => ['application/octet-stream', 'application/pkcs7-signature', 'application/x-pkcs7-signature'],
            'p7m' => ['application/octet-stream', 'application/pkcs7-mime', 'application/x-pkcs7-mime'],
            'xml' => ['application/xml', 'text/xml'],
            default => [],
        };
        if (! in_array(mb_strtolower($detectedMimeType), $allowed, true)) {
            throw new DomainException('legal_signature_container_mime_invalid');
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

    private function configuredCallbackUrl(): string
    {
        $container = Container::getInstance();
        $url = $container->bound('config')
            ? (string) $container->make('config')->get('legal-document-signatures.callback_url', '')
            : 'https://most.test/callback';
        if (filter_var($url, FILTER_VALIDATE_URL) === false || parse_url($url, PHP_URL_SCHEME) !== 'https') {
            throw new DomainException('legal_signature_callback_url_invalid');
        }

        return $url;
    }

    private function startLeaseSeconds(): int
    {
        $container = Container::getInstance();
        $configured = $container->bound('config')
            ? (int) $container->make('config')->get('legal-document-signatures.start_lease_seconds', 90)
            : 90;

        return max(30, min($configured, 300));
    }

    private function assertProviderSession(LegalSignatureRequest $request, SignatureSession $session): void
    {
        if (! hash_equals((string) $request->correlation_id, $session->correlationId)
            || ! hash_equals((string) $request->provider, $session->provider)
            || trim($session->providerRequestId) === ''
            || filter_var($session->redirectUrl, FILTER_VALIDATE_URL) === false
            || parse_url($session->redirectUrl, PHP_URL_SCHEME) !== 'https'
            || $session->expiresAt === null) {
            throw new DomainException('legal_signature_provider_session_invalid');
        }
        try {
            $expiresAt = new DateTimeImmutable($session->expiresAt);
        } catch (Throwable) {
            throw new DomainException('legal_signature_provider_session_invalid');
        }
        $container = Container::getInstance();
        $maxSeconds = $container->bound('config')
            ? (int) $container->make('config')->get('legal-document-signatures.max_session_seconds', 900)
            : 900;
        $hosts = $container->bound('config')
            ? (array) $container->make('config')->get('legal-document-signatures.redirect_hosts', [])
            : ['fixed.test'];
        $redirectHost = mb_strtolower((string) parse_url($session->redirectUrl, PHP_URL_HOST));
        $now = new DateTimeImmutable('now');
        if ($expiresAt <= $now || $expiresAt > $now->modify('+'.max(60, min($maxSeconds, 3600)).' seconds')
            || $hosts === [] || ! in_array($redirectHost, array_map('mb_strtolower', $hosts), true)) {
            throw new DomainException('legal_signature_provider_session_invalid');
        }
    }

    private function sessionFromOperation(LegalSignatureProviderOperation $operation): SignatureSession
    {
        if ($operation->session_expires_at === null || $operation->session_expires_at->isPast()
            || trim((string) $operation->provider_request_id) === '' || trim((string) $operation->redirect_url) === '') {
            throw new DomainException('legal_signature_provider_session_expired');
        }

        return new SignatureSession(
            (string) $operation->provider,
            (string) $operation->provider_request_id,
            (string) $operation->correlation_id,
            (string) $operation->redirect_url,
            $operation->session_expires_at->toAtomString(),
            (array) $operation->session_metadata,
        );
    }

    private function failProviderStart(string $operationId, string $leaseToken, Throwable $exception): void
    {
        $this->connection->transaction(function () use ($operationId, $leaseToken, $exception): void {
            $operation = $this->providerOperations()->whereKey($operationId)->lockForUpdate()->first();
            if (! $operation instanceof LegalSignatureProviderOperation
                || $operation->status !== 'starting'
                || ! hash_equals((string) $operation->lease_token_hash, hash('sha256', $leaseToken))) {
                return;
            }
            LegalSignatureProviderOperation::serviceMutation(static function () use ($operation, $exception): void {
                $operation->forceFill([
                    'status' => 'failed',
                    'lease_token_hash' => null,
                    'lease_expires_at' => null,
                    'last_error_code' => mb_substr($exception::class, 0, 128),
                    'completed_at' => now(),
                ])->save();
            });
        }, 3);
    }

    private function requests(): Builder
    {
        return $this->newRequest()->newQuery();
    }

    private function signatures(): Builder
    {
        return $this->newSignature()->newQuery();
    }

    private function providerOperations(): Builder
    {
        return $this->newProviderOperation()->newQuery();
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

    private function newProviderOperation(): LegalSignatureProviderOperation
    {
        return (new LegalSignatureProviderOperation)->setConnection($this->connection->getName());
    }
}
