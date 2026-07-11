<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Http\Controllers;

use App\BusinessModules\Addons\EstimateGeneration\Application\Apply\ApplyGeneratedEstimate;
use App\BusinessModules\Addons\EstimateGeneration\Application\Apply\ApplyGeneratedEstimateCommand;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\EstimateGenerationStatus;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\InvalidEstimateGenerationTransition;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\StaleEstimateGenerationState;
use App\BusinessModules\Addons\EstimateGeneration\Enums\EstimateGenerationMode;
use App\BusinessModules\Addons\EstimateGeneration\Http\Requests\ApplyEstimateGenerationDraftRequest;
use App\BusinessModules\Addons\EstimateGeneration\Http\Requests\CreateEstimateGenerationSessionRequest;
use App\BusinessModules\Addons\EstimateGeneration\Http\Requests\EstimateGenerationFeedbackRequest;
use App\BusinessModules\Addons\EstimateGeneration\Http\Requests\RebuildEstimateGenerationSectionRequest;
use App\BusinessModules\Addons\EstimateGeneration\Http\Requests\SearchEstimateGenerationNormativeCandidatesRequest;
use App\BusinessModules\Addons\EstimateGeneration\Http\Requests\SelectEstimateGenerationNormativeCandidateRequest;
use App\BusinessModules\Addons\EstimateGeneration\Http\Requests\UploadEstimateGenerationDocumentsRequest;
use App\BusinessModules\Addons\EstimateGeneration\Http\Resources\EstimateGenerationDocumentResource;
use App\BusinessModules\Addons\EstimateGeneration\Http\Resources\EstimateGenerationSessionListResource;
use App\BusinessModules\Addons\EstimateGeneration\Http\Resources\EstimateGenerationSessionResource;
use App\BusinessModules\Addons\EstimateGeneration\Jobs\GenerateEstimateDraftJob;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationFeedback;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationPackage;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationPackageItem;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Services\DocumentParsingService;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateGenerationExcelExportService;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateGenerationFinalWorkItemGuard;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateGenerationOrchestrator;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateGenerationPackagePresenter;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateGenerationRegionalContextResolver;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateGenerationReviewItemService;
use App\BusinessModules\Addons\EstimateGeneration\Services\Learning\EstimateGenerationLearningRecorder;
use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\NormativeCandidateFeedbackService;
use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\NormativeCandidateManualSearchService;
use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\NormativeCandidateSelectionService;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\DocumentGenerationReadinessService;
use App\BusinessModules\Addons\EstimateGeneration\Services\Quality\EstimatorReadinessService;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

use function trans_message;

class EstimateGenerationController extends Controller
{
    public function __construct(
        protected EstimateGenerationOrchestrator $orchestrator,
        protected DocumentParsingService $documentParsingService,
        protected ApplyGeneratedEstimate $applyGeneratedEstimate,
        protected EstimateGenerationExcelExportService $excelExportService,
        protected EstimateGenerationRegionalContextResolver $regionalContextResolver,
        protected EstimateGenerationFinalWorkItemGuard $finalWorkItemGuard,
        protected EstimateGenerationPackagePresenter $packagePresenter,
        protected NormativeCandidateSelectionService $candidateSelectionService,
        protected DocumentGenerationReadinessService $documentReadinessService,
        protected EstimatorReadinessService $estimatorReadinessService,
        protected EstimateGenerationLearningRecorder $learningRecorder,
        protected NormativeCandidateManualSearchService $candidateManualSearchService,
        protected NormativeCandidateFeedbackService $candidateFeedbackService,
        protected EstimateGenerationReviewItemService $reviewItemService,
    ) {}

