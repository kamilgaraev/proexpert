<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\LegalArchive;

use App\BusinessModules\Features\LegalArchive\Models\LegalDocumentAccessGrant;
use App\Http\Requests\Api\V1\Admin\LegalArchive\RecoverLegalDocumentManagementRequest;
use App\Http\Requests\Api\V1\Admin\LegalArchive\UpdateLegalArchiveAccessRequest;
use App\Http\Responses\AdminResponse;
use App\Services\LegalArchive\Access\LegalDocumentAccessService;
use App\Services\LegalArchive\Access\LegalDocumentAccessSubject;
use App\Services\LegalArchive\Access\LegalDocumentAccessSubjectKind;
use App\Services\LegalArchive\LegalArchiveRegistryService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

use function trans_message;

final class LegalArchiveAccessController extends LegalArchiveApiController
{
    public function __construct(
        private readonly LegalArchiveRegistryService $registry,
        private readonly LegalDocumentAccessService $access,
    ) {}

    public function index(Request $request, string $legalDocument): JsonResponse
    {
        try {
            $owner = $this->document($request, $legalDocument);
            $this->access->authorizePermission($this->actor($request), $owner, 'legal_archive.external_access.manage');
            $perPage = max(1, min($request->integer('per_page', 50), 100));
            $grants = $owner->accessGrants()->orderByDesc('id')->paginate($perPage);

            return AdminResponse::paginated(
                $grants->getCollection()->map(fn (LegalDocumentAccessGrant $grant): array => $this->payload($grant))->all(),
                ['current_page' => $grants->currentPage(), 'per_page' => $grants->perPage(), 'total' => $grants->total(), 'last_page' => $grants->lastPage()],
                trans_message('legal_archive.messages.access_loaded'),
            );
        } catch (Throwable $error) {
            return $this->failure($error, $request, 'access_index', ['document_id' => $legalDocument]);
        }
    }

    public function store(UpdateLegalArchiveAccessRequest $request, string $legalDocument): JsonResponse
    {
        try {
            $owner = $this->document($request, $legalDocument);
            $kind = LegalDocumentAccessSubjectKind::from((string) $request->validated('subject_kind'));
            $organizationId = (int) $request->validated('subject_organization_id');
            $subject = match ($kind) {
                LegalDocumentAccessSubjectKind::INTERNAL_USER => LegalDocumentAccessSubject::internalUser($organizationId, (int) $request->validated('subject_user_id')),
                LegalDocumentAccessSubjectKind::INTERNAL_ROLE => LegalDocumentAccessSubject::internalRole($organizationId, (string) $request->validated('subject_role_slug')),
                LegalDocumentAccessSubjectKind::EXTERNAL_ORGANIZATION => LegalDocumentAccessSubject::externalOrganization($organizationId),
                LegalDocumentAccessSubjectKind::EXTERNAL_USER => LegalDocumentAccessSubject::externalUser($organizationId, (int) $request->validated('subject_user_id')),
            };
            $grant = $this->access->grant(
                $owner,
                $this->actor($request),
                $subject,
                (array) $request->validated('abilities'),
                $request->validated('expires_at') === null ? null : CarbonImmutable::parse((string) $request->validated('expires_at')),
                (int) $request->validated('lock_version'),
            );

            return $this->etag(AdminResponse::success($this->payload($grant), trans_message('legal_archive.messages.access_granted'), 201, [
                'document_lock_version' => (int) $owner->fresh()->lock_version,
            ]), $owner->fresh());
        } catch (Throwable $error) {
            return $this->failure($error, $request, 'access_store', ['document_id' => $legalDocument]);
        }
    }

    public function revoke(Request $request, string $legalAccessGrant): JsonResponse
    {
        try {
            $data = $request->validate([
                'lock_version' => ['required', 'integer', 'min:0'],
                'reason' => ['required', 'string', 'max:5000'],
            ]);
            $found = LegalDocumentAccessGrant::query()->whereKey((int) $legalAccessGrant)
                ->where('organization_id', $this->organizationId($request))->first();
            if (! $found instanceof LegalDocumentAccessGrant) {
                throw new \Illuminate\Auth\Access\AuthorizationException;
            }
            $owner = $found->document()->firstOrFail();
            $revoked = $this->access->revoke($owner, $found, $this->actor($request), (string) $data['reason'], (int) $data['lock_version']);

            return $this->etag(AdminResponse::success($this->payload($revoked), trans_message('legal_archive.messages.access_revoked'), 200, [
                'document_lock_version' => (int) $owner->fresh()->lock_version,
            ]), $owner->fresh());
        } catch (Throwable $error) {
            return $this->failure($error, $request, 'access_revoke', ['grant_id' => $legalAccessGrant]);
        }
    }

    public function recoverManagement(RecoverLegalDocumentManagementRequest $request, string $legalDocument): JsonResponse
    {
        try {
            $owner = $this->document($request, $legalDocument);
            $grant = $this->access->recoverManagementAsSecurityAdministrator(
                $owner,
                $this->actor($request),
                (int) $request->validated('successor_user_id'),
                (int) $request->validated('lock_version'),
            );

            return $this->etag(AdminResponse::success(
                $this->payload($grant),
                trans_message('legal_archive.messages.management_recovered'),
                200,
                ['document_lock_version' => (int) $owner->fresh()->lock_version],
            ), $owner->fresh());
        } catch (Throwable $error) {
            return $this->failure($error, $request, 'access_management_recovery', ['document_id' => $legalDocument]);
        }
    }

    private function document(Request $request, string $id): \App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument
    {
        $document = $this->registry->findForOrganization($this->organizationId($request), (int) $id);
        if ($document === null) {
            throw new \Illuminate\Auth\Access\AuthorizationException;
        }

        return $document;
    }

    private function payload(LegalDocumentAccessGrant $grant): array
    {
        return [
            'id' => (int) $grant->id,
            'document_id' => (int) $grant->document_id,
            'subject_kind' => (string) $grant->subject_kind,
            'subject_organization_id' => (int) $grant->subject_organization_id,
            'subject_user_id' => $grant->subject_user_id,
            'subject_role_slug' => $grant->subject_role_slug,
            'abilities' => (array) $grant->abilities,
            'expires_at' => $grant->expires_at?->toAtomString(),
            'revoked_at' => $grant->revoked_at?->toAtomString(),
            'revocation_reason' => $grant->revocation_reason,
        ];
    }
}
