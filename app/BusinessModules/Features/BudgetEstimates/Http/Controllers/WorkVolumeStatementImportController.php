<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Http\Controllers;

use App\BusinessModules\Features\BudgetEstimates\Http\Requests\WorkVolumeStatementImportRegisterRequest;
use App\BusinessModules\Features\BudgetEstimates\Http\Requests\WorkVolumeStatementImportSaveRequest;
use App\BusinessModules\Features\BudgetEstimates\Http\Requests\WorkVolumeStatementImportUploadRequest;
use App\BusinessModules\Features\BudgetEstimates\Models\WorkVolumeStatementImport;
use App\BusinessModules\Features\BudgetEstimates\Services\WorkVolumeStatementImportService;
use App\Exceptions\BusinessLogicException;
use App\Http\Responses\AdminResponse;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class WorkVolumeStatementImportController
{
    public function __construct(private readonly WorkVolumeStatementImportService $service) {}

    public function index(Request $request, int $project): JsonResponse
    {
        return $this->respond(fn () => $this->service->paginate($request->user(), $project, $request->integer('per_page', 25)));
    }

    public function store(WorkVolumeStatementImportUploadRequest $request, int $project): JsonResponse
    {
        return $this->respond(fn () => $this->service->overview($this->service->stage(
            $request->user(), $project, $request->file('file'), $request->safe()->except('file'),
        )), 201);
    }

    public function show(Request $request, int $project, WorkVolumeStatementImport $import): JsonResponse
    {
        abort_unless((int) $import->project_id === $project, 404);
        return $this->respond(fn () => $this->service->describe($request->user(), $import->id, $request->integer('page', 1), $request->integer('per_page', 100)));
    }

    public function save(WorkVolumeStatementImportSaveRequest $request, int $project, WorkVolumeStatementImport $import): JsonResponse
    {
        abort_unless((int) $import->project_id === $project, 404);
        return $this->respond(fn () => $this->service->overview($this->service->savePreview(
            $request->user(), $import->id, (int) $request->validated('expected_preview_version'), $request->validated('rows'),
        )));
    }

    public function register(WorkVolumeStatementImportRegisterRequest $request, int $project, WorkVolumeStatementImport $import): JsonResponse
    {
        abort_unless((int) $import->project_id === $project, 404);
        return $this->respond(fn () => $this->service->register(
            $request->user(), $import->id, (int) $request->validated('expected_preview_version'), $request->safe()->except('expected_preview_version'),
        ), 201);
    }

    public function patch(WorkVolumeStatementImportSaveRequest $request, int $project, WorkVolumeStatementImport $import): JsonResponse
    {
        abort_unless((int) $import->project_id === $project, 404);
        return $this->respond(fn () => $this->service->overview($this->service->patchPreview(
            $request->user(), $import->id, (int) $request->validated('expected_preview_version'), $request->validated('rows'),
        )));
    }

    public function source(Request $request, int $project, WorkVolumeStatementImport $import): JsonResponse
    {
        abort_unless((int) $import->project_id === $project, 404);
        return $this->respond(fn () => $this->service->sourceDownload($request->user(), $import->id));
    }

    private function respond(callable $action, int $status = 200): JsonResponse
    {
        try {
            $result = $action();
            if ($result instanceof LengthAwarePaginator) {
                return AdminResponse::paginated($result->items(), [
                    'current_page' => $result->currentPage(), 'last_page' => $result->lastPage(),
                    'per_page' => $result->perPage(), 'total' => $result->total(),
                ]);
            }
            return AdminResponse::success($result, null, $status);
        } catch (BusinessLogicException $exception) {
            return AdminResponse::error($exception->getMessage(), in_array($exception->getCode(), [403, 404, 409, 422], true) ? $exception->getCode() : 422);
        }
    }
}
