<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Exceptions\BusinessLogicException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Counterparty\SearchCounterpartyRequest;
use App\Http\Requests\Api\V1\Admin\Counterparty\StoreCounterpartyRequest;
use App\Http\Requests\Api\V1\Admin\Counterparty\UpdateCounterpartyRequest;
use App\Http\Resources\Api\V1\Admin\Counterparty\CounterpartyResource;
use App\Http\Responses\AdminResponse;
use App\Services\Counterparty\CounterpartyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Throwable;

class CounterpartyController extends Controller
{
    private const DEFAULT_PER_PAGE = 15;
    private const LOAD_ALL_PER_PAGE = 1000;
    private const MAX_PER_PAGE = 1000;

    public function __construct(private readonly CounterpartyService $counterpartyService)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $organizationId = $this->resolveOrganizationId($request);

        if (!$organizationId) {
            return AdminResponse::error(trans_message('counterparty.organization_context_missing'), Response::HTTP_BAD_REQUEST);
        }

        try {
            $counterparties = $this->counterpartyService->paginate(
                $organizationId,
                $this->normalizePerPage($request->query('per_page')),
                $request->only(['q', 'search', 'name', 'inn', 'role', 'is_active']),
                (string) $request->query('sort_by', 'name'),
                (string) $request->query('sort_direction', 'asc')
            );

            return AdminResponse::paginated(
                CounterpartyResource::collection($counterparties->getCollection()),
                [
                    'current_page' => $counterparties->currentPage(),
                    'last_page' => $counterparties->lastPage(),
                    'per_page' => $counterparties->perPage(),
                    'total' => $counterparties->total(),
                ],
                null,
                Response::HTTP_OK,
                null,
                [
                    'first' => $counterparties->url(1),
                    'last' => $counterparties->url($counterparties->lastPage()),
                    'prev' => $counterparties->previousPageUrl(),
                    'next' => $counterparties->nextPageUrl(),
                ]
            );
        } catch (Throwable $exception) {
            $this->logUnexpectedException($request, 'counterparty_index_failed', $exception);

            return AdminResponse::error(trans_message('counterparty.internal_error_list'), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function search(SearchCounterpartyRequest $request): JsonResponse
    {
        $organizationId = $this->resolveOrganizationId($request);

        if (!$organizationId) {
            return AdminResponse::error(trans_message('counterparty.organization_context_missing'), Response::HTTP_BAD_REQUEST);
        }

        try {
            $counterparties = $this->counterpartyService->search(
                $organizationId,
                $request->validated(),
                (int) $request->validated('limit', 20)
            );

            return AdminResponse::success(CounterpartyResource::collection($counterparties));
        } catch (Throwable $exception) {
            $this->logUnexpectedException($request, 'counterparty_search_failed', $exception);

            return AdminResponse::error(trans_message('counterparty.internal_error_list'), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function store(StoreCounterpartyRequest $request): JsonResponse
    {
        $organizationId = $this->resolveOrganizationId($request);

        if (!$organizationId) {
            return AdminResponse::error(trans_message('counterparty.organization_context_missing'), Response::HTTP_BAD_REQUEST);
        }

        try {
            $counterparty = $this->counterpartyService->create($organizationId, $request->toDto());

            return AdminResponse::success(new CounterpartyResource($counterparty), null, Response::HTTP_CREATED);
        } catch (BusinessLogicException $exception) {
            return AdminResponse::error($exception->getMessage(), $exception->getCode());
        } catch (Throwable $exception) {
            $this->logUnexpectedException($request, 'counterparty_create_failed', $exception);

            return AdminResponse::error(trans_message('counterparty.internal_error_create'), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function show(Request $request, int $counterpartyId): JsonResponse
    {
        $organizationId = $this->resolveOrganizationId($request);

        if (!$organizationId) {
            return AdminResponse::error(trans_message('counterparty.organization_context_missing'), Response::HTTP_BAD_REQUEST);
        }

        try {
            $counterparty = $this->counterpartyService->getById($counterpartyId, $organizationId);

            if (!$counterparty) {
                return AdminResponse::error(trans_message('counterparty.not_found'), Response::HTTP_NOT_FOUND);
            }

            return AdminResponse::success(new CounterpartyResource($counterparty));
        } catch (Throwable $exception) {
            $this->logUnexpectedException($request, 'counterparty_show_failed', $exception);

            return AdminResponse::error(trans_message('counterparty.internal_error_get'), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function update(UpdateCounterpartyRequest $request, int $counterpartyId): JsonResponse
    {
        $organizationId = $this->resolveOrganizationId($request);

        if (!$organizationId) {
            return AdminResponse::error(trans_message('counterparty.organization_context_missing'), Response::HTTP_BAD_REQUEST);
        }

        try {
            $counterparty = $this->counterpartyService->update($counterpartyId, $organizationId, $request->toDto());

            return AdminResponse::success(new CounterpartyResource($counterparty));
        } catch (BusinessLogicException $exception) {
            return AdminResponse::error($exception->getMessage(), $exception->getCode());
        } catch (Throwable $exception) {
            $this->logUnexpectedException($request, 'counterparty_update_failed', $exception);

            return AdminResponse::error(trans_message('counterparty.internal_error_update'), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function destroy(Request $request, int $counterpartyId): JsonResponse
    {
        $organizationId = $this->resolveOrganizationId($request);

        if (!$organizationId) {
            return AdminResponse::error(trans_message('counterparty.organization_context_missing'), Response::HTTP_BAD_REQUEST);
        }

        try {
            $this->counterpartyService->delete($counterpartyId, $organizationId);

            return AdminResponse::success(null, trans_message('counterparty.deleted'));
        } catch (BusinessLogicException $exception) {
            return AdminResponse::error($exception->getMessage(), $exception->getCode());
        } catch (Throwable $exception) {
            $this->logUnexpectedException($request, 'counterparty_delete_failed', $exception);

            return AdminResponse::error(trans_message('counterparty.internal_error_delete'), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    private function resolveOrganizationId(Request $request): ?int
    {
        $organizationId = $request->attributes->get('current_organization_id') ?? $request->user()?->current_organization_id;

        return $organizationId !== null ? (int) $organizationId : null;
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

    private function logUnexpectedException(Request $request, string $message, Throwable $exception): void
    {
        Log::error($message, [
            'user_id' => $request->user()?->id,
            'organization_id' => $this->resolveOrganizationId($request),
            'params' => $request->all(),
            'exception' => $exception,
        ]);
    }
}
