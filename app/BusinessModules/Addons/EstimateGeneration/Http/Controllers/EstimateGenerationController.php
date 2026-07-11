<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Http\Controllers;

use App\BusinessModules\Addons\EstimateGeneration\Application\Apply\ApplyGeneratedEstimate;
use App\BusinessModules\Addons\EstimateGeneration\Application\Apply\ApplyGeneratedEstimateCommand;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\UploadEstimateGenerationDocuments;
use App\BusinessModules\Addons\EstimateGeneration\Application\Generation\AnalyzeEstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Application\Generation\RebuildGeneratedSection;
use App\BusinessModules\Addons\EstimateGeneration\Application\Generation\RequestEstimateGeneration;
use App\BusinessModules\Addons\EstimateGeneration\Application\Review\RecordEstimateGenerationFeedback;
use App\BusinessModules\Addons\EstimateGeneration\Application\Review\SelectNormativeCandidate;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\CreateEstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\InvalidEstimateGenerationState;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\InvalidEstimateGenerationTransition;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\StaleEstimateGenerationState;
use App\BusinessModules\Addons\EstimateGeneration\Enums\EstimateGenerationMode;
use App\BusinessModules\Addons\EstimateGeneration\Http\Requests\AnalyzeEstimateGenerationRequest;
use App\BusinessModules\Addons\EstimateGeneration\Http\Requests\ApplyEstimateGenerationDraftRequest;
use App\BusinessModules\Addons\EstimateGeneration\Http\Requests\CreateEstimateGenerationSessionRequest;
use App\BusinessModules\Addons\EstimateGeneration\Http\Requests\EstimateGenerationFeedbackRequest;
use App\BusinessModules\Addons\EstimateGeneration\Http\Requests\GenerateEstimateGenerationRequest;
use App\BusinessModules\Addons\EstimateGeneration\Http\Requests\RebuildEstimateGenerationSectionRequest;
use App\BusinessModules\Addons\EstimateGeneration\Http\Requests\SearchEstimateGenerationNormativeCandidatesRequest;
use App\BusinessModules\Addons\EstimateGeneration\Http\Requests\SelectEstimateGenerationNormativeCandidateRequest;
use App\BusinessModules\Addons\EstimateGeneration\Http\Requests\UploadEstimateGenerationDocumentsRequest;
use App\BusinessModules\Addons\EstimateGeneration\Http\Resources\EstimateGenerationDocumentResource;
use App\BusinessModules\Addons\EstimateGeneration\Http\Resources\EstimateGenerationSessionListResource;
use App\BusinessModules\Addons\EstimateGeneration\Http\Resources\EstimateGenerationSessionResource;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationPackage;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationPackageItem;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateGenerationExcelExportService;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateGenerationFinalWorkItemGuard;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateGenerationPackagePresenter;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateGenerationRegionalContextResolver;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateGenerationReviewItemService;
use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\NormativeCandidateManualSearchService;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

use function trans_message;

