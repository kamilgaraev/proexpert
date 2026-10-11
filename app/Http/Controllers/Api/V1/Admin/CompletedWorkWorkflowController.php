<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Exceptions\BusinessLogicException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\CompletedWork\CompletedWorkWorkflowRequest;
use App\Http\Resources\Api\V1\Admin\CompletedWork\CompletedWorkResource;
use App\Http\Responses\AdminResponse;
use App\Models\CompletedWork;
use App\Services\CompletedWork\CompletedWorkWorkflowService;
use Illuminate\Http\JsonResponse;

final class CompletedWorkWorkflowController extends Controller
{
    public function __construct(private readonly CompletedWorkWorkflowService $workflow) {}

    public function handle(CompletedWorkWorkflowRequest $request, int $project, CompletedWork $completed_work): JsonResponse
    {
        if ((int) $completed_work->project_id !== $project) {
            return AdminResponse::error(trans_message('completed_work.not_found'), 404);
        }
        try {
            $data = $request->validated();
            $work = match ($request->route('workflow_action')) {
                'reject' => $this->workflow->reject($completed_work, $request->user(), $data['reason']),
                'request_changes' => $this->workflow->requestChanges($completed_work, $request->user(), $data['comment']),
                default => $this->workflow->submit($completed_work, $request->user()),
            };

            return AdminResponse::success(new CompletedWorkResource($work), trans_message('completed_work.workflow_updated'));
        } catch (BusinessLogicException $exception) {
            return AdminResponse::error($exception->getMessage(), $exception->getCode() ?: 422);
        }
    }
}
