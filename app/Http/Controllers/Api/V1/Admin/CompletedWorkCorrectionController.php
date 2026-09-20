<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Middleware\ProjectContextMiddleware;
use App\Http\Requests\Api\V1\Admin\CompletedWork\CorrectCompletedWorkRequest;
use App\Http\Responses\AdminResponse;
use App\Models\CompletedWork;
use App\Services\CompletedWork\CompletedWorkCorrectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

use function trans_message;

final class CompletedWorkCorrectionController extends Controller
{
    public function __construct(private readonly CompletedWorkCorrectionService $service) {}

    public function store(CorrectCompletedWorkRequest $request, int $project, CompletedWork $completed_work): JsonResponse
    {
        $context = ProjectContextMiddleware::getProjectContext($request);
        $actor = Auth::user();
        if (! $context || ! $actor || (int) $completed_work->project_id !== $project) {
            return AdminResponse::error(trans_message('completed_work.not_found'), Response::HTTP_NOT_FOUND);
        }

        try {
            $correction = $this->service->correct($completed_work, $actor, $context, $request->validated());

            return AdminResponse::success($correction, trans_message('completed_work.updated'), Response::HTTP_CREATED);
        } catch (\App\Exceptions\BusinessLogicException $e) {
            return AdminResponse::error($e->getMessage(), $e->getCode() ?: Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }
}
