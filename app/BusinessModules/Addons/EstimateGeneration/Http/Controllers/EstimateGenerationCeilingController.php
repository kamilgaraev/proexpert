<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Http\Controllers;

use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\AcceptRoomCeilingReplacement;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\StaleEstimateGenerationState;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\Domain\Authorization\Http\Middleware\InterfaceRequestProvenance;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class EstimateGenerationCeilingController extends Controller
{
    public function show(Request $request, Project $project, EstimateGenerationSession $session, AcceptRoomCeilingReplacement $replacement): JsonResponse
    {
        $input = $request->validate(['parent_work_item_key' => ['required', 'string', 'max:160']]);

        return AdminResponse::success($replacement->show($request->user(), (int) $project->id, (int) $session->id, $input['parent_work_item_key']))
            ->header('Cache-Control', 'private, no-store');
    }

    public function accept(Request $request, Project $project, EstimateGenerationSession $session, AcceptRoomCeilingReplacement $replacement): JsonResponse
    {
        $input = $request->all();
        if (InterfaceRequestProvenance::isTrustedServerDerived($request)) {
            unset($input['current_interface']);
        }
        try {
            return AdminResponse::success($replacement->handle($request->user(), (int) $project->id, (int) $session->id, $input))
                ->header('Cache-Control', 'private, no-store');
        } catch (StaleEstimateGenerationState) {
            return AdminResponse::error(trans_message('estimate_ceiling.changed'), 409);
        }
    }
}
