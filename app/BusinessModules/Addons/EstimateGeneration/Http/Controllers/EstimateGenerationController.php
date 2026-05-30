<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Http\Controllers;

use App\BusinessModules\Addons\EstimateGeneration\Http\Requests\ApplyEstimateGenerationDraftRequest;
use App\BusinessModules\Addons\EstimateGeneration\Http\Requests\CreateEstimateGenerationSessionRequest;
use App\BusinessModules\Addons\EstimateGeneration\Http\Requests\EstimateGenerationFeedbackRequest;
use App\BusinessModules\Addons\EstimateGeneration\Http\Requests\RebuildEstimateGenerationSectionRequest;
use App\BusinessModules\Addons\EstimateGeneration\Http\Requests\SelectEstimateGenerationNormativeCandidateRequest;
use App\BusinessModules\Addons\EstimateGeneration\Http\Requests\UploadEstimateGenerationDocumentsRequest;
use App\BusinessModules\Addons\EstimateGeneration\Http\Resources\EstimateGenerationDocumentResource;
use App\BusinessModules\Addons\EstimateGeneration\Http\Resources\EstimateGenerationSessionResource;
use App\BusinessModules\Addons\EstimateGeneration\Jobs\GenerateEstimateDraftJob;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationFeedback;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationPackage;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Services\DocumentParsingService;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateDraftPersistenceService;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateGenerationExcelExportService;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateGenerationOrchestrator;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateGenerationPackagePresenter;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateGenerationRegionalContextResolver;
use App\BusinessModules\Addons\EstimateGeneration\Services\Learning\EstimateGenerationLearningRecorder;
use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\NormativeCandidateSelectionService;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\DocumentGenerationReadinessService;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Illuminate\Support\Facades\Log;

use function trans_message;

