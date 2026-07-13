<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Http\Controllers;

use App\BusinessModules\Addons\EstimateGeneration\Application\Geometry\ConfirmBuildingGeometry;
use App\BusinessModules\Addons\EstimateGeneration\Application\Geometry\GeometryConfirmationCommand;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\SessionOperationalSnapshotBuilder;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\SessionSnapshotEtag;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\StaleEstimateGenerationState;
use App\BusinessModules\Addons\EstimateGeneration\Http\Presentation\GeometryReviewPayloadReader;
use App\BusinessModules\Addons\EstimateGeneration\Http\Requests\ConfirmEstimateGenerationGeometryRequest;
use App\BusinessModules\Addons\EstimateGeneration\Http\Requests\ShowEstimateGenerationGeometryRequest;
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
    public function __construct(
        private ConfirmBuildingGeometry $confirmGeometry,
        private SessionOperationalSnapshotBuilder $snapshotBuilder,
        private GeometryReviewPayloadReader $reviewPayload,
    ) {}

    public function show(ShowEstimateGenerationGeometryRequest $request, Project $project, EstimateGenerationSession $session): JsonResponse
    {
        try {
            $organizationId = (int) $request->user()->current_organization_id;
            if ((int) $session->organization_id !== $organizationId || (int) $session->project_id !== (int) $project->getKey()) {
                throw new NotFoundHttpException;
            }

            $validated = $request->validated();

            return AdminResponse::success($this->reviewPayload->handle(
                $session,
                (int) ($validated['sources_page'] ?? 1),
                (int) ($validated['sources_per_page'] ?? 20),
            ));
        } catch (NotFoundHttpException) {
            return AdminResponse::error(trans_message('estimate_generation.geometry_not_found'), 404);
        } catch (\Throwable $exception) {
            Log::error('[EstimateGeneration] Geometry review read failed', [
                'exception' => $exception,
                'organization_id' => (int) ($request->user()?->current_organization_id ?? 0),
                'project_id' => (int) $project->getKey(),
                'session_id' => (int) $session->getKey(),
            ]);

            return AdminResponse::error(trans_message('estimate_generation.geometry_error'), 500);
        }
    }

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
                is_array($validated['source_confirmation'] ?? null) ? $validated['source_confirmation'] : null,
            ));

            $freshSession = $session->fresh() ?? $session;
            $snapshot = $this->snapshotBuilder->handle($freshSession, ['estimate_generation.review']);
            $payload = [...$snapshot->toArray(), ...$result];
            $etag = SessionSnapshotEtag::forRevision($organizationId, (int) $session->getKey(), $snapshot->operationalVersion);

            return AdminResponse::success($payload, trans_message('estimate_generation.geometry_confirmed'))->withHeaders([
                'ETag' => $etag,
                'Cache-Control' => 'private, no-cache',
            ]);
        } catch (NotFoundHttpException) {
            return AdminResponse::error(trans_message('estimate_generation.geometry_not_found'), 404);
        } catch (StaleEstimateGenerationState) {
            return AdminResponse::error(trans_message('estimate_generation.state_conflict'), 409);
        } catch (InvalidArgumentException) {
            return AdminResponse::error(trans_message('estimate_generation.geometry_invalid'), 422);
        } catch (\Throwable $exception) {
            $failureId = bin2hex(random_bytes(8));
            Log::error('[EstimateGeneration] Geometry confirmation failed', [
                'exception' => $exception,
                'failure_id' => $failureId,
                'organization_id' => (int) ($request->user()?->current_organization_id ?? 0),
                'project_id' => (int) $project->getKey(),
                'session_id' => (int) $session->getKey(),
                'actor_id' => (int) ($request->user()?->getKey() ?? 0),
                'request' => [
                    'body_bytes' => strlen($request->getContent()),
                    'operations_count' => is_array($request->input('operations')) ? count($request->input('operations')) : 0,
                    'has_scale' => $request->filled('scale'),
                ],
            ]);

            return AdminResponse::error(trans_message('estimate_generation.geometry_error'), 500, null, ['failure_id' => $failureId]);
        }
    }
}
