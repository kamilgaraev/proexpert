<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Http\Controllers;

use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\SaveManualProjectFacts;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\StaleEstimateGenerationState;
use App\BusinessModules\Addons\EstimateGeneration\Http\Requests\SaveManualProjectFactsRequest;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use App\Models\Project;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;

final class EstimateGenerationManualFactsController extends Controller
{
    public function store(SaveManualProjectFactsRequest $request, Project $project, EstimateGenerationSession $session, SaveManualProjectFacts $save): JsonResponse
    {
        $input = $request->validated();
        try {
            return AdminResponse::success($save->handle($request->user(), (int) $project->id, (int) $session->id,
                (int) $input['state_version'], $input['request_id'], ['entities' => $input['entities']]));
        } catch (StaleEstimateGenerationState) {
            return AdminResponse::error('Данные оценки изменились. Обновите их перед сохранением.', 409);
        } catch (AuthorizationException) {
            return AdminResponse::error('Нет доступа к изменению этой оценки.', 403);
        }
    }
}
