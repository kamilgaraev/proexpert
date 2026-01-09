<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\Analytics\EVMService;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use App\Http\Responses\AdminResponse;
use Illuminate\Http\Response;

class DashboardEVMController extends Controller
{
    protected EVMService $evmService;

    public function __construct(EVMService $evmService)
    {
        $this->evmService = $evmService;
    }

    /**
     * Get EVM metrics for a specific project
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function metrics(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'project_id' => 'required|integer|exists:projects,id',
            ]);

            $project = Project::findOrFail($request->input('project_id'));
            
            // Authorization check
            $user = Auth::user();
            if ($project->organization_id !== $user->current_organization_id) {
                return AdminResponse::error(trans_message('dashboard.access_denied'), Response::HTTP_FORBIDDEN);
            }

            $metrics = $this->evmService->calculateMetrics($project);

            return AdminResponse::success([
                'bac' => $metrics['bac'],
                'pv' => $metrics['pv'],
                'ev' => $metrics['ev'],
                'ac' => $metrics['ac'],
                'sv' => $metrics['sv'],
                'cv' => $metrics['cv'],
                'spi' => $metrics['spi'],
                'cpi' => $metrics['cpi'],
            ]);
        } catch (\Throwable $e) {
            Log::error('Error in DashboardEVMController@metrics', [
                'project_id' => $request->input('project_id'), 'message' => $e->getMessage()
            ]);
            return AdminResponse::error(trans_message('dashboard.evm_metrics_error'), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get EVM forecasts for a specific project
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function forecast(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'project_id' => 'required|integer|exists:projects,id',
            ]);

            $project = Project::findOrFail($request->input('project_id'));
            
            // Authorization check
            $user = Auth::user();
            if ($project->organization_id !== $user->current_organization_id) {
                return AdminResponse::error(trans_message('dashboard.access_denied'), Response::HTTP_FORBIDDEN);
            }

            $metrics = $this->evmService->calculateMetrics($project);

            return AdminResponse::success([
                'eac' => $metrics['eac'],
                'vac' => $metrics['vac'],
                'tcpi' => $metrics['tcpi'],
            ]);
        } catch (\Throwable $e) {
            Log::error('Error in DashboardEVMController@forecast', [
                'project_id' => $request->input('project_id'), 'message' => $e->getMessage()
            ]);
            return AdminResponse::error(trans_message('dashboard.evm_forecast_error'), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}

