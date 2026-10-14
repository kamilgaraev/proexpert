<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Http\Controllers;

use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\GetEvidenceSources;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class EstimateGenerationEvidenceController extends Controller
{
    public function show(Request $request, Project $project, EstimateGenerationSession $session, GetEvidenceSources $sources): JsonResponse
    {
        $input = $request->validate(['references' => ['required', 'array', 'min:1', 'max:50'],
            'references.*' => ['required', 'string', 'max:191', 'regex:/\A[A-Za-z0-9:._-]+\z/']]);

        return AdminResponse::success($sources->handle($request->user(), (int) $project->id, (int) $session->id, $input['references']))
            ->header('Cache-Control', 'private, no-store');
    }
}
