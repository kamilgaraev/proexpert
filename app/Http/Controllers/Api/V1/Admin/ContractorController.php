<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Exceptions\BusinessLogicException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Contractor\StoreContractorRequest;
use App\Http\Requests\Api\V1\Admin\Contractor\UpdateContractorRequest;
use App\Http\Resources\Api\V1\Admin\Contractor\ContractorResource;
use App\Http\Responses\AdminResponse;
use App\Services\Contractor\ContractorService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class ContractorController extends Controller
{
    private const SORTABLE_FIELDS = ['id', 'name', 'inn', 'created_at', 'updated_at'];
    private const DEFAULT_SORT_FIELD = 'name';
    private const DEFAULT_SORT_DIRECTION = 'asc';
    private const DEFAULT_PER_PAGE = 15;
    private const LOAD_ALL_PER_PAGE = 1000;
    private const MAX_PER_PAGE = 1000;

    public function __construct(private readonly ContractorService $contractorService)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $organizationId = $this->resolveOrganizationId($request);

        if (!$organizationId) {
            return AdminResponse::error(trans_message('contract.organization_context_missing'), Response::HTTP_BAD_REQUEST);
        }

        $contractors = $this->contractorService->getAllContractors(
            $organizationId,
            $this->normalizePerPage($request->input('per_page')),
            $request->only(['name', 'inn']),
            $this->normalizeSortField($request->input('sort_by')),
            $this->normalizeSortDirection($request->input('sort_direction'))
        );

        return AdminResponse::paginated(
            ContractorResource::collection($contractors->getCollection()),
            [
                'current_page' => $contractors->currentPage(),
                'last_page' => $contractors->lastPage(),
                'per_page' => $contractors->perPage(),
                'total' => $contractors->total(),
            ],
            null,
            Response::HTTP_OK,
            null,
            [
                'first' => $contractors->url(1),
                'last' => $contractors->url($contractors->lastPage()),
                'prev' => $contractors->previousPageUrl(),
                'next' => $contractors->nextPageUrl(),
            ]
        );
    }

    public function store(StoreContractorRequest $request): JsonResponse
    {
        $organizationId = $this->resolveOrganizationId($request);

        if (!$organizationId) {
            return AdminResponse::error(trans_message('contract.organization_context_missing'), Response::HTTP_BAD_REQUEST);
        }

        try {
            $contractor = $this->contractorService->createContractor($organizationId, $request->toDto());

            return AdminResponse::success(new ContractorResource($contractor), null, Response::HTTP_CREATED);
        } catch (BusinessLogicException $exception) {
            return AdminResponse::error($exception->getMessage(), $exception->getCode());
        } catch (Exception $exception) {
            $this->logUnexpectedException($request, 'contractor_create_failed', $exception);

            return AdminResponse::error(
                trans_message('contract.contractor_create_error'),
                Response::HTTP_BAD_REQUEST,
                $exception->getMessage()
            );
        }
    }

    public function show(int $contractorId, Request $request): JsonResponse
    {
        $organizationId = $this->resolveOrganizationId($request);

        if (!$organizationId) {
            return AdminResponse::error(trans_message('contract.organization_context_missing'), Response::HTTP_BAD_REQUEST);
        }

        $contractor = $this->contractorService->getContractorById($contractorId, $organizationId);

        if (!$contractor) {
            return AdminResponse::error(trans_message('contract.contractor_not_found'), Response::HTTP_NOT_FOUND);
        }

        return AdminResponse::success(new ContractorResource($contractor));
    }

    public function update(UpdateContractorRequest $request, int $contractorId): JsonResponse
    {
        $organizationId = $this->resolveOrganizationId($request);

        if (!$organizationId) {
            return AdminResponse::error(trans_message('contract.organization_context_missing'), Response::HTTP_BAD_REQUEST);
        }

        try {
            $contractor = $this->contractorService->updateContractor($contractorId, $organizationId, $request->toDto());

            return AdminResponse::success(new ContractorResource($contractor));
        } catch (BusinessLogicException $exception) {
            return AdminResponse::error($exception->getMessage(), $exception->getCode());
        } catch (Exception $exception) {
            $this->logUnexpectedException($request, 'contractor_update_failed', $exception);

            return AdminResponse::error(
                trans_message('contract.contractor_update_error'),
                Response::HTTP_BAD_REQUEST,
                $exception->getMessage()
            );
        }
    }

    public function destroy(int $contractorId, Request $request): JsonResponse
    {
        $organizationId = $this->resolveOrganizationId($request);

        if (!$organizationId) {
            return AdminResponse::error(trans_message('contract.organization_context_missing'), Response::HTTP_BAD_REQUEST);
        }

        try {
            $this->contractorService->deleteContractor($contractorId, $organizationId);

            return AdminResponse::success(null, trans_message('contract.contractor_deleted'));
        } catch (BusinessLogicException $exception) {
            return AdminResponse::error($exception->getMessage(), $exception->getCode());
        } catch (Exception $exception) {
            $this->logUnexpectedException($request, 'contractor_delete_failed', $exception);

            return AdminResponse::error(
                trans_message('contract.contractor_delete_error'),
                Response::HTTP_BAD_REQUEST,
                $exception->getMessage()
            );
        }
    }

    private function resolveOrganizationId(Request $request): ?int
    {
        $organizationId = $request->attributes->get('current_organization_id') ?? $request->user()?->current_organization_id;

        return $organizationId !== null ? (int) $organizationId : null;
    }

    private function normalizeSortField(mixed $sortField): string
    {
        $sortField = is_string($sortField) ? $sortField : self::DEFAULT_SORT_FIELD;

        return in_array($sortField, self::SORTABLE_FIELDS, true) ? $sortField : self::DEFAULT_SORT_FIELD;
    }

    private function normalizeSortDirection(mixed $sortDirection): string
    {
        $sortDirection = is_string($sortDirection) ? strtolower($sortDirection) : self::DEFAULT_SORT_DIRECTION;

        return in_array($sortDirection, ['asc', 'desc'], true) ? $sortDirection : self::DEFAULT_SORT_DIRECTION;
    }

    private function normalizePerPage(mixed $perPage): int
    {
        $perPage = filter_var($perPage, FILTER_VALIDATE_INT);

        if ($perPage === false) {
            return self::DEFAULT_PER_PAGE;
        }

        if ($perPage <= 0) {
            return self::LOAD_ALL_PER_PAGE;
        }

        return min($perPage, self::MAX_PER_PAGE);
    }

    private function logUnexpectedException(Request $request, string $message, Exception $exception): void
    {
        Log::error($message, [
            'user_id' => $request->user()?->id,
            'organization_id' => $this->resolveOrganizationId($request),
            'params' => $request->all(),
            'exception' => $exception,
        ]);
    }
}
