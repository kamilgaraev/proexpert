<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Http\Controllers;

use App\BusinessModules\Features\BudgetEstimates\Http\Requests\WorkVolumeStatementImportPreviewRequest;
use App\BusinessModules\Features\BudgetEstimates\Http\Requests\WorkVolumeStatementRequest;
use App\BusinessModules\Features\BudgetEstimates\Http\Requests\WorkVolumeAcceptanceMappingRequest;
use App\BusinessModules\Features\BudgetEstimates\Http\Requests\WorkVolumeStatementReviewRequest;
use App\BusinessModules\Features\BudgetEstimates\Http\Requests\WorkVolumeStatementDraftEditRequest;
use App\BusinessModules\Features\BudgetEstimates\Services\WorkVolumeAcceptedAllocationService;
use App\BusinessModules\Features\BudgetEstimates\Models\WorkVolumeStatement;
use App\BusinessModules\Features\BudgetEstimates\Services\WorkVolumeStatementService;
use App\Http\Responses\AdminResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Exceptions\BusinessLogicException;

final class WorkVolumeStatementController
{
    public function __construct(private readonly WorkVolumeStatementService $service) {}

    public function index(Request $request, int $project): JsonResponse
    {
        return $this->respond(function () use ($request, $project) {
            $this->service->assertProjectAccess($request->user(), $project);
            return WorkVolumeStatement::query()->where('organization_id', $request->user()->current_organization_id)
                ->where('project_id', $project)->with('lines')->latest('id')->paginate(min(max($request->integer('per_page', 25), 1), 100));
        });
    }

    public function store(WorkVolumeStatementRequest $request, int $project): JsonResponse
    {
        return $this->respond(fn () => $this->service->createDraft($request->user(), $project, $request->validated()), 201);
    }

    public function show(Request $request, int $project, WorkVolumeStatement $statement): JsonResponse
    {
        abort_unless((int) $statement->project_id === $project, 404);
        return $this->respond(function () use ($request, $statement) {
            $this->service->assertScope($request->user(), $statement);
            return $statement->load('lines');
        });
    }

    public function revision(WorkVolumeStatementRequest $request, int $project, WorkVolumeStatement $statement): JsonResponse
    {
        abort_unless((int) $statement->project_id === $project, 404);
        return $this->respond(fn () => $this->service->createRevision($request->user(), $statement, $request->validated()), 201);
    }

    public function compare(Request $request, int $project, WorkVolumeStatement $statement, WorkVolumeStatement $otherStatement): JsonResponse
    {
        abort_unless((int) $statement->project_id === $project && (int) $otherStatement->project_id === $project, 404);
        return $this->respond(fn () => $this->service->compareRevisions($request->user(), $statement, $otherStatement));
    }

    public function approve(WorkVolumeStatementReviewRequest $request, int $project, WorkVolumeStatement $statement): JsonResponse
    {
        abort_unless((int) $statement->project_id === $project, 404);
        return $this->respond(fn () => $this->service->approve($request->user(), $statement, (int) $request->validated('expected_review_round')));
    }

    public function submitForReview(WorkVolumeStatementReviewRequest $request, int $project, WorkVolumeStatement $statement): JsonResponse
    {
        abort_unless((int) $statement->project_id === $project, 404);
        return $this->respond(fn () => $this->service->submitForReview($request->user(), $statement, (int) $request->validated('expected_review_round')));
    }

    public function returnForCorrection(WorkVolumeStatementReviewRequest $request, int $project, WorkVolumeStatement $statement): JsonResponse
    {
        abort_unless((int) $statement->project_id === $project, 404);
        return $this->respond(fn () => $this->service->returnForCorrection(
            $request->user(), $statement, (int) $request->validated('expected_review_round'), (string) $request->validated('reason'),
        ));
    }

    public function editDraft(WorkVolumeStatementDraftEditRequest $request, int $project, WorkVolumeStatement $statement): JsonResponse
    {
        abort_unless((int) $statement->project_id === $project, 404);
        return $this->respond(fn () => $this->service->updateDraft(
            $request->user(), $statement, (int) $request->validated('expected_draft_version'), $request->validated(),
        ));
    }

    public function previewImport(WorkVolumeStatementImportPreviewRequest $request): JsonResponse
    {
        return $this->respond(fn () => $this->service->previewImport($request->validated('rows')));
    }

    public function mapAccepted(WorkVolumeAcceptanceMappingRequest $request, int $project, int $actLine, WorkVolumeAcceptedAllocationService $allocations): JsonResponse
    {
        return $this->respond(fn () => $allocations->mapActLine(
            $request->user(),
            $project,
            $actLine,
            $request->validated('allocations'),
            $request->validated('reason'),
            $request->validated('operation_key'),
            (int) $request->validated('expected_revision'),
        ));
    }

    public function acceptedMappingHistory(Request $request, int $project, int $actLine, WorkVolumeAcceptedAllocationService $allocations): JsonResponse
    {
        return $this->respond(fn () => $allocations->history($request->user(), $project, $actLine, $request->integer('per_page', 25)));
    }

    private function respond(callable $action, int $status = 200): JsonResponse
    {
        try {
            $result = $action();
            if ($result instanceof \Illuminate\Contracts\Pagination\LengthAwarePaginator) {
                return AdminResponse::paginated($result->items(), [
                    'current_page' => $result->currentPage(),
                    'last_page' => $result->lastPage(),
                    'per_page' => $result->perPage(),
                    'total' => $result->total(),
                ]);
            }
            return AdminResponse::success($result, null, $status);
        } catch (BusinessLogicException $exception) {
            return AdminResponse::error($exception->getMessage(), in_array($exception->getCode(), [403, 404, 409, 422], true) ? $exception->getCode() : 422);
        }
    }
}
