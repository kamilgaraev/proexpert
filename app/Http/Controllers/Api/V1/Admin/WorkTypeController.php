<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use App\Services\WorkType\WorkTypeService;
use App\Http\Requests\Api\V1\Admin\WorkType\StoreWorkTypeRequest;
use App\Http\Requests\Api\V1\Admin\WorkType\UpdateWorkTypeRequest;
use App\Http\Resources\Api\V1\Admin\WorkTypeResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Log;
use App\Exceptions\BusinessLogicException;

class WorkTypeController extends Controller
{
    protected WorkTypeService $workTypeService;

    public function __construct(WorkTypeService $workTypeService)
    {
        Log::info('[WorkTypeController] Constructor CALLED.');
        $this->workTypeService = $workTypeService;
        // $this->middleware('can:manage-catalogs'); // Удалено согласно указанию
        Log::info('[WorkTypeController] Constructor FINISHED.');
    }

    public function index(Request $request): JsonResponse | AnonymousResourceCollection
    {
        try {
            Log::info('WorkTypeController@index Method called', ['user_id' => $request->user()?->id]);
            $perPage = $request->query('per_page', 15);
            $workTypes = $this->workTypeService->getWorkTypesPaginated($request, (int)$perPage);
            return WorkTypeResource::collection($workTypes);
        } catch (\Throwable $e) {
            Log::error('WorkTypeController@index Exception', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'user_id' => $request->user()?->id,
            ]);
            return AdminResponse::error(trans_message('work_type.internal_error_list'), 500);
        }
    }

    public function store(StoreWorkTypeRequest $request): WorkTypeResource | JsonResponse
    {
        try {
            $validatedData = $request->validated();
            Log::info('WorkTypeController@store Creating work type', ['user_id' => $request->user()?->id]);

            $workType = $this->workTypeService->createWorkType($validatedData, $request);

            if (!$workType) {
                return AdminResponse::error(trans_message('work_type.create_failed'), 500);
            }

            return new WorkTypeResource($workType->load('measurementUnit'));

        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::error('WorkTypeController@store ValidationException', ['errors' => $e->errors(), 'user_id' => $request->user()?->id]);
            return AdminResponse::error(trans_message('errors.validation_failed'), 422, $e->errors());
        } catch (BusinessLogicException $e) {
            Log::error('WorkTypeController@store BusinessLogicException', ['message' => $e->getMessage(), 'user_id' => $request->user()?->id]);
            return AdminResponse::error($e->getMessage(), $e->getCode() ?: 400);
        } catch (\Throwable $e) {
            Log::error('WorkTypeController@store Exception', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'user_id' => $request->user()?->id,
            ]);
            return AdminResponse::error(trans_message('work_type.internal_error_create'), 500);
        }
    }

    public function show(Request $request, string $id): WorkTypeResource | JsonResponse
    {
        try {
            $workType = $this->workTypeService->findWorkTypeById((int)$id, $request);
            if (!$workType) {
                return AdminResponse::error(trans_message('work_type.not_found'), 404);
            }
            return new WorkTypeResource($workType->load('measurementUnit'));
        } catch (\Throwable $e) {
            Log::error('WorkTypeController@show Exception', [
                'id' => $id,
                'message' => $e->getMessage(),
                'user_id' => $request->user()?->id,
            ]);
            return AdminResponse::error(trans_message('work_type.internal_error_get'), 500);
        }
    }

    public function update(UpdateWorkTypeRequest $request, string $id): WorkTypeResource | JsonResponse
    {
        try {
            $success = $this->workTypeService->updateWorkType((int)$id, $request->validated(), $request);
            if (!$success) {
                return AdminResponse::error(trans_message('work_type.update_failed'), 404);
            }
            $workType = $this->workTypeService->findWorkTypeById((int)$id, $request);
            return new WorkTypeResource($workType->load('measurementUnit'));
        } catch (\Throwable $e) {
            Log::error('WorkTypeController@update Exception', [
                'id' => $id,
                'message' => $e->getMessage(),
                'user_id' => $request->user()?->id,
            ]);
            return AdminResponse::error(trans_message('work_type.internal_error_update'), 500);
        }
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        try {
            $success = $this->workTypeService->deleteWorkType((int)$id, $request);
            if (!$success) {
                return AdminResponse::error(trans_message('work_type.delete_failed'), 404);
            }
            return AdminResponse::success(null, trans_message('work_type.deleted'));
        } catch (\Throwable $e) {
            Log::error('WorkTypeController@destroy Exception', [
                'id' => $id,
                'message' => $e->getMessage(),
                'user_id' => $request->user()?->id,
            ]);
            return AdminResponse::error(trans_message('work_type.internal_error_delete'), 500);
        }
    }
} 