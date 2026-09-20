<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Http\Controllers;

use App\BusinessModules\Features\BudgetEstimates\Http\Requests\WorkVolumeCoverageRequest;
use App\BusinessModules\Features\BudgetEstimates\Models\WorkVolumeStatement;
use App\BusinessModules\Features\BudgetEstimates\Services\WorkVolumeCoverageService;
use App\Http\Responses\AdminResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Exceptions\BusinessLogicException;

final class WorkVolumeCoverageController
{
    public function __construct(private readonly WorkVolumeCoverageService $service) {}
    public function index(Request $request, int $project, WorkVolumeStatement $statement): JsonResponse
    {
        abort_unless((int) $statement->project_id === $project, 404);
        return $this->respond(fn () => $this->service->allocations($request->user(), $statement));
    }
    public function replace(WorkVolumeCoverageRequest $request, int $project, WorkVolumeStatement $statement): JsonResponse
    {
        abort_unless((int) $statement->project_id === $project, 404);
        return $this->respond(fn () => $this->service->replaceAllocations(
            $request->user(), $statement, $request->validated('allocations'),
            $request->validated('operation_key'), (int) $request->validated('expected_coverage_revision'),
        ));
    }

    private function respond(callable $action): JsonResponse
    {
        try {
            $result = $action();
            if ($result instanceof \Illuminate\Contracts\Pagination\LengthAwarePaginator) {
                return AdminResponse::paginated($result->items(), [
                    'current_page' => $result->currentPage(), 'last_page' => $result->lastPage(),
                    'per_page' => $result->perPage(), 'total' => $result->total(),
                ]);
            }
            return AdminResponse::success($result);
        } catch (BusinessLogicException $exception) {
            return AdminResponse::error($exception->getMessage(), $exception->getCode());
        }
    }

    public function sourceReviews(Request $request, int $project, WorkVolumeStatement $statement): JsonResponse
    {
        abort_unless((int) $statement->project_id === $project, 404);
        return $this->respond(fn () => $this->service->sourceReviews($request->user(), $statement, $request->integer('per_page', 25)));
    }
}