    public function index(Request $request, Project $project): JsonResponse
    {
        $user = $request->user();

        $sessions = EstimateGenerationSession::query()
            ->where('organization_id', $user->current_organization_id)
            ->where('project_id', $project->id)
            ->orderByDesc('id')
            ->paginate((int) $request->input('per_page', 10));

        return AdminResponse::paginated(
            EstimateGenerationSessionListResource::collection($sessions),
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
            $generationMode = EstimateGenerationMode::fromInput($validated['generation_mode'] ?? null)->value;

            $session = EstimateGenerationSession::create([
                'organization_id' => $user->current_organization_id,
                'project_id' => $project->id,
                'user_id' => $user->id,
                'status' => EstimateGenerationStatus::Draft->value,
                'processing_stage' => 'created',
                'processing_progress' => 0,
                'input_payload' => array_merge($validated, [
                    'generation_mode' => $generationMode,
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

            if (! $readiness['can_analyze']) {
                return AdminResponse::error(
                    trans_message($readiness['blocking_message_key'] ?? 'estimate_generation.documents_processing'),
                    409,
                    null,
                    ['documents_summary' => $readiness['summary']]
                );
            }

            if (! $this->hasGenerationInput($session, $readiness['summary'])) {
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
            $generationMode = EstimateGenerationMode::fromInput(
                $request->input('generation_mode', $session->input_payload['generation_mode'] ?? null)
            )->value;

            if (($session->input_payload['generation_mode'] ?? null) !== $generationMode) {
                $session->forceFill([
                    'input_payload' => [
                        ...($session->input_payload ?? []),
                        'generation_mode' => $generationMode,
                    ],
                ])->save();
            }

            $readiness = $this->documentReadinessService->evaluate($session->load('documents'));

            if (! $readiness['can_generate']) {
                if ($this->canWaitForDocuments($session, $readiness['summary'])) {
                    $session->forceFill([
                        'status' => 'waiting_for_documents',
                        'processing_stage' => 'documents_processing',
                        'processing_progress' => max((int) ($session->processing_progress ?? 0), 5),
                        'last_error' => null,
                    ])->save();

                    return AdminResponse::success(
                        $this->sessionPayload($session->fresh(['documents'])),
                        trans_message('estimate_generation.generation_waiting_for_documents'),
                        202
                    );
                }

                return AdminResponse::error(
                    trans_message($readiness['blocking_message_key'] ?? 'estimate_generation.documents_require_action'),
                    409,
                    null,
                    ['documents_summary' => $readiness['summary']]
                );
            }

            if (! $this->hasGenerationInput($session, $readiness['summary'])) {
                return AdminResponse::error(
                    trans_message('estimate_generation.input_required'),
                    422,
                    null,
                    ['documents_summary' => $readiness['summary']]
                );
            }

            if (! in_array($session->status, ['queued', 'processing'], true)) {
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

        return AdminResponse::success($this->sessionPayload($session));
    }

    public function status(Request $request, Project $project, EstimateGenerationSession $session): JsonResponse
    {
        $this->guardSession($request, $project, $session);

        return AdminResponse::success($this->sessionPayload($session));
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
            ->whereNotIn('item_type', EstimateGenerationPackageItem::SERVICE_ITEM_TYPES)
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

    public function reviewItems(Request $request, Project $project, EstimateGenerationSession $session): JsonResponse
    {
        try {
            $this->guardSession($request, $project, $session);

            return AdminResponse::success($this->reviewItemService->forSession($session));
        } catch (\Throwable $e) {
            Log::error('[EstimateGeneration] Review items failed', [
                'project_id' => $project->id,
                'session_id' => $session->id,
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('estimate_generation.review_items_error'), 500);
        }
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
                        if (! is_array($localEstimate)) {
                            continue;
                        }

                        foreach ($localEstimate['sections'] ?? [] as $section) {
                            if (! is_array($section)) {
                                continue;
                            }

                            foreach ($section['work_items'] ?? [] as $workItem) {
                                if (! is_array($workItem) || ! $this->finalWorkItemGuard->isFinalEstimateWorkItem($workItem)) {
                                    continue;
                                }

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
                }, 'estimate-generation-draft-'.$session->id.'.csv', [
                    'Content-Type' => 'text/csv; charset=UTF-8',
                ]);
            }

            if ($format === 'json') {
                return response()->streamDownload(function () use ($draft): void {
                    echo json_encode($draft, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                }, 'estimate-generation-draft-'.$session->id.'.json', [
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

            $validated = $request->validated();
            $result = $this->applyGeneratedEstimate->handle(new ApplyGeneratedEstimateCommand(
                sessionId: (int) $session->getKey(),
                organizationId: (int) $request->user()->current_organization_id,
                projectId: (int) $project->getKey(),
                expectedStateVersion: (int) $validated['state_version'],
                name: isset($validated['name']) ? (string) $validated['name'] : null,
                type: isset($validated['type']) ? (string) $validated['type'] : null,
                estimateDate: isset($validated['estimate_date']) ? (string) $validated['estimate_date'] : null,
            ));

            return AdminResponse::success($result->toArray(), trans_message('estimate_generation.draft_applied'));
        } catch (ValidationException $e) {
            return AdminResponse::error($e->getMessage(), 422, $e->errors());
        } catch (StaleEstimateGenerationState $e) {
            return AdminResponse::error(trans_message('estimate_generation.state_conflict'), 409);
        } catch (InvalidEstimateGenerationTransition $e) {
            return AdminResponse::error(trans_message('estimate_generation.apply_not_ready'), 422);
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
                (int) $request->validated('norm_id'),
                $request->validated('selection_source') === 'catalog_search'
            );

            if ($request->validated('response_scope') === 'review_queue') {
                $session = $session->fresh() ?? $session;

                return AdminResponse::success([
                    'session' => $this->sessionPayload($session),
                    'review_queue' => $this->reviewItemService->forSession($session),
                ], trans_message('estimate_generation.normative_candidate_selected'));
            }

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

    public function searchNormativeCandidates(
        SearchEstimateGenerationNormativeCandidatesRequest $request,
        Project $project,
        EstimateGenerationSession $session
    ): JsonResponse {
        try {
            $this->guardSession($request, $project, $session);
            $validated = $request->validated();

            return AdminResponse::success($this->candidateManualSearchService->search(
                $session,
                (string) $validated['work_item_key'],
                isset($validated['query']) ? (string) $validated['query'] : null,
                (int) ($validated['limit'] ?? 10)
            ));
        } catch (ValidationException $e) {
            return AdminResponse::error($e->getMessage(), 422, $e->errors());
        } catch (\Throwable $e) {
            Log::error('[EstimateGeneration] Normative candidate search failed', [
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

            $feedbackId = DB::transaction(function () use ($request, $project, $session): int {
                $lockedSession = EstimateGenerationSession::query()
                    ->whereKey($session->id)
                    ->lockForUpdate()
                    ->firstOrFail();
                $this->guardSession($request, $project, $lockedSession);

                $feedback = EstimateGenerationFeedback::create([
                    'session_id' => $lockedSession->id,
                    'user_id' => $request->user()->id,
                    'feedback_type' => $request->validated('feedback_type'),
                    'section_key' => $request->validated('section_key'),
                    'work_item_key' => $request->validated('work_item_key'),
                    'payload' => $request->validated('payload', []),
                    'comments' => $request->validated('comments'),
                ]);
                $this->candidateFeedbackService->apply($lockedSession, $feedback);
                $this->learningRecorder->recordFeedbackDecision($lockedSession, $feedback);

                return (int) $feedback->id;
            });

            $responseScope = (string) ($request->validated('response_scope') ?? 'full');

            return AdminResponse::success(
                $this->feedbackPayload($session->fresh() ?? $session, $feedbackId, $responseScope),
                trans_message('estimate_generation.feedback_saved')
            );
        } catch (ValidationException $e) {
            return AdminResponse::error($e->getMessage(), 422, $e->errors());
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
        $this->loadSessionDocumentsForReadiness($session);

        return (new EstimateGenerationSessionResource($session))->resolve();
    }

    /**
     * @return array<string, mixed>
     */
    private function feedbackPayload(EstimateGenerationSession $session, int $feedbackId, string $responseScope = 'full'): array
    {
        $session->refresh();

        $payload = [
            'feedback_id' => $feedbackId,
            'session' => $this->sessionPayload($session),
            'review_queue' => $this->reviewItemService->forSession($session),
        ];

        if ($responseScope === 'review_queue') {
            return $payload;
        }

        return [
            ...$payload,
            'draft' => $session->draft_payload ?? [],
            'packages' => $this->packagePresenter->collection($session->packages()->get()),
        ];
    }

    private function loadSessionDocumentsForReadiness(EstimateGenerationSession $session): void
    {
        if ($session->relationLoaded('documents')) {
            return;
        }

        $session->load([
            'documents' => static fn ($query) => $query
                ->withCount(['pages', 'facts', 'drawingElements', 'quantityTakeoffs', 'scopeInferences'])
                ->orderBy('id'),
        ]);
    }

    /**
     * @param  array<string, mixed>  $documentsSummary
     */
    private function hasGenerationInput(EstimateGenerationSession $session, array $documentsSummary): bool
    {
        $description = trim((string) ($session->input_payload['description'] ?? ''));

        return $description !== '' || (int) ($documentsSummary['ready_count'] ?? 0) > 0;
    }

    /**
     * @param  array<string, mixed>  $documentsSummary
     */
    private function canWaitForDocuments(EstimateGenerationSession $session, array $documentsSummary): bool
    {
        if ((int) ($documentsSummary['pending_count'] ?? 0) <= 0 || (int) ($documentsSummary['action_required_count'] ?? 0) > 0) {
            return false;
        }

        $description = trim((string) ($session->input_payload['description'] ?? ''));

        return $description !== '' || (int) ($documentsSummary['total'] ?? 0) > 0;
    }
}
