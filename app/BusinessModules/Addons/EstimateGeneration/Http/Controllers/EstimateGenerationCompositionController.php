<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Http\Controllers;

use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\EvaluationWorkComposition;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\StaleEstimateGenerationState;
use App\BusinessModules\Addons\EstimateGeneration\Http\Requests\SaveEvaluationWorkCompositionRequest;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class EstimateGenerationCompositionController extends Controller
{
    public function show(Request $request, Project $project, EstimateGenerationSession $session, EvaluationWorkComposition $composition): JsonResponse
    {
        return AdminResponse::success($composition->show($request->user(), (int) $project->id, (int) $session->id))
            ->header('Cache-Control', 'private, no-store');
    }

    public function accept(SaveEvaluationWorkCompositionRequest $request, Project $project, EstimateGenerationSession $session, EvaluationWorkComposition $composition): JsonResponse
    {
        $input = $request->validated();
        $input['state_version'] = (int) $input['state_version'];
        try {
            return AdminResponse::success($composition->accept($request->user(), (int) $project->id, (int) $session->id, $input))
                ->header('Cache-Control', 'private, no-store');
        } catch (StaleEstimateGenerationState) {
            return AdminResponse::error(trans_message('estimate_composition.changed'), 409);
        }
    }
}
