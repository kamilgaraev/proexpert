<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Http\Controllers;

use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\IgnoreEstimateGenerationDocument;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\RetryEstimateGenerationDocument;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\UploadEstimateGenerationDocuments;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\InvalidEstimateGenerationState;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\InvalidEstimateGenerationTransition;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\StaleEstimateGenerationState;
use App\BusinessModules\Addons\EstimateGeneration\Http\Requests\IgnoreEstimateGenerationDocumentRequest;
use App\BusinessModules\Addons\EstimateGeneration\Http\Requests\RetryEstimateGenerationDocumentRequest;
use App\BusinessModules\Addons\EstimateGeneration\Http\Requests\UploadEstimateGenerationDocumentsRequest;
use App\BusinessModules\Addons\EstimateGeneration\Http\Resources\EstimateGenerationDocumentDetailResource;
use App\BusinessModules\Addons\EstimateGeneration\Http\Resources\EstimateGenerationDocumentResource;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\DocumentGenerationReadinessService;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

use function trans_message;

class EstimateGenerationDocumentController extends Controller
{
    public function __construct(
        private readonly DocumentGenerationReadinessService $readinessService,
        private readonly RetryEstimateGenerationDocument $retryDocument,
        private readonly IgnoreEstimateGenerationDocument $ignoreDocument,
        private readonly UploadEstimateGenerationDocuments $uploadDocuments,
    ) {}

    public function upload(UploadEstimateGenerationDocumentsRequest $request, Project $project, EstimateGenerationSession $session): JsonResponse
    {
        try {
            $this->guardSession($request, $project, $session);
            $result = $this->uploadDocuments->handle(
                $session,
                (int) $request->validated('state_version'),
                $request->file('files', []),
                $request->user(),
            );

            return AdminResponse::success([
                'documents' => EstimateGenerationDocumentResource::collection($result->documents)->resolve(),
                'documents_summary' => $result->summary,
            ], trans_message('estimate_generation.documents_uploaded'));
        } catch (StaleEstimateGenerationState|InvalidEstimateGenerationTransition|InvalidEstimateGenerationState) {
            return AdminResponse::error(trans_message('estimate_generation.state_conflict'), 409);
        } catch (\Throwable) {
            Log::error('[EstimateGeneration] Failed to upload documents', [
                'failure_code' => 'document_upload_failed',
                'session_id' => $session->id,
            ]);

            return AdminResponse::error(trans_message('estimate_generation.documents_upload_error'), 500);
        }
    }

    public function index(Request $request, Project $project, EstimateGenerationSession $session): JsonResponse
    {
        return $this->safeReadResponse(function () use ($request, $project, $session): JsonResponse {
            $this->guardSession($request, $project, $session);
            $documents = $session->documents()
                ->withCount(['pages', 'facts', 'drawingElements', 'quantityTakeoffs', 'scopeInferences'])
                ->orderBy('id')
                ->get();

            return AdminResponse::success([
                'documents' => EstimateGenerationDocumentResource::collection($documents)->resolve(),
                'documents_summary' => $this->readinessService->summary($documents),
            ]);
        }, 'list documents', ['project_id' => $project->id, 'session_id' => $session->id]);
    }

    public function show(
        Request $request,
        Project $project,
        EstimateGenerationSession $session,
        EstimateGenerationDocument $document
    ): JsonResponse {
        return $this->safeReadResponse(function () use ($request, $project, $session, $document): JsonResponse {
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
        }, 'show document', [
            'project_id' => $project->id,
            'session_id' => $session->id,
            'document_id' => $document->id,
        ]);
    }

    public function retry(
        RetryEstimateGenerationDocumentRequest $request,
        Project $project,
        EstimateGenerationSession $session,
        EstimateGenerationDocument $document
    ): JsonResponse {
        $this->guardDocument($request, $project, $session, $document);

        try {
            $result = $this->retryDocument->handle(
                $session,
                $document,
                (int) $request->validated('state_version'),
                $request->validated('reason'),
            );

            return AdminResponse::success([
                'document' => (new EstimateGenerationDocumentResource($result->document))->resolve(),
                'documents_summary' => $result->summary,
            ], trans_message($result->messageKey));
        } catch (ValidationException $e) {
            return AdminResponse::error(trans_message('estimate_generation.validation_error'), 422, $e->errors());
        } catch (StaleEstimateGenerationState) {
            return AdminResponse::error(trans_message('estimate_generation.state_conflict'), 409);
        } catch (InvalidEstimateGenerationTransition|InvalidEstimateGenerationState) {
            return AdminResponse::error(trans_message('estimate_generation.state_conflict'), 409);
        } catch (\Throwable $e) {
            Log::error('[EstimateGeneration] Document retry failed', [
                'failure_code' => 'document_retry_failed',
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
            $result = $this->ignoreDocument->handle(
                $session,
                $document,
                (int) $request->validated('state_version'),
                $request->validated('reason'),
            );

            return AdminResponse::success([
                'document' => (new EstimateGenerationDocumentResource($result->document))->resolve(),
                'documents_summary' => $result->summary,
            ], trans_message($result->messageKey));
        } catch (ValidationException $e) {
            return AdminResponse::error(trans_message('estimate_generation.validation_error'), 422, $e->errors());
        } catch (StaleEstimateGenerationState) {
            return AdminResponse::error(trans_message('estimate_generation.state_conflict'), 409);
        } catch (InvalidEstimateGenerationTransition|InvalidEstimateGenerationState) {
            return AdminResponse::error(trans_message('estimate_generation.state_conflict'), 409);
        } catch (\Throwable $e) {
            Log::error('[EstimateGeneration] Document ignore failed', [
                'failure_code' => 'document_ignore_failed',
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

    /** @param callable(): JsonResponse $response @param array<string, mixed> $context */
    private function safeReadResponse(callable $response, string $operation, array $context): JsonResponse
    {
        try {
            return $response();
        } catch (HttpExceptionInterface $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            Log::error('[EstimateGeneration] Document read endpoint failed', [
                ...$context,
                'operation' => $operation,
                'failure_code' => 'document_read_failed',
            ]);

            return AdminResponse::error(trans_message('estimate_generation.read_error'), 500);
        }
    }
}