class EstimateGenerationController extends Controller
{
    public function __construct(
        protected ApplyGeneratedEstimate $applyGeneratedEstimate,
        protected EstimateGenerationExcelExportService $excelExportService,
        protected EstimateGenerationRegionalContextResolver $regionalContextResolver,
        protected EstimateGenerationFinalWorkItemGuard $finalWorkItemGuard,
        protected EstimateGenerationPackagePresenter $packagePresenter,
        protected NormativeCandidateManualSearchService $candidateManualSearchService,
        protected EstimateGenerationReviewItemService $reviewItemService,
        protected CreateEstimateGenerationSession $createSession,
        protected SelectNormativeCandidate $selectNormativeCandidate,
        protected RecordEstimateGenerationFeedback $recordFeedback,
        protected RebuildGeneratedSection $rebuildGeneratedSection,
        protected UploadEstimateGenerationDocuments $uploadDocumentsAction,
        protected AnalyzeEstimateGenerationSession $analyzeSession,
        protected RequestEstimateGeneration $requestGeneration,
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

            $session = $this->createSession->handle([
                'organization_id' => $user->current_organization_id,
                'project_id' => $project->id,
                'user_id' => $user->id,
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
            $result = $this->uploadDocumentsAction->handle(
                $session,
                (int) $request->validated('state_version'),
                $request->file('files', []),
                $request->user(),
            );

            return AdminResponse::success([
                'documents' => EstimateGenerationDocumentResource::collection($result->documents)->resolve(),
                'documents_summary' => $result->summary,
            ], trans_message('estimate_generation.documents_uploaded'));
        } catch (StaleEstimateGenerationState) {
            return AdminResponse::error(trans_message('estimate_generation.state_conflict'), 409);
        } catch (InvalidEstimateGenerationTransition|InvalidEstimateGenerationState) {
            return AdminResponse::error(trans_message('estimate_generation.state_conflict'), 409);
        } catch (\Throwable $e) {
            Log::error('[EstimateGeneration] Failed to upload documents', [
                'error' => $e->getMessage(),
                'session_id' => $session->id,
            ]);

            return AdminResponse::error(trans_message('estimate_generation.documents_upload_error'), 500);
        }
    }

    public function analyze(AnalyzeEstimateGenerationRequest $request, Project $project, EstimateGenerationSession $session): JsonResponse
    {
        try {
            $this->guardSession($request, $project, $session);
            $result = $this->analyzeSession->handle($session, (int) $request->validated('state_version'));
            if (! $result->successful) {
                return AdminResponse::error(
                    trans_message($result->messageKey),
                    $result->httpStatus,
                    null,
                    $result->context,
                );
            }

            return AdminResponse::success(
                $this->sessionPayload($result->session->load('documents')),
                trans_message($result->messageKey),
            );
        } catch (StaleEstimateGenerationState) {
            return AdminResponse::error(trans_message('estimate_generation.state_conflict'), 409);
        } catch (InvalidEstimateGenerationTransition|InvalidEstimateGenerationState) {
            return AdminResponse::error(trans_message('estimate_generation.state_conflict'), 409);
        } catch (\Throwable $e) {
            Log::error('[EstimateGeneration] Analyze failed', [
                'error' => $e->getMessage(),
                'session_id' => $session->id,
            ]);

            return AdminResponse::error(trans_message('estimate_generation.analysis_error'), 500);
        }
    }

    public function generate(GenerateEstimateGenerationRequest $request, Project $project, EstimateGenerationSession $session): JsonResponse
    {
        try {
            $this->guardSession($request, $project, $session);
            $result = $this->requestGeneration->handle(
                $session,
                (int) $request->validated('state_version'),
                $request->validated('generation_mode'),
            );
            if (! $result->successful) {
                return AdminResponse::error(
                    trans_message($result->messageKey),
                    $result->httpStatus,
                    null,
                    $result->context,
                );
            }

            return AdminResponse::success(
                $this->sessionPayload($result->session->fresh(['documents']) ?? $result->session),
                trans_message($result->messageKey),
                $result->httpStatus,
            );
        } catch (StaleEstimateGenerationState) {
            return AdminResponse::error(trans_message('estimate_generation.state_conflict'), 409);
        } catch (InvalidEstimateGenerationTransition|InvalidEstimateGenerationState) {
            return AdminResponse::error(trans_message('estimate_generation.state_conflict'), 409);
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

            $session = $this->selectNormativeCandidate->handle(
                (int) $session->getKey(),
                (int) $request->user()->current_organization_id,
                (int) $project->getKey(),
                (int) $request->validated('state_version'),
                (string) $request->validated('work_item_key'),
                (int) $request->validated('norm_id'),
                $request->validated('selection_source') === 'catalog_search',
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
        } catch (StaleEstimateGenerationState) {
            return AdminResponse::error(trans_message('estimate_generation.state_conflict'), 409);
        } catch (InvalidEstimateGenerationTransition|InvalidEstimateGenerationState) {
            return AdminResponse::error(trans_message('estimate_generation.state_conflict'), 409);
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
            $session = $this->rebuildGeneratedSection->handle(
                $session,
                (int) $request->validated('state_version'),
                (string) $request->validated('local_estimate_key'),
            );

            return AdminResponse::success($session->draft_payload, trans_message('estimate_generation.section_rebuilt'));
        } catch (StaleEstimateGenerationState) {
            return AdminResponse::error(trans_message('estimate_generation.state_conflict'), 409);
        } catch (InvalidEstimateGenerationTransition|InvalidEstimateGenerationState) {
            return AdminResponse::error(trans_message('estimate_generation.state_conflict'), 409);
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

            $feedbackId = $this->recordFeedback->handle(
                (int) $session->getKey(),
                (int) $request->user()->current_organization_id,
                (int) $project->getKey(),
                (int) $request->user()->getKey(),
                (int) $request->validated('state_version'),
                $request->validated(),
            );

            $responseScope = (string) ($request->validated('response_scope') ?? 'full');

            return AdminResponse::success(
                $this->feedbackPayload($session->fresh() ?? $session, $feedbackId, $responseScope),
                trans_message('estimate_generation.feedback_saved')
            );
        } catch (ValidationException $e) {
            return AdminResponse::error($e->getMessage(), 422, $e->errors());
        } catch (StaleEstimateGenerationState) {
            return AdminResponse::error(trans_message('estimate_generation.state_conflict'), 409);
        } catch (InvalidEstimateGenerationTransition|InvalidEstimateGenerationState) {
            return AdminResponse::error(trans_message('estimate_generation.state_conflict'), 409);
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
}
