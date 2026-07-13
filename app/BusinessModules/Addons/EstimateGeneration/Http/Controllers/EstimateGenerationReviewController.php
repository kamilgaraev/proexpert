<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Http\Controllers;

use App\BusinessModules\Addons\EstimateGeneration\Application\Review\RecordEstimateGenerationFeedback;
use App\BusinessModules\Addons\EstimateGeneration\Application\Review\SelectNormativeCandidate;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\InvalidEstimateGenerationState;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\InvalidEstimateGenerationTransition;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\StaleEstimateGenerationState;
use App\BusinessModules\Addons\EstimateGeneration\Http\Requests\EstimateGenerationFeedbackRequest;
use App\BusinessModules\Addons\EstimateGeneration\Http\Requests\ListEstimateGenerationReviewItemsRequest;
use App\BusinessModules\Addons\EstimateGeneration\Http\Requests\SearchEstimateGenerationNormativeCandidatesRequest;
use App\BusinessModules\Addons\EstimateGeneration\Http\Requests\SelectEstimateGenerationNormativeCandidateRequest;
use App\BusinessModules\Addons\EstimateGeneration\Http\Resources\EstimateGenerationSessionResource;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateGenerationPackagePresenter;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateGenerationReviewItemService;
use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\NormativeCandidateManualSearchService;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

use function trans_message;

final class EstimateGenerationReviewController extends Controller
{
    public function __construct(
        private readonly NormativeCandidateManualSearchService $candidateSearch,
        private readonly EstimateGenerationReviewItemService $reviewItems,
        private readonly SelectNormativeCandidate $selectCandidate,
        private readonly RecordEstimateGenerationFeedback $recordFeedback,
        private readonly EstimateGenerationPackagePresenter $packagePresenter,
    ) {}

    public function index(ListEstimateGenerationReviewItemsRequest $request, Project $project, EstimateGenerationSession $session): JsonResponse
    {
        return $this->safeRead(function () use ($request, $project, $session): JsonResponse {
            $this->guard($request, $project, $session);

            return AdminResponse::success($this->reviewItems->forSession($session, $request->validated()));
        }, 'review items', $project, $session);
    }

    public function search(SearchEstimateGenerationNormativeCandidatesRequest $request, Project $project, EstimateGenerationSession $session): JsonResponse
    {
        try {
            $this->guard($request, $project, $session);
            $validated = $request->validated();

            return AdminResponse::success($this->candidateSearch->search($session, (string) $validated['work_item_key'], isset($validated['query']) ? (string) $validated['query'] : null, (int) ($validated['limit'] ?? 10)));
        } catch (ValidationException $exception) {
            return AdminResponse::error(trans_message('estimate_generation.validation_error'), 422, $exception->errors());
        } catch (\Throwable) {
            Log::error('[EstimateGeneration] Normative candidate search failed', ['failure_code' => 'normative_search_failed', 'session_id' => $session->id]);

            return AdminResponse::error(trans_message('estimate_generation.normative_candidate_select_error'), 500);
        }
    }

    public function select(SelectEstimateGenerationNormativeCandidateRequest $request, Project $project, EstimateGenerationSession $session): JsonResponse
    {
        try {
            $this->guard($request, $project, $session);
            $session = $this->selectCandidate->handle((int) $session->getKey(), (int) $request->user()->current_organization_id, (int) $project->getKey(), (int) $request->validated('state_version'), (string) $request->validated('work_item_key'), (int) $request->validated('norm_id'), $request->validated('selection_source') === 'catalog_search');
            if ($request->validated('response_scope') === 'review_queue') {
                $session = $session->fresh() ?? $session;

                return AdminResponse::success(['session' => $this->sessionPayload($session), 'review_queue' => $this->reviewItems->forSession($session)], trans_message('estimate_generation.normative_candidate_selected'));
            }

            return AdminResponse::success((new EstimateGenerationSessionResource($session->fresh(['documents'])))->resolve(), trans_message('estimate_generation.normative_candidate_selected'));
        } catch (ValidationException $exception) {
            return AdminResponse::error(trans_message('estimate_generation.validation_error'), 422, $exception->errors());
        } catch (StaleEstimateGenerationState|InvalidEstimateGenerationTransition|InvalidEstimateGenerationState) {
            return AdminResponse::error(trans_message('estimate_generation.state_conflict'), 409);
        } catch (\Throwable) {
            Log::error('[EstimateGeneration] Normative candidate selection failed', ['failure_code' => 'normative_selection_failed', 'session_id' => $session->id]);

            return AdminResponse::error(trans_message('estimate_generation.normative_candidate_select_error'), 500);
        }
    }

    public function feedback(EstimateGenerationFeedbackRequest $request, Project $project, EstimateGenerationSession $session): JsonResponse
    {
        try {
            $this->guard($request, $project, $session);
            $feedbackId = $this->recordFeedback->handle((int) $session->getKey(), (int) $request->user()->current_organization_id, (int) $project->getKey(), (int) $request->user()->getKey(), (int) $request->validated('state_version'), $request->validated());
            $session = $session->fresh() ?? $session;
            $payload = ['feedback_id' => $feedbackId, 'session' => $this->sessionPayload($session), 'review_queue' => $this->reviewItems->forSession($session)];
            if ((string) ($request->validated('response_scope') ?? 'full') !== 'review_queue') {
                $payload['draft'] = $session->draft_payload ?? [];
                $payload['packages'] = $this->packagePresenter->collection($session->packages()->get());
            }

            return AdminResponse::success($payload, trans_message('estimate_generation.feedback_saved'));
        } catch (ValidationException $exception) {
            return AdminResponse::error(trans_message('estimate_generation.validation_error'), 422, $exception->errors());
        } catch (StaleEstimateGenerationState|InvalidEstimateGenerationTransition|InvalidEstimateGenerationState) {
            return AdminResponse::error(trans_message('estimate_generation.state_conflict'), 409);
        } catch (\Throwable) {
            Log::error('[EstimateGeneration] Feedback failed', ['failure_code' => 'feedback_failed', 'session_id' => $session->id]);

            return AdminResponse::error(trans_message('estimate_generation.feedback_error'), 500);
        }
    }

    private function sessionPayload(EstimateGenerationSession $session): array
    {
        $session->loadMissing(['documents' => static fn ($query) => $query->withCount(['pages', 'facts', 'drawingElements', 'quantityTakeoffs', 'scopeInferences'])->orderBy('id')]);

        return (new EstimateGenerationSessionResource($session))->resolve();
    }

    private function guard(Request $request, Project $project, EstimateGenerationSession $session): void
    {
        if ((int) $session->organization_id !== (int) $request->user()->current_organization_id || (int) $session->project_id !== (int) $project->id) {
            abort(403, trans_message('estimate_generation.access_denied'));
        }
    }

    /** @param callable(): JsonResponse $callback */
    private function safeRead(callable $callback, string $operation, Project $project, EstimateGenerationSession $session): JsonResponse
    {
        try {
            return $callback();
        } catch (HttpExceptionInterface $exception) {
            throw $exception;
        } catch (\Throwable) {
            Log::error('[EstimateGeneration] Review read failed', ['operation' => $operation, 'project_id' => $project->id, 'session_id' => $session->id, 'failure_code' => 'review_read_failed']);

            return AdminResponse::error(trans_message('estimate_generation.read_error'), 500);
        }
    }
}
