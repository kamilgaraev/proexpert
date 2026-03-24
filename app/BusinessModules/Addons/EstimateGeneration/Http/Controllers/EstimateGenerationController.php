<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Http\Controllers;

use App\BusinessModules\Addons\EstimateGeneration\Http\Requests\ApplyEstimateGenerationDraftRequest;
use App\BusinessModules\Addons\EstimateGeneration\Http\Requests\CreateEstimateGenerationSessionRequest;
use App\BusinessModules\Addons\EstimateGeneration\Http\Requests\EstimateGenerationFeedbackRequest;
use App\BusinessModules\Addons\EstimateGeneration\Http\Requests\RebuildEstimateGenerationSectionRequest;
use App\BusinessModules\Addons\EstimateGeneration\Http\Requests\UploadEstimateGenerationDocumentsRequest;
use App\BusinessModules\Addons\EstimateGeneration\Http\Resources\EstimateGenerationSessionResource;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationFeedback;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Services\DocumentParsingService;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateDraftPersistenceService;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateGenerationOrchestrator;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Illuminate\Support\Facades\Log;

use function trans_message;

class EstimateGenerationController extends Controller
{
    public function __construct(
        protected EstimateGenerationOrchestrator $orchestrator,
        protected DocumentParsingService $documentParsingService,
        protected EstimateDraftPersistenceService $draftPersistenceService,
    ) {}

    public function index(Request $request, Project $project): JsonResponse
    {
        $user = $request->user();

        $sessions = EstimateGenerationSession::query()
            ->where('organization_id', $user->current_organization_id)
            ->where('project_id', $project->id)
            ->with('documents')
            ->orderByDesc('id')
            ->paginate((int) $request->input('per_page', 10));

        return AdminResponse::paginated(
            EstimateGenerationSessionResource::collection($sessions),
            [
                'current_page' => $sessions->currentPage(),
                'last_page' => $sessions->lastPage(),
                'per_page' => $sessions->perPage(),
                'total' => $sessions->total(),
            ]
        );
    }

    public function store(CreateEstimateGenerationSessionRequest $request, Project $project): JsonResponse
    {
        try {
            $user = $request->user();

            $session = EstimateGenerationSession::create([
                'organization_id' => $user->current_organization_id,
                'project_id' => $project->id,
                'user_id' => $user->id,
                'status' => 'created',
                'processing_stage' => 'created',
                'processing_progress' => 0,
                'input_payload' => array_merge($request->validated(), [
                    'parameters' => $request->validated('parameters', []),
                ]),
                'problem_flags' => [],
            ]);

            return AdminResponse::success(
                (new EstimateGenerationSessionResource($session->load('documents')))->resolve(),
                trans_message('estimate_generation.session_created'),
                201
            );
        } catch (\Throwable $e) {
            Log::error('[EstimateGeneration] Failed to create session', [
                'error' => $e->getMessage(),
                'project_id' => $project->id,
            ]);

            return AdminResponse::error(trans_message('estimate_generation.session_create_error'), 500);
        }
    }

    public function uploadDocuments(UploadEstimateGenerationDocumentsRequest $request, Project $project, EstimateGenerationSession $session): JsonResponse
    {
        try {
            $this->guardSession($request, $project, $session);

            $documents = $this->documentParsingService->storeParsedDocuments(
                $session,
                $request->file('files', []),
                $request->user()
            );

            return AdminResponse::success([
                'documents' => $documents->map(static fn ($document): array => [
                    'id' => $document->id,
                    'filename' => $document->filename,
                    'mime_type' => $document->mime_type,
                    'meta' => $document->meta ?? [],
                ])->all(),
            ], trans_message('estimate_generation.documents_uploaded'));
        } catch (\Throwable $e) {
            Log::error('[EstimateGeneration] Failed to upload documents', [
                'error' => $e->getMessage(),
                'session_id' => $session->id,
            ]);

            return AdminResponse::error(trans_message('estimate_generation.documents_upload_error'), 500);
        }
    }

    public function analyze(Request $request, Project $project, EstimateGenerationSession $session): JsonResponse
    {
        try {
            $this->guardSession($request, $project, $session);
            $session = $this->orchestrator->analyze($session);

            return AdminResponse::success(
                (new EstimateGenerationSessionResource($session->load('documents')))->resolve(),
                trans_message('estimate_generation.analysis_completed')
            );
        } catch (\Throwable $e) {
            Log::error('[EstimateGeneration] Analyze failed', [
                'error' => $e->getMessage(),
                'session_id' => $session->id,
            ]);

            return AdminResponse::error(trans_message('estimate_generation.analysis_error'), 500);
        }
    }

    public function generate(Request $request, Project $project, EstimateGenerationSession $session): JsonResponse
    {
        try {
            $this->guardSession($request, $project, $session);
            $session = $this->orchestrator->generate($session);

            return AdminResponse::success($session->draft_payload, trans_message('estimate_generation.generation_completed'));
        } catch (\Throwable $e) {
            Log::error('[EstimateGeneration] Generate failed', [
                'error' => $e->getMessage(),
                'session_id' => $session->id,
            ]);

            return AdminResponse::error(trans_message('estimate_generation.generation_error'), 500);
        }
    }

