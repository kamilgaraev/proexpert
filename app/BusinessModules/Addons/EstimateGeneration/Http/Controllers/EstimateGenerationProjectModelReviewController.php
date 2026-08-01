<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Http\Controllers;

use App\BusinessModules\Addons\EstimateGeneration\Http\Presentation\ProjectModelReviewPayloadService;
use App\BusinessModules\Addons\EstimateGeneration\Http\Requests\ShowEstimateGenerationProjectModelReviewRequest;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

use function trans_message;

final class EstimateGenerationProjectModelReviewController extends Controller
{
    public function __construct(private readonly ProjectModelReviewPayloadService $payload) {}

    public function show(ShowEstimateGenerationProjectModelReviewRequest $request, Project $project, EstimateGenerationSession $session): JsonResponse
    {
        try {
            $user = $request->user();
            $organizationId = (int) ($user?->current_organization_id ?? 0);
            if (! $user instanceof User || $organizationId < 1 || (int) $project->organization_id !== $organizationId || (int) $session->organization_id !== $organizationId || (int) $session->project_id !== (int) $project->getKey()) throw new NotFoundHttpException;
            return AdminResponse::success($this->payload->handle($session, $user, $request->validated()));
        } catch (NotFoundHttpException) {
            return AdminResponse::error(trans_message('estimate_generation.building_model_not_found'), 404);
        } catch (\Throwable $exception) {
            Log::error('[EstimateGeneration] Project model review read failed', ['exception'=>$exception,'organization_id'=>(int)($request->user()?->current_organization_id??0),'project_id'=>(int)$project->getKey(),'session_id'=>(int)$session->getKey()]);
            return AdminResponse::error(trans_message('estimate_generation.building_model_error'), 500);
        }
    }
}
