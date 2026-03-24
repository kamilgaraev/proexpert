<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Agreement\StoreSupplementaryAgreementRequest;
use App\Http\Requests\Api\V1\Admin\Agreement\UpdateSupplementaryAgreementRequest;
use App\Http\Resources\Api\V1\Admin\Contract\Agreement\SupplementaryAgreementResource;
use App\Http\Responses\AdminResponse;
use App\Services\Contract\ContractService;
use App\Services\Contract\SupplementaryAgreementService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Throwable;

use function trans_message;

class AgreementController extends Controller
{
    public function __construct(
        private readonly SupplementaryAgreementService $service,
        private readonly ContractService $contractService
    ) {}

    private function validateAgreementAccess(Request $request, mixed $agreement): array
    {
        $projectId = $this->resolveProjectId($request);
        $organizationId = (int) ($request->user()?->current_organization_id ?? 0);

        if (!$agreement) {
            return ['valid' => false, 'message' => trans_message('agreements.not_found')];
        }

        if (!$agreement->relationLoaded('contract')) {
            $agreement->load('contract');
        }

        $contract = $agreement->contract;
        if (!$contract) {
            return ['valid' => false, 'message' => trans_message('agreements.contract_not_found')];
        }

        $accessibleContract = $this->contractService->getContractById((int) $contract->id, $organizationId, $projectId);
        if (!$accessibleContract) {
            return ['valid' => false, 'message' => trans_message('agreements.access_denied')];
        }

        if ($projectId !== null) {
            $belongsToProject = false;

            if ($contract->is_multi_project) {
                $belongsToProject = $contract->projects()->where('projects.id', $projectId)->exists();
            } else {
                $belongsToProject = (int) $contract->project_id === $projectId;
            }

            if (!$belongsToProject) {
                return ['valid' => false, 'message' => trans_message('agreements.contract_not_in_project')];
            }
        }

        return ['valid' => true];
    }

