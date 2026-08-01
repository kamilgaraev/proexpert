<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Http\Controllers;

use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\ApplyProjectModelCorrection;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\ProjectModelCorrectionConflict;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\ProjectModelCorrectionNotFound;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\ProjectModelCorrectionUndoUnavailable;
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
    public function __construct(private readonly ApplyProjectModelCorrection $corrections) {}

    public function store(ApplyProjectModelCorrectionRequest $request, Project $project, EstimateGenerationSession $session): JsonResponse
    {
        try {
            $organizationId = $this->guard($request, $project, $session);
            $data = $request->validated();
            $result = $this->corrections->apply(
                $organizationId,
                (int) $project->getKey(),
                (int) $session->getKey(),
                (int) $request->user()->getKey(),
                (string) $data['expected_source_version'],
                (string) $data['expected_value_fingerprint'],
                (string) $data['assertion_stable_key'],
                $data['value'],
                (string) $data['reason'],
                (string) $data['idempotency_key'],
            );

            return AdminResponse::success(
                $result,
                trans_message('estimate_generation.project_model_correction_applied'),
                $result['idempotent'] ? 200 : 201,
            );
        } catch (NotFoundHttpException|ProjectModelCorrectionNotFound) {
            return AdminResponse::error(trans_message('estimate_generation.project_model_correction_not_found'), 404);
        } catch (ProjectModelCorrectionConflict) {
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
            $result = $this->corrections->revert(
                $organizationId,
                (int) $project->getKey(),
                (int) $session->getKey(),
                (int) $request->user()->getKey(),
                (string) $data['expected_source_version'],
                (string) $data['expected_value_fingerprint'],
                (string) $data['assertion_stable_key'],
                (string) $data['reason'],
                (string) $data['idempotency_key'],
            );

            return AdminResponse::success(
                $result,
                trans_message('estimate_generation.project_model_correction_reverted'),
                $result['idempotent'] ? 200 : 201,
            );
        } catch (NotFoundHttpException|ProjectModelCorrectionNotFound) {
            return AdminResponse::error(trans_message('estimate_generation.project_model_correction_not_found'), 404);
        } catch (ProjectModelCorrectionConflict) {
            return AdminResponse::error(trans_message('estimate_generation.project_model_correction_conflict'), 409);
        } catch (ProjectModelCorrectionUndoUnavailable) {
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
