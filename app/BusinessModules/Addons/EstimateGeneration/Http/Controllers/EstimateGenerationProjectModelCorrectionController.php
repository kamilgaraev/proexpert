<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Http\Controllers;

use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\ProjectModelCorrectionNotFound;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Decisions\ActorContext;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Decisions\ApplyEstimateDecision;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Decisions\EstimateDecisionConflict;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Decisions\EstimateDecisionRepository;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Decisions\EstimateDecisionUndoUnavailable;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Decisions\RevertEstimateDecision;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\Http\Controllers\Controller;
use App\Http\Requests\EstimateGeneration\ApplyProjectModelCorrectionRequest;
use App\Http\Responses\AdminResponse;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

use function trans_message;

final class EstimateGenerationProjectModelCorrectionController extends Controller
{
    public function __construct(
        private readonly ApplyEstimateDecision $applyDecision,
        private readonly RevertEstimateDecision $revertDecision,
        private readonly EstimateDecisionRepository $decisions,
    ) {}

    public function store(ApplyProjectModelCorrectionRequest $request, Project $project, EstimateGenerationSession $session): JsonResponse
    {
        try {
            $organizationId = $this->guard($request, $project, $session);
            $data = $request->validated();
            $sessionId = (string) $session->getKey();
            $decisionKey = (string) $data['assertion_stable_key'];
            $latest = $this->decisions->latest($sessionId, $decisionKey);
            $decision = $this->applyDecision->handle(
                $sessionId,
                $decisionKey,
                $latest?->version ?? 0,
                $latest?->after ?? [],
                $data['value'],
                (string) $data['reason'],
                new ActorContext(
                    $organizationId,
                    (int) $project->getKey(),
                    (int) $request->user()->getKey(),
                    (string) $data['idempotency_key'],
                    (string) $data['expected_source_version'],
                    (string) $data['expected_value_fingerprint'],
                ),
            );
            $result = $decision->toArray();

            return AdminResponse::success(
                $result,
                trans_message('estimate_generation.project_model_correction_applied'),
                $result['idempotent'] ? 200 : 201,
            );
        } catch (NotFoundHttpException|ProjectModelCorrectionNotFound) {
            return AdminResponse::error(trans_message('estimate_generation.project_model_correction_not_found'), 404);
        } catch (EstimateDecisionConflict) {
            return AdminResponse::error(trans_message('estimate_generation.project_model_correction_conflict'), 409);
        } catch (InvalidArgumentException) {
            return AdminResponse::error(trans_message('estimate_generation.project_model_correction_invalid'), 422);
        } catch (\Throwable $exception) {
            $this->logFailure($exception, $request, $project, $session, 'apply');

            return AdminResponse::error(trans_message('estimate_generation.project_model_correction_error'), 500);
        }
    }

    public function revert(ApplyProjectModelCorrectionRequest $request, Project $project, EstimateGenerationSession $session): JsonResponse
    {
        try {
            $organizationId = $this->guard($request, $project, $session);
            $data = $request->validated();
            $sessionId = (string) $session->getKey();
            $decisionKey = (string) $data['assertion_stable_key'];
            $latest = $this->decisions->latest($sessionId, $decisionKey);
            $decision = $this->revertDecision->handle(
                $sessionId,
                $decisionKey,
                $latest?->version ?? 0,
                (string) $data['reason'],
                new ActorContext(
                    $organizationId,
                    (int) $project->getKey(),
                    (int) $request->user()->getKey(),
                    (string) $data['idempotency_key'],
                    (string) $data['expected_source_version'],
                    (string) $data['expected_value_fingerprint'],
                ),
            );
            $result = $decision->toArray();

            return AdminResponse::success(
                $result,
                trans_message('estimate_generation.project_model_correction_reverted'),
                $result['idempotent'] ? 200 : 201,
            );
        } catch (NotFoundHttpException|ProjectModelCorrectionNotFound) {
            return AdminResponse::error(trans_message('estimate_generation.project_model_correction_not_found'), 404);
        } catch (EstimateDecisionConflict) {
            return AdminResponse::error(trans_message('estimate_generation.project_model_correction_conflict'), 409);
        } catch (EstimateDecisionUndoUnavailable) {
            return AdminResponse::error(trans_message('estimate_generation.project_model_correction_undo_unavailable'), 422);
        } catch (InvalidArgumentException) {
            return AdminResponse::error(trans_message('estimate_generation.project_model_correction_invalid'), 422);
        } catch (\Throwable $exception) {
            $this->logFailure($exception, $request, $project, $session, 'revert');

            return AdminResponse::error(trans_message('estimate_generation.project_model_correction_error'), 500);
        }
    }

    private function guard(ApplyProjectModelCorrectionRequest $request, Project $project, EstimateGenerationSession $session): int
    {
        $organizationId = (int) ($request->user()?->current_organization_id ?? 0);
        if ($organizationId < 1
            || (int) $project->organization_id !== $organizationId
            || (int) $session->organization_id !== $organizationId
            || (int) $session->project_id !== (int) $project->getKey()) {
            throw new NotFoundHttpException;
        }

        return $organizationId;
    }

    private function logFailure(\Throwable $exception, ApplyProjectModelCorrectionRequest $request, Project $project, EstimateGenerationSession $session, string $operation): void
    {
        Log::error('[EstimateGeneration] Project model correction failed', [
            'exception' => $exception,
            'organization_id' => (int) ($request->user()?->current_organization_id ?? 0),
            'project_id' => (int) $project->getKey(),
            'session_id' => (int) $session->getKey(),
            'actor_id' => (int) ($request->user()?->getKey() ?? 0),
            'operation' => $operation,
        ]);
    }
}
