<?php

namespace App\Http\Controllers\Api\V1\Admin\Geo;

use App\Http\Controllers\Controller;
use App\Services\Geo\SearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class MapSearchController extends Controller
{
    public function __construct(
        private SearchService $searchService
    ) {}

    /**
     * Search projects by query
     * GET /api/v1/admin/dashboard/map/search
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function search(Request $request): JsonResponse
    {
        $request->validate([
            'q' => 'required|string|min:1',
            'limit' => 'sometimes|integer|min:1|max:100',
        ]);

        $organizationId = Auth::user()->current_organization_id;
        $query = $request->input('q');
        $limit = $request->input('limit', 20);

        try {
            $results = $this->searchService->search($organizationId, $query, $limit);

            return response()->json([
                'success' => true,
                'data' => $results,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Search failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Search projects near a location
     * GET /api/v1/admin/dashboard/map/search/nearby
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function searchNearby(Request $request): JsonResponse
    {
        $request->validate([
            'lat' => 'required|numeric|between:-90,90',
            'lng' => 'required|numeric|between:-180,180',
            'radius' => 'sometimes|numeric|min:0.1|max:1000',
            'limit' => 'sometimes|integer|min:1|max:100',
        ]);

        $organizationId = Auth::user()->current_organization_id;
        $latitude = $request->input('lat');
        $longitude = $request->input('lng');
        $radiusKm = $request->input('radius', 10);
        $limit = $request->input('limit', 20);

        try {
            $results = $this->searchService->searchNearby(
                $organizationId,
                $latitude,
                $longitude,
                $radiusKm,
                $limit
            );

            return response()->json([
                'success' => true,
                'data' => $results,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Nearby search failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Auto-suggest addresses
     * GET /api/v1/admin/dashboard/map/search/suggest
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function suggest(Request $request): JsonResponse
    {
        $request->validate([
            'q' => 'required|string|min:2',
            'limit' => 'sometimes|integer|min:1|max:20',
        ]);

        $organizationId = Auth::user()->current_organization_id;
        $query = $request->input('q');
        $limit = $request->input('limit', 10);

        try {
            $results = $this->searchService->suggest($organizationId, $query, $limit);

            return response()->json([
                'success' => true,
                'data' => $results,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Suggest failed: ' . $e->getMessage(),
            ], 500);
        }
    }
}

