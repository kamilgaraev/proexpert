<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use App\Services\Contract\SpecificationService;
use App\Http\Requests\Api\V1\Admin\Specification\StoreSpecificationRequest;
use App\Http\Requests\Api\V1\Admin\Specification\UpdateSpecificationRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class SpecificationController extends Controller
{
    public function __construct(private SpecificationService $service) {}

    public function index(Request $request)
    {
        try {
            // Получаем project_id из URL (обязательный параметр для project-based маршрутов)
            $projectId = $request->route('project');
            $perPage = $request->query('per_page', 15);
            
            // TODO: Добавить фильтрацию по project_id в SpecificationService
            // Пока возвращаем все спецификации (требует доработки сервиса)
            return $this->service->paginate($perPage);
        } catch (\Throwable $e) {
            Log::error('SpecificationController@index Exception', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'user_id' => $request->user()?->id,
            ]);
            return AdminResponse::error(trans_message('specification.internal_error_list'), 500);
        }
    }

    public function store(StoreSpecificationRequest $request): JsonResponse
    {
        try {
            $spec = $this->service->create($request->toDto());
            return AdminResponse::success($spec, trans_message('specification.created'), 201);
        } catch (\Throwable $e) {
            Log::error('SpecificationController@store Exception', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'user_id' => $request->user()?->id,
            ]);
            return AdminResponse::error(trans_message('specification.internal_error_create'), 500);
        }
    }

    public function show(Request $request, int $id)
    {
        try {
            $spec = $this->service->getById($id);
            if (!$spec) {
                return AdminResponse::error(trans_message('specification.not_found'), 404);
            }
            return $spec;
        } catch (\Throwable $e) {
            Log::error('SpecificationController@show Exception', [
                'id' => $id,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'user_id' => $request->user()?->id,
            ]);
            return AdminResponse::error(trans_message('specification.internal_error_get'), 500);
        }
    }

    public function update(UpdateSpecificationRequest $request, int $id): JsonResponse
    {
        try {
            $this->service->update($id, $request->toDto());
            $spec = $this->service->getById($id);
            return AdminResponse::success($spec, trans_message('specification.updated'));
        } catch (\Throwable $e) {
            Log::error('SpecificationController@update Exception', [
                'id' => $id,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'user_id' => $request->user()?->id,
            ]);
            return AdminResponse::error(trans_message('specification.internal_error_update'), 500);
        }
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        try {
            $this->service->delete($id);
            return AdminResponse::success(null, trans_message('specification.deleted'));
        } catch (\Throwable $e) {
            Log::error('SpecificationController@destroy Exception', [
                'id' => $id,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'user_id' => $request->user()?->id,
            ]);
            return AdminResponse::error(trans_message('specification.internal_error_delete'), 500);
        }
    }
} 