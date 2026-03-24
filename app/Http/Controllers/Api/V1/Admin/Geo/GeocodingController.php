<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\Geo;

use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use App\Jobs\GeocodeProjectJob;
use App\Models\Project;
use App\Services\Geo\Geocoding\GeocodeService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use function trans_message;

class GeocodingController extends Controller
{
    public function __construct(private readonly GeocodeService $geocodeService)
    {
    }

    public function geocodeProject(Request $request, int $id): JsonResponse
    {
        try {
            $organizationId = $this->resolveOrganizationId($request);
            if (!$organizationId) {
                return AdminResponse::error(
                    trans_message('geocoding.organization_not_found'),
                    Response::HTTP_BAD_REQUEST
                );
            }

            $project = Project::where('organization_id', $organizationId)->findOrFail($id);
            $sync = $request->boolean('sync');

            if (!$sync) {
                GeocodeProjectJob::dispatch($project->id);

                return AdminResponse::success(
                    [
                        'project_id' => $project->id,
                        'status' => 'pending',
                    ],
                    trans_message('geocoding.job_queued')
                );
            }

            $success = $this->geocodeService->geocodeAndSave($project);
            if (!$success) {
                return AdminResponse::error(
                    trans_message('geocoding.project_geocode_failed'),
                    Response::HTTP_UNPROCESSABLE_ENTITY
                );
            }

            $project = $project->fresh();

            return AdminResponse::success(
                [
                    'latitude' => $project?->latitude,
                    'longitude' => $project?->longitude,
                ],
                trans_message('geocoding.project_geocoded')
            );
        } catch (ModelNotFoundException) {
            return AdminResponse::error(
                trans_message('geocoding.project_not_found'),
                Response::HTTP_NOT_FOUND
            );
        } catch (Throwable $e) {
            Log::error('geocoding.project.error', [
                'project_id' => $id,
                'user_id' => $request->user()?->id,
                'message' => $e->getMessage(),
            ]);

            return AdminResponse::error(
                trans_message('geocoding.project_geocode_error'),
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    public function getStatistics(Request $request): JsonResponse
    {
        $organizationId = $this->resolveOrganizationId($request);
        if (!$organizationId) {
            return AdminResponse::error(
                trans_message('geocoding.organization_not_found'),
                Response::HTTP_BAD_REQUEST
            );
        }

        try {
            return AdminResponse::success(
                $this->geocodeService->getStatistics($organizationId)
            );
        } catch (Throwable $e) {
            Log::error('geocoding.statistics.error', [
                'organization_id' => $organizationId,
                'user_id' => $request->user()?->id,
                'message' => $e->getMessage(),
            ]);

            return AdminResponse::error(
                trans_message('geocoding.statistics_load_error'),
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    public function batchGeocode(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'project_ids' => 'sometimes|array',
                'project_ids.*' => 'integer|exists:projects,id',
                'status' => 'sometimes|in:pending,failed,all',
            ]);

            $organizationId = $this->resolveOrganizationId($request);
            if (!$organizationId) {
                return AdminResponse::error(
                    trans_message('geocoding.organization_not_found'),
                    Response::HTTP_BAD_REQUEST
                );
            }

            $projectIds = $validated['project_ids'] ?? null;
            $status = $validated['status'] ?? 'pending';

            $query = Project::where('organization_id', $organizationId)
                ->whereNotNull('address');

            if ($projectIds) {
                $query->whereIn('id', $projectIds);
            } elseif ($status !== 'all') {
                $query->where('geocoding_status', $status);
            }

            $projects = $query->get();

            foreach ($projects as $project) {
                GeocodeProjectJob::dispatch($project->id);
            }

            return AdminResponse::success(
                [
                    'count' => $projects->count(),
                    'status' => 'pending',
                ],
                trans_message('geocoding.batch_queued', ['count' => $projects->count()])
            );
        } catch (ValidationException $e) {
            return AdminResponse::error(
                trans_message('estimate.validation_error'),
                Response::HTTP_UNPROCESSABLE_ENTITY,
                $e->errors()
            );
        } catch (Throwable $e) {
            Log::error('geocoding.batch.error', [
                'user_id' => $request->user()?->id,
                'message' => $e->getMessage(),
            ]);

            return AdminResponse::error(
                trans_message('geocoding.batch_geocode_error'),
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    private function resolveOrganizationId(Request $request): ?int
    {
        return $request->user()?->current_organization_id
            ?? $request->user()?->organization_id;
    }
}
