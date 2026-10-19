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
use Illuminate\Http\Request;

final class EstimateGenerationManualFactsController extends Controller
{
    public function descriptionProposals(Request $request, Project $project, EstimateGenerationSession $session,
        \App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\GetDescriptionNumericProposals $proposals): JsonResponse
    {
        return AdminResponse::success($proposals->handle($request->user(), (int) $project->id, (int) $session->id))
            ->header('Cache-Control', 'private, no-store');
    }

    public function inventory(Request $request, Project $project, EstimateGenerationSession $session,
        \App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\GetGeometryInventory $inventory): JsonResponse
    {
        $input = $request->validate(['search' => ['sometimes', 'nullable', 'string', 'max:100'], 'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'], 'parent_entity_id' => ['required_with:relation', 'string', 'max:160'],
            'relation' => ['required_with:parent_entity_id', \Illuminate\Validation\Rule::in(array_keys(\App\BusinessModules\Addons\EstimateGeneration\Quantities\GeometryInventory::RELATIONS))]]);
        foreach (['page', 'per_page'] as $field) {
            if (isset($input[$field])) {
                $input[$field] = (int) $input[$field];
            }
        }

        return AdminResponse::success($inventory->handle($request->user(), (int) $project->id, (int) $session->id, $input))
            ->header('Cache-Control', 'private, no-store');
    }

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
