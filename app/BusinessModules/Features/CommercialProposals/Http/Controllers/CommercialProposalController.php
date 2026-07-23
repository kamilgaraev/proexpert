<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\CommercialProposals\Http\Controllers;

use App\BusinessModules\Features\CommercialProposals\Exceptions\CommercialProposalWorkflowException;
use App\BusinessModules\Features\CommercialProposals\Http\Requests\CommercialProposalApprovalDecisionRequest;
use App\BusinessModules\Features\CommercialProposals\Http\Requests\CommercialProposalExportRequest;
use App\BusinessModules\Features\CommercialProposals\Http\Requests\CommercialProposalListRequest;
use App\BusinessModules\Features\CommercialProposals\Http\Requests\CommercialProposalRequest;
use App\BusinessModules\Features\CommercialProposals\Http\Requests\CommercialProposalResultRequest;
use App\BusinessModules\Features\CommercialProposals\Http\Requests\CommercialProposalSendRequest;
use App\BusinessModules\Features\CommercialProposals\Http\Requests\CommercialProposalTemplateRequest;
use App\BusinessModules\Features\CommercialProposals\Http\Requests\CommercialProposalVersionRequest;
use App\BusinessModules\Features\CommercialProposals\Http\Requests\UploadCommercialProposalFileRequest;
use App\BusinessModules\Features\CommercialProposals\Http\Resources\CommercialProposalExportResource;
use App\BusinessModules\Features\CommercialProposals\Http\Resources\CommercialProposalFileResource;
use App\BusinessModules\Features\CommercialProposals\Http\Resources\CommercialProposalResource;
use App\BusinessModules\Features\CommercialProposals\Http\Resources\CommercialProposalTemplateResource;
use App\BusinessModules\Features\CommercialProposals\Http\Resources\CommercialProposalVersionResource;
use App\BusinessModules\Features\CommercialProposals\Services\CommercialProposalService;
use App\DTOs\Contract\ContractDossierCreationInput;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Contract\StoreContractRequest;
use App\Http\Resources\Api\V1\Admin\Contract\ContractResource;
use App\Http\Responses\AdminResponse;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

use function trans_message;

final class CommercialProposalController extends Controller
{
    public function __construct(private readonly CommercialProposalService $service)
    {
    }

    public function summary(Request $request): JsonResponse
    {
        try {
            return AdminResponse::success(
                $this->service->summary($this->organizationId($request), $this->canViewAmounts($request))
            );
        } catch (Throwable $e) {
            return $this->failure($request, $e, 'commercial_proposals.errors.summary');
        }
    }

    public function references(Request $request): JsonResponse
    {
        try {
            return AdminResponse::success(
                $this->service->references($this->organizationId($request), $this->canViewAmounts($request))
            );
        } catch (Throwable $e) {
            return $this->failure($request, $e, 'commercial_proposals.errors.references');
        }
    }

    public function templates(Request $request): JsonResponse
    {
        try {
            return AdminResponse::success(
                CommercialProposalTemplateResource::collection(
                    $this->service->templates($this->organizationId($request))
                )
            );
        } catch (Throwable $e) {
            return $this->failure($request, $e, 'commercial_proposals.errors.templates');
        }
    }

    public function storeTemplate(CommercialProposalTemplateRequest $request): JsonResponse
    {
        try {
            return AdminResponse::success(
                new CommercialProposalTemplateResource(
                    $this->service->storeTemplate($this->organizationId($request), $request->validated())
                ),
                trans_message('commercial_proposals.messages.template_created'),
                201
            );
        } catch (Throwable $e) {
            return $this->failure($request, $e, 'commercial_proposals.errors.template_store');
        }
    }

    public function index(CommercialProposalListRequest $request): JsonResponse
    {
        try {
            $paginator = $this->service->paginate(
                $this->organizationId($request),
                $request->validated(),
                $this->perPage($request)
            );

            return $this->paginated($request, $paginator);
        } catch (Throwable $e) {
            return $this->failure($request, $e, 'commercial_proposals.errors.index');
        }
    }

    public function show(Request $request, string $proposalId): JsonResponse
    {
        try {
            return AdminResponse::success(
                new CommercialProposalResource(
                    $this->service->find($this->organizationId($request), $proposalId, true)
                )
            );
        } catch (Throwable $e) {
            return $this->failure($request, $e, 'commercial_proposals.errors.show');
        }
    }

    public function store(CommercialProposalRequest $request): JsonResponse
    {
        try {
            return AdminResponse::success(
                new CommercialProposalResource(
                    $this->service->create($this->organizationId($request), $request->validated(), $this->actorId($request))
                ),
                trans_message('commercial_proposals.messages.created'),
                201
            );
        } catch (Throwable $e) {
            return $this->failure($request, $e, 'commercial_proposals.errors.store');
        }
    }

