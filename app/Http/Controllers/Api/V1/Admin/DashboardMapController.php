<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\Analytics\MapService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class DashboardMapController extends Controller
{
    protected MapService $mapService;

    public function __construct(MapService $mapService)
    {
        $this->mapService = $mapService;
    }

    /**
     * Get map data (projects locations and status)
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        $organizationId = Auth::user()->current_organization_id;

        if (!$organizationId) {
            return response()->json(['message' => 'Organization context required'], 400);
        }

        $zoom = (int) $request->input('zoom', 12);
        
        // Pass bounds if provided
        // $bounds = $request->only(['north', 'south', 'east', 'west']);

        $data = $this->mapService->getMapData($organizationId, ['zoom' => $zoom]);

        return response()->json([
            'success' => true,
            'data' => $data
        ]);
    }
}

