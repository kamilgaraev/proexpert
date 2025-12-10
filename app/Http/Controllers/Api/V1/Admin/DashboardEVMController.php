<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\Analytics\EVMService;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

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
        $request->validate([
            'project_id' => 'required|integer|exists:projects,id',
        ]);

        $project = Project::findOrFail($request->input('project_id'));
        
        // Authorization check
        $user = Auth::user();
        if ($project->organization_id !== $user->current_organization_id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $metrics = $this->evmService->calculateMetrics($project);

        return response()->json([
            'success' => true,
            'data' => [
                'bac' => $metrics['bac'],
                'pv' => $metrics['pv'],
                'ev' => $metrics['ev'],
                'ac' => $metrics['ac'],
                'sv' => $metrics['sv'],
                'cv' => $metrics['cv'],
                'spi' => $metrics['spi'],
                'cpi' => $metrics['cpi'],
            ]
        ]);
    }

    /**
     * Get EVM forecasts for a specific project
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function forecast(Request $request): JsonResponse
    {
        $request->validate([
            'project_id' => 'required|integer|exists:projects,id',
        ]);

        $project = Project::findOrFail($request->input('project_id'));
        
        // Authorization check
        $user = Auth::user();
        if ($project->organization_id !== $user->current_organization_id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $metrics = $this->evmService->calculateMetrics($project);

        return response()->json([
            'success' => true,
            'data' => [
                'eac' => $metrics['eac'],
                'vac' => $metrics['vac'],
                'tcpi' => $metrics['tcpi'],
            ]
        ]);
    }
}

