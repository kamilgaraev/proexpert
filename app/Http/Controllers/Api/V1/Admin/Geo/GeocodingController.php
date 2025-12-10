<?php

namespace App\Http\Controllers\Api\V1\Admin\Geo;

use App\Http\Controllers\Controller;
use App\Jobs\GeocodeProjectJob;
use App\Models\Project;
use App\Services\Geo\Geocoding\GeocodeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class GeocodingController extends Controller
{
    public function __construct(
        private GeocodeService $geocodeService
    ) {}

    /**
     * Geocode a single project
     * POST /api/v1/admin/projects/{id}/geocode
     * 
     * @param Request $request
     * @param int $id
     * @return JsonResponse
     */
    public function geocodeProject(Request $request, int $id): JsonResponse
    {
        $user = Auth::user();
        $project = Project::where('organization_id', $user->current_organization_id)
            ->findOrFail($id);

        $sync = $request->input('sync', false);

        if ($sync) {
            // Synchronous geocoding
            try {
                $success = $this->geocodeService->geocodeAndSave($project);

                if ($success) {
                    return response()->json([
                        'success' => true,
                        'message' => 'Project geocoded successfully',
                        'data' => [
                            'latitude' => $project->fresh()->latitude,
                            'longitude' => $project->fresh()->longitude,
                        ],
                    ]);
                } else {
                    return response()->json([
                        'success' => false,
                        'message' => 'Geocoding failed. Check project address.',
                    ], 422);
                }
            } catch (\Exception $e) {
                return response()->json([
                    'success' => false,
                    'message' => 'Geocoding error: ' . $e->getMessage(),
                ], 500);
            }
        } else {
            // Asynchronous geocoding via queue
            GeocodeProjectJob::dispatch($project->id);

            return response()->json([
                'success' => true,
                'message' => 'Geocoding job queued',
                'data' => [
                    'project_id' => $project->id,
                    'status' => 'pending',
                ],
            ]);
        }
    }

    /**
     * Get geocoding statistics
     * GET /api/v1/admin/projects/geocoding-status
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function getStatistics(Request $request): JsonResponse
    {
        $organizationId = Auth::user()->current_organization_id;

        try {
            $stats = $this->geocodeService->getStatistics($organizationId);

            return response()->json([
                'success' => true,
                'data' => $stats,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to get statistics: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Batch geocode projects
     * POST /api/v1/admin/projects/batch-geocode
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function batchGeocode(Request $request): JsonResponse
    {
        $request->validate([
            'project_ids' => 'sometimes|array',
            'project_ids.*' => 'integer|exists:projects,id',
            'status' => 'sometimes|in:pending,failed,all',
        ]);

        $organizationId = Auth::user()->current_organization_id;
        $projectIds = $request->input('project_ids');
        $status = $request->input('status', 'pending');

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

        return response()->json([
            'success' => true,
            'message' => "Queued {$projects->count()} projects for geocoding",
            'data' => [
                'count' => $projects->count(),
            ],
        ]);
    }
}

