<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\BusinessModules\Features\BudgetEstimates\Services\Export\OfficialFormsExportService;
use App\DTOs\Contract\ContractDossierCreationInput;
use App\Exceptions\BusinessLogicException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Contract\AttachToParentContractRequest;
use App\Http\Requests\Api\V1\Admin\Contract\DetachFromParentContractRequest;
use App\Http\Requests\Api\V1\Admin\Contract\ExportKS6aRequest;
use App\Http\Requests\Api\V1\Admin\Contract\ResolveContractSideReviewRequest;
use App\Http\Requests\Api\V1\Admin\Contract\StoreContractRequest;
use App\Http\Requests\Api\V1\Admin\Contract\TransitionContractRequest;
use App\Http\Requests\Api\V1\Admin\Contract\UpdateContractRequest;
use App\Http\Resources\Api\V1\Admin\Contract\ContractCollection;
use App\Http\Resources\Api\V1\Admin\Contract\ContractResource;
use App\Http\Responses\AdminResponse;
use App\Services\Contract\ContractAccessService;
use App\Services\Contract\ContractDossierCreationService;
use App\Services\Contract\ContractLifecycleService;
use App\Services\Contract\ContractReadService;
use App\Services\Contract\ContractService;
use Exception;
use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class ContractController extends Controller
{
    public function __construct(
        private readonly ContractService $contractService,
        private readonly OfficialFormsExportService $exportService,
        private readonly ContractLifecycleService $contractLifecycleService,
        private readonly ?ContractDossierCreationService $contractDossierCreationService = null,
        private readonly ?ContractAccessService $contractAccessService = null,
        private readonly ?ContractReadService $contractReadService = null,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $organizationId = $this->organizationId($request);

        if ($organizationId === null || $user === null) {
            return AdminResponse::error(trans_message('contract.organization_context_missing'), Response::HTTP_BAD_REQUEST);
        }

        try {
            $projectId = $this->routeInt($request, 'project');
            $filters = $this->contractFilters($request);

            if ($projectId !== null) {
                $filters['project_id'] = $projectId;
            }

            $filters['related_party_organization_id'] = $organizationId;

            $contracts = $this->contractService->getAllContracts(
                $organizationId,
                (int) $request->input('per_page', 15),
                $filters,
                (string) $request->input('sort_by', 'created_at'),
                (string) $request->input('sort_direction', 'desc')
            );
            $summary = $this->contractService->getContractsSummary($organizationId, $filters);

            return AdminResponse::success((new ContractCollection($contracts))->additional(['summary' => $summary]));
        } catch (Exception $exception) {
            $this->logFailure('contract.index.failed', $request, $exception, [
                'organization_id' => $organizationId,
                'user_id' => $user->id,
            ]);

            return AdminResponse::error(trans_message('contract.list_error'), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function store(StoreContractRequest $request): JsonResponse
    {
        $user = $request->user();
        $organizationId = $this->organizationId($request);

        if ($organizationId === null || $user === null) {
            return AdminResponse::error(trans_message('contract.organization_context_missing'), Response::HTTP_BAD_REQUEST);
        }

        try {
            $contractDTO = $request->toDto();
            $result = $this->contractDossierCreationService()->create(
                $organizationId,
                $user,
                new ContractDossierCreationInput(
                    $contractDTO,
                    $request->validated('idempotency_key'),
                    $request->validated('document_title') ?? 'Договор №'.$contractDTO->number,
                    $request->validated('document_profile_code') ?? $this->documentProfileCode($contractDTO),
                    $request->validated('document_metadata') ?? [],
                    $request->validated('document_confidentiality_level'),
                ),
            );

            return AdminResponse::success(
                new ContractResource($result->contract),
                null,
                $result->replayed ? Response::HTTP_OK : Response::HTTP_CREATED
            );
        } catch (QueryException $exception) {
            $this->logFailure('contract.store.query_failed', $request, $exception, [
                'organization_id' => $organizationId,
                'user_id' => $user->id,
            ]);

            return AdminResponse::error(trans_message('contract.create_error'), Response::HTTP_INTERNAL_SERVER_ERROR);
        } catch (Exception $exception) {
            $this->logFailure('contract.store.failed', $request, $exception, [
                'organization_id' => $organizationId,
                'user_id' => $user->id,
            ]);

            return AdminResponse::error(trans_message('contract.create_error'), Response::HTTP_BAD_REQUEST);
        }
    }

    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $organizationId = $this->organizationId($request);
        $contractId = $this->routeInt($request, 'contract');
        $projectId = $this->routeInt($request, 'project');

        if ($organizationId === null || $user === null || $contractId === null) {
            return AdminResponse::error(trans_message('contract.organization_context_missing'), Response::HTTP_BAD_REQUEST);
        }

        try {
            return AdminResponse::success(new ContractResource(
                $this->contractReadService()->show($contractId, $organizationId, $projectId)
            ));
        } catch (ModelNotFoundException $exception) {
            $this->logFailure('contract.show.not_found', $request, $exception, [
                'contract_id' => $contractId,
                'project_id' => $projectId,
                'organization_id' => $organizationId,
                'user_id' => $user->id,
            ], 'warning');

            return AdminResponse::error(trans_message('contract.contract_not_found'), Response::HTTP_NOT_FOUND);
        } catch (Exception $exception) {
            $this->logFailure('contract.show.failed', $request, $exception, [
                'contract_id' => $contractId,
                'project_id' => $projectId,
                'organization_id' => $organizationId,
                'user_id' => $user->id,
            ]);

            return AdminResponse::error(trans_message('contract.read_error'), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function update(UpdateContractRequest $request, int $contract): JsonResponse
    {
        $user = $request->user();
        $organizationId = $this->organizationId($request);
        $contractId = $this->routeInt($request, 'contract') ?? $contract;
        $projectId = $this->routeInt($request, 'project');

        if ($organizationId === null || $user === null) {
            return AdminResponse::error(trans_message('contract.organization_context_missing'), Response::HTTP_BAD_REQUEST);
        }

        try {
            $accessibleContract = $this->contractAccessService()->findAccessibleOrFail($contractId, $organizationId, $projectId);
            $updatedContract = $this->contractService->updateContract(
                $contractId,
                (int) $accessibleContract->organization_id,
                $request->toDto()
            );

            return AdminResponse::success(new ContractResource($updatedContract));
        } catch (ModelNotFoundException $exception) {
            $this->logFailure('contract.update.not_found', $request, $exception, [
                'contract_id' => $contractId,
                'project_id' => $projectId,
                'organization_id' => $organizationId,
                'user_id' => $user->id,
            ], 'warning');

            return AdminResponse::error(trans_message('contract.contract_not_found'), Response::HTTP_NOT_FOUND);
        } catch (InvalidArgumentException $exception) {
            $this->logFailure('contract.update.invalid', $request, $exception, [
                'contract_id' => $contractId,
                'organization_id' => $organizationId,
                'user_id' => $user->id,
            ], 'warning');

            return AdminResponse::error(trans_message('contract.invalid_data'), Response::HTTP_BAD_REQUEST);
        } catch (Exception $exception) {
            $this->logFailure('contract.update.failed', $request, $exception, [
                'contract_id' => $contractId,
                'project_id' => $projectId,
                'organization_id' => $organizationId,
                'user_id' => $user->id,
            ]);

            return AdminResponse::error(trans_message('contract.update_error'), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function resolveSideReview(ResolveContractSideReviewRequest $request, int $contract): JsonResponse
    {
        $user = $request->user();
        $organizationId = $this->organizationId($request);

        if ($organizationId === null || $user === null) {
            return AdminResponse::error(trans_message('contract.organization_context_missing'), Response::HTTP_BAD_REQUEST);
        }

        try {
            $resolvedContract = $this->contractService->resolveSideReview(
                $contract,
                $organizationId,
                $request->contractSideType()
            );

            return AdminResponse::success(
                new ContractResource($resolvedContract),
                trans_message('contract.review_resolved')
            );
        } catch (Exception $exception) {
            $this->logFailure('contract.resolve_side_review.failed', $request, $exception, [
                'contract_id' => $contract,
                'organization_id' => $organizationId,
                'user_id' => $user->id,
            ]);

            return AdminResponse::error(trans_message('contract.review_resolve_error'), Response::HTTP_BAD_REQUEST);
        }
    }

    public function transition(TransitionContractRequest $request): JsonResponse
    {
        $user = $request->user();
        $organizationId = $this->organizationId($request);
        $projectId = $this->routeInt($request, 'project');
        $contractId = $this->routeInt($request, 'contract');
        $action = (string) $request->route('action');

        if ($organizationId === null || $user === null || $contractId === null) {
            return AdminResponse::error(trans_message('contract.organization_context_missing'), Response::HTTP_BAD_REQUEST);
        }

        try {
            $contract = $this->contractService->getContractById($contractId, $organizationId, $projectId);

            if ($contract === null) {
                throw (new ModelNotFoundException())->setModel(\App\Models\Contract::class, [$contractId]);
            }

            $transitionedContract = $this->contractLifecycleService->transition(
                $contract,
                $action,
                $user,
                $request->reason()
            );

            return AdminResponse::success(
                new ContractResource($transitionedContract),
                $action === 'archive'
                    ? trans_message('contracts.archived')
                    : trans_message('contracts.transitioned')
            );
        } catch (ModelNotFoundException $exception) {
            $this->logFailure('contract.transition.not_found', $request, $exception, [
                'contract_id' => $contractId,
                'project_id' => $projectId,
                'organization_id' => $organizationId,
                'user_id' => $user->id,
                'action' => $action,
            ], 'warning');

            return AdminResponse::error(trans_message('contract.contract_not_found'), Response::HTTP_NOT_FOUND);
        } catch (BusinessLogicException $exception) {
            return AdminResponse::error($exception->getMessage(), $exception->getCode());
        } catch (Exception $exception) {
            $this->logFailure('contract.transition.failed', $request, $exception, [
                'contract_id' => $contractId,
                'project_id' => $projectId,
                'organization_id' => $organizationId,
                'user_id' => $user->id,
                'action' => $action,
            ]);

            return AdminResponse::error(trans_message('contracts.transition_error'), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function destroy(int $contract, Request $request): JsonResponse
    {
        return AdminResponse::error(
            trans_message('contracts.archive_instead_of_delete'),
            Response::HTTP_CONFLICT
        );
    }

    public function destroyForProject(int $project, int $contract, Request $request): JsonResponse
    {
        return $this->destroy($contract, $request);
    }

    public function analytics(int $contract, Request $request): JsonResponse
    {
        $user = $request->user();
        $organizationId = $this->organizationId($request);
        $projectId = $this->routeInt($request, 'project');

        if ($organizationId === null || $user === null) {
            return AdminResponse::error(trans_message('contract.organization_context_missing'), Response::HTTP_BAD_REQUEST);
        }

        try {
            return AdminResponse::success($this->contractReadService()->analytics($contract, $organizationId, $projectId));
        } catch (ModelNotFoundException $exception) {
            $this->logFailure('contract.analytics.not_found', $request, $exception, [
                'contract_id' => $contract,
                'project_id' => $projectId,
                'organization_id' => $organizationId,
                'user_id' => $user->id,
            ], 'warning');

            return AdminResponse::error(trans_message('contract.contract_not_found'), Response::HTTP_NOT_FOUND);
        } catch (Exception $exception) {
            $this->logFailure('contract.analytics.failed', $request, $exception, [
                'contract_id' => $contract,
                'project_id' => $projectId,
                'organization_id' => $organizationId,
                'user_id' => $user->id,
            ]);

            return AdminResponse::error(trans_message('contract.read_error'), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function completedWorks(int $contract, Request $request): JsonResponse
    {
        $user = $request->user();
        $organizationId = $this->organizationId($request);
        $projectId = $this->routeInt($request, 'project');

        if ($organizationId === null || $user === null) {
            return AdminResponse::error(trans_message('contract.organization_context_missing'), Response::HTTP_BAD_REQUEST);
        }

        try {
            return AdminResponse::success($this->contractReadService()->completedWorks(
                $contract,
                $organizationId,
                $projectId,
                (int) $request->query('per_page', 15)
            ));
        } catch (ModelNotFoundException $exception) {
            $this->logFailure('contract.completed_works.not_found', $request, $exception, [
                'contract_id' => $contract,
                'project_id' => $projectId,
                'organization_id' => $organizationId,
                'user_id' => $user->id,
            ], 'warning');

            return AdminResponse::error(trans_message('contract.contract_not_found'), Response::HTTP_NOT_FOUND);
        } catch (Exception $exception) {
            $this->logFailure('contract.completed_works.failed', $request, $exception, [
                'contract_id' => $contract,
                'project_id' => $projectId,
                'organization_id' => $organizationId,
                'user_id' => $user->id,
            ]);

            return AdminResponse::error(trans_message('contract.read_error'), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function fullDetails(int $contract, Request $request): JsonResponse
    {
        $user = $request->user();
        $organizationId = $this->organizationId($request);
        $projectId = $this->routeInt($request, 'project');

        if ($organizationId === null || $user === null) {
            return AdminResponse::error(trans_message('contract.organization_context_missing'), Response::HTTP_BAD_REQUEST);
        }

        try {
            return AdminResponse::success($this->contractReadService()->fullDetails($contract, $organizationId, $projectId));
        } catch (ModelNotFoundException $exception) {
            $this->logFailure('contract.full_details.not_found', $request, $exception, [
                'contract_id' => $contract,
                'project_id' => $projectId,
                'organization_id' => $organizationId,
                'user_id' => $user->id,
            ], 'warning');

            return AdminResponse::error(trans_message('contract.contract_not_found'), Response::HTTP_NOT_FOUND);
        } catch (Exception $exception) {
            $this->logFailure('contract.full_details.failed', $request, $exception, [
                'contract_id' => $contract,
                'project_id' => $projectId,
                'organization_id' => $organizationId,
                'user_id' => $user->id,
            ]);

            return AdminResponse::error(trans_message('contract.read_error'), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function exportKS6a(int $contract, ExportKS6aRequest $request): JsonResponse
    {
        $user = $request->user();
        $organizationId = $this->organizationId($request);
        $projectId = $this->routeInt($request, 'project');

        if ($organizationId === null || $user === null) {
            return AdminResponse::error(trans_message('contract.organization_context_missing'), Response::HTTP_BAD_REQUEST);
        }

        try {
            $accessibleContract = $this->contractAccessService()->findAccessibleOrFail($contract, $organizationId, $projectId);
            $path = $request->exportFormat() === 'xlsx'
                ? $this->exportService->exportKS6aToExcel($accessibleContract)
                : $this->exportService->exportKS6aToPdf($accessibleContract);
            $url = $this->exportService->getFileService()->temporaryUrl($path, 15);

            return AdminResponse::success(['url' => $url], trans_message('contract.file_generated'));
        } catch (ModelNotFoundException $exception) {
            $this->logFailure('contract.export_ks6a.not_found', $request, $exception, [
                'contract_id' => $contract,
                'project_id' => $projectId,
                'organization_id' => $organizationId,
                'user_id' => $user->id,
            ], 'warning');

            return AdminResponse::error(trans_message('contract.contract_not_found'), Response::HTTP_NOT_FOUND);
        } catch (Exception $exception) {
            $this->logFailure('contract.export_ks6a.failed', $request, $exception, [
                'contract_id' => $contract,
                'project_id' => $projectId,
                'organization_id' => $organizationId,
                'user_id' => $user->id,
            ]);

            return AdminResponse::error(trans_message('contract.export_error'), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function attachToParent(AttachToParentContractRequest $request, int $contract): JsonResponse
    {
        $user = $request->user();
        $organizationId = $this->organizationId($request);

        if ($organizationId === null || $user === null) {
            return AdminResponse::error(trans_message('contract.organization_context_missing'), Response::HTTP_BAD_REQUEST);
        }

        try {
            $updatedContract = $this->contractService->attachToParentContract(
                $contract,
                $organizationId,
                (int) $request->input('parent_contract_id')
            );

            return AdminResponse::success(
                new ContractResource($updatedContract),
                trans_message('contract.attached_to_parent')
            );
        } catch (Exception $exception) {
            $this->logFailure('contract.attach_parent.failed', $request, $exception, [
                'contract_id' => $contract,
                'organization_id' => $organizationId,
                'user_id' => $user->id,
            ]);

            return AdminResponse::error(trans_message('contract.attach_error'), Response::HTTP_BAD_REQUEST);
        }
    }

    public function detachFromParent(DetachFromParentContractRequest $request, int $contract): JsonResponse
    {
        $user = $request->user();
        $organizationId = $this->organizationId($request);

        if ($organizationId === null || $user === null) {
            return AdminResponse::error(trans_message('contract.organization_context_missing'), Response::HTTP_BAD_REQUEST);
        }

        try {
            $updatedContract = $this->contractService->detachFromParentContract($contract, $organizationId);

            return AdminResponse::success(
                new ContractResource($updatedContract),
                trans_message('contract.detached_from_parent')
            );
        } catch (Exception $exception) {
            $this->logFailure('contract.detach_parent.failed', $request, $exception, [
                'contract_id' => $contract,
                'organization_id' => $organizationId,
                'user_id' => $user->id,
            ]);

            return AdminResponse::error(trans_message('contract.detach_error'), Response::HTTP_BAD_REQUEST);
        }
    }

    private function documentProfileCode(\App\DTOs\Contract\ContractDTO $contract): string
    {
        return $contract->supplier_id !== null ? 'contract.supply' : 'contract.work';
    }

    private function contractAccessService(): ContractAccessService
    {
        return $this->contractAccessService ?? app(ContractAccessService::class);
    }

    private function contractDossierCreationService(): ContractDossierCreationService
    {
        return $this->contractDossierCreationService ?? app(ContractDossierCreationService::class);
    }

    private function contractReadService(): ContractReadService
    {
        return $this->contractReadService ?? app(ContractReadService::class);
    }

    private function organizationId(Request $request): ?int
    {
        $this->ensureRequestBound($request);

        $organization = $request->attributes->get('current_organization');
        $organizationId = $organization?->id
            ?? $request->attributes->get('current_organization_id')
            ?? $request->user()?->current_organization_id;

        return $organizationId !== null ? (int) $organizationId : null;
    }

    private function ensureRequestBound(Request $request): void
    {
        $container = Container::getInstance();

        if (! $container->bound('request')) {
            $container->instance('request', $request);
        }
    }

    private function routeInt(Request $request, string $key): ?int
    {
        $value = $request->route($key);

        if ($value instanceof Model) {
            return (int) $value->getKey();
        }

        return $value !== null && is_numeric($value) ? (int) $value : null;
    }

    private function contractFilters(Request $request): array
    {
        $filters = $request->only([
            'contractor_id',
            'status',
            'type',
            'number',
            'date_from',
            'date_to',
            'start_date_from',
            'start_date_to',
            'end_date_from',
            'end_date_to',
            'completion_from',
            'completion_to',
            'amount_from',
            'amount_to',
            'gp_percentage_from',
            'gp_percentage_to',
            'work_type_category',
            'has_advance',
            'advance_paid_status',
            'has_parent',
            'has_children',
            'requiring_attention',
            'is_nearing_limit',
            'is_overdue',
            'search',
            'contractor_search',
            'project_search',
            'contract_side_type',
        ]);

        if ($request->has('requires_contract_side_review')) {
            $filters['requires_contract_side_review'] = $request->boolean('requires_contract_side_review');
        }

        return $filters;
    }

    private function logFailure(
        string $event,
        Request $request,
        Exception $exception,
        array $context = [],
        string $level = 'error'
    ): void {
        $previous = $exception->getPrevious();

        if (! app()->bound('log')) {
            return;
        }

        Log::log($level, $event, $context + [
            'url' => $request->url(),
            'route_params' => $request->route()?->parameters() ?? [],
            'error' => $exception->getMessage(),
            'exception' => $exception::class,
            'previous_exception' => $previous !== null ? $previous::class : null,
            'code' => $exception->getCode(),
        ]);
    }
}
