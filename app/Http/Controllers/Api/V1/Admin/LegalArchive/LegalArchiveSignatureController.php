<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\LegalArchive;

use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocumentVersion;
use App\BusinessModules\Features\LegalArchive\Models\LegalDocumentSignature;
use App\BusinessModules\Features\LegalArchive\Models\LegalSignatureRequest;
use App\Http\Requests\Api\V1\Admin\LegalArchive\CreateLegalArchiveSignatureRequest;
use App\Http\Requests\Api\V1\Admin\LegalArchive\LegalArchiveLockRequest;
use App\Http\Requests\Api\V1\Admin\LegalArchive\RegisterLegalArchiveOriginalRequest;
use App\Http\Resources\Api\V1\Admin\LegalArchive\LegalArchiveSignatureResource;
use App\Http\Responses\AdminResponse;
use App\Services\LegalArchive\Access\LegalDocumentAuthorizer;
use App\Services\LegalArchive\LegalArchiveRegistryService;
use App\Services\LegalArchive\Signatures\ElectronicSignatureEvidence;
use App\Services\LegalArchive\Signatures\ExternalOriginalData;
use App\Services\LegalArchive\Signatures\LegalDocumentSignatureService;
use App\Services\LegalArchive\Signatures\PaperOriginalData;
use App\Services\LegalArchive\Signatures\SignatureCallback;
use App\Services\LegalArchive\Signatures\SignerIdentitySet;
use DateTimeImmutable;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

use function trans_message;

final class LegalArchiveSignatureController extends LegalArchiveApiController
{
    public function __construct(
        private readonly LegalArchiveRegistryService $registry,
        private readonly LegalDocumentSignatureService $signatures,
        private readonly LegalDocumentAuthorizer $access,
    ) {}

    public function createRequests(CreateLegalArchiveSignatureRequest $request, string $document): JsonResponse
    {
        try {
            $owner = $this->registry->findForOrganization($this->organizationId($request), (int) $document);
            if ($owner === null) {
                throw new \Illuminate\Auth\Access\AuthorizationException;
            }
            $version = LegalArchiveDocumentVersion::query()
                ->whereKey((int) $request->validated('document_version_id'))
                ->where('organization_id', $this->organizationId($request))
                ->where('document_id', (int) $owner->id)->first();
            if (! $version instanceof LegalArchiveDocumentVersion) {
                throw new DomainException('legal_signature_version_not_found');
            }
            $created = $this->signatures->createRequest(
                $owner,
                $version,
                $this->actor($request),
                (string) $request->validated('method'),
                SignerIdentitySet::fromSnapshot((array) $request->validated('signers')),
                (string) $request->validated('idempotency_key'),
                $request->validated('party_id'),
                $request->validated('provider'),
                $request->validated('expires_at') === null ? null : new DateTimeImmutable((string) $request->validated('expires_at')),
                $request->validated('replaces_request_id'),
                (int) $request->validated('lock_version'),
            );

            return AdminResponse::success($this->requestPayload($created), trans_message('legal_archive.messages.signature_request_created'), 201, [
                'document_lock_version' => (int) $owner->fresh()->lock_version,
                'idempotency_key' => (string) $request->validated('idempotency_key'),
            ]);
        } catch (Throwable $error) {
            return $this->failure($error, $request, 'signature_request_create', ['document_id' => $document]);
        }
    }

