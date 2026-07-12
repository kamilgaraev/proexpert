<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Http\Controllers;

use App\BusinessModules\Addons\EstimateGeneration\Application\Geometry\ConfirmBuildingGeometry;
use App\BusinessModules\Addons\EstimateGeneration\Application\Geometry\GeometryConfirmationCommand;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\StaleEstimateGenerationState;
use App\BusinessModules\Addons\EstimateGeneration\Http\Requests\ConfirmEstimateGenerationGeometryRequest;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

use function trans_message;

final class EstimateGenerationGeometryController extends Controller
{
    public function __construct(private ConfirmBuildingGeometry $confirmGeometry) {}

    public function confirm(ConfirmEstimateGenerationGeometryRequest $request, Project $project, EstimateGenerationSession $session): JsonResponse
    {
        try {
            $organizationId = (int) $request->user()->current_organization_id;
            if ((int) $session->organization_id !== $organizationId || (int) $session->project_id !== (int) $project->getKey()) {
                throw new NotFoundHttpException;
            }
            $validated = $request->validated();
            $result = $this->confirmGeometry->handle(new GeometryConfirmationCommand(
                $organizationId, (int) $project->getKey(), (int) $session->getKey(), (int) $request->user()->getKey(),
                (int) $validated['state_version'], (string) $validated['model_version'], (string) $validated['input_version'],
                is_array($validated['scale'] ?? null) ? $validated['scale'] : null,
                is_array($validated['operations'] ?? null) ? $validated['operations'] : [],
            ));

            return AdminResponse::success($result, trans_message('estimate_generation.geometry_confirmed'));
        } catch (NotFoundHttpException) {
            return AdminResponse::error(trans_message('estimate_generation.geometry_not_found'), 404);
        } catch (StaleEstimateGenerationState) {
            return AdminResponse::error(trans_message('estimate_generation.state_conflict'), 409);
        } catch (InvalidArgumentException) {
            return AdminResponse::error(trans_message('estimate_generation.geometry_invalid'), 422);
        } catch (\Throwable) {
            $failureId = bin2hex(random_bytes(8));
            Log::error('[EstimateGeneration] Geometry confirmation failed', ['failure_id' => $failureId, 'session_id' => $session->getKey()]);

            return AdminResponse::error(trans_message('estimate_generation.geometry_error'), 500, null, ['failure_id' => $failureId]);
        }
    }
}
