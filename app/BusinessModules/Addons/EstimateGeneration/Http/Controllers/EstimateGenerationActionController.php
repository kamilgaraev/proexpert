<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Http\Controllers;

use App\BusinessModules\Addons\EstimateGeneration\Application\Apply\ApplyGeneratedEstimate;
use App\BusinessModules\Addons\EstimateGeneration\Application\Apply\ApplyGeneratedEstimateCommand;
use App\BusinessModules\Addons\EstimateGeneration\Application\Generation\AnalyzeEstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Application\Generation\RebuildGeneratedSection;
use App\BusinessModules\Addons\EstimateGeneration\Application\Generation\RequestEstimateGeneration;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\RetryEstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\RetryEstimateGenerationSessionCommand;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\TransitionEstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\EstimateGenerationEvent;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\InvalidEstimateGenerationState;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\InvalidEstimateGenerationTransition;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\StaleEstimateGenerationState;
use App\BusinessModules\Addons\EstimateGeneration\Http\Requests\AnalyzeEstimateGenerationRequest;
use App\BusinessModules\Addons\EstimateGeneration\Http\Requests\ApplyEstimateGenerationDraftRequest;
use App\BusinessModules\Addons\EstimateGeneration\Http\Requests\ConfirmEstimateGenerationInputRequest;
use App\BusinessModules\Addons\EstimateGeneration\Http\Requests\ControlEstimateGenerationSessionRequest;
use App\BusinessModules\Addons\EstimateGeneration\Http\Requests\GenerateEstimateGenerationRequest;
use App\BusinessModules\Addons\EstimateGeneration\Http\Requests\RebuildEstimateGenerationSectionRequest;
use App\BusinessModules\Addons\EstimateGeneration\Http\Resources\EstimateGenerationSessionResource;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

use function trans_message;

