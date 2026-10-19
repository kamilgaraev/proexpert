<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Http\Controllers;

use App\BusinessModules\Addons\EstimateGeneration\Application\Factory\FactoryInspectionStore;
use App\BusinessModules\Addons\EstimateGeneration\Application\Factory\InspectFactoryInputs;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\StaleEstimateGenerationState;
use App\BusinessModules\Addons\EstimateGeneration\Http\Requests\InspectEstimateFactoryRequest;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class EstimateGenerationFactoryController extends Controller
{
    public function show(Request $request, Project $project, EstimateGenerationSession $session, FactoryInspectionStore $store): JsonResponse
    {
        return AdminResponse::success($store->current($request->user(), (int) $project->id, (int) $session->id))
            ->header('Cache-Control', 'private, no-store');
    }

    public function inspect(InspectEstimateFactoryRequest $request, Project $project, EstimateGenerationSession $session, InspectFactoryInputs $inspect): JsonResponse
    {
        $input = $request->validated();
        try {
            return AdminResponse::success($inspect->handle($request->user(), (int) $project->id, (int) $session->id,
                (int) $input['state_version'], $input['request_id']))->header('Cache-Control', 'private, no-store');
        } catch (StaleEstimateGenerationState) {
            return AdminResponse::error(trans_message('estimate_generation.factory.changed'), 409);
        }
    }
}