    public function updateDraft(CommercialProposalVersionRequest $request, string $proposalId): JsonResponse
    {
        try {
            return AdminResponse::success(
                new CommercialProposalResource(
                    $this->service->updateDraft($this->organizationId($request), $proposalId, $request->validated(), $this->actorId($request))
                ),
                trans_message('commercial_proposals.messages.updated')
            );
        } catch (Throwable $e) {
            return $this->failure($request, $e, 'commercial_proposals.errors.update');
        }
    }

    public function archive(Request $request, string $proposalId): JsonResponse
    {
        try {
            return AdminResponse::success(
                new CommercialProposalResource(
                    $this->service->archive($this->organizationId($request), $proposalId, $this->actorId($request))
                ),
                trans_message('commercial_proposals.messages.archived')
            );
        } catch (Throwable $e) {
            return $this->failure($request, $e, 'commercial_proposals.errors.archive');
        }
    }

    public function createVersion(CommercialProposalVersionRequest $request, string $proposalId): JsonResponse
    {
        try {
            return AdminResponse::success(
                new CommercialProposalResource(
                    $this->service->createVersion($this->organizationId($request), $proposalId, $request->validated(), $this->actorId($request))
                ),
                trans_message('commercial_proposals.messages.version_created'),
                201
            );
        } catch (Throwable $e) {
            return $this->failure($request, $e, 'commercial_proposals.errors.version');
        }
    }

    public function requestApproval(Request $request, string $proposalId): JsonResponse
    {
        try {
            $payload = $request->validate([
                'comment' => ['nullable', 'string', 'max:2000'],
            ]);

            return AdminResponse::success(
                new CommercialProposalResource(
                    $this->service->requestApproval($this->organizationId($request), $proposalId, $payload, $this->actorId($request))
                ),
                trans_message('commercial_proposals.messages.approval_requested')
            );
        } catch (Throwable $e) {
            return $this->failure($request, $e, 'commercial_proposals.errors.approval_request');
        }
    }

    public function decideApproval(CommercialProposalApprovalDecisionRequest $request, string $proposalId): JsonResponse
    {
        try {
            return AdminResponse::success(
                new CommercialProposalResource(
                    $this->service->decideApproval($this->organizationId($request), $proposalId, $request->validated(), $this->actorId($request))
                ),
                trans_message('commercial_proposals.messages.approval_decided')
            );
        } catch (Throwable $e) {
            return $this->failure($request, $e, 'commercial_proposals.errors.approval_decision');
        }
    }

    public function send(CommercialProposalSendRequest $request, string $proposalId): JsonResponse
    {
        try {
            return AdminResponse::success(
                new CommercialProposalResource(
                    $this->service->send(
                        $this->organizationId($request),
                        $proposalId,
                        $request->validated(),
                        $this->actorId($request),
                        $this->canViewAmounts($request)
                    )
                ),
                trans_message('commercial_proposals.messages.sent')
            );
        } catch (Throwable $e) {
            return $this->failure($request, $e, 'commercial_proposals.errors.send');
        }
    }

    public function recordResult(CommercialProposalResultRequest $request, string $proposalId): JsonResponse
    {
        try {
            return AdminResponse::success(
                new CommercialProposalResource(
                    $this->service->recordResult($this->organizationId($request), $proposalId, $request->validated(), $this->actorId($request))
                ),
                trans_message('commercial_proposals.messages.result_recorded')
            );
        } catch (Throwable $e) {
            return $this->failure($request, $e, 'commercial_proposals.errors.result');
        }
    }

    public function createContract(StoreContractRequest $request, string $proposalId): JsonResponse
    {
        try {
            $contract = $request->toDto();
            $result = $this->service->createContract(
                $this->organizationId($request),
                $proposalId,
                $request->user(),
                new ContractDossierCreationInput(
                    contract: $contract,
                    idempotencyKey: 'commercial-proposal:'.hash('sha256', $proposalId.':'.$request->string('idempotency_key')->toString()),
                    documentTitle: $request->validated('document_title') ?? 'Договор №'.$contract->number,
                    profileCode: $request->validated('document_profile_code') ?? 'contract.work',
                    documentMetadata: $request->validated('document_metadata') ?? [],
                    confidentialityLevel: $request->validated('document_confidentiality_level'),
                    sourceLinks: [[
                        'link_type' => 'commercial_proposal',
                        'linked_type' => 'commercial_proposal',
                        'linked_id' => $proposalId,
                    ]],
                    sourceType: 'commercial_proposal',
                    sourceId: $proposalId,
                ),
            );

            return AdminResponse::success(
                new ContractResource($result->contract),
                null,
                $result->replayed ? 200 : 201,
            );
        } catch (Throwable $e) {
            return $this->failure($request, $e, 'commercial_proposals.errors.contract_creation');
        }
    }

