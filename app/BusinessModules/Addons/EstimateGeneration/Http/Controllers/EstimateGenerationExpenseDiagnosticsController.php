<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Http\Controllers;

use App\BusinessModules\Addons\EstimateGeneration\Application\Credits\ReadEstimatorQaExpenseDiagnostics;
use App\BusinessModules\Addons\EstimateGeneration\Http\Requests\EstimatorQaExpenseDiagnosticsRequest;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use App\Models\Project;
use Illuminate\Http\JsonResponse;

final class EstimateGenerationExpenseDiagnosticsController extends Controller
{
    public function show(EstimatorQaExpenseDiagnosticsRequest $request, Project $project, EstimateGenerationSession $session,
        ReadEstimatorQaExpenseDiagnostics $diagnostics): JsonResponse
    {
        $input = $request->validated();

        return AdminResponse::success($diagnostics->handle($request->user(), (int) $project->id, (int) $session->id,
            (int) ($input['page'] ?? 1), (int) ($input['per_page'] ?? 100), (bool) ($input['unknown_only'] ?? false)))
            ->header('Cache-Control', 'private, no-store');
    }
}
