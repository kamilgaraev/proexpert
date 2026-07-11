<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Http\Controllers;

use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\ReconcileEstimateGenerationDocuments;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\StaleEstimateGenerationState;
use App\BusinessModules\Addons\EstimateGeneration\Http\Requests\IgnoreEstimateGenerationDocumentRequest;
use App\BusinessModules\Addons\EstimateGeneration\Http\Requests\RetryEstimateGenerationDocumentRequest;
use App\BusinessModules\Addons\EstimateGeneration\Http\Resources\EstimateGenerationDocumentDetailResource;
use App\BusinessModules\Addons\EstimateGeneration\Http\Resources\EstimateGenerationDocumentResource;
use App\BusinessModules\Addons\EstimateGeneration\Jobs\ProcessEstimateGenerationDocumentJob;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\DocumentGenerationReadinessService;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

use function trans_message;

class EstimateGenerationDocumentController extends Controller
{
    private const RETRYABLE_STATUSES = ['ready', 'failed', 'needs_review', 'ignored'];

    private const IGNORABLE_STATUSES = ['ready', 'failed', 'needs_review'];

    public function __construct(
        private readonly DocumentGenerationReadinessService $readinessService,
        private readonly ReconcileEstimateGenerationDocuments $documentReconciler,
    ) {}

    public function index(Request $request, Project $project, EstimateGenerationSession $session): JsonResponse
    {
        $this->guardSession($request, $project, $session);
        $documents = $session->documents()
            ->withCount(['pages', 'facts', 'drawingElements', 'quantityTakeoffs', 'scopeInferences'])
            ->orderBy('id')
            ->get();

        return AdminResponse::success([
            'documents' => EstimateGenerationDocumentResource::collection($documents)->resolve(),
            'documents_summary' => $this->readinessService->summary($documents),
        ]);
    }

    public function show(
        Request $request,
        Project $project,
        EstimateGenerationSession $session,
        EstimateGenerationDocument $document
    ): JsonResponse {
        $this->guardDocument($request, $project, $session, $document);

        return AdminResponse::success(
            (new EstimateGenerationDocumentDetailResource($document->load([
                'pages',
                'facts',
                'drawingElements',
                'quantityTakeoffs',
                'scopeInferences',
            ])))->resolve()
        );
    }

    public function retry(
        RetryEstimateGenerationDocumentRequest $request,
        Project $project,
        EstimateGenerationSession $session,
        EstimateGenerationDocument $document
    ): JsonResponse {
        $this->guardDocument($request, $project, $session, $document);

        try {
            if (! in_array((string) $document->status, self::RETRYABLE_STATUSES, true)) {
                return AdminResponse::error(trans_message('estimate_generation.document_retry_not_allowed'), 422);
            }

            $meta = is_array($document->meta) ? $document->meta : [];
            $reason = $request->input('reason');

            $document->forceFill([
                'status' => 'queued',
                'processing_stage' => 'stored',
                'progress_percent' => 0,
                'ocr_started_at' => null,
                'ocr_finished_at' => null,
                'error_code' => null,
                'error_message_key' => null,
                'error_context' => null,
                'ignored_at' => null,
                'meta' => [
                    ...$meta,
                    'retry_requested_at' => now()->toISOString(),
                    'retry_reason' => is_string($reason) && $reason !== '' ? mb_substr($reason, 0, 500) : null,
                ],
            ])->save();

            $this->documentReconciler->changed($session);

            ProcessEstimateGenerationDocumentJob::dispatch($document->id)
                ->onConnection(ProcessEstimateGenerationDocumentJob::CONNECTION)
                ->onQueue(ProcessEstimateGenerationDocumentJob::QUEUE)
                ->afterCommit();

            $session = $session->fresh(['documents']);

            return AdminResponse::success([
                'document' => (new EstimateGenerationDocumentResource($document->fresh()))->resolve(),
                'documents_summary' => $this->readinessService->evaluate($session)['summary'],
            ], trans_message('estimate_generation.document_retry_queued'));
        } catch (StaleEstimateGenerationState) {
            return AdminResponse::error(trans_message('estimate_generation.state_conflict'), 409);
        } catch (\Throwable $e) {
            Log::error('[EstimateGeneration] Document retry failed', [
                'error' => $e->getMessage(),
                'session_id' => $session->id,
                'document_id' => $document->id,
            ]);

            return AdminResponse::error(trans_message('estimate_generation.documents_upload_error'), 500);
        }
    }

    public function ignore(
        IgnoreEstimateGenerationDocumentRequest $request,
        Project $project,
        EstimateGenerationSession $session,
        EstimateGenerationDocument $document
    ): JsonResponse {
        $this->guardDocument($request, $project, $session, $document);

        try {
            if (! in_array((string) $document->status, self::IGNORABLE_STATUSES, true)) {
                return AdminResponse::error(trans_message('estimate_generation.document_ignore_not_allowed'), 422);
            }

            $meta = is_array($document->meta) ? $document->meta : [];
            $reason = $request->input('reason');

            $document->forceFill([
                'status' => 'ignored',
                'processing_stage' => 'completed',
                'progress_percent' => 100,
                'ignored_at' => now(),
                'meta' => [
                    ...$meta,
                    'ignored_reason' => is_string($reason) && $reason !== '' ? mb_substr($reason, 0, 500) : null,
                    'ignored_at' => now()->toISOString(),
                ],
            ])->save();

            $this->documentReconciler->reconcile($session);

            $session = $session->fresh(['documents']);

            return AdminResponse::success([
                'document' => (new EstimateGenerationDocumentResource($document->fresh()))->resolve(),
                'documents_summary' => $this->readinessService->evaluate($session)['summary'],
            ], trans_message('estimate_generation.document_ignored'));
        } catch (StaleEstimateGenerationState) {
            return AdminResponse::error(trans_message('estimate_generation.state_conflict'), 409);
        } catch (\Throwable $e) {
            Log::error('[EstimateGeneration] Document ignore failed', [
                'error' => $e->getMessage(),
                'session_id' => $session->id,
                'document_id' => $document->id,
            ]);

            return AdminResponse::error(trans_message('estimate_generation.documents_upload_error'), 500);
        }
    }

    private function guardSession(Request $request, Project $project, EstimateGenerationSession $session): void
    {
        $user = $request->user();

        if (
            (int) $session->organization_id !== (int) $user->current_organization_id ||
            (int) $session->project_id !== (int) $project->id
        ) {
            abort(403, trans_message('estimate_generation.access_denied'));
        }
    }

    private function guardDocument(
        Request $request,
        Project $project,
        EstimateGenerationSession $session,
        EstimateGenerationDocument $document
    ): void {
        $this->guardSession($request, $project, $session);

        if (
            (int) $document->session_id !== (int) $session->id ||
            (int) $document->organization_id !== (int) $session->organization_id ||
            (int) $document->project_id !== (int) $project->id
        ) {
            abort(404, trans_message('estimate_generation.document_not_found'));
        }
    }
}
