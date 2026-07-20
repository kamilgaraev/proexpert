<?php

declare(strict_types=1);

namespace App\Services\LegalArchive\Signatures;

use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument;
use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocumentVersion;
use App\BusinessModules\Features\LegalArchive\Models\LegalDocumentSignature;
use App\BusinessModules\Features\LegalArchive\Models\LegalSignatureProviderOperation;
use App\BusinessModules\Features\LegalArchive\Models\LegalSignatureRequest;
use App\BusinessModules\Features\LegalArchive\Models\LegalSignatureVerification;
use App\Models\User;
use App\Services\LegalArchive\Access\LegalDocumentAbility;
use App\Services\LegalArchive\Access\LegalDocumentAuthorizer;
use App\Services\LegalArchive\Audit\LegalDocumentAudit;
use App\Services\LegalArchive\CanonicalJson;
use App\Services\LegalArchive\Comments\LegalDocumentBlockingCommentGuard;
use App\Services\LegalArchive\Editor\LegalDocumentEditGuard;
use App\Services\LegalArchive\Files\LegalCleanupDebtKey;
use App\Services\LegalArchive\LegalArchiveLockConflict;
use App\Services\LegalArchive\LegalDocumentAggregateLock;
use App\Services\LegalArchive\LegalDocumentNotificationPublisher;
use App\Notifications\LegalArchive\LegalDocumentSignatureRequiredNotification;
use App\Services\LegalArchive\Profiles\LegalDocumentProfileRegistry;
use App\Services\LegalArchive\Profiles\LegalDocumentProfileValidator;
use App\Services\Storage\FileService;
use DateTimeImmutable;
use DomainException;
use Illuminate\Container\Container;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
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
        private readonly ?LegalDocumentNotificationPublisher $notifications = null,
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
        ?int $replacesRequestId = null,
        ?int $expectedDocumentLockVersion = null,
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
            'replaces_request_id' => $replacesRequestId,
        ]);

        $request = $this->connection->transaction(function () use ($document, $version, $actor, $method, $provider, $partyId, $signers, $signerSnapshot, $key, $requestHash, $expiresAt, $replacesRequestId, $expectedDocumentLockVersion): LegalSignatureRequest {
            $lockedDocument = $this->aggregateLock->lockDocument($this->connection, (int) $document->organization_id, (int) $document->id);
            $existing = $this->requests()->where('organization_id', $lockedDocument->organization_id)
                ->where('requested_by_user_id', $actor->id)->where('idempotency_key', $key)->lockForUpdate()->first();
            if ($existing instanceof LegalSignatureRequest) {
                $existingRequirements = [
                    'profile_code' => (string) $existing->profile_code,
                    'profile_lock_version' => (int) $existing->profile_lock_version,
                    'allowed_signature_kinds' => (array) $existing->allowed_signature_kinds,
                    'required_signature_kinds' => (array) $existing->required_signature_kinds,
                    'allowed_signature_formats' => (array) $existing->allowed_signature_formats,
                ];
                $this->assertSameRequest($existing->request_hash, CanonicalJson::fingerprint([
                    'client_request_hash' => $requestHash,
                    'requirements' => $existingRequirements,
                ]));

                return $existing;
            }
            if ($expectedDocumentLockVersion !== null && (int) $lockedDocument->lock_version !== $expectedDocumentLockVersion) {
                throw LegalArchiveLockConflict::forDocument((int) $lockedDocument->id, (int) $lockedDocument->lock_version);
            }
            $lockedVersion = $this->aggregateLock->lockVersion($this->connection, $lockedDocument, (int) $version->id);
            (new LegalDocumentEditGuard($this->connection))->assertSignatureAllowed($lockedDocument);
            $this->authorizer->authorize($actor, $lockedDocument, LegalDocumentAbility::REQUEST_SIGNATURE->value);
            $requirements = $this->signingGuard->assertRequestAllowed($lockedDocument, $lockedVersion, $method);
            $requirementSnapshotHash = CanonicalJson::fingerprint($requirements);
            $effectiveRequestHash = CanonicalJson::fingerprint([
                'client_request_hash' => $requestHash,
                'requirements' => $requirements,
            ]);
            $requirementGroupKey = CanonicalJson::fingerprint([
                'organization_id' => (int) $lockedDocument->organization_id,
                'document_id' => (int) $lockedDocument->id,
                'document_version_id' => (int) $lockedVersion->id,
                'party_id' => $partyId,
                'method' => $method,
                'provider' => $provider,
                'signer_snapshot_hash' => $signers->hash(),
                'requirement_snapshot_hash' => $requirementSnapshotHash,
            ]);
            $latestAttempt = $this->requests()->where('organization_id', $lockedDocument->organization_id)
                ->where('document_id', $lockedDocument->id)->where('document_version_id', $lockedVersion->id)
                ->where('requirement_group_key', $requirementGroupKey)->orderByDesc('id')->lockForUpdate()->first();
            if ($replacesRequestId === null && $latestAttempt instanceof LegalSignatureRequest) {
                throw new DomainException('legal_signature_replacement_required');
            }
            if ($replacesRequestId !== null && (! $latestAttempt instanceof LegalSignatureRequest
                || (int) $latestAttempt->id !== $replacesRequestId
                || ! $this->isReplaceableAttempt($latestAttempt))) {
                throw new DomainException('legal_signature_replacement_invalid');
            }
            $this->freezeVersion($lockedVersion);
            $this->assertParty($lockedDocument, $lockedVersion, $partyId);
            $this->assertSignerIdentities($lockedDocument, $lockedVersion, $signers);
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
                'allowed_signature_formats' => $requirements['allowed_signature_formats'],
                'requirement_snapshot_hash' => $requirementSnapshotHash,
                'requirement_group_key' => $requirementGroupKey,
                'replaces_request_id' => $replacesRequestId,
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
                'replaces_request_id' => $replacesRequestId,
            ]);

            return $request;
        }, 3);
        foreach ($signers->identities as $signer) {
            if ($signer->userId !== null && ($recipient = User::query()->find($signer->userId)) instanceof User) {
                ($this->notifications ?? new LegalDocumentNotificationPublisher)->publish($document, $recipient, 'signature-request:'.$request->id.':'.$recipient->id, new LegalDocumentSignatureRequiredNotification($document));
            }
        }
        return $request;
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
            'expected_document_lock_version' => $data->expectedDocumentLockVersion,
        ]);
    }

    public function preflightPaperOriginalUpload(
        LegalSignatureRequest $request,
        User $actor,
        PaperOriginalData $data,
    ): bool {
        if (trim($data->storageLocation) === '' || mb_strlen($data->storageLocation) > 2000) {
            throw new DomainException('legal_signature_storage_location_required');
        }
        if ($data->signedAt > new DateTimeImmutable('now')) {
            throw new DomainException('legal_signature_signed_at_invalid');
        }
        $key = $this->validKey($data->idempotencyKey);
        $document = $this->documentForRequest($request);
        $this->authorizer->authorize($actor, $document, LegalDocumentAbility::SIGN->value);

        return $this->connection->transaction(function () use ($request, $actor, $data, $key, $document): bool {
            $lockedDocument = $this->aggregateLock->lockDocument($this->connection, (int) $document->organization_id, (int) $document->id);
            $existing = $this->signatures()->where('signature_request_id', $request->id)->where('idempotency_key', $key)->lockForUpdate()->exists();
            if ($existing) {
                return true;
            }
            if ($data->expectedDocumentLockVersion !== null && (int) $lockedDocument->lock_version !== $data->expectedDocumentLockVersion) {
                throw LegalArchiveLockConflict::forDocument((int) $lockedDocument->id, (int) $lockedDocument->lock_version);
            }
            $lockedVersion = $this->aggregateLock->lockVersion($this->connection, $lockedDocument, (int) $request->document_version_id);
            $this->authorizer->authorize($actor, $lockedDocument, LegalDocumentAbility::SIGN->value);
            $lockedRequest = $this->lockRequest($request);
            $this->signingGuard->assertCompletionAllowed($lockedDocument, $lockedVersion, $lockedRequest);
            if ($lockedRequest->status !== 'pending' || $lockedRequest->method !== 'paper') {
                throw new DomainException('legal_signature_request_not_pending');
            }
            if ($lockedRequest->expires_at !== null && $lockedRequest->expires_at->isPast()) {
                throw new DomainException('legal_signature_request_expired');
            }
            if ((int) $lockedDocument->current_primary_version_id !== (int) $lockedVersion->id
                || ! (bool) $lockedVersion->is_current
                || ! hash_equals((string) $lockedRequest->signed_content_hash, (string) $lockedVersion->content_hash)
                || (string) $lockedVersion->processing_status !== 'ready') {
                throw new DomainException('legal_signature_version_changed');
            }
            $this->assertNoActiveWorkflow((int) $lockedDocument->id);
            $partyId = $data->partyId ?? $lockedRequest->party_id;
            if (($lockedRequest->party_id === null) !== ($partyId === null)
                || ($partyId !== null && (int) $partyId !== (int) $lockedRequest->party_id)) {
                throw new DomainException('legal_signature_party_mismatch');
            }
            $requestedSigners = SignerIdentitySet::fromSnapshot((array) $lockedRequest->signers);
            if (! $requestedSigners->equals($data->signers)
                || ! hash_equals((string) $lockedRequest->signer_snapshot_hash, $data->signers->hash())) {
                throw new DomainException('legal_signature_signers_mismatch');
            }
            $this->assertParty($lockedDocument, $lockedVersion, $partyId === null ? null : (int) $partyId);
            $this->assertSignerIdentities($lockedDocument, $lockedVersion, $data->signers);

            return false;
        }, 3);
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
        if (trim($metadata->provider) === '') {
            throw new DomainException('legal_signature_provider_required');
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
        $evidenceSnapshot = $metadata->evidence->snapshot();
        unset($evidenceSnapshot['verified_at']);
        $importCommandHash = CanonicalJson::fingerprint([
            'request_id' => (int) $request->id,
            'provider' => trim($metadata->provider),
            'party_id' => $metadata->partyId,
            'artifact_hash' => $artifact->sha256(),
            'detected_mime_type' => $detectedMimeType,
            'extension' => strtolower($upload->getClientOriginalExtension()),
            'evidence' => $evidenceSnapshot,
            'provider_metadata' => $metadata->providerMetadata,
        ]);
        $existing = $this->signatures()->where('signature_request_id', $request->id)
            ->where('idempotency_key', $this->validKey($metadata->idempotencyKey))->first();
        if ($existing instanceof LegalDocumentSignature) {
            if (! hash_equals((string) ((array) $existing->provider_metadata)['import_command_hash'], $importCommandHash)) {
                throw new DomainException('legal_signature_idempotency_conflict');
            }

            return $existing;
        }
        $extension = strtolower($upload->getClientOriginalExtension());
        $this->assertDetectedSignatureMime($extension, $detectedMimeType);
        $storedPath = "org-{$request->organization_id}/legal-archive/signatures/requests/{$request->id}/{$artifact->sha256()}.{$extension}";
        $reservation = $this->reserveSignatureArtifact(
            (int) $request->organization_id,
            (int) $request->document_id,
            (int) $request->document_version_id,
            (int) $request->id,
            $storedPath,
            $artifact->sha256(),
            $detectedMimeType,
        );
        $artifactKey = $reservation['artifact_key'];
        $attemptToken = $reservation['attempt_token'];
        $this->heartbeatSignatureArtifact((int) $request->organization_id, $artifactKey, $attemptToken);
        try {
            $stored = $this->fileService->putImmutable($storedPath, $artifact->content, $detectedMimeType);
        } catch (Throwable $error) {
            try {
                $this->markSignatureArtifactAmbiguous((int) $request->organization_id, $artifactKey, $attemptToken, $error);
            } catch (DomainException) {
            }
            throw $error;
        }
        $this->bindStoredArtifactOrRecoverLateVersion($request, $artifactKey, $attemptToken, $storedPath, $artifact->sha256(), $stored);
        if (! hash_equals($artifact->sha256(), (string) $stored['sha256'])
            || (string) $stored['version_id'] === ''
            || (string) $stored['body'] !== $artifact->content) {
            $error = new DomainException('legal_signature_container_invalid');
            $this->releaseFailedArtifactClaim(
                (int) $request->organization_id, (int) $request->document_id, (int) $request->document_version_id,
                $storedPath, (string) $stored['version_id'], is_string($stored['etag']) ? $stored['etag'] : null,
                (string) $stored['sha256'], $error, $artifactKey, $attemptToken,
            );
            throw $error;
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
                'certificate_metadata' => [],
                'provider_metadata' => [...$metadata->providerMetadata, 'import_command_hash' => $importCommandHash],
                'storage_location' => null,
                'verification_status' => 'pending_verification',
                'verified_at' => null,
                'revocation_reason' => null,
                'artifact_key' => $artifactKey,
                'artifact_attempt_token' => $attemptToken,
                ...$this->pendingEvidenceColumns($metadata->evidence),
                'expected_document_lock_version' => $metadata->expectedDocumentLockVersion,
            ]);
        } catch (Throwable $exception) {
            $this->releaseFailedArtifactClaim(
                (int) $request->organization_id,
                (int) $request->document_id,
                (int) $request->document_version_id,
                $storedPath,
                (string) $stored['version_id'],
                is_string($stored['etag']) ? $stored['etag'] : null,
                (string) $stored['sha256'],
                $exception,
                $artifactKey,
                $attemptToken,
            );
            throw $exception;
        }
    }

    public function startElectronicSession(LegalSignatureRequest $request, User $actor, ?int $expectedDocumentLockVersion = null): SignatureSession
    {
        $document = $this->documentForRequest($request);
        $this->authorizer->authorize($actor, $document, LegalDocumentAbility::SIGN->value);
        $this->assertRequestMethod($request, 'provider_electronic');
        $callbackUrl = $this->configuredCallbackUrl();
        $leaseToken = Str::random(64);
        $reservation = $this->connection->transaction(function () use ($request, $document, $actor, $leaseToken, $expectedDocumentLockVersion): array {
            $lockedDocument = $this->aggregateLock->lockDocument($this->connection, (int) $document->organization_id, (int) $document->id);
            $version = $this->aggregateLock->lockVersion($this->connection, $lockedDocument, (int) $request->document_version_id);
            $this->authorizer->authorize($actor, $lockedDocument, LegalDocumentAbility::SIGN->value);
            $lockedRequest = $this->lockRequest($request);
            $operation = $this->providerOperations()->where('signature_request_id', $lockedRequest->id)
                ->orderByDesc('generation')->lockForUpdate()->first();
            if ($operation instanceof LegalSignatureProviderOperation && $operation->status === 'started'
                && $operation->session_expires_at !== null && $operation->session_expires_at->isFuture()) {
                return ['session' => $this->sessionFromOperation($operation)];
            }
            if ($operation instanceof LegalSignatureProviderOperation
                && $operation->status === 'starting'
                && $operation->lease_expires_at !== null
                && $operation->lease_expires_at->isFuture()) {
                throw new DomainException('legal_signature_provider_start_in_progress');
            }
            if ($expectedDocumentLockVersion !== null
                && (int) $lockedDocument->lock_version !== $expectedDocumentLockVersion) {
                throw LegalArchiveLockConflict::forDocument((int) $lockedDocument->id, (int) $lockedDocument->lock_version);
            }
            $this->signingGuard->assertCompletionAllowed($lockedDocument, $version, $lockedRequest);
            $requestIdempotencyKey = $operation instanceof LegalSignatureProviderOperation
                ? (string) $operation->request_idempotency_key
                : hash('sha256', "signature-start:{$lockedRequest->organization_id}:{$lockedRequest->id}");
            $generation = $operation instanceof LegalSignatureProviderOperation ? ((int) $operation->generation) + 1 : 1;
            $supersedesOperationId = $operation instanceof LegalSignatureProviderOperation ? (string) $operation->id : null;
            $operation = $this->newProviderOperation()->newQuery()->create([
                'id' => (string) Str::uuid(),
                'organization_id' => (int) $lockedRequest->organization_id,
                'document_id' => (int) $lockedRequest->document_id,
                'document_version_id' => (int) $lockedRequest->document_version_id,
                'signature_request_id' => (int) $lockedRequest->id,
                'provider' => (string) $lockedRequest->provider,
                'status' => 'starting',
                'correlation_id' => (string) $lockedRequest->correlation_id,
                'request_idempotency_key' => $requestIdempotencyKey,
                'generation' => $generation,
                'supersedes_operation_id' => $supersedesOperationId,
                'provider_idempotency_key' => hash('sha256', "{$requestIdempotencyKey}:{$generation}"),
                'lease_token_hash' => hash('sha256', $leaseToken),
                'lease_expires_at' => now()->addSeconds($this->startLeaseSeconds()),
                'attempt_count' => 1,
                'started_at' => now(),
            ]);
            $this->audit->record('signature_provider_session_reserved', $lockedDocument, $actor, [
                'source_event_id' => "signature-provider-operation:{$operation->id}:{$operation->generation}:reserved",
                'signature_request_id' => (int) $lockedRequest->id,
                'operation_id' => (string) $operation->id,
                'generation' => (int) $operation->generation,
            ]);

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
            $this->failProviderStart((string) $operation->id, $leaseToken, $actor, $exception);
            throw $exception;
        }

        $finalized = $this->connection->transaction(function () use ($request, $session, $document, $actor, $operation, $leaseToken, $expectedDocumentLockVersion): array {
            $lockedDocument = $this->aggregateLock->lockDocument($this->connection, (int) $document->organization_id, (int) $document->id);
            $version = $this->aggregateLock->lockVersion($this->connection, $lockedDocument, (int) $request->document_version_id);
            $this->authorizer->authorize($actor, $lockedDocument, LegalDocumentAbility::SIGN->value);
            $lockedRequest = $this->lockRequest($request);
            $lockedOperation = $this->providerOperations()->whereKey($operation->id)->lockForUpdate()->first();
            if (! $lockedOperation instanceof LegalSignatureProviderOperation
                || $lockedOperation->status !== 'starting'
                || ! hash_equals((string) $lockedOperation->lease_token_hash, hash('sha256', $leaseToken))) {
                throw new DomainException('legal_signature_provider_lease_lost');
            }
            $lockConflictVersion = $expectedDocumentLockVersion !== null
                && (int) $lockedDocument->lock_version !== $expectedDocumentLockVersion
                    ? (int) $lockedDocument->lock_version
                    : null;
            if ($lockConflictVersion === null) {
                $this->signingGuard->assertCompletionAllowed($lockedDocument, $version, $lockedRequest);
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
            $this->audit->record('signature_provider_session_started', $lockedDocument, $actor, [
                'source_event_id' => "signature-provider-operation:{$lockedOperation->id}:{$lockedOperation->generation}:started",
                'signature_request_id' => (int) $lockedRequest->id,
                'operation_id' => (string) $lockedOperation->id,
                'generation' => (int) $lockedOperation->generation,
                'provider_request_fingerprint' => hash('sha256', $session->providerRequestId),
                'redirect_host' => mb_strtolower((string) parse_url($session->redirectUrl, PHP_URL_HOST)),
                'session_expires_at' => $session->expiresAt,
                'session_metadata_hash' => CanonicalJson::fingerprint($session->metadata),
            ]);

            return ['session' => $session, 'lock_conflict_version' => $lockConflictVersion];
        }, 3);
        if ($finalized['lock_conflict_version'] !== null) {
            throw LegalArchiveLockConflict::forDocument((int) $document->id, (int) $finalized['lock_conflict_version']);
        }

        return $finalized['session'];
    }

    public function completeElectronic(SignatureCallback $callback, ?int $expectedDocumentLockVersion = null): LegalDocumentSignature
    {
        $candidateOperation = trim($callback->provider) === '' || trim($callback->providerRequestId) === ''
            ? null
            : $this->providerOperations()->where('provider', $callback->provider)
                ->where('provider_request_id', $callback->providerRequestId)->orderByDesc('generation')->first();
        $candidate = $candidateOperation instanceof LegalSignatureProviderOperation
            ? $this->requests()->whereKey($candidateOperation->signature_request_id)->first()
            : null;
        if (trim($callback->provider) === '' || trim($callback->providerRequestId) === ''
            || preg_match('/^[a-f0-9]{64}$/D', $callback->correlationId) !== 1
            || trim($callback->replayToken) === '') {
            $this->auditRejectedCallback($candidate, $callback, 'malformed');
            throw new DomainException('legal_signature_callback_invalid');
        }
        $request = $candidate;
        if (! $request instanceof LegalSignatureRequest) {
            $this->auditRejectedCallback(null, $callback, 'unknown_request');
            throw new DomainException('legal_signature_request_not_found');
        }
        if (! hash_equals((string) $request->correlation_id, $callback->correlationId)) {
            $this->auditRejectedCallback($request, $callback, 'correlation_mismatch');
            throw new DomainException('legal_signature_callback_invalid');
        }
        $latestOperation = $this->providerOperations()->where('signature_request_id', $request->id)
            ->orderByDesc('generation')->first();
        if (! $candidateOperation instanceof LegalSignatureProviderOperation
            || ! $latestOperation instanceof LegalSignatureProviderOperation
            || (string) $latestOperation->id !== (string) $candidateOperation->id
            || (string) $candidateOperation->status !== 'started') {
            $this->auditRejectedCallback($request, $callback, 'stale_provider_generation');
            throw new DomainException('legal_signature_callback_stale_generation');
        }
        $this->assertRequestMethod($request, 'provider_electronic', false);
        $replayHash = hash('sha256', $callback->replayToken);
        $payloadHash = CanonicalJson::fingerprint($callback->payload);
        if ($request->callback_replay_hash !== null) {
            if (! hash_equals((string) $request->callback_replay_hash, $replayHash)
                || ! hash_equals((string) $request->callback_payload_hash, $payloadHash)) {
                $this->auditRejectedCallback($request, $callback, 'replay_conflict');
                throw new DomainException('legal_signature_callback_replay_conflict');
            }
            $existing = $this->signatures()->where('signature_request_id', $request->id)->first();
            if (! $existing instanceof LegalDocumentSignature) {
                throw new DomainException('legal_signature_callback_incomplete');
            }

            return $existing;
        }
        if ($expectedDocumentLockVersion !== null) {
            $this->connection->transaction(function () use ($request, $expectedDocumentLockVersion): void {
                $document = $this->aggregateLock->lockDocument(
                    $this->connection,
                    (int) $request->organization_id,
                    (int) $request->document_id,
                );
                if ((int) $document->lock_version !== $expectedDocumentLockVersion) {
                    throw LegalArchiveLockConflict::forDocument((int) $document->id, (int) $document->lock_version);
                }
            }, 3);
        }
        try {
            $result = $this->provider->complete($callback);
            $this->assertProviderResult(
                $request,
                $result->artifact,
                $result,
                (string) $candidateOperation->provider_request_id,
            );
            if (! $result->callbackAuthentic) {
                throw new DomainException('legal_signature_callback_invalid');
            }
        } catch (Throwable $error) {
            $this->auditRejectedCallback($request, $callback, 'provider_verification_failed');
            throw $error;
        }
        $detectedMimeType = (new \finfo(FILEINFO_MIME_TYPE))->buffer($result->artifact->content);
        if (! is_string($detectedMimeType) || $detectedMimeType === '') {
            $this->auditRejectedCallback($request, $callback, 'container_detection_failed');
            throw new DomainException('legal_signature_container_invalid');
        }
        try {
            $this->assertDetectedSignatureMime($result->evidence->containerFormat, $detectedMimeType);
        } catch (Throwable $error) {
            $this->auditRejectedCallback($request, $callback, 'container_mime_rejected');
            throw $error;
        }
        $storedPath = "org-{$request->organization_id}/legal-archive/signatures/requests/{$request->id}/{$result->artifact->sha256()}.{$result->evidence->containerFormat}";
        $reservation = $this->reserveSignatureArtifact(
            (int) $request->organization_id,
            (int) $request->document_id,
            (int) $request->document_version_id,
            (int) $request->id,
            $storedPath,
            $result->artifact->sha256(),
            $detectedMimeType,
        );
        $artifactKey = $reservation['artifact_key'];
        $attemptToken = $reservation['attempt_token'];
        $this->heartbeatSignatureArtifact((int) $request->organization_id, $artifactKey, $attemptToken);
        try {
            $stored = $this->fileService->putImmutable($storedPath, $result->artifact->content, $detectedMimeType);
        } catch (Throwable $error) {
            try {
                $this->markSignatureArtifactAmbiguous((int) $request->organization_id, $artifactKey, $attemptToken, $error);
            } catch (DomainException) {
            }
            $this->auditRejectedCallback($request, $callback, 'container_storage_failed');
            throw $error;
        }
        $this->bindStoredArtifactOrRecoverLateVersion($request, $artifactKey, $attemptToken, $storedPath, $result->artifact->sha256(), $stored);
        if (! hash_equals($result->artifact->sha256(), (string) $stored['sha256'])
            || (string) $stored['version_id'] === '' || (string) $stored['body'] !== $result->artifact->content) {
            $this->auditRejectedCallback($request, $callback, 'container_storage_mismatch');
            $error = new DomainException('legal_signature_container_invalid');
            $this->releaseFailedArtifactClaim(
                (int) $request->organization_id, (int) $request->document_id, (int) $request->document_version_id,
                $storedPath, (string) $stored['version_id'], is_string($stored['etag']) ? $stored['etag'] : null,
                (string) $stored['sha256'], $error, $artifactKey, $attemptToken,
            );
            throw $error;
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
                'artifact_key' => $artifactKey,
                'artifact_attempt_token' => $attemptToken,
                'expected_operation_id' => (string) $candidateOperation->id,
                'expected_operation_generation' => (int) $candidateOperation->generation,
                'expected_provider_request_id' => (string) $candidateOperation->provider_request_id,
                'expected_correlation_id' => (string) $candidateOperation->correlation_id,
                'expected_document_lock_version' => $expectedDocumentLockVersion,
                ...$this->evidenceColumns($result->evidence),
            ], false);
        } catch (Throwable $exception) {
            try {
                $this->releaseFailedArtifactClaim(
                    (int) $request->organization_id,
                    (int) $request->document_id,
                    (int) $request->document_version_id,
                    $storedPath,
                    (string) $stored['version_id'],
                    is_string($stored['etag']) ? $stored['etag'] : null,
                    (string) $stored['sha256'],
                    $exception,
                    $artifactKey,
                    $attemptToken,
                );
            } finally {
                $reason = $exception instanceof DomainException
                    && $exception->getMessage() === 'legal_signature_callback_stale_generation'
                    ? 'stale_provider_generation'
                    : 'registration_rejected';
                $this->auditRejectedCallback($request, $callback, $reason);
            }
            throw $exception;
        }
    }

    public function verify(
        LegalDocumentSignature $signature,
        User $actor,
        string $idempotencyKey,
        ?int $expectedDocumentLockVersion = null,
    ): LegalSignatureVerification {
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
        $requestHash = CanonicalJson::fingerprint([
            'signature_id' => (int) $signature->id,
            'actor_id' => (int) $actor->id,
            'signature_content_hash' => (string) $signature->signature_content_hash,
            'storage_version_id' => (string) $signature->storage_version_id,
        ]);
        $replay = $this->connection->transaction(function () use ($document, $signature, $key, $requestHash, $expectedDocumentLockVersion): ?LegalSignatureVerification {
            $lockedDocument = $this->aggregateLock->lockDocument($this->connection, (int) $document->organization_id, (int) $document->id);
            $existing = $this->verifications()->where('signature_id', $signature->id)
                ->where('idempotency_key', $key)->lockForUpdate()->first();
            if ($existing instanceof LegalSignatureVerification) {
                $this->assertSameRequest($existing->request_hash, $requestHash);

                return $existing;
            }
            if ($expectedDocumentLockVersion !== null && (int) $lockedDocument->lock_version !== $expectedDocumentLockVersion) {
                throw LegalArchiveLockConflict::forDocument((int) $lockedDocument->id, (int) $lockedDocument->lock_version);
            }

            return null;
        }, 3);
        if ($replay instanceof LegalSignatureVerification) {
            return $replay;
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
        $verifier = $this->verifierForProvider((string) $signature->provider);
        $result = $verifier->verify(new SignatureVerificationContext(
            $signature,
            $artifact,
            (string) $descriptor['version_id'],
            is_string($descriptor['etag']) ? $descriptor['etag'] : null,
        ));
        $this->assertVerificationResult($signature, $request, $artifact, $result);

        return $this->connection->transaction(function () use ($signature, $request, $actor, $document, $result, $key, $requestHash, $expectedDocumentLockVersion): LegalSignatureVerification {
            $lockedDocument = $this->aggregateLock->lockDocument($this->connection, (int) $document->organization_id, (int) $document->id);
            $existing = $this->verifications()->where('signature_id', $signature->id)->where('idempotency_key', $key)->lockForUpdate()->first();
            if ($existing instanceof LegalSignatureVerification) {
                $this->assertSameRequest($existing->request_hash, $requestHash);

                return $existing;
            }
            if ($expectedDocumentLockVersion !== null && (int) $lockedDocument->lock_version !== $expectedDocumentLockVersion) {
                throw LegalArchiveLockConflict::forDocument((int) $lockedDocument->id, (int) $lockedDocument->lock_version);
            }
            $lockedVersion = $this->aggregateLock->lockVersion($this->connection, $lockedDocument, (int) $signature->document_version_id);
            $this->authorizer->authorize($actor, $lockedDocument, LegalDocumentAbility::VERIFY_SIGNATURE->value);
            $lockedRequest = $this->lockRequest($request);
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
            if ($lockedDocument->lifecycle_status === 'signed') {
                $this->markVersionSigned($lockedVersion);
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

    private function assertVerificationResult(
        LegalDocumentSignature $signature,
        LegalSignatureRequest $request,
        SignatureArtifact $artifact,
        SignatureVerificationResult $result,
    ): void {
        $expectedProviderRequestId = $signature->method === 'provider_electronic'
            ? (string) $request->provider_request_id
            : "external-verification:{$signature->id}";
        $this->assertProviderResult($request, $artifact, $result, $expectedProviderRequestId);
        if (! hash_equals((string) $signature->signer_snapshot_hash, $result->signers->hash())
            || $result->evidence->signatureKind !== $signature->signature_kind
            || $result->evidence->containerFormat !== $signature->container_format
            || $result->evidence->signedAt->format(DATE_ATOM) !== $signature->signed_at?->toAtomString()) {
            throw new DomainException('legal_signature_verification_result_invalid');
        }
    }

    private function assertProviderResult(
        LegalSignatureRequest $request,
        SignatureArtifact $artifact,
        SignatureVerificationResult $result,
        string $expectedProviderRequestId,
    ): void {
        $expectedSigners = SignerIdentitySet::fromSnapshot((array) $request->signers);
        $now = new DateTimeImmutable('now');
        if (! in_array($result->status, ['verified', 'failed', 'revoked'], true)
            || ! hash_equals((string) $request->signed_content_hash, $result->signedContentHash)
            || ! hash_equals((string) $request->provider, $result->provider)
            || ! hash_equals($expectedProviderRequestId, $result->providerRequestId)
            || ! hash_equals((string) $request->correlation_id, $result->correlationId)
            || ! $expectedSigners->equals($result->signers)
            || ! $result->signers->equals($result->evidence->signers)
            || ! hash_equals((string) $request->signer_snapshot_hash, $result->signers->hash())
            || ! hash_equals($artifact->sha256(), $result->artifact->sha256())
            || ! in_array($result->evidence->signatureKind, (array) $request->allowed_signature_formats, true)
            || $result->evidence->signedAt > $now
            || $result->evidence->verifiedAt > $now
            || ($result->status === 'verified' && (! $result->evidence->authorityConfirmed || $result->evidence->diagnosticCode !== 'verified'))
            || ($result->status === 'revoked' && trim((string) $result->revocationReason) === '')
            || ($result->status !== 'revoked' && $result->revocationReason !== null)) {
            throw new DomainException('legal_signature_verification_result_invalid');
        }
    }

    private function registerOriginal(LegalSignatureRequest $request, User $actor, string $method, string $idempotencyKey, array $data, bool $authorize = true): LegalDocumentSignature
    {
        $key = $this->validKey($idempotencyKey);
        $requestHash = CanonicalJson::fingerprint([
            'request_id' => (int) $request->id,
            'method' => $method,
            ...array_diff_key($data, [
                'callback_replay_hash' => true,
                'callback_payload_hash' => true,
                'artifact_attempt_token' => true,
            ]),
        ]);
        $document = $this->documentForRequest($request);
        if ($authorize) {
            $this->authorizer->authorize($actor, $document, LegalDocumentAbility::SIGN->value);
        }

        return $this->connection->transaction(function () use ($request, $actor, $method, $key, $requestHash, $data, $document, $authorize): LegalDocumentSignature {
            $lockedDocument = $this->aggregateLock->lockDocument($this->connection, (int) $document->organization_id, (int) $document->id);
            $existing = $this->signatures()->where('signature_request_id', $request->id)->where('idempotency_key', $key)->lockForUpdate()->first();
            if ($existing instanceof LegalDocumentSignature) {
                $this->assertSameRequest($existing->request_hash, $requestHash);
                if ($method !== 'paper' && isset($data['artifact_key'])) {
                    $artifact = $this->connection->table('legal_signature_artifacts')
                        ->where('organization_id', $lockedDocument->organization_id)
                        ->where('artifact_key', (string) $data['artifact_key'])->lockForUpdate()->first();
                    if ($artifact !== null && (int) $artifact->claim_count > 0
                        && hash_equals((string) $artifact->upload_lease_token_hash, hash('sha256', (string) ($data['artifact_attempt_token'] ?? '')))) {
                        $this->connection->table('legal_signature_artifacts')->where('id', $artifact->id)->update([
                            'claim_count' => ((int) $artifact->claim_count) - 1,
                            'upload_lease_token_hash' => (int) $artifact->claim_count === 1 ? null : $artifact->upload_lease_token_hash,
                            'upload_lease_expires_at' => (int) $artifact->claim_count === 1 ? null : now()->addMinutes(10),
                            'updated_at' => now(),
                        ]);
                    }
                }

                return $existing;
            }
            if (isset($data['expected_document_lock_version'])
                && (int) $lockedDocument->lock_version !== (int) $data['expected_document_lock_version']) {
                throw LegalArchiveLockConflict::forDocument((int) $lockedDocument->id, (int) $lockedDocument->lock_version);
            }
            $lockedVersion = $this->aggregateLock->lockVersion($this->connection, $lockedDocument, (int) $request->document_version_id);
            if ($authorize) {
                $this->authorizer->authorize($actor, $lockedDocument, LegalDocumentAbility::SIGN->value);
            }
            $lockedRequest = $this->lockRequest($request);
            if ($method === 'provider_electronic') {
                $this->assertProviderOperationFence($lockedRequest, $data);
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
                    || preg_match('/^[a-f0-9]{64}$/D', $containerHash) !== 1) {
                    throw new DomainException('legal_signature_container_invalid');
                }
                if (! in_array((string) $data['signature_kind'], (array) $lockedRequest->allowed_signature_formats, true)) {
                    throw new DomainException('legal_signature_format_not_allowed_by_profile');
                }
                if ($data['verification_status'] !== 'pending_verification'
                    && (! $data['verified_at'] instanceof DateTimeImmutable || (array) $data['certificate_metadata'] === [])) {
                    throw new DomainException('legal_signature_certificate_metadata_required');
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
            if (($lockedRequest->party_id === null) !== ($partyId === null)
                || ($partyId !== null && (int) $partyId !== (int) $lockedRequest->party_id)) {
                throw new DomainException('legal_signature_party_mismatch');
            }
            $requestedSigners = SignerIdentitySet::fromSnapshot((array) $lockedRequest->signers);
            $actualSigners = SignerIdentitySet::fromSnapshot((array) $data['signers']);
            if (! $requestedSigners->equals($actualSigners)
                || ! hash_equals((string) $lockedRequest->signer_snapshot_hash, $actualSigners->hash())) {
                throw new DomainException('legal_signature_signers_mismatch');
            }
            if (! $data['signed_at'] instanceof DateTimeImmutable || $data['signed_at'] > new DateTimeImmutable('now')) {
                throw new DomainException('legal_signature_signed_at_invalid');
            }
            if ($data['verified_at'] instanceof DateTimeImmutable && $data['verified_at'] < $data['signed_at']) {
                throw new DomainException('legal_signature_verified_at_invalid');
            }
            $this->assertParty($lockedDocument, $lockedVersion, $partyId === null ? null : (int) $partyId);
            $this->assertSignerIdentities($lockedDocument, $lockedVersion, $actualSigners);
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
            if ($method !== 'paper') {
                $artifact = $this->connection->table('legal_signature_artifacts')
                    ->where('organization_id', $lockedDocument->organization_id)
                    ->where('artifact_key', (string) ($data['artifact_key'] ?? ''))
                    ->whereNotIn('state', ['deleting', 'deleted'])
                    ->lockForUpdate()->first();
                if ($artifact === null) {
                    throw new DomainException('legal_signature_artifact_claim_lost');
                }
                if (! hash_equals(
                    (string) $artifact->upload_lease_token_hash,
                    hash('sha256', (string) ($data['artifact_attempt_token'] ?? '')),
                ) || $artifact->upload_lease_expires_at === null || now()->gte($artifact->upload_lease_expires_at)) {
                    throw new DomainException('legal_signature_artifact_attempt_stale');
                }
                $this->connection->table('legal_signature_artifacts')->where('id', $artifact->id)->update([
                    'state' => 'referenced',
                    'referenced_signature_id' => (int) $signature->id,
                    'claim_count' => max(0, ((int) $artifact->claim_count) - 1),
                    'upload_lease_token_hash' => null,
                    'upload_lease_expires_at' => null,
                    'updated_at' => now(),
                ]);
            }
            $this->authorizeSignatureMutation();
            LegalSignatureRequest::serviceMutation(function () use ($lockedRequest, $data): void {
                $lockedRequest->forceFill([
                    'status' => 'completed',
                    'callback_replay_hash' => $data['callback_replay_hash'] ?? null,
                    'callback_payload_hash' => $data['callback_payload_hash'] ?? null,
                    'completed_at' => now(),
                ])->save();
            });
            $this->projection->apply($lockedDocument);
            if ($lockedDocument->lifecycle_status === 'signed') {
                $this->markVersionSigned($lockedVersion);
            }
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

    private function freezeVersion(LegalArchiveDocumentVersion $version): void
    {
        if (in_array((string) $version->status, ['frozen', 'signed'], true)) {
            return;
        }
        if ($version->status !== 'uploaded' || $version->processing_status !== 'ready' || ! (bool) $version->is_current) {
            throw new DomainException('legal_signature_version_not_freezable');
        }
        $this->authorizeVersionSignatureMutation();
        $version->transitionForSignature('frozen');
    }

    private function markVersionSigned(LegalArchiveDocumentVersion $version): void
    {
        if ($version->status === 'signed') {
            return;
        }
        if ($version->status !== 'frozen') {
            throw new DomainException('legal_signature_version_not_frozen');
        }
        $this->authorizeVersionSignatureMutation();
        $version->transitionForSignature('signed');
    }

    private function authorizeVersionSignatureMutation(): void
    {
        if ($this->connection->getDriverName() === 'pgsql') {
            $this->connection->statement("SET LOCAL most.legal_archive_version_mutation = 'signature_service'");
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

    private function assertSignerIdentities(
        LegalArchiveDocument $document,
        LegalArchiveDocumentVersion $version,
        SignerIdentitySet $signers,
    ): void {
        foreach ($signers->identities as $identity) {
            if ($identity->kind === 'manual') {
                continue;
            }
            if ($identity->kind === 'party') {
                $party = $this->connection->table('legal_document_parties')
                    ->where('id', $identity->partyId)
                    ->where('organization_id', $document->organization_id)
                    ->where('document_id', $document->id)
                    ->where('document_version_id', $version->id)
                    ->first([
                        'party_organization_id', 'party_role', 'legal_name', 'tax_number',
                        'registration_number', 'representative_position', 'authority_basis',
                    ]);
                if ($party === null
                    || trim((string) $party->legal_name) !== trim($identity->name)
                    || ! $this->sameNullableString($party->party_role, $identity->partyRole)
                    || ! $this->sameNullableString($party->tax_number, $identity->taxNumber)
                    || ! $this->sameNullableString($party->registration_number, $identity->registrationNumber)
                    || ($identity->organizationId !== null && (int) $party->party_organization_id !== $identity->organizationId)
                    || ($identity->position !== null && ! $this->sameNullableString($party->representative_position, $identity->position))
                    || ($identity->authorityBasis !== null && ! $this->sameNullableString($party->authority_basis, $identity->authorityBasis))) {
                    throw new DomainException('legal_signature_signer_party_invalid');
                }

                continue;
            }
            if ($identity->kind === 'organization') {
                $organization = $this->connection->table('organizations')->where('id', $identity->organizationId)
                    ->where('is_active', true)->whereNull('deleted_at')
                    ->first(['id', 'name', 'legal_name', 'tax_number', 'registration_number']);
                $isDocumentParty = (int) $identity->organizationId === (int) $document->organization_id
                    || $this->connection->table('legal_document_parties')
                        ->where('organization_id', $document->organization_id)
                        ->where('document_id', $document->id)
                        ->where('document_version_id', $version->id)
                        ->where('party_organization_id', $identity->organizationId)
                        ->exists();
                if ($organization === null) {
                    throw new DomainException('legal_signature_signer_organization_invalid');
                }
                $legalName = trim((string) $organization->legal_name);
                $expectedName = $legalName !== '' ? $legalName : trim((string) $organization->name);
                if (! $isDocumentParty || $expectedName !== trim($identity->name)
                    || ! $this->sameNullableString($organization->tax_number, $identity->taxNumber)
                    || ! $this->sameNullableString($organization->registration_number, $identity->registrationNumber)) {
                    throw new DomainException('legal_signature_signer_organization_invalid');
                }

                continue;
            }
            $identityOrganizationIsParty = (int) $identity->organizationId === (int) $document->organization_id
                || $this->connection->table('legal_document_parties')
                    ->where('organization_id', $document->organization_id)
                    ->where('document_id', $document->id)
                    ->where('document_version_id', $version->id)
                    ->where('party_organization_id', $identity->organizationId)->exists();
            $user = $this->connection->table('users')->where('id', $identity->userId)
                ->where('is_active', true)->whereNull('deleted_at')->first(['id', 'name']);
            if (! $identityOrganizationIsParty || $user === null || trim((string) $user->name) !== trim($identity->name)
                || ! $this->connection->table('organization_user')->where('organization_id', $identity->organizationId)
                    ->where('user_id', $identity->userId)->where('is_active', true)->exists()) {
                throw new DomainException('legal_signature_signer_user_invalid');
            }
            if ($identity->kind === 'role') {
                $hasRole = $this->connection->table('user_role_assignments as assignment')
                    ->join('authorization_contexts as context', 'context.id', '=', 'assignment.context_id')
                    ->where('assignment.user_id', $identity->userId)
                    ->where('assignment.role_slug', $identity->roleSlug)
                    ->where('assignment.is_active', true)
                    ->where(static function ($query): void {
                        $query->whereNull('assignment.expires_at')->orWhere('assignment.expires_at', '>', now());
                    })
                    ->where('context.type', 'organization')
                    ->where('context.resource_id', $identity->organizationId)
                    ->exists();
                if (! $hasRole) {
                    throw new DomainException('legal_signature_signer_role_invalid');
                }
            }
        }
    }

    private function sameNullableString(mixed $stored, ?string $expected): bool
    {
        $actual = $stored === null ? null : trim((string) $stored);
        $expected = $expected === null ? null : trim($expected);

        return $actual === $expected;
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

    private function pendingEvidenceColumns(ElectronicSignatureEvidence $evidence): array
    {
        $primary = $evidence->signers->primary();

        return [
            'signature_kind' => $evidence->signatureKind,
            'container_format' => $evidence->containerFormat,
            'signer_snapshot_hash' => $evidence->signers->hash(),
            'signer_user_id' => $primary->userId,
            'signer_organization_id' => $primary->organizationId,
            'party_role_snapshot' => $evidence->partyRoleSnapshot ?? $primary->partyRole,
            'certificate_fingerprint' => null,
            'certificate_serial' => null,
            'certificate_issuer' => null,
            'certificate_valid_from' => null,
            'certificate_valid_until' => null,
            'authority_confirmed' => false,
            'time_source' => 'operator',
            'diagnostic_code' => 'verification_pending',
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

    /** @return array{artifact_key:string,attempt_token:string} */
    private function reserveSignatureArtifact(
        int $organizationId,
        int $documentId,
        int $documentVersionId,
        int $requestId,
        string $path,
        string $contentHash,
        string $contentType,
    ): array {
        $artifactKey = CanonicalJson::fingerprint([
            'organization_id' => $organizationId,
            'storage_path' => $path,
            'content_hash' => $contentHash,
        ]);
        $putRequestHash = CanonicalJson::fingerprint([
            'organization_id' => $organizationId,
            'storage_path' => $path,
            'content_hash' => $contentHash,
            'content_type' => mb_strtolower($contentType),
        ]);
        $attemptToken = Str::random(64);
        $this->connection->transaction(function () use ($organizationId, $documentId, $documentVersionId, $requestId, $path, $contentHash, $putRequestHash, $artifactKey, $attemptToken): void {
            $now = now();
            $this->connection->table('legal_signature_artifacts')->insertOrIgnore([
                'organization_id' => $organizationId,
                'document_id' => $documentId,
                'document_version_id' => $documentVersionId,
                'signature_request_id' => $requestId,
                'artifact_key' => $artifactKey,
                'storage_path' => $path,
                'storage_version_id' => null,
                'content_hash' => $contentHash,
                'put_request_hash' => $putRequestHash,
                'state' => 'uploading',
                'claim_count' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $artifact = $this->connection->table('legal_signature_artifacts')
                ->where('organization_id', $organizationId)->where('artifact_key', $artifactKey)
                ->lockForUpdate()->first();
            if ($artifact === null
                || (int) $artifact->document_id !== $documentId
                || (int) $artifact->document_version_id !== $documentVersionId
                || (int) $artifact->signature_request_id !== $requestId
                || ! hash_equals((string) $artifact->storage_path, $path)
                || ! hash_equals((string) $artifact->content_hash, $contentHash)
                || ! hash_equals((string) $artifact->put_request_hash, $putRequestHash)
                || in_array((string) $artifact->state, ['referenced', 'deleting', 'deleted'], true)) {
                throw new DomainException('legal_signature_artifact_claim_conflict');
            }
            if ((int) $artifact->claim_count > 0
                && $artifact->upload_lease_expires_at !== null
                && now()->lt($artifact->upload_lease_expires_at)) {
                throw new DomainException('legal_signature_artifact_upload_in_progress');
            }
            if ((int) $artifact->claim_count > 0) {
                throw new DomainException('legal_signature_artifact_recovery_required');
            }
            if ((string) $artifact->state === 'ambiguous') {
                throw new DomainException('legal_signature_artifact_recovery_required');
            }
            $this->connection->table('legal_signature_artifacts')->where('id', $artifact->id)->update([
                'state' => (string) $artifact->state === 'confirmed_absent' ? 'uploading' : (string) $artifact->state,
                'claim_count' => 1,
                'upload_lease_token_hash' => hash('sha256', $attemptToken),
                'upload_lease_expires_at' => now()->addMinutes(10),
                'last_attempt_at' => now(),
                'attempt_count' => ((int) $artifact->attempt_count) + 1,
                'dead_lettered_at' => null,
                'last_error_code' => null,
                'first_ambiguous_at' => null,
                'next_reconcile_at' => null,
                'absence_check_count' => 0,
                'updated_at' => $now,
            ]);
        }, 3);

        return ['artifact_key' => $artifactKey, 'attempt_token' => $attemptToken];
    }

    private function heartbeatSignatureArtifact(int $organizationId, string $artifactKey, string $attemptToken): void
    {
        $this->connection->transaction(function () use ($organizationId, $artifactKey, $attemptToken): void {
            $artifact = $this->connection->table('legal_signature_artifacts')
                ->where('organization_id', $organizationId)->where('artifact_key', $artifactKey)
                ->lockForUpdate()->first();
            if ($artifact === null || ! hash_equals((string) $artifact->upload_lease_token_hash, hash('sha256', $attemptToken))
                || $artifact->upload_lease_expires_at === null || now()->gte($artifact->upload_lease_expires_at)
                || ! in_array((string) $artifact->state, ['uploading', 'uploaded'], true)) {
                throw new DomainException('legal_signature_artifact_attempt_stale');
            }
            $this->connection->table('legal_signature_artifacts')->where('id', $artifact->id)->update([
                'upload_lease_expires_at' => now()->addMinutes(10), 'last_attempt_at' => now(), 'updated_at' => now(),
            ]);
        }, 3);
    }

    private function bindSignatureArtifact(
        int $organizationId,
        string $artifactKey,
        string $attemptToken,
        string $versionId,
        bool $created,
    ): void {
        $this->connection->transaction(function () use ($organizationId, $artifactKey, $attemptToken, $versionId, $created): void {
            $artifact = $this->connection->table('legal_signature_artifacts')
                ->where('organization_id', $organizationId)->where('artifact_key', $artifactKey)
                ->lockForUpdate()->first();
            if ($artifact === null || ! hash_equals((string) $artifact->upload_lease_token_hash, hash('sha256', $attemptToken))
                || $artifact->upload_lease_expires_at === null || now()->gte($artifact->upload_lease_expires_at)
                || in_array((string) $artifact->state, ['deleting', 'deleted'], true)
                || ($artifact->storage_version_id !== null && ! hash_equals((string) $artifact->storage_version_id, $versionId))) {
                throw new DomainException('legal_signature_artifact_attempt_stale');
            }
            $this->connection->table('legal_signature_artifacts')->where('id', $artifact->id)->update([
                'storage_version_id' => $versionId,
                'state' => (string) $artifact->state === 'uploading' ? 'uploaded' : (string) $artifact->state,
                'cleanup_owned' => (bool) $artifact->cleanup_owned || $created,
                'upload_lease_expires_at' => in_array((string) $artifact->state, ['uploading', 'uploaded'], true)
                    ? now()->addMinutes(10) : null,
                'last_attempt_at' => now(),
                'updated_at' => now(),
            ]);
        }, 3);
    }

    private function markSignatureArtifactAmbiguous(
        int $organizationId,
        string $artifactKey,
        string $attemptToken,
        Throwable $error,
    ): void {
        $this->connection->transaction(function () use ($organizationId, $artifactKey, $attemptToken, $error): void {
            $artifact = $this->connection->table('legal_signature_artifacts')
                ->where('organization_id', $organizationId)->where('artifact_key', $artifactKey)
                ->lockForUpdate()->first();
            if ($artifact === null || (string) $artifact->state !== 'uploading'
                || ! hash_equals((string) $artifact->upload_lease_token_hash, hash('sha256', $attemptToken))
                || $artifact->upload_lease_expires_at === null || now()->gte($artifact->upload_lease_expires_at)) {
                throw new DomainException('legal_signature_artifact_attempt_stale');
            }
            $remainingClaims = max(0, ((int) $artifact->claim_count) - 1);
            $this->connection->table('legal_signature_artifacts')->where('id', $artifact->id)->update([
                'state' => $remainingClaims === 0 ? 'ambiguous' : 'uploading',
                'claim_count' => $remainingClaims,
                'upload_lease_token_hash' => $remainingClaims === 0 ? null : $artifact->upload_lease_token_hash,
                'upload_lease_expires_at' => $remainingClaims === 0 ? null : now()->addMinutes(10),
                'first_ambiguous_at' => $remainingClaims === 0 ? ($artifact->first_ambiguous_at ?? now()) : $artifact->first_ambiguous_at,
                'next_reconcile_at' => $remainingClaims === 0 ? now()->addMinute() : $artifact->next_reconcile_at,
                'last_error_code' => $error::class,
                'updated_at' => now(),
            ]);
        }, 3);
    }

    /** @param array{version_id:string,created:bool,sha256:string,body:string,etag?:string|null} $stored */
    private function bindStoredArtifactOrRecoverLateVersion(
        LegalSignatureRequest $request,
        string $artifactKey,
        string $attemptToken,
        string $path,
        string $contentHash,
        array $stored,
    ): void {
        try {
            $this->heartbeatSignatureArtifact((int) $request->organization_id, $artifactKey, $attemptToken);
            $this->bindSignatureArtifact(
                (int) $request->organization_id,
                $artifactKey,
                $attemptToken,
                (string) $stored['version_id'],
                (bool) $stored['created'],
            );
        } catch (DomainException $error) {
            if ($error->getMessage() !== 'legal_signature_artifact_attempt_stale') {
                throw $error;
            }
            $this->recoverLateArtifactVersion(
                (int) $request->organization_id,
                (int) $request->document_id,
                (int) $request->document_version_id,
                $artifactKey,
                $path,
                (string) $stored['version_id'],
                isset($stored['etag']) ? (string) $stored['etag'] : null,
                $contentHash,
            );

            throw $error;
        }
    }

    private function recoverLateArtifactVersion(
        int $organizationId,
        int $documentId,
        int $documentVersionId,
        string $artifactKey,
        string $path,
        string $versionId,
        ?string $etag,
        string $contentHash,
    ): void {
        if ($versionId === '') {
            throw new RuntimeException('legal_signature_artifact_version_missing');
        }
        $cleanupRequired = $this->connection->transaction(function () use ($organizationId, $artifactKey, $path, $versionId): bool {
            $this->lockArtifactMutex($organizationId, $path);
            $artifact = $this->connection->table('legal_signature_artifacts')
                ->where('organization_id', $organizationId)->where('artifact_key', $artifactKey)
                ->lockForUpdate()->first();
            if ($artifact === null) {
                throw new RuntimeException('legal_signature_artifact_recovery_state_missing');
            }
            $referencedSignatureId = $this->signatures()->where('organization_id', $organizationId)
                ->where('signature_path', $path)->where('storage_version_id', $versionId)
                ->lockForUpdate()->value('id');
            if ($artifact->storage_version_id !== null && hash_equals((string) $artifact->storage_version_id, $versionId)) {
                if (in_array((string) $artifact->state, ['uploading', 'uploaded', 'ambiguous'], true)) {
                    $this->connection->table('legal_signature_artifacts')->where('id', $artifact->id)->update([
                        'next_reconcile_at' => now(), 'updated_at' => now(),
                    ]);
                }

                return false;
            }
            $leaseActive = $artifact->upload_lease_expires_at !== null && now()->lt($artifact->upload_lease_expires_at);
            $canonicalCanOwnVersion = ! $leaseActive
                && $artifact->storage_version_id === null
                && in_array((string) $artifact->state, ['uploading', 'ambiguous', 'confirmed_absent'], true);
            if ($canonicalCanOwnVersion && $referencedSignatureId === null) {
                if ((string) $artifact->state === 'confirmed_absent') {
                    $this->connection->table('legal_signature_artifacts')->where('id', $artifact->id)->update([
                        'state' => 'uploading', 'claim_count' => 0, 'updated_at' => now(),
                    ]);
                }
                $this->connection->table('legal_signature_artifacts')->where('id', $artifact->id)->update([
                    'state' => 'uploaded', 'storage_version_id' => $versionId,
                    'cleanup_owned' => true, 'updated_at' => now(),
                ]);
                $this->connection->table('legal_signature_artifacts')->where('id', $artifact->id)->update([
                    'state' => 'deleting', 'cleanup_owned' => true, 'claim_count' => 0,
                    'upload_lease_token_hash' => null, 'upload_lease_expires_at' => null,
                    'next_reconcile_at' => null, 'last_error_code' => null, 'updated_at' => now(),
                ]);

                return true;
            }
            $lateArtifactKey = CanonicalJson::fingerprint([
                'canonical_artifact_key' => $artifactKey,
                'storage_version_id' => $versionId,
            ]);
            $now = now();
            $this->connection->table('legal_signature_artifacts')->insertOrIgnore([
                'organization_id' => $organizationId,
                'document_id' => (int) $artifact->document_id,
                'document_version_id' => (int) $artifact->document_version_id,
                'signature_request_id' => (int) $artifact->signature_request_id,
                'artifact_key' => $lateArtifactKey,
                'storage_path' => $path,
                'storage_version_id' => $versionId,
                'content_hash' => (string) $artifact->content_hash,
                'put_request_hash' => (string) $artifact->put_request_hash,
                'state' => $referencedSignatureId === null ? 'deleting' : 'referenced',
                'claim_count' => 0,
                'cleanup_owned' => $referencedSignatureId === null,
                'referenced_signature_id' => $referencedSignatureId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $lateArtifact = $this->connection->table('legal_signature_artifacts')
                ->where('organization_id', $organizationId)->where('artifact_key', $lateArtifactKey)
                ->lockForUpdate()->first();
            if ($lateArtifact === null
                || ! hash_equals((string) $lateArtifact->storage_path, $path)
                || ! hash_equals((string) $lateArtifact->storage_version_id, $versionId)
                || ! hash_equals((string) $lateArtifact->content_hash, (string) $artifact->content_hash)) {
                throw new RuntimeException('legal_signature_late_artifact_identity_conflict');
            }

            return true;
        }, 3);
        if (! $cleanupRequired) {
            return;
        }
        $now = now();
        $this->connection->table('legal_archive_file_cleanup_debts')->upsert([[
            'organization_id' => $organizationId,
            'document_id' => $documentId,
            'document_version_id' => $documentVersionId,
            'storage_path' => $path,
            'storage_version_id' => $versionId,
            'debt_key' => LegalCleanupDebtKey::for($organizationId, $path, $versionId),
            'storage_etag' => $etag,
            'content_hash' => $contentHash,
            'reason' => 'signature_registration_failed',
            'attempts' => 0,
            'next_attempt_at' => $now,
            'last_error' => null,
            'resolved_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]], ['organization_id', 'debt_key'], [
            'document_id', 'document_version_id', 'storage_version_id', 'storage_etag', 'content_hash', 'reason',
            'next_attempt_at', 'last_error', 'resolved_at', 'updated_at',
        ]);
    }

    private function lockArtifactMutex(int $organizationId, string $path): void
    {
        if ($this->connection->getDriverName() === 'pgsql') {
            $this->connection->select(
                'SELECT pg_advisory_xact_lock(hashtextextended(?, 0))',
                ["legal-signature-artifact:{$organizationId}:{$path}"],
            );
        }
    }

    private function releaseFailedArtifactClaim(
        int $organizationId,
        int $documentId,
        int $documentVersionId,
        string $path,
        string $versionId,
        ?string $etag,
        string $contentHash,
        Throwable $exception,
        string $artifactKey,
        string $attemptToken,
    ): void {
        $mayDelete = $this->connection->transaction(function () use ($organizationId, $path, $versionId, $artifactKey, $attemptToken): bool {
            $artifact = $this->connection->table('legal_signature_artifacts')
                ->where('organization_id', $organizationId)->where('artifact_key', $artifactKey)
                ->lockForUpdate()->first();
            if ($artifact === null || ! hash_equals((string) $artifact->upload_lease_token_hash, hash('sha256', $attemptToken))
                || $artifact->upload_lease_expires_at === null || now()->gte($artifact->upload_lease_expires_at)) {
                throw new DomainException('legal_signature_artifact_attempt_stale');
            }
            $referencedSignatureId = $this->signatures()->where('organization_id', $organizationId)
                ->where('signature_path', $path)->where('storage_version_id', $versionId)
                ->lockForUpdate()->value('id');
            $remainingClaims = max(0, ((int) $artifact->claim_count) - 1);
            if ($referencedSignatureId !== null || (string) $artifact->state === 'referenced') {
                $this->connection->table('legal_signature_artifacts')->where('id', $artifact->id)->update([
                    'state' => 'referenced',
                    'referenced_signature_id' => $referencedSignatureId ?? $artifact->referenced_signature_id,
                    'claim_count' => $remainingClaims,
                    'upload_lease_token_hash' => null,
                    'upload_lease_expires_at' => null,
                    'updated_at' => now(),
                ]);

                return false;
            }
            if (in_array((string) $artifact->state, ['deleting', 'deleted'], true)) {
                return false;
            }
            if (! (bool) $artifact->cleanup_owned || $remainingClaims > 0) {
                $this->connection->table('legal_signature_artifacts')->where('id', $artifact->id)->update([
                    'claim_count' => $remainingClaims, 'updated_at' => now(),
                    'upload_lease_token_hash' => $artifact->upload_lease_token_hash ?? hash('sha256', Str::random(64)),
                    'upload_lease_expires_at' => now()->addMinutes(10),
                ]);

                return false;
            }
            $this->connection->table('legal_signature_artifacts')->where('id', $artifact->id)->update([
                'state' => 'deleting', 'claim_count' => 0,
                'upload_lease_token_hash' => null, 'upload_lease_expires_at' => null,
                'updated_at' => now(),
            ]);

            return true;
        }, 3);
        if (! $mayDelete) {
            return;
        }
        try {
            $this->fileService->removeImmutable($path, $versionId);
            $this->connection->table('legal_signature_artifacts')
                ->where('organization_id', $organizationId)->where('artifact_key', $artifactKey)
                ->where('state', 'deleting')->update([
                    'state' => 'deleted',
                    'upload_lease_token_hash' => null, 'upload_lease_expires_at' => null,
                    'deletion_lease_token_hash' => null, 'deletion_lease_expires_at' => null,
                    'updated_at' => now(),
                ]);

            return;
        } catch (Throwable $cleanupException) {
            $lastError = $cleanupException::class;
        }
        if (! $this->connection->getSchemaBuilder()->hasTable('legal_archive_file_cleanup_debts')) {
            throw new RuntimeException('legal_signature_cleanup_debt_storage_unavailable', previous: $exception);
        }
        $now = now();
        $this->connection->table('legal_archive_file_cleanup_debts')->upsert([[
            'organization_id' => $organizationId,
            'document_id' => $documentId,
            'document_version_id' => $documentVersionId,
            'storage_path' => $path,
            'storage_version_id' => $versionId,
            'debt_key' => LegalCleanupDebtKey::for($organizationId, $path, $versionId),
            'storage_etag' => $etag,
            'content_hash' => $contentHash,
            'reason' => 'signature_registration_failed',
            'attempts' => 1,
            'next_attempt_at' => $now,
            'last_error' => $lastError,
            'resolved_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]], ['organization_id', 'debt_key'], [
            'document_id', 'document_version_id', 'storage_version_id', 'debt_key', 'storage_etag', 'content_hash', 'reason', 'next_attempt_at',
            'last_error', 'resolved_at', 'updated_at',
        ]);
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

    private function isReplaceableAttempt(LegalSignatureRequest $request): bool
    {
        if (in_array((string) $request->status, ['failed', 'expired', 'revoked'], true)) {
            return true;
        }
        if ((string) $request->status !== 'completed') {
            return false;
        }
        $signature = $this->signatures()->where('signature_request_id', $request->id)->orderByDesc('id')->first();
        if (! $signature instanceof LegalDocumentSignature || $signature->method === 'paper') {
            return false;
        }
        $latestVerification = $this->verifications()->where('signature_id', $signature->id)->orderByDesc('id')->first();
        $effectiveStatus = $latestVerification instanceof LegalSignatureVerification
            ? (string) $latestVerification->status
            : (string) $signature->verification_status;

        return in_array($effectiveStatus, ['failed', 'revoked'], true);
    }

    private function assertProviderOperationFence(LegalSignatureRequest $request, array $data): void
    {
        $operation = $this->providerOperations()->where('signature_request_id', $request->id)
            ->orderByDesc('generation')->lockForUpdate()->first();
        if (! $operation instanceof LegalSignatureProviderOperation
            || (string) $operation->id !== (string) ($data['expected_operation_id'] ?? '')
            || (int) $operation->generation !== (int) ($data['expected_operation_generation'] ?? 0)
            || (string) $operation->provider_request_id !== (string) ($data['expected_provider_request_id'] ?? '')
            || ! hash_equals((string) $operation->correlation_id, (string) ($data['expected_correlation_id'] ?? ''))
            || (string) $operation->status !== 'started') {
            throw new DomainException('legal_signature_callback_stale_generation');
        }
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

    private function auditRejectedCallback(
        ?LegalSignatureRequest $request,
        SignatureCallback $callback,
        string $reason,
    ): void {
        $fingerprint = CanonicalJson::fingerprint([
            'provider' => $callback->provider,
            'provider_request_id_hash' => hash('sha256', $callback->providerRequestId),
            'correlation_id_hash' => hash('sha256', $callback->correlationId),
            'replay_token_hash' => hash('sha256', $callback->replayToken),
            'payload_hash' => CanonicalJson::fingerprint($callback->payload),
            'reason' => $reason,
        ]);
        if (! $request instanceof LegalSignatureRequest) {
            Log::warning('legal_signature.callback_rejected', ['reason' => $reason, 'fingerprint' => $fingerprint]);

            return;
        }
        $document = $this->documents()->whereKey($request->document_id)
            ->where('organization_id', $request->organization_id)->first();
        if (! $document instanceof LegalArchiveDocument) {
            Log::warning('legal_signature.callback_rejected', ['reason' => $reason, 'fingerprint' => $fingerprint]);

            return;
        }
        $this->audit->recordForActorId('signature_callback_rejected', $document, null, [
            'source_event_id' => "signature-callback-rejected:{$request->id}:{$fingerprint}",
            'signature_request_id' => (int) $request->id,
            'reason' => $reason,
            'diagnostic_code' => $reason,
            'callback_fingerprint' => $fingerprint,
            'provider_request_fingerprint' => hash('sha256', $callback->providerRequestId),
            'replay_token_fingerprint' => hash('sha256', $callback->replayToken),
        ]);
    }

    private function verifierForProvider(string $provider): ElectronicSignatureProvider
    {
        $container = Container::getInstance();
        if (! $container->bound('config')) {
            return $this->provider;
        }
        $configuredDriver = (string) $container->make('config')->get('legal-document-signatures.driver', '');
        if ($configuredDriver === $provider) {
            return $this->provider;
        }
        $drivers = (array) $container->make('config')->get('legal-document-signatures.drivers', []);
        $providerClass = $drivers[$provider] ?? null;
        if (! is_string($providerClass) || ! is_a($providerClass, ElectronicSignatureProvider::class, true)) {
            throw new DomainException('legal_signature_verifier_not_configured');
        }
        $resolved = $container->make($providerClass);
        if (! $resolved instanceof ElectronicSignatureProvider) {
            throw new DomainException('legal_signature_verifier_not_configured');
        }

        return $resolved;
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

    private function failProviderStart(string $operationId, string $leaseToken, User $actor, Throwable $exception): void
    {
        $candidate = $this->providerOperations()->whereKey($operationId)->first();
        if (! $candidate instanceof LegalSignatureProviderOperation) {
            return;
        }
        $this->connection->transaction(function () use ($candidate, $operationId, $leaseToken, $actor, $exception): void {
            $document = $this->aggregateLock->lockDocument($this->connection, (int) $candidate->organization_id, (int) $candidate->document_id);
            $this->aggregateLock->lockVersion($this->connection, $document, (int) $candidate->document_version_id);
            $this->requests()->whereKey($candidate->signature_request_id)->lockForUpdate()->firstOrFail();
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
            $this->audit->record('signature_provider_session_failed', $document, $actor, [
                'source_event_id' => "signature-provider-operation:{$operation->id}:{$operation->generation}:failed",
                'signature_request_id' => (int) $operation->signature_request_id,
                'operation_id' => (string) $operation->id,
                'generation' => (int) $operation->generation,
                'error_class' => $exception::class,
            ]);
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
