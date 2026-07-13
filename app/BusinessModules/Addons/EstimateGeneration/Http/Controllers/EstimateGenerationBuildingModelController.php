<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Http\Controllers;

use App\BusinessModules\Addons\EstimateGeneration\Http\Presentation\BuildingModelPayloadService;
use App\BusinessModules\Addons\EstimateGeneration\Http\Requests\ShowEstimateGenerationBuildingModelRequest;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

use function trans_message;

final class EstimateGenerationBuildingModelController extends Controller
{
    public function __construct(private readonly BuildingModelPayloadService $payload) {}

    public function show(
        ShowEstimateGenerationBuildingModelRequest $request,
        Project $project,
        EstimateGenerationSession $session,
    ): JsonResponse {
        try {
            $this->guard($request, $project, $session);
            $validated = $request->validated();

            return AdminResponse::success($this->payload->handle(
                $session,
                (int) ($validated['quantities_page'] ?? 1),
                (int) ($validated['quantities_per_page'] ?? 25),
            ));
        } catch (NotFoundHttpException) {
            return AdminResponse::error(trans_message('estimate_generation.building_model_not_found'), 404);
        } catch (\Throwable $exception) {
            Log::error('[EstimateGeneration] Building model read failed', [
                'exception' => $exception,
                'organization_id' => (int) ($request->user()?->current_organization_id ?? 0),
                'project_id' => (int) $project->getKey(),
                'session_id' => (int) $session->getKey(),
            ]);

            return AdminResponse::error(trans_message('estimate_generation.building_model_error'), 500);
        }
    }

    public function evidence(
        ShowEstimateGenerationBuildingModelRequest $request,
        Project $project,
        EstimateGenerationSession $session,
        int $evidence,
    ): JsonResponse {
        try {
            $this->guard($request, $project, $session);
            $payload = $evidence > 0 ? $this->payload->evidence($session, $evidence) : null;
            if ($payload === null) {
                throw new NotFoundHttpException;
            }

            return AdminResponse::success($payload);
        } catch (NotFoundHttpException) {
            return AdminResponse::error(trans_message('estimate_generation.evidence_not_found'), 404);
        } catch (\Throwable $exception) {
            Log::error('[EstimateGeneration] Evidence read failed', [
                'exception' => $exception,
                'organization_id' => (int) ($request->user()?->current_organization_id ?? 0),
                'project_id' => (int) $project->getKey(),
                'session_id' => (int) $session->getKey(),
                'evidence_id' => $evidence,
            ]);

            return AdminResponse::error(trans_message('estimate_generation.evidence_error'), 500);
        }
    }

    private function guard(Request $request, Project $project, EstimateGenerationSession $session): void
    {
        $organizationId = (int) ($request->user()?->current_organization_id ?? 0);
        if ((int) $project->organization_id !== $organizationId
            || (int) $session->organization_id !== $organizationId
            || (int) $session->project_id !== (int) $project->getKey()) {
            throw new NotFoundHttpException;
        }
    }
}
