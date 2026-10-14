<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Http\Controllers;

use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\GetClarificationAnswerReceipt;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class EstimateGenerationAnswerReceiptController extends Controller
{
    public function show(Request $request, Project $project, EstimateGenerationSession $session, string $question,
        GetClarificationAnswerReceipt $receipt): JsonResponse
    {
        return AdminResponse::success($receipt->handle($request->user(), (int) $project->id, (int) $session->id,
            $question, $request->query()))->header('Cache-Control', 'private, no-store');
    }
}