    public function uploadOriginal(RegisterLegalArchiveOriginalRequest $request, string $signatureRequest): JsonResponse
    {
        try {
            $pending = $this->request($request, $signatureRequest);
            $signers = SignerIdentitySet::fromSnapshot((array) $request->validated('signers'));
            if ((string) $request->validated('method') === 'paper') {
                $signature = $this->signatures->registerPaperOriginal($pending, $this->actor($request), new PaperOriginalData(
                    signedAt: new DateTimeImmutable((string) $request->validated('signed_at')),
                    signers: $signers,
                    storageLocation: (string) $request->validated('storage_location'),
                    idempotencyKey: (string) $request->validated('idempotency_key'),
                    partyId: $request->validated('party_id'),
                    partyRoleSnapshot: $request->validated('party_role_snapshot'),
                    authorityConfirmed: (bool) ($request->validated('authority_confirmed') ?? false),
                    clientIpHash: hash('sha256', (string) $request->ip()),
                    userAgentHash: hash('sha256', (string) $request->userAgent()),
                    expectedDocumentLockVersion: (int) $request->validated('lock_version'),
                ));
            } else {
                $signature = $this->signatures->registerExternalOriginal(
                    $pending,
                    $request->file('file'),
                    $this->actor($request),
                    new ExternalOriginalData(
                        provider: (string) $request->validated('provider'),
                        evidence: $this->evidence((array) $request->validated('evidence'), $signers),
                        idempotencyKey: (string) $request->validated('idempotency_key'),
                        providerMetadata: (array) ($request->validated('provider_metadata') ?? []),
                        partyId: $request->validated('party_id'),
                        expectedDocumentLockVersion: (int) $request->validated('lock_version'),
                    ),
                );
            }

            return AdminResponse::success(new LegalArchiveSignatureResource($signature), trans_message('legal_archive.messages.original_registered'), 201, [
                'document_lock_version' => (int) $pending->document()->value('lock_version'),
                'idempotency_key' => (string) $request->validated('idempotency_key'),
            ]);
        } catch (Throwable $error) {
            return $this->failure($error, $request, 'signature_original_upload', ['signature_request_id' => $signatureRequest]);
        }
    }

    public function signingSession(LegalArchiveLockRequest $request, string $signatureRequest): JsonResponse
    {
        try {
            $session = $this->signatures->startElectronicSession(
                $this->request($request, $signatureRequest),
                $this->actor($request),
                (int) $request->validated('lock_version'),
            );

            return AdminResponse::success([
                'provider_request_id' => $session->providerRequestId,
                'redirect_url' => $session->redirectUrl,
                'expires_at' => $session->expiresAt,
                'metadata' => $session->metadata,
            ], trans_message('legal_archive.messages.signing_session_created'));
        } catch (Throwable $error) {
            return $this->failure($error, $request, 'signature_session', ['signature_request_id' => $signatureRequest]);
        }
    }

    public function complete(Request $request, string $signatureRequest): JsonResponse
    {
        try {
            $pending = $this->request($request, $signatureRequest);
            $data = $request->validate([
                'provider' => ['required', 'string', 'max:191'],
                'provider_request_id' => ['required', 'string', 'max:191'],
                'correlation_id' => ['required', 'string', 'size:64'],
                'replay_token' => ['required', 'string', 'max:4096'],
                'payload' => ['required', 'array'],
            ]);
            if (! hash_equals((string) $pending->provider_request_id, (string) $data['provider_request_id'])
                || ! hash_equals((string) $pending->correlation_id, (string) $data['correlation_id'])) {
                throw new DomainException('legal_signature_callback_invalid');
            }
            $signature = $this->signatures->completeElectronic(new SignatureCallback(
                (string) $data['provider'], (string) $data['provider_request_id'], (string) $data['correlation_id'],
                (string) $data['replay_token'], (array) $data['payload'],
            ));

            return AdminResponse::success(new LegalArchiveSignatureResource($signature), trans_message('legal_archive.messages.signature_completed'));
        } catch (Throwable $error) {
            return $this->failure($error, $request, 'signature_complete', ['signature_request_id' => $signatureRequest]);
        }
    }