class EstimateGenerationController extends Controller
{
    public function __construct(
        protected EstimateGenerationOrchestrator $orchestrator,
        protected DocumentParsingService $documentParsingService,
        protected EstimateDraftPersistenceService $draftPersistenceService,
        protected EstimateGenerationExcelExportService $excelExportService,
        protected EstimateGenerationRegionalContextResolver $regionalContextResolver,
        protected EstimateGenerationPackagePresenter $packagePresenter,
        protected NormativeCandidateSelectionService $candidateSelectionService,
        protected DocumentGenerationReadinessService $documentReadinessService,
        protected EstimateGenerationLearningRecorder $learningRecorder,
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
            $validated = $request->validated();

            $session = EstimateGenerationSession::create([
                'organization_id' => $user->current_organization_id,
                'project_id' => $project->id,
                'user_id' => $user->id,
                'status' => 'created',
                'processing_stage' => 'created',
                'processing_progress' => 0,
                'input_payload' => array_merge($validated, [
                    'parameters' => $validated['parameters'] ?? [],
                    'regional_context' => $this->regionalContextResolver->resolve($validated),
                ]),
                'problem_flags' => [],
            ]);

            return AdminResponse::success(
                $this->sessionPayload($session->load('documents')),
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
                'documents' => EstimateGenerationDocumentResource::collection($documents)->resolve(),
                'documents_summary' => $this->documentReadinessService->evaluate($session->fresh(['documents']))['summary'],
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

            $readiness = $this->documentReadinessService->evaluate($session->load('documents'));

            if (!$readiness['can_analyze']) {
                return AdminResponse::error(
                    trans_message($readiness['blocking_message_key'] ?? 'estimate_generation.documents_processing'),
                    409,
                    null,
                    ['documents_summary' => $readiness['summary']]
                );
            }

            if (!$this->hasGenerationInput($session, $readiness['summary'])) {
                return AdminResponse::error(
                    trans_message('estimate_generation.input_required'),
                    422,
                    null,
                    ['documents_summary' => $readiness['summary']]
                );
            }

            $session = $this->orchestrator->analyze($session);

            return AdminResponse::success(
                $this->sessionPayload($session->load('documents')),
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

            $readiness = $this->documentReadinessService->evaluate($session->load('documents'));

            if (!$readiness['can_generate']) {
                return AdminResponse::error(
                    trans_message($readiness['blocking_message_key'] ?? 'estimate_generation.documents_require_action'),
                    409,
                    null,
                    ['documents_summary' => $readiness['summary']]
                );
            }

            if (!$this->hasGenerationInput($session, $readiness['summary'])) {
                return AdminResponse::error(
                    trans_message('estimate_generation.input_required'),
                    422,
                    null,
                    ['documents_summary' => $readiness['summary']]
                );
            }

            if (!in_array($session->status, ['queued', 'processing'], true)) {
                $session->forceFill([
                    'status' => 'queued',
                    'processing_stage' => 'queued',
                    'processing_progress' => 40,
                    'last_error' => null,
                ])->save();

                GenerateEstimateDraftJob::dispatch($session->id)->onQueue(GenerateEstimateDraftJob::QUEUE);
            }

            return AdminResponse::success(
                $this->sessionPayload($session->fresh(['documents'])),
                trans_message('estimate_generation.generation_queued'),
                202
            );
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

        return AdminResponse::success($this->sessionPayload($session->load('documents')));
    }

    public function status(Request $request, Project $project, EstimateGenerationSession $session): JsonResponse
    {
        $this->guardSession($request, $project, $session);
        $session->load('documents');
        $packages = $session->packages()->get();
        $documentsSummary = $this->documentReadinessService->evaluate($session)['summary'];

        return AdminResponse::success([
            'id' => $session->id,
            'status' => $session->status,
            'processing_stage' => $session->processing_stage,
            'processing_progress' => $session->processing_progress,
            'packages_summary' => $this->packagePresenter->collection($packages)['summary'],
            'documents_summary' => $documentsSummary,
            'problem_flags_count' => count($session->problem_flags ?? []),
            'last_error' => $session->last_error,
            'updated_at' => $session->updated_at?->toISOString(),
        ]);
    }

    public function packages(Request $request, Project $project, EstimateGenerationSession $session): JsonResponse
    {
        $this->guardSession($request, $project, $session);

        return AdminResponse::success(
            $this->packagePresenter->collection($session->packages()->get())
        );
    }

    public function package(Request $request, Project $project, EstimateGenerationSession $session, EstimateGenerationPackage $package): JsonResponse
    {
        $this->guardSession($request, $project, $session);

        if ((int) $package->session_id !== (int) $session->id) {
            abort(404);
        }

        $perPage = min(max((int) $request->query('per_page', 100), 1), 500);
        $items = $package->items()
            ->limit($perPage)
            ->get();

        return AdminResponse::success(
            $this->packagePresenter->detail($package, $items)
        );
    }

    public function draft(Request $request, Project $project, EstimateGenerationSession $session): JsonResponse
    {
        $this->guardSession($request, $project, $session);

        return AdminResponse::success($session->draft_payload ?? []);
    }

    public function export(Request $request, Project $project, EstimateGenerationSession $session): Response|StreamedResponse|JsonResponse
    {
        try {
            $this->guardSession($request, $project, $session);

            $draft = $session->draft_payload ?? [];
            $format = (string) $request->query('format', 'excel');

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

            if ($format === 'json') {
                return response()->streamDownload(function () use ($draft): void {
                    echo json_encode($draft, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                }, 'estimate-generation-draft-' . $session->id . '.json', [
                    'Content-Type' => 'application/json; charset=UTF-8',
                ]);
            }

            $result = $this->excelExportService->export($session->loadMissing(['project.organization', 'organization']));
            $filename = $result['filename'];
            $encodedFilename = rawurlencode($filename);

            return response($result['content'])
                ->header('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
                ->header('Content-Disposition', "attachment; filename=\"{$filename}\"; filename*=UTF-8''{$encodedFilename}");
        } catch (\Throwable $e) {
            Log::error('[EstimateGeneration] Export failed', [
                'error' => $e->getMessage(),
                'session_id' => $session->id,
            ]);

            return AdminResponse::error(trans_message('estimate_generation.export_error'), 500);
        }
    }

    public function apply(ApplyEstimateGenerationDraftRequest $request, Project $project, EstimateGenerationSession $session): JsonResponse
    {
        try {
            $this->guardSession($request, $project, $session);

            if ($session->status === 'blocked') {
                return AdminResponse::error(trans_message('estimate_generation.apply_blocked'), 422);
            }

            $estimate = $this->draftPersistenceService->apply($session, $request->validated(), $request->user());

            return AdminResponse::success([
                'estimate_id' => $estimate->id,
            ], trans_message('estimate_generation.draft_applied'));
        } catch (ValidationException $e) {
            return AdminResponse::error($e->getMessage(), 422, $e->errors());
        } catch (\Throwable $e) {
            Log::error('[EstimateGeneration] Apply failed', [
                'error' => $e->getMessage(),
                'session_id' => $session->id,
            ]);

            return AdminResponse::error(trans_message('estimate_generation.apply_error'), 500);
        }
    }

    public function selectNormativeCandidate(
        SelectEstimateGenerationNormativeCandidateRequest $request,
        Project $project,
        EstimateGenerationSession $session
    ): JsonResponse {
        try {
            $this->guardSession($request, $project, $session);

            $this->candidateSelectionService->select(
                $session,
                (string) $request->validated('work_item_key'),
                (int) $request->validated('norm_id')
            );

            return AdminResponse::success(
                (new EstimateGenerationSessionResource($session->fresh(['documents'])))->resolve(),
                trans_message('estimate_generation.normative_candidate_selected')
            );
        } catch (ValidationException $e) {
            return AdminResponse::error($e->getMessage(), 422, $e->errors());
        } catch (\Throwable $e) {
            Log::error('[EstimateGeneration] Normative candidate selection failed', [
                'error' => $e->getMessage(),
                'session_id' => $session->id,
            ]);

            return AdminResponse::error(trans_message('estimate_generation.normative_candidate_select_error'), 500);
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
            $this->learningRecorder->recordUserRejection($session, $feedback);

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

    /**
     * @return array<string, mixed>
     */
    private function sessionPayload(EstimateGenerationSession $session): array
    {
        $session->loadMissing('documents');
        $payload = (new EstimateGenerationSessionResource($session))->resolve();
        $payload['documents_summary'] = $this->documentReadinessService->evaluate($session)['summary'];

        return $payload;
    }

    /**
     * @param array<string, mixed> $documentsSummary
     */
    private function hasGenerationInput(EstimateGenerationSession $session, array $documentsSummary): bool
    {
        $description = trim((string) ($session->input_payload['description'] ?? ''));

        return $description !== '' || (int) ($documentsSummary['ready_count'] ?? 0) > 0;
    }
}
