<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Http\Controllers;

use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\EvaluationResourcePriceChoices;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\StaleEstimateGenerationState;
use App\BusinessModules\Addons\EstimateGeneration\Http\Requests\EvaluationResourcePriceSourcesRequest;
use App\BusinessModules\Addons\EstimateGeneration\Http\Requests\EvaluationResourceTargetRequest;
use App\BusinessModules\Addons\EstimateGeneration\Http\Requests\SaveEvaluationResourcePriceRequest;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use App\Models\Project;
use Illuminate\Http\JsonResponse;

final class EstimateGenerationResourcePriceController extends Controller
{
    public function resources(EvaluationResourceTargetRequest $request, Project $project, EstimateGenerationSession $session, EvaluationResourcePriceChoices $choices): JsonResponse
    {
        $input = $request->validated();

        return AdminResponse::success($choices->resources($request->user(), (int) $project->id, (int) $session->id, $input['work_item_key']))
            ->header('Cache-Control', 'private, no-store');
    }

    public function sources(EvaluationResourcePriceSourcesRequest $request, Project $project, EstimateGenerationSession $session, EvaluationResourcePriceChoices $choices): JsonResponse
    {
        $input = $request->validated();

        return AdminResponse::success($choices->sources($request->user(), (int) $project->id, (int) $session->id,
            $input['work_item_key'], (int) $input['norm_resource_id'], $input['search'] ?? '', (int) ($input['page'] ?? 1), (int) ($input['per_page'] ?? 25)))
            ->header('Cache-Control', 'private, no-store');
    }

    public function save(SaveEvaluationResourcePriceRequest $request, Project $project, EstimateGenerationSession $session, EvaluationResourcePriceChoices $choices): JsonResponse
    {
        try {
            return AdminResponse::success($choices->save($request->user(), (int) $project->id, (int) $session->id, $request->all()))
                ->header('Cache-Control', 'private, no-store');
        } catch (StaleEstimateGenerationState) {
            return AdminResponse::error(trans_message('estimate_resource_price.changed'), 409);
        }
    }
}