    public function preview(Request $request, string $proposalId): JsonResponse
    {
        try {
            return AdminResponse::success(
                $this->service->preview(
                    $this->organizationId($request),
                    $proposalId,
                    is_string($request->query('version_id')) ? $request->query('version_id') : null,
                    $this->canViewAmounts($request)
                )
            );
        } catch (Throwable $e) {
            return $this->failure($request, $e, 'commercial_proposals.errors.preview');
        }
    }

    public function export(CommercialProposalExportRequest $request, string $proposalId): JsonResponse
    {
        try {
            $payload = $this->service->export(
                $this->organizationId($request),
                $proposalId,
                $request->validated(),
                $this->actorId($request),
                $this->canViewAmounts($request)
            );

            return AdminResponse::success([
                'proposal' => (new CommercialProposalResource($payload['proposal']))->resolve($request),
                'version' => (new CommercialProposalVersionResource($payload['version']))->resolve($request),
                'export' => (new CommercialProposalExportResource($payload['export']))->resolve($request),
            ], trans_message('commercial_proposals.messages.export_ready'), 201);
        } catch (Throwable $e) {
            return $this->failure($request, $e, 'commercial_proposals.errors.export');
        }
    }

    public function exportStatus(Request $request, string $proposalId, string $exportId): JsonResponse
    {
        try {
            return AdminResponse::success(
                new CommercialProposalExportResource(
                    $this->service->exportStatus($this->organizationId($request), $proposalId, $exportId)
                )
            );
        } catch (Throwable $e) {
            return $this->failure($request, $e, 'commercial_proposals.errors.export');
        }
    }

    public function uploadFile(UploadCommercialProposalFileRequest $request, string $proposalId): JsonResponse
    {
        try {
            $uploadedFile = $request->file('file');

            if (!$uploadedFile instanceof UploadedFile) {
                throw ValidationException::withMessages([
                    'file' => [trans_message('commercial_proposals.errors.file_upload')],
                ]);
            }

            return AdminResponse::success(
                new CommercialProposalFileResource(
                    $this->service->uploadFile(
                        $this->organizationId($request),
                        $proposalId,
                        $request->validated(),
                        $uploadedFile,
                        $this->actorId($request)
                    )
                ),
                trans_message('commercial_proposals.messages.file_uploaded'),
                201
            );
        } catch (Throwable $e) {
            return $this->failure($request, $e, 'commercial_proposals.errors.file_upload');
        }
    }

    public function deleteFile(Request $request, string $proposalId, string $fileId): JsonResponse
    {
        try {
            $this->service->deleteFile($this->organizationId($request), $proposalId, $fileId, $this->actorId($request));

            return AdminResponse::success(['id' => $fileId], trans_message('commercial_proposals.messages.file_deleted'));
        } catch (Throwable $e) {
            return $this->failure($request, $e, 'commercial_proposals.errors.file_delete');
        }
    }

    private function paginated(Request $request, LengthAwarePaginator $paginator): JsonResponse
    {
        return AdminResponse::paginated(
            CommercialProposalResource::collection($paginator->items()),
            [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
            null,
            200,
            $this->service->summary($this->organizationId($request), $this->canViewAmounts($request))
        );
    }

    private function organizationId(Request $request): int
    {
        return (int) $request->attributes->get('current_organization_id');
    }

    private function actorId(Request $request): ?int
    {
        return $request->user()?->id;
    }

    private function canViewAmounts(Request $request): bool
    {
        $user = $request->user();

        if ($user === null) {
            return false;
        }

        return $user->can('commercial_proposals.amounts.view', [
            'organization_id' => $this->organizationId($request),
        ]);
    }

    private function perPage(Request $request): int
    {
        return min(max((int) $request->input('per_page', 20), 1), 100);
    }

    private function failure(Request $request, Throwable $e, string $translationKey): JsonResponse
    {
        if ($e instanceof ValidationException) {
            return AdminResponse::error($this->validationMessage($e, $translationKey), 422, $e->errors());
        }

        if ($e instanceof CommercialProposalWorkflowException) {
            return AdminResponse::error(
                $e->getMessage() !== '' ? $e->getMessage() : trans_message('commercial_proposals.errors.workflow_conflict'),
                409,
                null,
                ['meta' => ['blockers' => $e->blockers()]]
            );
        }

        if ($e instanceof ModelNotFoundException) {
            return AdminResponse::error(trans_message('commercial_proposals.errors.not_found'), 404);
        }

        Log::error($translationKey, [
            'user_id' => $request->user()?->id,
            'organization_id' => $this->organizationId($request),
            'message' => $e->getMessage(),
        ]);

        return AdminResponse::error(trans_message($translationKey), 500);
    }

    private function validationMessage(ValidationException $e, string $translationKey): string
    {
        foreach ($e->errors() as $messages) {
            if (is_array($messages) && isset($messages[0]) && is_string($messages[0])) {
                return $messages[0];
            }
        }

        return trans_message($translationKey);
    }
}
