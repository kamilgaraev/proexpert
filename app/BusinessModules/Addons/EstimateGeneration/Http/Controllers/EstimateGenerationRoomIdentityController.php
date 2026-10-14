<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Http\Controllers;

use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\BindEvaluationWorkEntity;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\ConfirmRoomIdentity;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\GetRoomIdentitySources;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\StaleEstimateGenerationState;
use App\BusinessModules\Addons\EstimateGeneration\Http\Requests\BindEvaluationWorkEntityRequest;
use App\BusinessModules\Addons\EstimateGeneration\Http\Requests\ConfirmRoomIdentityRequest;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class EstimateGenerationRoomIdentityController extends Controller
{
    public function bindWork(BindEvaluationWorkEntityRequest $request, Project $project, EstimateGenerationSession $session, BindEvaluationWorkEntity $binding): JsonResponse
    {
        try {
            return AdminResponse::success($binding->handle($request->user(), (int) $project->id, (int) $session->id, $request->validated()))
                ->header('Cache-Control', 'private, no-store');
        } catch (StaleEstimateGenerationState) {
            return AdminResponse::error(trans_message('estimate_generation.room_identity.state_stale'), 409);
        }
    }

    public function index(Request $request, Project $project, EstimateGenerationSession $session, GetRoomIdentitySources $sources): JsonResponse
    {
        $input = $request->validate(['search' => ['sometimes', 'string', 'max:100'], 'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'], 'include_manual' => ['sometimes', 'boolean']]);

        return AdminResponse::success($sources->handle($request->user(), (int) $project->id, (int) $session->id,
            $input['search'] ?? '', (int) ($input['page'] ?? 1), (int) ($input['per_page'] ?? 25), (bool) ($input['include_manual'] ?? false)))->header('Cache-Control', 'private, no-store');
    }

    public function confirm(ConfirmRoomIdentityRequest $request, Project $project, EstimateGenerationSession $session, ConfirmRoomIdentity $confirmation): JsonResponse
    {
        try {
            return AdminResponse::success($confirmation->handle($request->user(), (int) $project->id, (int) $session->id, $request->validated()))
                ->header('Cache-Control', 'private, no-store');
        } catch (StaleEstimateGenerationState) {
            return AdminResponse::error(trans_message('estimate_generation.room_identity.state_stale'), 409);
        }
    }
}
