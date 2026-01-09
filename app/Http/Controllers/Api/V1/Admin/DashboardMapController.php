<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\Analytics\MapService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use App\Http\Responses\AdminResponse;
use Illuminate\Http\Response;

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
        try {
            $organizationId = Auth::user()->current_organization_id;

            if (!$organizationId) {
                return AdminResponse::error(trans_message('dashboard.organization_required'), Response::HTTP_BAD_REQUEST);
            }

            $zoom = (int) $request->input('zoom', 12);

            $data = $this->mapService->getMapData($organizationId, ['zoom' => $zoom]);

            return AdminResponse::success($data);
        } catch (\Throwable $e) {
            Log::error('Error in DashboardMapController@index', [
                'message' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()
            ]);
            return AdminResponse::error(trans_message('dashboard.map_data_error'), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}