    public function index(Request $request, string $version): JsonResponse
    {
        try {
            $documentVersion = LegalArchiveDocumentVersion::query()->whereKey((int) $version)
                ->where('organization_id', $this->organizationId($request))->first();
            if (! $documentVersion instanceof LegalArchiveDocumentVersion) {
                throw new \Illuminate\Auth\Access\AuthorizationException;
            }
            $this->access->authorize($this->actor($request), $documentVersion->document()->firstOrFail(), 'view');
            $items = LegalDocumentSignature::query()->where('organization_id', $this->organizationId($request))
                ->where('document_version_id', (int) $documentVersion->id)->with('verificationHistory')->orderByDesc('id')->get();

            return AdminResponse::success(LegalArchiveSignatureResource::collection($items), trans_message('legal_archive.messages.signatures_loaded'));
        } catch (Throwable $error) {
            return $this->failure($error, $request, 'signature_index', ['version_id' => $version]);
        }
    }

    public function verify(Request $request, string $signature): JsonResponse
    {
        try {
            $data = $request->validate(['idempotency_key' => ['required', 'string', 'max:191']]);
            $found = LegalDocumentSignature::query()->whereKey((int) $signature)
                ->where('organization_id', $this->organizationId($request))->first();
            if (! $found instanceof LegalDocumentSignature) {
                throw new \Illuminate\Auth\Access\AuthorizationException;
            }
            $verification = $this->signatures->verify($found, $this->actor($request), (string) $data['idempotency_key']);

            return AdminResponse::success([
                'id' => (int) $verification->id,
                'signature_id' => (int) $verification->signature_id,
                'status' => (string) $verification->status,
                'verified_at' => $verification->verified_at?->toAtomString(),
                'revocation_reason' => $verification->revocation_reason,
            ], trans_message('legal_archive.messages.signature_verified'));
        } catch (Throwable $error) {
            return $this->failure($error, $request, 'signature_verify', ['signature_id' => $signature]);
        }
    }

    private function request(Request $request, string $id): LegalSignatureRequest
    {
        $found = LegalSignatureRequest::query()->whereKey((int) $id)->where('organization_id', $this->organizationId($request))->first();
        if (! $found instanceof LegalSignatureRequest) {
            throw new \Illuminate\Auth\Access\AuthorizationException;
        }

        return $found;
    }

    private function requestPayload(LegalSignatureRequest $request): array
    {
        return [
            'id' => (int) $request->id, 'document_id' => (int) $request->document_id,
            'document_version_id' => (int) $request->document_version_id, 'method' => (string) $request->method,
            'provider' => $request->provider, 'status' => (string) $request->status,
            'signers' => (array) $request->signers, 'expires_at' => $request->expires_at?->toAtomString(),
        ];
    }

    private function evidence(array $value, SignerIdentitySet $signers): ElectronicSignatureEvidence
    {
        $certificate = (array) ($value['certificate'] ?? []);

        return new ElectronicSignatureEvidence(
            signatureKind: (string) ($value['signature_kind'] ?? ''),
            containerFormat: (string) ($value['container_format'] ?? ''),
            signers: $signers,
            certificateFingerprint: (string) ($certificate['fingerprint'] ?? ''),
            certificateSerial: (string) ($certificate['serial'] ?? ''),
            certificateIssuer: (string) ($certificate['issuer'] ?? ''),
            certificateValidFrom: new DateTimeImmutable((string) ($certificate['valid_from'] ?? '')),
            certificateValidUntil: new DateTimeImmutable((string) ($certificate['valid_until'] ?? '')),
            authorityConfirmed: (bool) ($value['authority_confirmed'] ?? false),
            timeSource: (string) ($value['time_source'] ?? ''),
            diagnosticCode: (string) ($value['diagnostic_code'] ?? ''),
            signedAt: new DateTimeImmutable((string) ($value['signed_at'] ?? '')),
            verifiedAt: new DateTimeImmutable((string) ($value['verified_at'] ?? '')),
            partyRoleSnapshot: isset($value['party_role_snapshot']) ? (string) $value['party_role_snapshot'] : null,
            signingSessionId: isset($value['signing_session_id']) ? (string) $value['signing_session_id'] : null,
        );
    }
}