final class EstimateGenerationActionController extends Controller
{
    public function __construct(
        protected ApplyGeneratedEstimate $applyGeneratedEstimate,
        protected RebuildGeneratedSection $rebuildGeneratedSection,
        protected AnalyzeEstimateGenerationSession $analyzeSession,
        protected RequestEstimateGeneration $requestGeneration,
        protected TransitionEstimateGenerationSession $transitionSession,
        protected RetryEstimateGenerationSession $retrySession,
    ) {}

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
                'failure_code' => 'analysis_failed',
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
                $request->validated('estimate_regional_price_version_id'),
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
        } catch (\InvalidArgumentException) {
            return AdminResponse::error(trans_message('estimate_generation.price_source_invalid'), 422);
        } catch (\Throwable $e) {
            report($e);
            Log::error('[EstimateGeneration] Generate failed', [
                'failure_code' => 'generation_request_failed',
                'session_id' => $session->id,
                'exception_class' => $e::class,
            ]);

            return AdminResponse::error(trans_message('estimate_generation.generation_error'), 500);
        }
    }

    public function confirmInput(ConfirmEstimateGenerationInputRequest $request, Project $project, EstimateGenerationSession $session): JsonResponse
    {
        return $this->transitionSessionResponse(
            $request,
            $project,
            $session,
            EstimateGenerationEvent::InputConfirmed,
            'estimate_generation.input_confirmed',
            'confirm input',
        );
    }

    public function retry(ControlEstimateGenerationSessionRequest $request, Project $project, EstimateGenerationSession $session): JsonResponse
    {
        try {
            $this->guardSession($request, $project, $session);
            $updated = $this->retrySession->handle(new RetryEstimateGenerationSessionCommand(
                (int) $session->getKey(),
                (int) $request->user()->current_organization_id,
                (int) $project->getKey(),
                (int) $request->validated('state_version'),
            ));

            return AdminResponse::success(
                $this->sessionPayload($updated),
                trans_message('estimate_generation.session_retried'),
            );
        } catch (StaleEstimateGenerationState|InvalidEstimateGenerationTransition|InvalidEstimateGenerationState) {
            return AdminResponse::error(trans_message('estimate_generation.state_conflict'), 409);
        } catch (HttpExceptionInterface $exception) {
            return AdminResponse::error(
                trans_message('estimate_generation.access_denied'),
                $exception->getStatusCode(),
            );
        } catch (\Throwable $exception) {
            Log::error('[EstimateGeneration] Session retry failed', [
                'project_id' => $project->id,
                'session_id' => $session->id,
                'failure_code' => 'session_retry_failed',
            ]);

            return AdminResponse::error(trans_message('estimate_generation.session_transition_error'), 500);
        }
    }

    public function cancel(ControlEstimateGenerationSessionRequest $request, Project $project, EstimateGenerationSession $session): JsonResponse
    {
        return $this->transitionSessionResponse(
            $request,
            $project,
            $session,
            EstimateGenerationEvent::Cancelled,
            'estimate_generation.session_cancelled',
            'cancel session',
        );
    }

    public function archive(ControlEstimateGenerationSessionRequest $request, Project $project, EstimateGenerationSession $session): JsonResponse
    {
        return $this->transitionSessionResponse(
            $request,
            $project,
            $session,
            EstimateGenerationEvent::Archived,
            'estimate_generation.session_archived',
            'archive session',
        );
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
            return AdminResponse::error(trans_message('estimate_generation.validation_error'), 422, $e->errors());
        } catch (StaleEstimateGenerationState $e) {
            return AdminResponse::error(trans_message('estimate_generation.state_conflict'), 409);
        } catch (InvalidEstimateGenerationTransition $e) {
            return AdminResponse::error(trans_message('estimate_generation.apply_not_ready'), 422);
        } catch (\Throwable $e) {
            report($e);
            Log::error('[EstimateGeneration] Apply failed', [
                'failure_code' => 'apply_failed',
                'session_id' => $session->id,
                'exception_class' => $e::class,
            ]);

            return AdminResponse::error(trans_message('estimate_generation.apply_error'), 500);
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

            return AdminResponse::success(
                $this->sessionPayload($session),
                trans_message('estimate_generation.generation_queued'),
                202,
            );
        } catch (StaleEstimateGenerationState) {
            return AdminResponse::error(trans_message('estimate_generation.state_conflict'), 409);
        } catch (InvalidEstimateGenerationTransition|InvalidEstimateGenerationState) {
            return AdminResponse::error(trans_message('estimate_generation.state_conflict'), 409);
        } catch (\Throwable $e) {
            Log::error('[EstimateGeneration] Rebuild section failed', [
                'failure_code' => 'section_rebuild_failed',
                'session_id' => $session->id,
            ]);

            return AdminResponse::error(trans_message('estimate_generation.section_rebuild_error'), 500);
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

    private function transitionSessionResponse(
        Request $request,
        Project $project,
        EstimateGenerationSession $session,
        EstimateGenerationEvent $event,
        string $messageKey,
        string $operation,
    ): JsonResponse {
        try {
            $this->guardSession($request, $project, $session);
            $updated = $this->transitionSession->handle(
                $session,
                (int) $request->input('state_version'),
                $event,
            );

            return AdminResponse::success(
                $this->sessionPayload($updated),
                trans_message($messageKey),
            );
        } catch (StaleEstimateGenerationState|InvalidEstimateGenerationTransition|InvalidEstimateGenerationState) {
            return AdminResponse::error(trans_message('estimate_generation.state_conflict'), 409);
        } catch (HttpExceptionInterface $exception) {
            return AdminResponse::error(
                trans_message('estimate_generation.access_denied'),
                $exception->getStatusCode(),
            );
        } catch (\Throwable $e) {
            Log::error('[EstimateGeneration] Session transition failed', [
                'operation' => $operation,
                'project_id' => $project->id,
                'session_id' => $session->id,
                'failure_code' => 'session_transition_failed',
            ]);

            return AdminResponse::error(trans_message('estimate_generation.session_transition_error'), 500);
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
