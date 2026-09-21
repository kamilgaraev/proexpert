<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Exceptions\BusinessLogicException;
use App\Http\Middleware\ProjectContextMiddleware;
use App\Http\Requests\Api\V1\Admin\CompletedWork\CompletedWorkHistoryDecisionRequest;
use App\Http\Requests\Api\V1\Admin\CompletedWork\CompletedWorkHistoryTransformRequest;
use App\Http\Requests\Api\V1\Admin\CompletedWork\CompletedWorkReconciliationRequest;
use App\Http\Responses\AdminResponse;
use App\Models\CompletedWork;
use App\Services\CompletedWork\CompletedWorkHistoryTransformationService;
use App\Services\CompletedWork\CompletedWorkReconciliationQuery;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

use function trans_message;

final class CompletedWorkReconciliationController
{
    public function __construct(
        private readonly CompletedWorkReconciliationQuery $query,
        private readonly CompletedWorkHistoryTransformationService $transformation,
    ) {}

    public function index(CompletedWorkReconciliationRequest $request, int $project): JsonResponse
    {
        $organization = $request->attributes->get('current_organization');
        $result = $this->query->collect(
            (int) $organization->id,
            $project,
            $request->afterId(),
            $request->batchSize(),
        );

        return AdminResponse::success($result['records'], null, 200, $result['meta']);
    }

    public function transform(CompletedWorkHistoryTransformRequest $request, int $project): JsonResponse
    {
        $organization = $request->attributes->get('current_organization');
        $result = $this->transformation->transformProject(
            (int) $organization->id,
            $project,
            $request->user(),
            $request->dryRun(),
            $request->afterId(),
            $request->batchSize(),
            false,
        );

        return AdminResponse::success($result['records'], trans_message('completed_work.history_transformed'), 200, $result['meta']);
    }

    public function decide(CompletedWorkHistoryDecisionRequest $request, int $project, CompletedWork $completed_work): JsonResponse
    {
        $context = ProjectContextMiddleware::getProjectContext($request);
        $actor = $request->user();
        if (! $context || ! $actor || (int) $completed_work->project_id !== $project) {
            return AdminResponse::error(trans_message('completed_work.not_found'), Response::HTTP_NOT_FOUND);
        }

        try {
            $protocol = $this->transformation->decide($completed_work, $actor, $context, $request->validated());

            return AdminResponse::success($protocol->toReport(), trans_message('completed_work.history_decision_saved'), Response::HTTP_CREATED);
        } catch (BusinessLogicException $exception) {
            return AdminResponse::error($exception->getMessage(), $exception->getCode() ?: Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }
}
