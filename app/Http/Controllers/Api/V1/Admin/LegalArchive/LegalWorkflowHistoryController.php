<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\LegalArchive;

use App\Http\Requests\Api\V1\Admin\LegalArchive\LegalWorkflowHistoryRequest;
use App\Http\Resources\Api\V1\Admin\LegalArchive\LegalWorkflowHistoryResource;
use App\Http\Responses\AdminResponse;
use App\Services\LegalArchive\LegalArchiveRegistryService;
use App\Services\LegalArchive\Workflow\LegalWorkflowHistoryService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Throwable;

final class LegalWorkflowHistoryController extends LegalArchiveApiController
{
    public function __construct(
        private readonly LegalArchiveRegistryService $registry,
        private readonly LegalWorkflowHistoryService $history,
    ) {}

    public function __invoke(LegalWorkflowHistoryRequest $request, string $legalDocument): JsonResponse
    {
        try {
            $document = $this->registry->findForOrganization($this->organizationId($request), (int) $legalDocument);
            if ($document === null) {
                throw new AuthorizationException;
            }
            $beforeId = $request->validated('before_id');
            $page = $this->history->forDocument($this->actor($request), $document, $beforeId === null ? null : (int) $beforeId);

            return AdminResponse::paginated(
                LegalWorkflowHistoryResource::collection($page['items']),
                ['next_before_id' => $page['next_before_id']],
                trans_message('legal_workflow_history.loaded'),
            );
        } catch (Throwable $error) {
            return $this->failure($error, $request, 'workflow_history', ['document_id' => $legalDocument]);
        }
    }
}
