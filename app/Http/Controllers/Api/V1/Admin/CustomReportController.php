<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\CustomReport;
use App\Services\Report\CustomReportBuilderService;
use App\Services\Report\CustomReportExecutionService;
use App\Services\Logging\LoggingService;
use App\Http\Requests\Api\V1\Admin\CustomReport\CreateCustomReportRequest;
use App\Http\Requests\Api\V1\Admin\CustomReport\UpdateCustomReportRequest;
use App\Http\Requests\Api\V1\Admin\CustomReport\ExecuteCustomReportRequest;
use App\Http\Responses\AdminResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CustomReportController extends Controller
{
    public function __construct(
        protected CustomReportBuilderService $builderService,
        protected CustomReportExecutionService $executionService,
        protected LoggingService $logging
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $organizationId = $user->current_organization_id;
        
        // Получаем project_id из URL (обязательный параметр для project-based маршрутов)
        $projectId = $request->route('project');

        $query = CustomReport::forOrganization($organizationId)
            ->where(function ($q) use ($user) {
                $q->where('user_id', $user->id)
                  ->orWhere('is_shared', true);
            });
        
        // Фильтруем по проекту если есть project_id в URL
        if ($projectId) {
            $query->where('project_id', $projectId);
        }

        if ($request->filled('category')) {
            $query->byCategory($request->category);
        }

        if ($request->boolean('is_favorite')) {
            $query->where('user_id', $user->id)->where('is_favorite', true);
        }

        if ($request->boolean('is_shared')) {
            $query->shared();
        }

        $reports = $query->with('user')
            ->orderBy('created_at', 'desc')
            ->paginate($request->input('per_page', 15));

        return AdminResponse::success([
            'data' => $reports->items(),
            'meta' => [
                'current_page' => $reports->currentPage(),
                'per_page' => $reports->perPage(),
                'total' => $reports->total(),
                'last_page' => $reports->lastPage(),
            ],
        ]);
    }

    public function store(CreateCustomReportRequest $request): JsonResponse
    {
        $user = $request->user();
        $organizationId = $user->current_organization_id;

        try {
            $this->logging->technical('custom_report.create_started', [
                'user_id' => $user->id,
                'organization_id' => $organizationId
            ], 'debug');

            $data = $request->validated();
            
            $errors = $this->builderService->validateReportConfig($data);
            
            if (!empty($errors)) {
                $this->logging->technical('custom_report.validation_failed', [
                    'user_id' => $user->id,
                    'organization_id' => $organizationId,
                    'errors' => $errors
                ], 'warning');

                return AdminResponse::error(trans_message('reports.custom.config_invalid'), 422, $errors);
            }

            $report = CustomReport::create([
                ...$data,
                'user_id' => $user->id,
                'organization_id' => $organizationId,
            ]);

            $this->logging->audit('custom_report.created', [
                'report_id' => $report->id,
                'report_name' => $report->name,
                'report_category' => $report->report_category,
                'user_id' => $user->id,
                'organization_id' => $organizationId
            ]);

            $this->logging->business('custom_report.created', [
                'report_id' => $report->id,
                'report_name' => $report->name,
                'has_aggregations' => !empty($report->aggregations_config),
                'has_joins' => !empty($report->data_sources['joins'] ?? [])
            ]);

            return AdminResponse::success($report->load('user'), trans_message('reports.custom.created'), 201);
        } catch (\Throwable $e) {
            $this->logging->technical('custom_report.create_failed', [
                'user_id' => $user->id,
                'organization_id' => $organizationId,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ], 'error');

            Log::error('[CustomReportController@store] Unexpected error', [
                'user_id' => $user->id,
                'organization_id' => $organizationId,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return AdminResponse::error(trans_message('reports.custom.internal_error'), 500);
        }
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $organizationId = $user->current_organization_id;

        $report = CustomReport::find($id);

        if (!$report || !$report->canBeViewedBy($user->id, $organizationId)) {
            return AdminResponse::error(trans_message('reports.custom.not_found'), 404);
        }

        return AdminResponse::success($report->load(['user', 'executions' => fn($q) => $q->recent()->limit(10)]));
    }

    public function update(UpdateCustomReportRequest $request, int $id): JsonResponse
    {
        $user = $request->user();
        $organizationId = $user->current_organization_id;

        $report = CustomReport::find($id);

        if (!$report || !$report->canBeEditedBy($user->id)) {
            return AdminResponse::error(trans_message('reports.custom.no_permission_edit'), 403);
        }

        $data = $request->validated();
        
        if (isset($data['data_sources']) || isset($data['columns_config'])) {
            $configToValidate = array_merge($report->toArray(), $data);
            $errors = $this->builderService->validateReportConfig($configToValidate);
            
            if (!empty($errors)) {
                return AdminResponse::error(trans_message('reports.custom.config_invalid'), 422, $errors);
            }
        }

        $report->update($data);

        return AdminResponse::success($report->fresh(['user']), trans_message('reports.custom.updated'));
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        $report = CustomReport::find($id);

        if (!$report || !$report->canBeEditedBy($user->id)) {
            return AdminResponse::error(trans_message('reports.custom.no_permission_delete'), 403);
        }

        $report->delete();

        return AdminResponse::success(null, trans_message('reports.custom.deleted'));
    }

    public function clone(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $organizationId = $user->current_organization_id;

        $report = CustomReport::find($id);

        if (!$report || !$report->canBeViewedBy($user->id, $organizationId)) {
            return AdminResponse::error(trans_message('reports.custom.not_found'), 404);
        }

        $newReport = $this->builderService->cloneReport($report, $user->id, $organizationId);

        return AdminResponse::success($newReport->load('user'), trans_message('reports.custom.cloned'), 201);
    }

    public function toggleFavorite(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $organizationId = $user->current_organization_id;

        $report = CustomReport::find($id);

        if (!$report || !$report->canBeViewedBy($user->id, $organizationId)) {
            return AdminResponse::error(trans_message('reports.custom.not_found'), 404);
        }

        if ($report->user_id !== $user->id) {
            return AdminResponse::error(trans_message('reports.custom.no_permission_favorite'), 403);
        }

        $report->update(['is_favorite' => !$report->is_favorite]);

        return AdminResponse::success(['is_favorite' => $report->is_favorite]);
    }

    public function updateSharing(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        $report = CustomReport::find($id);

        if (!$report || !$report->canBeEditedBy($user->id)) {
            return AdminResponse::error(trans_message('reports.custom.no_permission_edit'), 403);
        }

        $request->validate(['is_shared' => 'required|boolean']);

        $report->update(['is_shared' => $request->is_shared]);

        return AdminResponse::success(['is_shared' => $report->is_shared], $request->is_shared 
                ? trans_message('reports.custom.shared_organization') 
                : trans_message('reports.custom.shared_private'));
    }

    public function execute(ExecuteCustomReportRequest $request, int $id): JsonResponse|StreamedResponse
    {
        $user = $request->user();
        $organizationId = $user->current_organization_id;

        try {
            $this->logging->technical('custom_report.execute_started', [
                'report_id' => $id,
                'user_id' => $user->id,
                'organization_id' => $organizationId,
                'has_filters' => !empty($request->input('filters', [])),
                'export_format' => $request->input('export')
            ], 'debug');

            $report = CustomReport::find($id);

            if (!$report || !$report->canBeViewedBy($user->id, $organizationId)) {
                $this->logging->security('custom_report.execute_access_denied', [
                    'report_id' => $id,
                    'user_id' => $user->id,
                    'organization_id' => $organizationId
                ], 'warning');

                return AdminResponse::error(trans_message('reports.custom.not_found'), 404);
            }

            $filters = $request->input('filters', []);
            $exportFormat = $request->input('export');

            $this->logging->business('custom_report.execute_requested', [
                'report_id' => $report->id,
                'report_name' => $report->name,
                'user_id' => $user->id,
                'export_format' => $exportFormat
            ]);

            return $this->executionService->executeReport(
                $report,
                $organizationId,
                $filters,
                $exportFormat,
                $user->id
            );
        } catch (\Throwable $e) {
            $this->logging->technical('custom_report.execute_failed', [
                'report_id' => $id,
                'user_id' => $user->id,
                'organization_id' => $organizationId,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ], 'error');

            Log::error('[CustomReportController@execute] Unexpected error', [
                'report_id' => $id,
                'user_id' => $user->id,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return AdminResponse::error(trans_message('reports.custom.execution_failed'), 500);
        }
    }

    public function executions(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $organizationId = $user->current_organization_id;

        $report = CustomReport::find($id);

        if (!$report || !$report->canBeViewedBy($user->id, $organizationId)) {
            return AdminResponse::error(trans_message('reports.custom.not_found'), 404);
        }

        $executions = $this->executionService->getExecutionHistory(
            $report,
            $request->input('limit', 50)
        );

        return AdminResponse::success($executions);
    }
}

