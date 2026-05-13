<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Exceptions\BusinessLogicException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\WorkType\StoreWorkTypeRequest;
use App\Http\Requests\Api\V1\Admin\WorkType\UpdateWorkTypeRequest;
use App\Http\Resources\Api\V1\Admin\WorkTypeResource;
use App\Http\Responses\AdminResponse;
use App\Services\WorkType\WorkTypeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Throwable;

class WorkTypeController extends Controller
{
    private const DEFAULT_PER_PAGE = 15;
    private const LOAD_ALL_PER_PAGE = 1000;
    private const MAX_PER_PAGE = 1000;

    public function __construct(private readonly WorkTypeService $workTypeService)
    {
        $this->middleware('can:admin.catalogs.manage');
    }

    public function index(Request $request): JsonResponse
    {
        try {
            $workTypes = $this->workTypeService->getWorkTypesPaginated(
                $request,
                $this->normalizePerPage($request->query('per_page'))
            );

            return AdminResponse::paginated(
                WorkTypeResource::collection($workTypes->getCollection()),
                [
                    'current_page' => $workTypes->currentPage(),
                    'last_page' => $workTypes->lastPage(),
                    'per_page' => $workTypes->perPage(),
                    'total' => $workTypes->total(),
                ],
                null,
                Response::HTTP_OK,
                null,
                [
                    'first' => $workTypes->url(1),
                    'last' => $workTypes->url($workTypes->lastPage()),
                    'prev' => $workTypes->previousPageUrl(),
                    'next' => $workTypes->nextPageUrl(),
                ]
            );
        } catch (BusinessLogicException $exception) {
            return AdminResponse::error($exception->getMessage(), $exception->getCode());
        } catch (Throwable $exception) {
            $this->logUnexpectedException($request, 'work_type_index_failed', $exception);

            return AdminResponse::error(trans_message('work_type.internal_error_list'), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function store(StoreWorkTypeRequest $request): JsonResponse
    {
        try {
            $workType = $this->workTypeService->createWorkType($request->validated(), $request);

            return AdminResponse::success(
                new WorkTypeResource($workType->load('measurementUnit')),
                null,
                Response::HTTP_CREATED
            );
        } catch (BusinessLogicException $exception) {
            return AdminResponse::error($exception->getMessage(), $exception->getCode());
        } catch (Throwable $exception) {
            $this->logUnexpectedException($request, 'work_type_create_failed', $exception);

            return AdminResponse::error(trans_message('work_type.internal_error_create'), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function show(Request $request, string $id): JsonResponse
    {
        try {
            $workType = $this->workTypeService->findWorkTypeById((int) $id, $request);

            if (!$workType) {
                return AdminResponse::error(trans_message('work_type.not_found'), Response::HTTP_NOT_FOUND);
            }

            return AdminResponse::success(new WorkTypeResource($workType->load('measurementUnit')));
        } catch (BusinessLogicException $exception) {
            return AdminResponse::error($exception->getMessage(), $exception->getCode());
        } catch (Throwable $exception) {
            $this->logUnexpectedException($request, 'work_type_show_failed', $exception);

            return AdminResponse::error(trans_message('work_type.internal_error_get'), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function update(UpdateWorkTypeRequest $request, string $id): JsonResponse
    {
        try {
            $this->workTypeService->updateWorkType((int) $id, $request->validated(), $request);
            $workType = $this->workTypeService->findWorkTypeById((int) $id, $request);

            return AdminResponse::success(new WorkTypeResource($workType->load('measurementUnit')));
        } catch (BusinessLogicException $exception) {
            return AdminResponse::error($exception->getMessage(), $exception->getCode());
        } catch (Throwable $exception) {
            $this->logUnexpectedException($request, 'work_type_update_failed', $exception);

            return AdminResponse::error(trans_message('work_type.internal_error_update'), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        try {
            $this->workTypeService->deleteWorkType((int) $id, $request);

            return AdminResponse::success(null, trans_message('work_type.deleted'));
        } catch (BusinessLogicException $exception) {
            return AdminResponse::error($exception->getMessage(), $exception->getCode());
        } catch (Throwable $exception) {
            $this->logUnexpectedException($request, 'work_type_delete_failed', $exception);

            return AdminResponse::error(trans_message('work_type.internal_error_delete'), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
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
            'organization_id' => $request->attributes->get('current_organization_id'),
            'params' => $request->all(),
            'exception' => $exception,
        ]);
    }
}
