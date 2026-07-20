<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\LegalArchive;

use App\Http\Requests\Api\V1\Admin\LegalArchive\UpdateLegalArchiveLegalHoldRequest;
use App\Http\Requests\Api\V1\Admin\LegalArchive\UpdateLegalArchiveRetentionRequest;
use App\Http\Resources\Api\V1\Admin\LegalArchive\LegalArchiveDocumentResource;
use App\Http\Responses\AdminResponse;
use App\Services\LegalArchive\LegalArchiveRegistryService;
use App\Services\LegalArchive\LegalDocumentGovernanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

use function trans_message;

final class LegalArchiveRetentionController extends LegalArchiveApiController
{
    public function __construct(
        private readonly LegalArchiveRegistryService $registry,
        private readonly LegalDocumentGovernanceService $governance,
    ) {}

    public function update(UpdateLegalArchiveRetentionRequest $request, string $document): JsonResponse
    {
        try {
            $updated = $this->governance->updateRetention(
                $this->document($request, $document),
                $this->actor($request),
                $request->safe()->except('lock_version'),
                (int) $request->validated('lock_version'),
            );

            return $this->etag(AdminResponse::success(new LegalArchiveDocumentResource($updated), trans_message('legal_archive.messages.retention_updated')), $updated);
        } catch (Throwable $error) {
            return $this->failure($error, $request, 'retention_update', ['document_id' => $document]);
        }
    }

    public function legalHold(UpdateLegalArchiveLegalHoldRequest $request, string $document): JsonResponse
    {
        try {
            $updated = $this->governance->setLegalHold(
                $this->document($request, $document),
                $this->actor($request),
                (bool) $request->validated('enabled'),
                (int) $request->validated('lock_version'),
            );

            return $this->etag(AdminResponse::success(new LegalArchiveDocumentResource($updated), trans_message('legal_archive.messages.legal_hold_updated')), $updated);
        } catch (Throwable $error) {
            return $this->failure($error, $request, 'legal_hold_update', ['document_id' => $document]);
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
}
