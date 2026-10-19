<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Http\Controllers;

use App\BusinessModules\Addons\EstimateGeneration\Application\Review\GetEstimatePrerequisites;
use App\BusinessModules\Addons\EstimateGeneration\Application\Review\UpdateEstimatePrerequisites;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\StaleEstimateGenerationState;
use App\BusinessModules\Addons\EstimateGeneration\Http\Requests\AnswerEstimatePrerequisiteRequest;
use App\BusinessModules\Addons\EstimateGeneration\Http\Requests\RefreshEstimatePrerequisitesRequest;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class EstimateGenerationPrerequisiteController extends Controller
{
    public function index(Request $request, Project $project, EstimateGenerationSession $session, GetEstimatePrerequisites $get): JsonResponse
    {
        return AdminResponse::success($get->handle($request->user(), (int) $project->id, (int) $session->id))
            ->header('Cache-Control', 'private, no-store');
    }

    public function refresh(RefreshEstimatePrerequisitesRequest $request, Project $project, EstimateGenerationSession $session, UpdateEstimatePrerequisites $update): JsonResponse
    {
        $input = $request->validated();
        try {
            return AdminResponse::success($update->refresh($request->user(), (int) $project->id, (int) $session->id,
                (int) $input['state_version'], $input['request_id']))->header('Cache-Control', 'private, no-store');
        } catch (StaleEstimateGenerationState) {
            return AdminResponse::error(trans_message('estimate_generation.prerequisites.changed'), 409);
        }
    }

    public function answer(AnswerEstimatePrerequisiteRequest $request, Project $project, EstimateGenerationSession $session, UpdateEstimatePrerequisites $update): JsonResponse
    {
        $input = $request->validated();
        try {
            return AdminResponse::success($update->answer($request->user(), (int) $project->id, (int) $session->id,
                (int) $input['state_version'], $input['request_id'], array_diff_key($input, array_flip(['state_version', 'request_id']))))
                ->header('Cache-Control', 'private, no-store');
        } catch (StaleEstimateGenerationState) {
            return AdminResponse::error(trans_message('estimate_generation.prerequisites.changed'), 409);
        }
    }
}
