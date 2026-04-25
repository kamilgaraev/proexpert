<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Domain\Project\ValueObjects\ProjectContext;
use App\Enums\ProjectOrganizationRole;
use App\Services\Analytics\EVMService;
use App\Services\Admin\AdminProjectAccessService;
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
    protected AdminProjectAccessService $projectAccessService;

    public function __construct(EVMService $evmService, AdminProjectAccessService $projectAccessService)
    {
        $this->evmService = $evmService;
        $this->projectAccessService = $projectAccessService;
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
            
            $user = Auth::user();

            $projectContext = $user ? $this->projectAccessService->getProjectContext($project, $user) : null;

            if (!$projectContext instanceof ProjectContext || !$this->canViewEvm($projectContext)) {
                return AdminResponse::error(trans_message('dashboard.access_denied'), Response::HTTP_FORBIDDEN);
            }

            $metrics = $this->evmService->calculateMetrics($project, $this->resolveEvmScopeOrganizationId($projectContext));

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
            
            $user = Auth::user();

            $projectContext = $user ? $this->projectAccessService->getProjectContext($project, $user) : null;

            if (!$projectContext instanceof ProjectContext || !$this->canViewEvm($projectContext)) {
                return AdminResponse::error(trans_message('dashboard.access_denied'), Response::HTTP_FORBIDDEN);
            }

            $metrics = $this->evmService->calculateMetrics($project, $this->resolveEvmScopeOrganizationId($projectContext));

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

    private function canViewEvm(ProjectContext $projectContext): bool
    {
        return $projectContext->roleConfig->canViewFinances
            || $projectContext->hasPermission('view_own_finances');
    }

    private function resolveEvmScopeOrganizationId(ProjectContext $projectContext): ?int
    {
        return in_array($projectContext->role, [
            ProjectOrganizationRole::CONTRACTOR,
            ProjectOrganizationRole::SUBCONTRACTOR,
        ], true) ? $projectContext->organizationId : null;
    }
}