    public function index(Request $request, ?int $project = null, ?int $contract = null): JsonResponse
    {
        $organizationId = (int) ($request->user()?->current_organization_id ?? 0);
        $projectId = $project ?? $this->resolveProjectId($request);
        $contractId = $contract ?? ($request->route('contract') ? (int) $request->route('contract') : null);
        $perPage = max(1, (int) $request->integer('per_page', 15));
        $sortBy = (string) $request->query('sort_by', 'agreement_date');
        $sortDirection = (string) $request->query('sort_direction', 'desc');
        $filters = $this->extractFilters($request);

        try {
            if ($contractId !== null) {
                $contractModel = $this->contractService->getContractById($contractId, $organizationId, $projectId);
                if (!$contractModel) {
                    return AdminResponse::error(trans_message('agreements.contract_not_found_or_denied'), Response::HTTP_NOT_FOUND);
                }

                $paginator = $this->service->paginateByContract($contractId, $perPage, $filters, $sortBy, $sortDirection);

                return AdminResponse::paginated(
                    SupplementaryAgreementResource::collection($paginator->getCollection())->resolve(),
                    $this->buildPaginationMeta($paginator)
                );
            }

            if ($projectId !== null) {
                $paginator = $this->service->paginateByProject($projectId, $organizationId, $perPage, $filters, $sortBy, $sortDirection);

                return AdminResponse::paginated(
                    SupplementaryAgreementResource::collection($paginator->getCollection())->resolve(),
                    $this->buildPaginationMeta($paginator)
                );
            }

            $paginator = $this->service->paginate($perPage, $filters, $sortBy, $sortDirection);

            return AdminResponse::paginated(
                SupplementaryAgreementResource::collection($paginator->getCollection())->resolve(),
                $this->buildPaginationMeta($paginator)
            );
        } catch (Throwable $e) {
            Log::error('agreements.index.failed', [
                'user_id' => $request->user()?->id,
                'organization_id' => $organizationId,
                'project_id' => $projectId,
                'contract_id' => $contractId,
                'filters' => $filters,
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('agreements.load_error'), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function store(StoreSupplementaryAgreementRequest $request, int $project): JsonResponse
    {
        try {
            $organizationId = (int) ($request->user()?->current_organization_id ?? 0);
            $contractId = (int) $request->validated('contract_id');
            $contract = $this->contractService->getContractById($contractId, $organizationId, $project);

            if (!$contract) {
                return AdminResponse::error(trans_message('agreements.contract_not_found_or_denied'), Response::HTTP_NOT_FOUND);
            }

            $agreement = $this->service->create($request->toDto());

            return AdminResponse::success(
                new SupplementaryAgreementResource($agreement),
                trans_message('agreements.created'),
                Response::HTTP_CREATED
            );
        } catch (Throwable $e) {
            Log::error('agreements.store.failed', [
                'user_id' => $request->user()?->id,
                'project_id' => $project,
                'contract_id' => $request->validated('contract_id'),
                'payload' => $request->validated(),
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('agreements.create_error'), Response::HTTP_BAD_REQUEST);
        }
    }

    public function show(Request $request, int $project, int $agreement): JsonResponse
    {
        try {
            $agreementModel = $this->service->getById($agreement);
            $validation = $this->validateAgreementAccess($request, $agreementModel);

            if (!$validation['valid']) {
                return AdminResponse::error($validation['message'], Response::HTTP_NOT_FOUND);
            }

            return AdminResponse::success(new SupplementaryAgreementResource($agreementModel));
        } catch (Throwable $e) {
            Log::error('agreements.show.failed', [
                'user_id' => $request->user()?->id,
                'project_id' => $project,
                'agreement_id' => $agreement,
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('agreements.load_error'), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function update(UpdateSupplementaryAgreementRequest $request, int $project, int $agreement): JsonResponse
    {
        try {
            $agreementModel = $this->service->getById($agreement);
            $validation = $this->validateAgreementAccess($request, $agreementModel);

            if (!$validation['valid']) {
                return AdminResponse::error($validation['message'], Response::HTTP_NOT_FOUND);
            }

            $dto = $request->toDto((int) $agreementModel->contract_id);
            $updated = $this->service->update($agreement, $dto);

            if (!$updated) {
                return AdminResponse::error(trans_message('agreements.not_found'), Response::HTTP_NOT_FOUND);
            }

            return AdminResponse::success(
                new SupplementaryAgreementResource($this->service->getById($agreement)),
                trans_message('agreements.updated')
            );
        } catch (Throwable $e) {
            Log::error('agreements.update.failed', [
                'user_id' => $request->user()?->id,
                'project_id' => $project,
                'agreement_id' => $agreement,
                'payload' => $request->validated(),
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('agreements.update_error'), Response::HTTP_BAD_REQUEST);
        }
    }

    public function destroy(Request $request, int $project, int $agreement): JsonResponse
    {
        try {
            $agreementModel = $this->service->getById($agreement);
            $validation = $this->validateAgreementAccess($request, $agreementModel);

            if (!$validation['valid']) {
                return AdminResponse::error($validation['message'], Response::HTTP_NOT_FOUND);
            }

            $deleted = $this->service->delete($agreement);
            if (!$deleted) {
                return AdminResponse::error(trans_message('agreements.not_found'), Response::HTTP_NOT_FOUND);
            }

            return AdminResponse::success(null, trans_message('agreements.deleted'));
        } catch (Throwable $e) {
            Log::error('agreements.destroy.failed', [
                'user_id' => $request->user()?->id,
                'project_id' => $project,
                'agreement_id' => $agreement,
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('agreements.delete_error'), Response::HTTP_BAD_REQUEST);
        }
    }

    public function applyChanges(Request $request, int $project, int $agreement): JsonResponse
    {
        try {
            $agreementModel = $this->service->getById($agreement);
            $validation = $this->validateAgreementAccess($request, $agreementModel);

            if (!$validation['valid']) {
                return AdminResponse::error($validation['message'], Response::HTTP_NOT_FOUND);
            }

            $this->service->applyChangesToContract($agreement);

            return AdminResponse::success(
                ['message' => trans_message('agreements.applied')],
                trans_message('agreements.applied')
            );
        } catch (Throwable $e) {
            Log::error('agreements.apply_changes.failed', [
                'user_id' => $request->user()?->id,
                'project_id' => $project,
                'agreement_id' => $agreement,
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('agreements.apply_error'), Response::HTTP_BAD_REQUEST, [
                'details' => [$e->getMessage()],
            ]);
        }
    }

    private function extractFilters(Request $request): array
    {
        $filters = [
            'contract_id' => $request->query('contract_id'),
            'number' => $request->query('number'),
            'date_from' => $request->query('date_from'),
            'date_to' => $request->query('date_to'),
        ];

        return array_filter(
            $filters,
            static fn (mixed $value): bool => $value !== null && $value !== ''
        );
    }

    private function resolveProjectId(Request $request): ?int
    {
        if ($request->route('project')) {
            return (int) $request->route('project');
        }

        if ($request->query('project_id')) {
            return (int) $request->query('project_id');
        }

        return null;
    }

    private function buildPaginationMeta(LengthAwarePaginator $paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'from' => $paginator->firstItem(),
            'last_page' => $paginator->lastPage(),
            'path' => $paginator->path(),
            'per_page' => $paginator->perPage(),
            'to' => $paginator->lastItem(),
            'total' => $paginator->total(),
            'links' => collect($paginator->linkCollection()->toArray())
                ->map(static fn (array $link): array => [
                    'url' => $link['url'] ?? null,
                    'label' => $link['label'] ?? '',
                    'active' => (bool) ($link['active'] ?? false),
                ])
                ->values()
                ->all(),
        ];
    }
}
