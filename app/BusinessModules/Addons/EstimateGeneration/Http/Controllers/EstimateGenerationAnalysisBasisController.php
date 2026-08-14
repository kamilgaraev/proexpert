<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Http\Controllers;

use App\BusinessModules\Addons\EstimateGeneration\Http\Presentation\AnalysisBasisPayloadService;
use App\BusinessModules\Addons\EstimateGeneration\Http\Requests\ShowEstimateGenerationAnalysisBasisRequest;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

use function trans_message;

final class EstimateGenerationAnalysisBasisController extends Controller
{
    public function __construct(private readonly AnalysisBasisPayloadService $payload) {}

    public function show(
        ShowEstimateGenerationAnalysisBasisRequest $request,
        Project $project,
        EstimateGenerationSession $session,
    ): JsonResponse {
        $organizationId = (int) ($request->user()?->current_organization_id ?? 0);
        if ((int) $project->organization_id !== $organizationId
            || (int) $session->organization_id !== $organizationId
            || (int) $session->project_id !== (int) $project->getKey()) {
            throw new NotFoundHttpException;
        }
        $validated = $request->validated();
        $payload = $this->payload->handle(
            $organizationId,
            (int) $project->getKey(),
            (int) $session->getKey(),
            (string) $validated['type'],
            (string) $validated['id'],
        );
        if ($payload === null) {
            return AdminResponse::error(trans_message('estimate_generation.analysis_basis.not_found'), 404);
        }

        return AdminResponse::success($payload)->withHeaders(['Cache-Control' => 'private, no-store']);
    }
}
