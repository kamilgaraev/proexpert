<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Http\Controllers;

use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentCostJournalReader;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\ReadDocumentAdmission;
use App\BusinessModules\Addons\EstimateGeneration\Http\Resources\EstimateGenerationDocumentResource;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class EstimateGenerationDocumentAdmissionController extends Controller
{
    public function __construct(private readonly ReadDocumentAdmission $reader,
        private readonly DocumentCostJournalReader $costJournal) {}

    public function show(Request $request, Project $project, EstimateGenerationSession $session, string $requestId): JsonResponse
    {
        $status = $this->reader->handle($session, (int) $project->id, $request->user(), $requestId);
        $data = ['request_id' => $status->requestId, 'status' => $status->status];
        if ($status->result !== null) {
            $this->costJournal->attach($status->result->documents);
            $data['documents'] = EstimateGenerationDocumentResource::collection($status->result->documents)->resolve();
            $data['documents_summary'] = $status->result->summary;
        }

        return AdminResponse::success($data);
    }
}