    public function show(Request $request, Project $project, EstimateGenerationSession $session): JsonResponse
    {
        $this->guardSession($request, $project, $session);

        return AdminResponse::success((new EstimateGenerationSessionResource($session->load('documents')))->resolve());
    }

    public function draft(Request $request, Project $project, EstimateGenerationSession $session): JsonResponse
    {
        $this->guardSession($request, $project, $session);

        return AdminResponse::success($session->draft_payload ?? []);
    }

    public function export(Request $request, Project $project, EstimateGenerationSession $session): StreamedResponse
    {
        $this->guardSession($request, $project, $session);

        $draft = $session->draft_payload ?? [];
        $format = (string) $request->query('format', 'json');

        if ($format === 'csv') {
            return response()->streamDownload(function () use ($draft): void {
                $handle = fopen('php://output', 'w');
                fputcsv($handle, ['Локальная смета', 'Раздел', 'Работа', 'Ед.', 'Кол-во', 'Итого', 'Основание']);

                foreach ($draft['local_estimates'] ?? [] as $localEstimate) {
                    foreach ($localEstimate['sections'] ?? [] as $section) {
                        foreach ($section['work_items'] ?? [] as $workItem) {
                            fputcsv($handle, [
                                $localEstimate['title'] ?? '',
                                $section['title'] ?? '',
                                $workItem['name'] ?? '',
                                $workItem['unit'] ?? '',
                                $workItem['quantity'] ?? '',
                                $workItem['total_cost'] ?? '',
                                $workItem['quantity_basis'] ?? '',
                            ]);
                        }
                    }
                }

                fclose($handle);
            }, 'estimate-generation-draft-' . $session->id . '.csv', [
                'Content-Type' => 'text/csv; charset=UTF-8',
            ]);
        }

        return response()->streamDownload(function () use ($draft): void {
            echo json_encode($draft, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }, 'estimate-generation-draft-' . $session->id . '.json', [
            'Content-Type' => 'application/json; charset=UTF-8',
        ]);
    }

    public function apply(ApplyEstimateGenerationDraftRequest $request, Project $project, EstimateGenerationSession $session): JsonResponse
    {
        try {
            $this->guardSession($request, $project, $session);
            $estimate = $this->draftPersistenceService->apply($session, $request->validated(), $request->user());

            return AdminResponse::success([
                'estimate_id' => $estimate->id,
            ], trans_message('estimate_generation.draft_applied'));
        } catch (\Throwable $e) {
            Log::error('[EstimateGeneration] Apply failed', [
                'error' => $e->getMessage(),
                'session_id' => $session->id,
            ]);

            return AdminResponse::error(trans_message('estimate_generation.apply_error'), 500);
        }
    }

    public function rebuildSection(RebuildEstimateGenerationSectionRequest $request, Project $project, EstimateGenerationSession $session): JsonResponse
    {
        try {
            $this->guardSession($request, $project, $session);
            $session = $this->orchestrator->rebuildSection($session, $request->validated('local_estimate_key'));

            return AdminResponse::success($session->draft_payload, trans_message('estimate_generation.section_rebuilt'));
        } catch (\Throwable $e) {
            Log::error('[EstimateGeneration] Rebuild section failed', [
                'error' => $e->getMessage(),
                'session_id' => $session->id,
            ]);

            return AdminResponse::error(trans_message('estimate_generation.section_rebuild_error'), 500);
        }
    }

    public function feedback(EstimateGenerationFeedbackRequest $request, Project $project, EstimateGenerationSession $session): JsonResponse
    {
        try {
            $this->guardSession($request, $project, $session);

            $feedback = EstimateGenerationFeedback::create([
                'session_id' => $session->id,
                'user_id' => $request->user()->id,
                'feedback_type' => $request->validated('feedback_type'),
                'section_key' => $request->validated('section_key'),
                'work_item_key' => $request->validated('work_item_key'),
                'payload' => $request->validated('payload', []),
                'comments' => $request->validated('comments'),
            ]);

            return AdminResponse::success([
                'feedback_id' => $feedback->id,
            ], trans_message('estimate_generation.feedback_saved'));
        } catch (\Throwable $e) {
            Log::error('[EstimateGeneration] Feedback failed', [
                'error' => $e->getMessage(),
                'session_id' => $session->id,
            ]);

            return AdminResponse::error(trans_message('estimate_generation.feedback_error'), 500);
        }
    }

    protected function guardSession(Request $request, Project $project, EstimateGenerationSession $session): void
    {
        $user = $request->user();

        if (
            (int) $session->organization_id !== (int) $user->current_organization_id ||
            (int) $session->project_id !== (int) $project->id
        ) {
            abort(403, trans_message('estimate_generation.access_denied'));
        }
    }
}
